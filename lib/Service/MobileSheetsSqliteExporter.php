<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalMiscMapper;
use OCA\SheetMusic\Db\InternalSongAttributeMapper;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Util\MobileSheetsTimestampUtil;
use OCA\SheetMusic\Util\SongSortingUtil;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use PDO;
use Psr\Log\LoggerInterface;

class MobileSheetsSqliteExporter {
	/**
	 * Mapping of internal entity types to MobileSheets table specifications.
	 *
	 * @var array<string, array{table: string, nameCol: string, linkTable: string, linkCol: string, hasSort: bool}>
	 */
	public const ENTITY_TABLE_CONFIG = MobileSheetsEntityConfig::BY_TYPE;

	public function __construct(
		private InternalSongMapper $songMapper,
		private InternalEntityMapper $entityMapper,
		private InternalSongFileMapper $songFileMapper,
		private InternalSongAttributeMapper $attributeMapper,
		private InternalMiscMapper $miscMapper,
		private LibraryMapper $libraryMapper,
		private MobileSheetsPathResolver $pathResolver,
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Export the entire library state to a MobileSheets-compatible SQLite database file.
	 */
	public function exportToFile(InternalLibrary|array|int $library, string $targetSqlitePath): void {
		$origPrecision = ini_get('precision');
		ini_set('precision', '-1');

		try {
			$lib = $this->resolveLibrary($library);
			if ($lib->id === null) {
				throw new \InvalidArgumentException('Library must have an ID to export');
			}

			$directory = dirname($targetSqlitePath);
			if (!is_dir($directory)) {
				mkdir($directory, 0777, true);
			}
			if (file_exists($targetSqlitePath)) {
				@unlink($targetSqlitePath);
			}

			$db = new PDO('sqlite:' . $targetSqlitePath, options: [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			]);

			$db->exec('PRAGMA page_size = ' . MobileSheetsBaselineSchema::DEFAULT_PAGE_SIZE);
			$db->exec('PRAGMA auto_vacuum = FULL');
			$db->exec('PRAGMA synchronous = OFF');
			$db->exec('PRAGMA journal_mode = MEMORY');

			$this->initializeSchema($db, $lib->id);
			$this->applyUserVersion($db, $lib->id);

			$db->beginTransaction();
			try {
				$user = $this->userManager->get($lib->userId);

				$this->exportMiscData($db, $lib->id, $user);
				$validSongIds = $this->getSongIdsWithValidFiles($db, $lib, $user);
				$this->exportEntities($db, $lib->id);
				$this->exportSongs($db, $lib->id, $validSongIds);
				$this->exportSongEntityLinks($db, $lib->id, $validSongIds);
				$this->exportFiles($db, $lib, $user);

				$db->commit();
			} catch (\Throwable $e) {
				if ($db->inTransaction()) {
					$db->rollBack();
				}
				$this->logger->error('Failed to export MobileSheets SQLite database: ' . $e->getMessage(), [
					'exception' => $e,
					'libraryId' => $lib->id,
				]);
				throw $e;
			}
		} finally {
			if ($origPrecision !== false) {
				ini_set('precision', $origPrecision);
			}
		}
	}

	/**
	 * Generate the dynamic content for mobilesheets_hashcodes.txt.
	 */
	public function generateHashcodes(InternalLibrary|array|int $library): string {
		$lib = $this->resolveLibrary($library);
		if ($lib->id === null) {
			return '';
		}

		$user = $this->userManager->get($lib->userId);
		$files = $this->songFileMapper->findAllForLibrary($lib->id, includeDeleted: false);

		$lines = [];
		$handledPaths = [];

		foreach ($files as $file) {
			$webdavPath = $this->resolveWebdavPathForFile($file, $lib, $user);
			if ($webdavPath === null || $webdavPath === '' || isset($handledPaths[$webdavPath])) {
				continue;
			}
			$handledPaths[$webdavPath] = true;

			$hash = $file->fileHash ?? 0;
			$modifiedAtMs = MobileSheetsTimestampUtil::secondsToMs($file->updatedAt);
			$size = $file->fileSize ?? 0;

			$lines[] = $webdavPath;
			$lines[] = (string)$hash;
			$lines[] = (string)$modifiedAtMs;
			$lines[] = (string)$size;
		}

		// Also supplement with files from misc_data if present and library has no managed files
		if (empty($files)) {
			$miscFiles = $this->miscMapper->getMiscDataForTable($lib->id, 'mobilesheets', 'Files', includeDeleted: false);
			if ($miscFiles !== []) {
				$songs = $this->songMapper->findAllForLibrary($lib->id, includeDeleted: true);
				$deletedSongClientIds = [];
				foreach ($songs as $s) {
					if ($s->clientId !== null && $s->deletedAt !== null) {
						$deletedSongClientIds[$s->clientId] = true;
					}
				}

				foreach ($miscFiles as $mf) {
					if (!is_array($mf) || empty($mf['Path'])) {
						continue;
					}
					$songClientId = isset($mf['SongId']) && is_numeric($mf['SongId']) ? (int)$mf['SongId'] : null;
					if ($songClientId !== null && isset($deletedSongClientIds[$songClientId])) {
						continue;
					}

					$webdavPath = (string)$mf['Path'];
					if (isset($handledPaths[$webdavPath])) {
						continue;
					}

					if ($user !== null) {
						try {
							$pathObj = $this->pathResolver->fromStored($webdavPath, $user);
							if ($pathObj !== null) {
								$rel = $this->pathResolver->relativeToUserFolder($pathObj->internal, $user);
								$userFolder = $this->rootFolder->getUserFolder($user->getUID());
								if ($rel !== null && !$userFolder->nodeExists($rel)) {
									continue;
								}
							}
						} catch (\Throwable) {
							// Pass through in synthetic environments where userFolder cannot resolve
						}
					}

					$handledPaths[$webdavPath] = true;

					$hash = isset($mf['FileHash']) && is_numeric($mf['FileHash']) ? (int)$mf['FileHash'] : 0;
					$modifiedAtMs = isset($mf['LastModified']) && is_numeric($mf['LastModified']) ? (int)$mf['LastModified'] : 0;
					$size = isset($mf['FileSize']) && is_numeric($mf['FileSize']) ? (int)$mf['FileSize'] : 0;

					$lines[] = $webdavPath;
					$lines[] = (string)$hash;
					$lines[] = (string)$modifiedAtMs;
					$lines[] = (string)$size;
				}
			}
		}

		return implode("\n", $lines);
	}

	private function resolveLibrary(InternalLibrary|array|int $library): InternalLibrary {
		if ($library instanceof InternalLibrary) {
			return $library;
		}

		if (is_int($library)) {
			$model = $this->libraryMapper->findModelById($library);
			if ($model === null) {
				throw new \InvalidArgumentException('Library not found: ' . $library);
			}
			return $model;
		}

		return InternalLibrary::fromRow($library);
	}

	private function applyUserVersion(PDO $db, int $libraryId): void {
		$userVersion = $this->miscMapper->getPragmaUserVersion($libraryId, 'mobilesheets');
		// Fallback to standard MobileSheets baseline schema version if not explicitly specified
		$userVersion ??= MobileSheetsBaselineSchema::DEFAULT_USER_VERSION;
		$db->exec('PRAGMA user_version = ' . $userVersion);
	}

	private function initializeSchema(PDO $db, int $libraryId): void {
		$miscSchemas = $this->miscMapper->getMiscSchemas($libraryId, 'mobilesheets');
		$miscSchemaKeysLower = array_change_key_case($miscSchemas, CASE_LOWER);

		if (isset($miscSchemaKeysLower['songs'])) {
			foreach ($miscSchemas as $tableName => $createSql) {
				if (strtolower($tableName) === '_nc_sync_metadata') {
					continue;
				}
				$db->exec($createSql);
			}
			$db->exec('CREATE TABLE IF NOT EXISTS _nc_sync_metadata (entity_type TEXT NOT NULL, uuid TEXT NOT NULL, client_id INTEGER, updated_at INTEGER, extra_json TEXT, PRIMARY KEY (entity_type, uuid))');
			$db->exec('CREATE INDEX IF NOT EXISTS idx_nc_sync_client ON _nc_sync_metadata (entity_type, client_id)');
			return;
		}

		$statements = MobileSheetsBaselineSchema::TABLES;
		$statements['_nc_sync_metadata'] = 'CREATE TABLE _nc_sync_metadata (entity_type TEXT NOT NULL, uuid TEXT NOT NULL, client_id INTEGER, updated_at INTEGER, extra_json TEXT, PRIMARY KEY (entity_type, uuid))';

		foreach ($statements as $tableName => $sql) {
			$lowerName = strtolower($tableName);
			if (isset($miscSchemaKeysLower[$lowerName])) {
				$sql = $miscSchemaKeysLower[$lowerName];
			}
			$db->exec($sql);
		}

		$db->exec('CREATE INDEX IF NOT EXISTS idx_nc_sync_client ON _nc_sync_metadata (entity_type, client_id)');

		// Recreate standard baseline MobileSheets indexes
		foreach (MobileSheetsBaselineSchema::INDEXES as $idxSql) {
			try {
				$db->exec($idxSql);
			} catch (\Throwable) {
				// Ignore if underlying table/column does not exist in customized schemas
			}
		}

		// Any remaining misc schemas not yet created
		foreach ($miscSchemas as $tableName => $createSql) {
			$check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . str_replace("'", "''", $tableName) . "'");
			if ($check === false || $check->fetch() === false) {
				try {
					$db->exec($createSql);
				} catch (\Throwable $e) {
					$this->logger->warning('Failed to execute misc schema DDL for table: ' . $tableName, ['exception' => $e]);
				}
			}
		}
	}

