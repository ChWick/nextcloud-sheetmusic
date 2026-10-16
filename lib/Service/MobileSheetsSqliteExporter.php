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
use OCA\SheetMusic\Util\SongSortingUtil;
use OCP\Files\File;
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
	public const ENTITY_TABLE_CONFIG = [
		'composer' => [
			'table' => 'Composer',
			'nameCol' => 'Name',
			'linkTable' => 'ComposerSongs',
			'linkCol' => 'ComposerId',
			'hasSort' => true,
		],
		'artist' => [
			'table' => 'Artists',
			'nameCol' => 'Name',
			'linkTable' => 'ArtistsSongs',
			'linkCol' => 'ArtistId',
			'hasSort' => false,
		],
		'book' => [
			'table' => 'Books',
			'nameCol' => 'Title',
			'linkTable' => 'BookSongs',
			'linkCol' => 'BookId',
			'hasSort' => true,
		],
		'collection' => [
			'table' => 'Collections',
			'nameCol' => 'Name',
			'linkTable' => 'CollectionSong',
			'linkCol' => 'CollectionId',
			'hasSort' => true,
		],
		'genre' => [
			'table' => 'Genres',
			'nameCol' => 'Type',
			'linkTable' => 'GenresSongs',
			'linkCol' => 'GenreId',
			'hasSort' => false,
		],
		'key' => [
			'table' => 'Key',
			'nameCol' => 'Name',
			'linkTable' => 'KeySongs',
			'linkCol' => 'KeyId',
			'hasSort' => false,
		],
		'customgroup' => [
			'table' => 'CustomGroup',
			'nameCol' => 'Name',
			'linkTable' => 'CustomGroupSongs',
			'linkCol' => 'GroupId',
			'hasSort' => false,
		],
		'year' => [
			'table' => 'Years',
			'nameCol' => 'Year',
			'linkTable' => 'YearsSongs',
			'linkCol' => 'YearId',
			'hasSort' => false,
		],
		'signature' => [
			'table' => 'Signature',
			'nameCol' => 'Name',
			'linkTable' => 'SignatureSongs',
			'linkCol' => 'SignatureId',
			'hasSort' => false,
		],
		'sourcetype' => [
			'table' => 'SourceType',
			'nameCol' => 'Type',
			'linkTable' => 'SourceTypeSongs',
			'linkCol' => 'SourceTypeId',
			'hasSort' => false,
		],
		'instrument' => [
			'table' => 'CustomGroup',
			'nameCol' => 'Name',
			'linkTable' => 'CustomGroupSongs',
			'linkCol' => 'GroupId',
			'hasSort' => false,
		],
	];

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

				$this->exportMiscData($db, $lib->id);
				$this->exportEntities($db, $lib->id);
				$this->exportSongs($db, $lib->id);
				$this->exportSongEntityLinks($db, $lib->id);
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
		foreach ($files as $file) {
			$webdavPath = $this->resolveWebdavPathForFile($file, $lib, $user);
			if ($webdavPath === '') {
				continue;
			}

			$hash = $file->fileHash ?? 0;
			$modifiedAtMs = $file->updatedAt * 1000;
			$size = $file->fileSize ?? 0;

			$lines[] = $webdavPath;
			$lines[] = (string)$hash;
			$lines[] = (string)$modifiedAtMs;
			$lines[] = (string)$size;
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

		$hasSyncMeta = false;
		$checkMeta = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='_nc_sync_metadata'");
		if ($checkMeta !== false && $checkMeta->fetch() !== false) {
			$hasSyncMeta = true;
		}

		$metaStmt = $hasSyncMeta ? $db->prepare('INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES (:type, :uuid, :client_id, :updated_at, :extra_json)') : null;

		foreach ($entities as $entity) {
			$config = self::ENTITY_TABLE_CONFIG[$entity->type] ?? self::ENTITY_TABLE_CONFIG['customgroup'];

			$tableName = $config['table'];
			$check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . str_replace("'", "''", $tableName) . "'");
			if ($check === false || $check->fetch() === false) {
				if ($tableName === 'Key') {
					$alt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Keys'");
					if ($alt !== false && $alt->fetch() !== false) {
						$tableName = 'Keys';
					}
				} elseif ($tableName === 'Keys') {
					$alt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Key'");
					if ($alt !== false && $alt->fetch() !== false) {
						$tableName = 'Key';
					}
				} elseif ($tableName === 'SourceType') {
					$alt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='SourceTypes'");
					if ($alt !== false && $alt->fetch() !== false) {
						$tableName = 'SourceTypes';
					}
				} elseif ($tableName === 'SourceTypes') {
					$alt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='SourceType'");
					if ($alt !== false && $alt->fetch() !== false) {
						$tableName = 'SourceType';
					}
				}
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
				$colStmt = $db->query(sprintf('PRAGMA table_info("%s")', str_replace('"', '""', $tableName)));
				$existingCols = [];
				if ($colStmt !== false) {
					while ($colInfo = $colStmt->fetch()) {
						if (isset($colInfo['name'])) {
							$existingCols[strtolower((string)$colInfo['name'])] = (string)$colInfo['name'];
						}
					}
				}

				$nameCol = $config['nameCol'];
				if (!isset($existingCols[strtolower($nameCol)])) {
					if (isset($existingCols['name'])) {
						$nameCol = $existingCols['name'];
					} elseif (isset($existingCols['title'])) {
						$nameCol = $existingCols['title'];
					} elseif (isset($existingCols['type'])) {
						$nameCol = $existingCols['type'];
					} elseif (isset($existingCols['year'])) {
						$nameCol = $existingCols['year'];
					}
				}

				$val = (strtolower($nameCol) === 'year') ? (int)$entity->name : $entity->name;
				$hasSortCols = isset($existingCols['sortby']) && isset($existingCols['ascending']);
				$dateCreatedCol = isset($existingCols['datecreated']) ? $existingCols['datecreated'] : (isset($existingCols['creationdate']) ? $existingCols['creationdate'] : null);
				$lastModCol = isset($existingCols['lastmodified']) ? $existingCols['lastmodified'] : null;

				$cols = ['Id', $nameCol];
				$vals = [$clientId, $val];

				if ($hasSortCols) {
					$cols[] = $existingCols['sortby'];
					$vals[] = $entity->sortBy;
					$cols[] = $existingCols['ascending'];
					$vals[] = $entity->ascending;
				}
				if ($dateCreatedCol !== null) {
					$cols[] = $dateCreatedCol;
					$vals[] = $entity->createdAt * 1000;
				}
				if ($lastModCol !== null) {
					$cols[] = $lastModCol;
					$vals[] = $entity->updatedAt * 1000;
				}

				$escaped = array_map(fn ($c) => '"' . str_replace('"', '""', $c) . '"', $cols);
				$placeholders = array_fill(0, count($cols), '?');
				$sql = sprintf('INSERT INTO "%s" (%s) VALUES (%s)', str_replace('"', '""', $tableName), implode(', ', $escaped), implode(', ', $placeholders));
				$stmt = $db->prepare($sql);
				$stmt->execute($vals);
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

	private function exportSongs(PDO $db, int $libraryId): void {
		$checkSongs = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Songs'");
		if ($checkSongs === false || $checkSongs->fetch() === false) {
			return;
		}

		$hasNotesTable = false;
		$checkNotes = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='SongNotes'");
		if ($checkNotes !== false && $checkNotes->fetch() !== false) {
			$hasNotesTable = true;
		}

		$hasSyncMeta = false;
		$checkMeta = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='_nc_sync_metadata'");
		if ($checkMeta !== false && $checkMeta->fetch() !== false) {
			$hasSyncMeta = true;
		}

		$songs = $this->songMapper->findByLibrary($libraryId, includeDeleted: false);
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

		$colStmt = $db->query('PRAGMA table_info("Songs")');
		$existingCols = [];
		if ($colStmt !== false) {
			while ($c = $colStmt->fetch()) {
				$existingCols[strtolower((string)$c['name'])] = (string)$c['name'];
			}
		}

		foreach ($songs as $song) {
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
					'datecreated' => $song->createdAt * 1000,
					'creationdate' => $song->createdAt * 1000,
					'lastmodified' => $song->updatedAt * 1000,
					'opus' => $song->opus ?? '',
					'catalognumber' => $song->catalogNumber ?? '',
					'songnumber' => $song->songNumber ?? '',
				];

				$insertCols = [];
				$insertVals = [];
				foreach ($candidateValues as $lowerKey => $val) {
					if (isset($existingCols[$lowerKey])) {
						$insertCols[] = '"' . $existingCols[$lowerKey] . '"';
						$insertVals[] = $val;
					}
				}

				if ($insertCols !== []) {
					$placeholders = array_fill(0, count($insertCols), '?');
					$sql = sprintf(
						'INSERT INTO Songs (%s) VALUES (%s)',
						implode(', ', $insertCols),
						implode(', ', $placeholders),
					);
					$stmt = $db->prepare($sql);
					$stmt->execute($insertVals);
				}
			}

			if ($hasNotesTable && $song->notes !== '') {
				$noteExists = false;
				$checkNote = $db->query(sprintf('SELECT 1 FROM SongNotes WHERE SongId = %d', (int)$clientId));
				if ($checkNote !== false && $checkNote->fetch() !== false) {
					$noteExists = true;
				}

				if (!$noteExists) {
					$noteColStmt = $db->query('PRAGMA table_info("SongNotes")');
					$noteCols = [];
					if ($noteColStmt !== false) {
						while ($nc = $noteColStmt->fetch()) {
							$noteCols[strtolower((string)$nc['name'])] = (string)$nc['name'];
						}
					}

					$noteVals = [
						'songid' => $clientId,
						'notes' => $song->notes,
						'note' => $song->notes,
						'title' => '',
						'datecreated' => $song->createdAt * 1000,
						'creationdate' => $song->createdAt * 1000,
						'lastmodified' => $song->updatedAt * 1000,
					];
					$insertCols = [];
					$insertVals = [];
					foreach ($noteVals as $lowerKey => $val) {
						if (isset($noteCols[$lowerKey])) {
							$insertCols[] = '"' . $noteCols[$lowerKey] . '"';
							$insertVals[] = $val;
						}
					}
					if ($insertCols !== []) {
						$sql = sprintf(
							'INSERT INTO SongNotes (%s) VALUES (%s)',
							implode(', ', $insertCols),
							implode(', ', array_fill(0, count($insertCols), '?')),
						);
						$noteStmt = $db->prepare($sql);
						$noteStmt->execute($insertVals);
					}
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

	private function exportSongEntityLinks(PDO $db, int $libraryId): void {
		$links = $this->entityMapper->findAllSongEntityLinksForLibrary($libraryId);

		$songs = $this->songMapper->findByLibrary($libraryId, includeDeleted: false);
		$songClientMap = [];
		$songById = [];
		foreach ($songs as $s) {
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
			$entityId = $link['entity_id'];
			$linksByEntityId[$entityId][] = $link;
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

			$checkLinkTable = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . str_replace("'", "''", $linkTable) . "'");
			if ($checkLinkTable === false || $checkLinkTable->fetch() === false) {
				continue;
			}

			// Sort entity links according to entity's sortBy and ascending mode
			usort($entityLinks, function ($a, $b) use ($songById, $entity) {
				return SongSortingUtil::compare(
					$songById[$a['song_id']] ?? null,
					$songById[$b['song_id']] ?? null,
					$entity->sortBy,
					$entity->ascending,
					$a['link_id'] ?? null,
					$b['link_id'] ?? null,
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
				$songId = $link['song_id'];
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
		$checkFiles = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Files'");
		if ($checkFiles === false || $checkFiles->fetch() === false) {
			return;
		}

		$hasSyncMeta = false;
		$checkMeta = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='_nc_sync_metadata'");
		if ($checkMeta !== false && $checkMeta->fetch() !== false) {
			$hasSyncMeta = true;
		}

		$files = $this->songFileMapper->findAllForLibrary($library->id, includeDeleted: false);

		$songs = $this->songMapper->findByLibrary($library->id, includeDeleted: false);
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

		$colStmt = $db->query('PRAGMA table_info("Files")');
		$existingCols = [];
		if ($colStmt !== false) {
			while ($c = $colStmt->fetch()) {
				$existingCols[strtolower((string)$c['name'])] = (string)$c['name'];
			}
		}

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

			$checkRow = $db->query(sprintf('SELECT 1 FROM Files WHERE Id = %d', (int)$clientId));
			if ($checkRow === false || $checkRow->fetch() === false) {
				$webdavPath = $this->resolveWebdavPathForFile($file, $library, $user);

				$hash = $file->fileHash ?? 0;
				$size = $file->fileSize ?? 0;
				$pageCount = $file->pageCount ?? 1;

				$candidateValues = [
					'id' => $clientId,
					'songid' => $songClientId,
					'path' => $webdavPath,
					'pageorder' => $file->pageOrder,
					'filesize' => $size,
					'lastmodified' => $file->updatedAt * 1000,
					'source' => 1,
					'type' => $file->type,
					'password' => '',
					'sourcefilepagecount' => $pageCount,
					'filehash' => $hash,
					'width' => -1,
					'height' => -1,
				];

				$insertCols = [];
				$insertVals = [];
				foreach ($candidateValues as $lowerKey => $val) {
					if (isset($existingCols[$lowerKey])) {
						$insertCols[] = '"' . $existingCols[$lowerKey] . '"';
						$insertVals[] = $val;
					}
				}

				if ($insertCols !== []) {
					$placeholders = array_fill(0, count($insertCols), '?');
					$sql = sprintf('INSERT INTO Files (%s) VALUES (%s)', implode(', ', $insertCols), implode(', ', $placeholders));
					$stmt = $db->prepare($sql);
					$stmt->execute($insertVals);
				}
			}

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
	}

	private function exportMiscData(PDO $db, int $libraryId): void {
		$miscSchemas = $this->miscMapper->getMiscSchemas($libraryId, 'mobilesheets');
		if ($miscSchemas !== []) {
			$songs = $this->songMapper->findByLibrary($libraryId, includeDeleted: true);
			$songClientByUuid = [];
			foreach ($songs as $s) {
				if ($s->clientId !== null) {
					$songClientByUuid[$s->uuid] = $s->clientId;
				}
			}

			foreach (array_keys($miscSchemas) as $tableName) {
				$check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . str_replace("'", "''", $tableName) . "'");
				if ($check === false || $check->fetch() === false) {
					continue;
				}

				// Query table column info to ensure we only insert existing columns
				$colStmt = $db->query(sprintf('PRAGMA table_info("%s")', str_replace('"', '""', $tableName)));
				$existingColumns = [];
				if ($colStmt !== false) {
					while ($colInfo = $colStmt->fetch()) {
						if (isset($colInfo['name'])) {
							$existingColumns[strtolower((string)$colInfo['name'])] = (string)$colInfo['name'];
						}
					}
				}

				$rows = $this->miscMapper->getMiscDataForTable($libraryId, 'mobilesheets', $tableName, includeDeleted: false);
				foreach ($rows as $row) {
					if (!is_array($row) || $row === []) {
						continue;
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

					$cols = array_keys($row);
					$escapedCols = array_map(fn ($c) => '"' . str_replace('"', '""', (string)$c) . '"', $cols);
					$placeholders = array_fill(0, count($cols), '?');

					$sql = sprintf(
						'INSERT OR REPLACE INTO "%s" (%s) VALUES (%s)',
						$tableName,
						implode(', ', $escapedCols),
						implode(', ', $placeholders),
					);

					try {
						$stmt = $db->prepare($sql);
						$stmt->execute(array_values($row));
					} catch (\Throwable $e) {
						$this->logger->warning('Failed to insert misc row into table: ' . $tableName, ['exception' => $e]);
					}
				}
			}
		}

		$checkMeta = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='android_metadata'");
		if ($checkMeta !== false && $checkMeta->fetch() !== false) {
			$cnt = (int)$db->query('SELECT count(*) FROM android_metadata')->fetchColumn();
			if ($cnt === 0) {
				$db->exec("INSERT INTO android_metadata (locale) VALUES ('en_US')");
			}
		}

		$checkDef = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Defaults'");
		if ($checkDef !== false && $checkDef->fetch() !== false) {
			$cnt = (int)$db->query('SELECT count(*) FROM Defaults')->fetchColumn();
			if ($cnt === 0) {
				$db->exec('INSERT INTO Defaults (Id, Initialized) VALUES (1, 1)');
			}
		}
	}

	private function resolveWebdavPathForFile(InternalSongFile $file, InternalLibrary $library, ?IUser $user): string {
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
		}

		// Fallback
		$base = rtrim($library->path, '/');
		return sprintf('/remote.php/dav/files/%s/%s/file_%d.pdf', $library->userId, ltrim($base, '/'), $file->fileId);
	}
}