	private function exportEntities(PDO $db, int $libraryId): void {
		$entities = $this->entityMapper->findAllForLibrary($libraryId, includeDeleted: false);

		// Determine highest clientId per table to assign new client IDs safely
		$usedIds = [];
		$maxId = 0;
		foreach ($entities as $e) {
			if ($e->clientId !== null && $e->clientId > 0) {
				$usedIds[$e->clientId] = true;
				if ($e->clientId > $maxId) {
					$maxId = $e->clientId;
				}
			}
		}

		$nextId = $maxId + 1;

		$hasSyncMeta = MobileSheetsTableHelper::tableExists($db, '_nc_sync_metadata');
		$metaStmt = $hasSyncMeta ? $db->prepare('INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES (:type, :uuid, :client_id, :updated_at, :extra_json)') : null;

		foreach ($entities as $entity) {
			$config = self::ENTITY_TABLE_CONFIG[$entity->type] ?? self::ENTITY_TABLE_CONFIG['customgroup'];

			$tableName = MobileSheetsTableHelper::resolveTable($db, $config['table']);
			if ($tableName === null) {
				continue;
			}

			$clientId = $entity->clientId;
			if ($clientId === null || $clientId <= 0) {
				while (isset($usedIds[$nextId])) {
					$nextId++;
				}
				$clientId = $nextId++;
				$usedIds[$clientId] = true;

				$entity->clientId = $clientId;
				$this->entityMapper->update($entity);
			}

			$rowExists = false;
			$checkRow = $db->query(sprintf('SELECT 1 FROM "%s" WHERE Id = %d', str_replace('"', '""', $tableName), (int)$clientId));
			if ($checkRow !== false && $checkRow->fetch() !== false) {
				$rowExists = true;
			}

			if (!$rowExists) {
				$existingCols = MobileSheetsTableHelper::getTableColumns($db, $tableName);

				$nameCol = MobileSheetsTableHelper::resolveEntityNameColumn($existingCols, $config['nameCol']) ?? $config['nameCol'];
				$val = (strtolower($nameCol) === 'year') ? (int)$entity->name : $entity->name;
				$hasSortCols = isset($existingCols['sortby']) && isset($existingCols['ascending']);
				$dateCreatedCol = $existingCols['datecreated'] ?? ($existingCols['creationdate'] ?? null);
				$lastModCol = $existingCols['lastmodified'] ?? null;

				$insertRow = [
					'Id' => $clientId,
					$nameCol => $val,
				];

				if ($hasSortCols) {
					$insertRow[$existingCols['sortby']] = $entity->sortBy;
					$insertRow[$existingCols['ascending']] = $entity->ascending;
				}
				if ($dateCreatedCol !== null) {
					$insertRow[$dateCreatedCol] = MobileSheetsTimestampUtil::secondsToMs($entity->createdAt);
				}
				if ($lastModCol !== null) {
					$insertRow[$lastModCol] = MobileSheetsTimestampUtil::secondsToMs($entity->updatedAt);
				}

				MobileSheetsTableHelper::insertRow($db, $tableName, $insertRow);
			}

			if ($metaStmt !== null) {
				$metaStmt->execute([
					':type' => 'entity:' . $entity->type,
					':uuid' => $entity->uuid,
					':client_id' => $clientId,
					':updated_at' => $entity->updatedAt,
					':extra_json' => json_encode(['original_type' => $entity->type, 'name' => $entity->name], JSON_THROW_ON_ERROR),
				]);
			}
		}
	}

	/**
	 * @return array<int, bool> Set of internal song IDs (and/or client IDs) that have at least one valid file.
	 */
	private function getSongIdsWithValidFiles(PDO $db, InternalLibrary $library, ?IUser $user): array {
		$validSongIds = [];
		$files = $this->songFileMapper->findAllForLibrary($library->id, includeDeleted: false);

		foreach ($files as $file) {
			$webdavPath = $this->resolveWebdavPathForFile($file, $library, $user);
			if ($webdavPath !== null && $webdavPath !== '') {
				$validSongIds[$file->songId] = true;
			}
		}

		// Also check if any files were exported from misc_data (e.g. synthetic roundtrip tests)
		if (MobileSheetsTableHelper::tableExists($db, 'Files')) {
			$stmt = $db->query('SELECT DISTINCT SongId FROM Files WHERE SongId IS NOT NULL');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$validSongIds[(int)$row['SongId']] = true;
				}
			}
		}

		return $validSongIds;
	}

	/**
	 * @param array<int, bool> $validSongIds
	 */
	private function exportSongs(PDO $db, int $libraryId, array $validSongIds): void {
		if (!MobileSheetsTableHelper::tableExists($db, 'Songs')) {
			return;
		}

		$hasNotesTable = MobileSheetsTableHelper::tableExists($db, 'SongNotes');
		$hasSyncMeta = MobileSheetsTableHelper::tableExists($db, '_nc_sync_metadata');

		$songs = $this->songMapper->findAllForLibrary($libraryId, includeDeleted: false);
		$attributesBySong = $this->attributeMapper->findAllForLibrary($libraryId);

		$usedIds = [];
		$maxId = 0;
		foreach ($songs as $s) {
			if ($s->clientId !== null && $s->clientId > 0) {
				$usedIds[$s->clientId] = true;
				if ($s->clientId > $maxId) {
					$maxId = $s->clientId;
				}
			}
		}

		$nextId = $maxId + 1;

		$metaStmt = $hasSyncMeta ? $db->prepare('INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES (:type, :uuid, :client_id, :updated_at, :extra_json)') : null;

		$existingCols = MobileSheetsTableHelper::getTableColumns($db, 'Songs');

		foreach ($songs as $song) {
			$hasValidFile = isset($validSongIds[$song->id])
				|| ($song->clientId !== null && isset($validSongIds[$song->clientId]));
			if (!$hasValidFile) {
				continue;
			}

			$clientId = $song->clientId;
			if ($clientId === null || $clientId <= 0) {
				while (isset($usedIds[$nextId])) {
					$nextId++;
				}
				$clientId = $nextId++;
				$usedIds[$clientId] = true;

				$song->clientId = $clientId;
				$this->songMapper->update($song);
			}

			$rowExists = false;
			$checkRow = $db->query(sprintf('SELECT 1 FROM Songs WHERE Id = %d', (int)$clientId));
			if ($checkRow !== false && $checkRow->fetch() !== false) {
				$rowExists = true;
			}

			if (!$rowExists) {
				$attrs = $attributesBySong[$song->id] ?? [];
				$custom = $attrs['custom'] ?? '';
				$custom2 = $attrs['custom2'] ?? '';

				$candidateValues = [
					'id' => $clientId,
					'title' => $song->title,
					'sorttitle' => $song->sortTitle,
					'stars' => $song->rating,
					'difficulty' => $song->difficulty,
					'duration' => $song->duration,
					'keywords' => $song->keywords,
					'custom' => $custom,
					'custom2' => $custom2,
					'songid' => $clientId,
					'datecreated' => MobileSheetsTimestampUtil::secondsToMs($song->createdAt),
					'creationdate' => MobileSheetsTimestampUtil::secondsToMs($song->createdAt),
					'lastmodified' => MobileSheetsTimestampUtil::secondsToMs($song->updatedAt),
					'opus' => $song->opus ?? '',
					'catalognumber' => $song->catalogNumber ?? '',
					'songnumber' => $song->songNumber ?? '',
				];

				$insertRow = [];
				foreach ($candidateValues as $lowerKey => $val) {
					if (isset($existingCols[$lowerKey])) {
						$insertRow[$existingCols[$lowerKey]] = $val;
					}
				}

				MobileSheetsTableHelper::insertRow($db, 'Songs', $insertRow);
			}

			if ($hasNotesTable && $song->notes !== '') {
				$noteExists = false;
				$checkNote = $db->query(sprintf('SELECT 1 FROM SongNotes WHERE SongId = %d', (int)$clientId));
				if ($checkNote !== false && $checkNote->fetch() !== false) {
					$noteExists = true;
				}

				if (!$noteExists) {
					$noteCols = MobileSheetsTableHelper::getTableColumns($db, 'SongNotes');

					$noteVals = [
						'songid' => $clientId,
						'notes' => $song->notes,
						'note' => $song->notes,
						'title' => '',
						'datecreated' => MobileSheetsTimestampUtil::secondsToMs($song->createdAt),
						'creationdate' => MobileSheetsTimestampUtil::secondsToMs($song->createdAt),
						'lastmodified' => MobileSheetsTimestampUtil::secondsToMs($song->updatedAt),
					];
					$noteRow = [];
					foreach ($noteVals as $lowerKey => $val) {
						if (isset($noteCols[$lowerKey])) {
							$noteRow[$noteCols[$lowerKey]] = $val;
						}
					}
					MobileSheetsTableHelper::insertRow($db, 'SongNotes', $noteRow);
				}
			}

			if ($metaStmt !== null) {
				$extra = [
					'opus' => $song->opus,
					'catalog_number' => $song->catalogNumber,
					'song_number' => $song->songNumber,
				];

				$metaStmt->execute([
					':type' => 'song',
					':uuid' => $song->uuid,
					':client_id' => $clientId,
					':updated_at' => $song->updatedAt,
					':extra_json' => json_encode($extra, JSON_THROW_ON_ERROR),
				]);
			}
		}
	}

	/**
	 * @param array<int, bool> $validSongIds
	 */
	private function exportSongEntityLinks(PDO $db, int $libraryId, array $validSongIds): void {
		$links = $this->entityMapper->findAllSongEntityLinksForLibrary($libraryId);

		$songs = $this->songMapper->findAllForLibrary($libraryId, includeDeleted: false);
		$songClientMap = [];
		$songById = [];
		foreach ($songs as $s) {
			$hasValidFile = isset($validSongIds[$s->id])
				|| ($s->clientId !== null && isset($validSongIds[$s->clientId]));
			if (!$hasValidFile) {
				continue;
			}
			$songById[$s->id] = $s;
			if ($s->clientId !== null) {
				$songClientMap[$s->id] = $s->clientId;
			}
		}

		$entities = $this->entityMapper->findAllForLibrary($libraryId, includeDeleted: false);
		$entityClientMap = [];
		$entityById = [];
		foreach ($entities as $e) {
			$entityById[$e->id] = $e;
			if ($e->clientId !== null) {
				$entityClientMap[$e->id] = $e->clientId;
			}
		}

		$linksByEntityId = [];
		foreach ($links as $link) {
			$linksByEntityId[$link->entityId][] = $link;
		}

		foreach ($linksByEntityId as $entityId => $entityLinks) {
			$entity = $entityById[$entityId] ?? null;
			$entityClientId = $entityClientMap[$entityId] ?? null;
			if ($entity === null || $entityClientId === null) {
				continue;
			}

			$type = $entity->type;
			$config = self::ENTITY_TABLE_CONFIG[$type] ?? self::ENTITY_TABLE_CONFIG['customgroup'];
			$linkTable = $config['linkTable'];
			$linkCol = $config['linkCol'];

			if (!MobileSheetsTableHelper::tableExists($db, $linkTable)) {
				continue;
			}

			// Sort entity links according to entity's sortBy and ascending mode
			usort($entityLinks, function ($a, $b) use ($songById, $entity) {
				return SongSortingUtil::compare(
					$songById[$a->songId] ?? null,
					$songById[$b->songId] ?? null,
					$entity->sortBy,
					$entity->ascending,
					$a->linkId,
					$b->linkId,
				);
			});

			$stmt = $db->prepare(sprintf(
				'INSERT INTO "%s" ("%s", SongId) VALUES (?, ?)',
				str_replace('"', '""', $linkTable),
				str_replace('"', '""', $linkCol),
			));

			$checkExisting = $db->prepare(sprintf('SELECT 1 FROM "%s" WHERE "%s" = ? AND SongId = ?', str_replace('"', '""', $linkTable), str_replace('"', '""', $linkCol)));
			$insertedSongClientIds = [];
			foreach ($entityLinks as $link) {
				$songId = $link->songId;
				$songClientId = $songClientMap[$songId] ?? null;
				if ($songClientId === null || isset($insertedSongClientIds[$songClientId])) {
					continue;
				}
				$insertedSongClientIds[$songClientId] = true;

				$checkExisting->execute([$entityClientId, $songClientId]);
				if ($checkExisting->fetch() !== false) {
					continue;
				}

				$stmt->execute([$entityClientId, $songClientId]);
			}
		}
	}

	private function exportFiles(PDO $db, InternalLibrary $library, ?IUser $user): void {
		if (!MobileSheetsTableHelper::tableExists($db, 'Files')) {
			return;
		}

		$hasSyncMeta = MobileSheetsTableHelper::tableExists($db, '_nc_sync_metadata');

		$files = $this->songFileMapper->findAllForLibrary($library->id, includeDeleted: false);

		$songs = $this->songMapper->findAllForLibrary($library->id, includeDeleted: false);
		$songClientMap = [];
		foreach ($songs as $s) {
			if ($s->clientId !== null) {
				$songClientMap[$s->id] = $s->clientId;
			}
		}

		$usedIds = [];
		$maxId = 0;
		foreach ($files as $f) {
			if ($f->clientId !== null && $f->clientId > 0) {
				$usedIds[$f->clientId] = true;
				if ($f->clientId > $maxId) {
					$maxId = $f->clientId;
				}
			}
		}

		$nextId = $maxId + 1;

		$existingCols = MobileSheetsTableHelper::getTableColumns($db, 'Files');

		$metaStmt = $hasSyncMeta ? $db->prepare('INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES (:type, :uuid, :client_id, :updated_at, :extra_json)') : null;

		foreach ($files as $file) {
			$songClientId = $songClientMap[$file->songId] ?? null;
			if ($songClientId === null) {
				continue;
			}

			$clientId = $file->clientId;
			if ($clientId === null || $clientId <= 0) {
				while (isset($usedIds[$nextId])) {
					$nextId++;
				}
				$clientId = $nextId++;
				$usedIds[$clientId] = true;

				$file->clientId = $clientId;
				$this->songFileMapper->update($file);
			}

			$webdavPath = $this->resolveWebdavPathForFile($file, $library, $user);
			if ($webdavPath === null || $webdavPath === '') {
				continue;
			}

			$hash = $file->fileHash ?? 0;
			$size = $file->fileSize ?? 0;
			$pageCount = $file->pageCount ?? 1;

			$candidateValues = [
				'id' => $clientId,
				'songid' => $songClientId,
				'path' => $webdavPath,
				'pageorder' => $file->pageOrder,
				'filesize' => $size,
				'lastmodified' => MobileSheetsTimestampUtil::secondsToMs($file->updatedAt),
				'source' => 1,
				'type' => $file->type,
				'password' => '',
				'sourcefilepagecount' => $pageCount,
				'filehash' => $hash,
				'width' => -1,
				'height' => -1,
			];

			$insertRow = [];
			foreach ($candidateValues as $lowerKey => $val) {
				if (isset($existingCols[$lowerKey])) {
					$insertRow[$existingCols[$lowerKey]] = $val;
				}
			}

			MobileSheetsTableHelper::insertRow($db, 'Files', $insertRow, orReplace: true);

			if ($metaStmt !== null) {
				$metaStmt->execute([
					':type' => 'file',
					':uuid' => $file->uuid,
					':client_id' => $clientId,
					':updated_at' => $file->updatedAt,
					':extra_json' => json_encode(['file_id' => $file->fileId], JSON_THROW_ON_ERROR),
				]);
			}
		}

		if (!empty($files) && MobileSheetsTableHelper::tableExists($db, 'Files')) {
			$validClientIds = [];
			foreach ($files as $f) {
				if ($f->clientId !== null && $f->clientId > 0) {
					$validClientIds[] = (int)$f->clientId;
				}
			}
			if (!empty($validClientIds)) {
				$inList = implode(',', $validClientIds);
				$db->exec("DELETE FROM Files WHERE Id NOT IN ($inList)");
			}
		}

		if (MobileSheetsTableHelper::tableExists($db, 'Files') && MobileSheetsTableHelper::tableExists($db, 'Songs')) {
			$db->exec('DELETE FROM Files WHERE SongId IS NULL OR SongId NOT IN (SELECT Id FROM Songs)');
			$db->exec('DELETE FROM Songs WHERE Id NOT IN (SELECT DISTINCT SongId FROM Files WHERE SongId IS NOT NULL)');
			$this->cleanOrphanSongReferences($db);
		}
	}

	private function cleanOrphanSongReferences(PDO $db): void {
		if (MobileSheetsTableHelper::tableExists($db, 'SongNotes')) {
			$db->exec('DELETE FROM SongNotes WHERE SongId NOT IN (SELECT Id FROM Songs)');
		}
		if (MobileSheetsTableHelper::tableExists($db, '_nc_sync_metadata')) {
			$db->exec("DELETE FROM _nc_sync_metadata WHERE entity_type = 'song' AND client_id NOT IN (SELECT Id FROM Songs)");
		}
		foreach (MobileSheetsEntityConfig::BY_TABLE as $config) {
			$linkTable = $config['linkTable'];
			if (MobileSheetsTableHelper::tableExists($db, $linkTable)) {
				$db->exec(sprintf('DELETE FROM "%s" WHERE SongId NOT IN (SELECT Id FROM Songs)', str_replace('"', '""', $linkTable)));
			}
		}
		if (MobileSheetsTableHelper::tableExists($db, 'SetlistSongs')) {
			$db->exec('DELETE FROM SetlistSongs WHERE SongId NOT IN (SELECT Id FROM Songs)');
		}
	}

	private function exportMiscData(PDO $db, int $libraryId, ?IUser $user = null): void {
		$miscSchemas = $this->miscMapper->getMiscSchemas($libraryId, 'mobilesheets');
		if ($miscSchemas !== []) {
			$songs = $this->songMapper->findAllForLibrary($libraryId, includeDeleted: true);
			$deletedSongClientIds = [];
			foreach ($songs as $s) {
				if ($s->clientId !== null && $s->deletedAt !== null) {
					$deletedSongClientIds[$s->clientId] = true;
				}
			}

			foreach (array_keys($miscSchemas) as $tableName) {
				if (!MobileSheetsTableHelper::tableExists($db, $tableName)) {
					continue;
				}

				$lowerTable = strtolower($tableName);
				$existingColumns = MobileSheetsTableHelper::getTableColumns($db, $tableName);

				$rows = $this->miscMapper->getMiscDataForTable($libraryId, 'mobilesheets', $tableName, includeDeleted: false);
				foreach ($rows as $row) {
					if (!is_array($row) || $row === []) {
						continue;
					}

					// Never export tombstoned / deleted records or references via misc data
					if ($lowerTable === 'songs') {
						if (isset($row['Id']) && isset($deletedSongClientIds[(int)$row['Id']])) {
							continue;
						}
					} elseif ($lowerTable === 'files') {
						if (!isset($row['SongId']) || !is_numeric($row['SongId']) || isset($deletedSongClientIds[(int)$row['SongId']])) {
							continue;
						}
					} elseif (isset($row['SongId']) && is_numeric($row['SongId'])) {
						if (isset($deletedSongClientIds[(int)$row['SongId']])) {
							continue;
						}
					} elseif ($lowerTable === 'recent' && isset($row['Song']) && is_numeric($row['Song'])) {
						if (isset($deletedSongClientIds[(int)$row['Song']])) {
							continue;
						}
					}

					if ($existingColumns !== []) {
						$filteredRow = [];
						foreach ($row as $colKey => $val) {
							$lowerKey = strtolower((string)$colKey);
							if (isset($existingColumns[$lowerKey])) {
								$filteredRow[$existingColumns[$lowerKey]] = $val;
							}
						}
						$row = $filteredRow;
					}

					if ($row === []) {
						continue;
					}

					try {
						MobileSheetsTableHelper::insertRow($db, $tableName, $row, orReplace: true);
					} catch (\Throwable $e) {
						$this->logger->warning('Failed to insert misc row into table: ' . $tableName, ['exception' => $e]);
					}
				}
			}
		}

		if (MobileSheetsTableHelper::tableExists($db, 'android_metadata')) {
			$cnt = (int)$db->query('SELECT count(*) FROM android_metadata')->fetchColumn();
			if ($cnt === 0) {
				$db->exec("INSERT INTO android_metadata (locale) VALUES ('en_US')");
			}
		}

		if (MobileSheetsTableHelper::tableExists($db, 'Defaults')) {
			$cnt = (int)$db->query('SELECT count(*) FROM Defaults')->fetchColumn();
			if ($cnt === 0) {
				$db->exec('INSERT INTO Defaults (Id, Initialized) VALUES (1, 1)');
			}
		}
	}

	private function resolveWebdavPathForFile(InternalSongFile $file, InternalLibrary $library, ?IUser $user): ?string {
		if ($user !== null) {
			try {
				$userFolder = $this->rootFolder->getUserFolder($user->getUID());
				$nodes = $userFolder->getById($file->fileId);
				if ($nodes !== []) {
					$nodePath = $nodes[0]->getPath();
					$msPath = $this->pathResolver->fromInternal($nodePath, $user);
					if ($msPath !== null) {
						return $msPath->webdav;
					}
				}
			} catch (\Throwable) {
				// Fall through
			}

			try {
				$rootNodes = $this->rootFolder->getById($file->fileId);
				if ($rootNodes !== []) {
					$nodePath = $rootNodes[0]->getPath();
					$msPath = $this->pathResolver->fromInternal($nodePath, $user);
					if ($msPath !== null) {
						return $msPath->webdav;
					}
				}
			} catch (\Throwable) {
				// Fall through
			}

			return null;
		}

		// Fallback for tests/synthetic libraries where $user is null
		$base = rtrim($library->path, '/');
		return sprintf('%s/%s/%s/file_%d.pdf', MobileSheetsPathResolver::WEBDAV_ROOT, $library->userId, ltrim($base, '/'), $file->fileId);
	}
}
