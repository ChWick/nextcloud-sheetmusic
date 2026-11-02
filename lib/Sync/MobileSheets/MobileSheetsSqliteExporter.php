<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalMiscMapper;
use OCA\SheetMusic\Db\InternalSetlistItemMapper;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongAttributeMapper;
use OCA\SheetMusic\Db\InternalSongBookmark;
use OCA\SheetMusic\Db\InternalSongBookmarkMapper;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
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
	public const ENTITY_TABLE_CONFIG = MobileSheetsEntityConfig::BY_TYPE;

	private MobileSheetsEntityExporter $entityExporter;

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
		?MobileSheetsEntityExporter $entityExporter = null,
		private ?InternalSongBookmarkMapper $bookmarkMapper = null,
		private ?InternalSetlistItemMapper $setlistItemMapper = null,
	) {
		$this->entityExporter = $entityExporter ?? new MobileSheetsEntityExporter(
			$this->entityMapper,
			$this->songMapper,
			$this->setlistItemMapper,
		);
	}

	/**
	 * Export the entire library state to a MobileSheets-compatible SQLite database file.
	 */
	public function exportToFile(InternalLibrary|array|int $library, string $targetSqlitePath): void {
		$origPrecision = ini_get('precision');
		ini_set('precision', '-1');

		try {
			$lib = $library instanceof InternalLibrary ? $library : $this->libraryMapper->resolveLibrary($library);
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
				$this->entityExporter->exportEntities($db, $lib->id);
				$this->exportSongs($db, $lib->id, $validSongIds);
				$this->entityExporter->exportSongEntityLinks($db, $lib->id, $validSongIds);
				$this->exportFiles($db, $lib, $user);
				$this->exportBookmarks($db, $lib, $user);
				$this->ensureSongAndPageDefaults($db);
				$this->cleanOrphanSongReferences($db);

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
	 * Check if the library has any active songs or imported schemas/data.
	 */
	public function hasLibraryData(InternalLibrary|array|int $library): bool {
		$lib = $library instanceof InternalLibrary ? $library : $this->libraryMapper->resolveLibrary($library);
		if ($lib->id === null) {
			return false;
		}

		if ($this->songMapper->findAllForLibrary($lib->id, includeDeleted: false) !== []) {
			return true;
		}

		if ($this->miscMapper->getMiscSchemas($lib->id, 'mobilesheets') !== []) {
			return true;
		}

		return false;
	}

	/**
	 * Generate the dynamic content for mobilesheets_hashcodes.txt.
	 */
	public function generateHashcodes(InternalLibrary|array|int $library): string {
		$lib = $library instanceof InternalLibrary ? $library : $this->libraryMapper->resolveLibrary($library);
		if ($lib->id === null) {
			return '';
		}

		$user = $this->userManager->get($lib->userId);
		$files = $this->songFileMapper->findAllForLibrary($lib->id, includeDeleted: false);

		$lines = [];
		$handledPaths = [];

		foreach ($files as $file) {
			$this->ensureAccurateFileFacts($file, $user);
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

		return implode("\n", $lines);
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
			MobileSheetsTableHelper::ensureSyncMetadataTable($db);
			return;
		}

		$statements = MobileSheetsBaselineSchema::TABLES;

		foreach ($statements as $tableName => $sql) {
			$lowerName = strtolower($tableName);
			if (isset($miscSchemaKeysLower[$lowerName])) {
				$sql = $miscSchemaKeysLower[$lowerName];
			}
			$db->exec($sql);
		}

		MobileSheetsTableHelper::ensureSyncMetadataTable($db);

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
		$libraryBookmarks = $this->bookmarkMapper !== null
			? $this->bookmarkMapper->findAllForLibrary($libraryId)
			: [];
		[$usedIds, $nextId] = MobileSheetsTableHelper::initClientIdTracking($songs);

		$metaStmt = $hasSyncMeta ? $db->prepare('INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES (:type, :uuid, :client_id, :updated_at, :extra_json)') : null;

		$existingCols = MobileSheetsTableHelper::getTableColumns($db, 'Songs');

		foreach ($songs as $song) {
			$hasValidFile = isset($validSongIds[$song->id])
				|| ($song->clientId !== null && isset($validSongIds[$song->clientId]));
			if (!$hasValidFile) {
				continue;
			}

			$clientId = MobileSheetsTableHelper::nextClientId($usedIds, $nextId, $song->clientId);
			if ($song->clientId !== $clientId) {
				$song->clientId = $clientId;
				$this->songMapper->update($song);
			}

			$existingSongRow = MobileSheetsTableHelper::findRowById($db, 'Songs', $clientId);

			$attrs = $attributesBySong[$song->id] ?? [];
			$custom = $attrs['custom'] ?? '';
			$custom2 = $attrs['custom2'] ?? '';

			$effectiveUpdatedAt = $song->updatedAt;
			if (!empty($libraryBookmarks[$song->id])) {
				foreach ($libraryBookmarks[$song->id] as $bm) {
					if ($bm->updatedAt > $effectiveUpdatedAt) {
						$effectiveUpdatedAt = $bm->updatedAt;
					}
				}
			}

			if ($existingSongRow === null) {
				$candidateValues = [
					'id' => $clientId,
					'title' => $song->title,
					'sorttitle' => $song->sortTitle ?? '',
					'stars' => $song->rating,
					'difficulty' => $song->difficulty,
					'duration' => $song->duration,
					'keywords' => $song->keywords ?? '',
					'custom' => $custom,
					'custom2' => $custom2,
					'lastpage' => 0,
					'orientationlock' => 0,
					'autostartaudio' => 0,
					'verticalzoom' => 1.0,
					'sharpen' => 0,
					'sharpenlevel' => 4,
					'songid' => 0,
					'datecreated' => MobileSheetsTimestampUtil::secondsToMs($song->createdAt),
					'creationdate' => MobileSheetsTimestampUtil::secondsToMs($song->createdAt),
					'lastmodified' => MobileSheetsTimestampUtil::secondsToMs($effectiveUpdatedAt),
					'opus' => $song->opus ?? '',
					'catalognumber' => $song->catalogNumber ?? '',
					'songnumber' => $song->songNumber ?? '',
				];

				$insertRow = MobileSheetsTableHelper::filterToExistingColumns($existingCols, $candidateValues);
				MobileSheetsTableHelper::insertRow($db, 'Songs', $insertRow);
			} else {
				$existingModSec = MobileSheetsTimestampUtil::extractLastModifiedSec($existingSongRow);
				if (($existingModSec > 0 && $effectiveUpdatedAt > $existingModSec) || trim((string)($existingSongRow['Title'] ?? '')) !== $song->title) {
					$updateCandidates = [
						'title' => $song->title,
						'sorttitle' => $song->sortTitle ?? '',
						'stars' => $song->rating,
						'difficulty' => $song->difficulty,
						'duration' => $song->duration,
						'keywords' => $song->keywords ?? '',
						'lastmodified' => MobileSheetsTimestampUtil::secondsToMs($effectiveUpdatedAt),
					];
					if (isset($attrs['custom'])) {
						$updateCandidates['custom'] = $custom;
					}
					if (isset($attrs['custom2'])) {
						$updateCandidates['custom2'] = $custom2;
					}
					if ($song->opus !== null) {
						$updateCandidates['opus'] = $song->opus;
					}
					if ($song->catalogNumber !== null) {
						$updateCandidates['catalognumber'] = $song->catalogNumber;
					}
					if ($song->songNumber !== null) {
						$updateCandidates['songnumber'] = $song->songNumber;
					}

					$updateRow = MobileSheetsTableHelper::filterToExistingColumns($existingCols, $updateCandidates);
					MobileSheetsTableHelper::updateRow($db, 'Songs', $updateRow, 'Id', $clientId);
				}
			}

			if ($hasNotesTable) {
				$noteExists = false;
				$checkNote = $db->query(sprintf('SELECT 1 FROM SongNotes WHERE SongId = %d', (int)$clientId));
				if ($checkNote !== false && $checkNote->fetch() !== false) {
					$noteExists = true;
				}

				$noteText = (string)($song->notes ?? '');
				if ($noteText !== '') {
					$noteCols = MobileSheetsTableHelper::getTableColumns($db, 'SongNotes');
					if (!$noteExists) {
						$noteVals = [
							'songid' => $clientId,
							'notes' => $noteText,
							'note' => $noteText,
							'title' => '',
							'datecreated' => MobileSheetsTimestampUtil::secondsToMs($song->createdAt),
							'creationdate' => MobileSheetsTimestampUtil::secondsToMs($song->createdAt),
							'lastmodified' => MobileSheetsTimestampUtil::secondsToMs($song->updatedAt),
						];
						$noteRow = MobileSheetsTableHelper::filterToExistingColumns($noteCols, $noteVals);
						MobileSheetsTableHelper::insertRow($db, 'SongNotes', $noteRow);
					} else {
						$noteColName = MobileSheetsTableHelper::getSongNotesContentColumn($db);
						if ($noteColName !== null) {
							MobileSheetsTableHelper::updateRow($db, 'SongNotes', [$noteColName => $noteText], 'SongId', $clientId);
						}
					}
				} elseif ($noteExists && $existingSongRow !== null && $song->updatedAt > MobileSheetsTimestampUtil::extractLastModifiedSec($existingSongRow)) {
					$db->exec(sprintf('DELETE FROM SongNotes WHERE SongId = %d', (int)$clientId));
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

	private function exportFiles(PDO $db, InternalLibrary $library, ?IUser $user): void {
		if (!MobileSheetsTableHelper::tableExists($db, 'Files')) {
			return;
		}

		$hasSyncMeta = MobileSheetsTableHelper::tableExists($db, '_nc_sync_metadata');

		$files = $this->songFileMapper->findAllForLibrary($library->id, includeDeleted: false);
		[$usedIds, $nextId] = MobileSheetsTableHelper::initClientIdTracking($files);

		$songs = $this->songMapper->findAllForLibrary($library->id, includeDeleted: false);
		$songClientMap = [];
		foreach ($songs as $s) {
			if ($s->clientId !== null) {
				$songClientMap[$s->id] = $s->clientId;
			}
		}

		$existingCols = MobileSheetsTableHelper::getTableColumns($db, 'Files');

		$metaStmt = $hasSyncMeta ? $db->prepare('INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES (:type, :uuid, :client_id, :updated_at, :extra_json)') : null;

		foreach ($files as $file) {
			$this->ensureAccurateFileFacts($file, $user);
			$songClientId = $songClientMap[$file->songId] ?? null;
			if ($songClientId === null) {
				continue;
			}

			$clientId = MobileSheetsTableHelper::nextClientId($usedIds, $nextId, $file->clientId);
			if ($file->clientId !== $clientId) {
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
			$pageOrder = InternalSongFile::normalizePageOrder($file->pageOrder, $pageCount) ?? MobileSheetsPdf::pageOrder($pageCount);
			// Repair records stuck on a single-page order ('1-1') although the
			// file has more pages (indexed while the upload was still empty).
			if ($pageCount > 1) {
				$pageOrder = InternalSongFile::refreshPageOrder($pageOrder, 1, $pageCount);
			}
			if ($file->pageOrder !== $pageOrder) {
				$file->pageOrder = $pageOrder;
				$this->songFileMapper->update($file);
			}

			$candidateValues = [
				'id' => $clientId,
				'songid' => $songClientId,
				'path' => $webdavPath,
				'pageorder' => $pageOrder,
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

			$insertRow = MobileSheetsTableHelper::filterToExistingColumns($existingCols, $candidateValues);
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
		if (!MobileSheetsTableHelper::tableExists($db, 'Songs')) {
			return;
		}

		$songCount = (int)$db->query('SELECT COUNT(*) FROM Songs')->fetchColumn();
		if ($songCount === 0) {
			return;
		}

		$tablesStmt = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name != 'Songs'");
		$tables = $tablesStmt !== false ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];

		foreach ($tables as $tableName) {
			$cols = MobileSheetsTableHelper::getTableColumns($db, $tableName);
			if (isset($cols['songid'])) {
				$db->exec(sprintf('DELETE FROM "%s" WHERE SongId IS NULL OR SongId NOT IN (SELECT Id FROM Songs)', str_replace('"', '""', $tableName)));
			}
		}

		if (MobileSheetsTableHelper::tableExists($db, 'AnnotationsBase')) {
			foreach ($tables as $tableName) {
				if ($tableName === 'AnnotationsBase') {
					continue;
				}
				$cols = MobileSheetsTableHelper::getTableColumns($db, $tableName);
				if (isset($cols['baseid'])) {
					$db->exec(sprintf('DELETE FROM "%s" WHERE BaseId IS NULL OR BaseId NOT IN (SELECT Id FROM AnnotationsBase)', str_replace('"', '""', $tableName)));
				}
			}
		}

		if (MobileSheetsTableHelper::tableExists($db, '_nc_sync_metadata')) {
			$db->exec("DELETE FROM _nc_sync_metadata WHERE entity_type = 'song' AND client_id NOT IN (SELECT Id FROM Songs)");
			if (MobileSheetsTableHelper::tableExists($db, 'Files')) {
				$db->exec("DELETE FROM _nc_sync_metadata WHERE entity_type = 'file' AND client_id NOT IN (SELECT Id FROM Files)");
			}
			$db->exec("DELETE FROM _nc_sync_metadata WHERE entity_type = 'song_movement' AND client_id NOT IN (SELECT Id FROM Songs)");
			if (MobileSheetsTableHelper::tableExists($db, 'Collections')) {
				$db->exec("DELETE FROM _nc_sync_metadata WHERE entity_type = 'movement_collection' AND client_id NOT IN (SELECT Id FROM Collections)");
			}
		}
	}

	private function exportBookmarks(
		PDO $db,
		InternalLibrary $library,
		?IUser $user,
	): void {
		if ($this->bookmarkMapper === null || !MobileSheetsTableHelper::tableExists($db, 'Bookmarks')) {
			return;
		}

		$libraryBookmarks = $this->bookmarkMapper->findAllForLibrary($library->id);
		if (empty($libraryBookmarks)) {
			return;
		}

		$songs = $this->songMapper->findAllForLibrary($library->id, includeDeleted: false);
		$existingBmCols = MobileSheetsTableHelper::getTableColumns($db, 'Bookmarks');

		foreach ($songs as $song) {
			if ($song->clientId === null || empty($libraryBookmarks[$song->id])) {
				continue;
			}

			$bookmarks = $libraryBookmarks[$song->id];
			$this->exportMasterBookmarks($db, $song->clientId, $bookmarks, $existingBmCols);
		}
	}

	/**
	 * @param InternalSongBookmark[] $bookmarks
	 * @param array<string, bool> $existingBmCols
	 */
	private function exportMasterBookmarks(
		PDO $db,
		int $songClientId,
		array $bookmarks,
		array $existingBmCols,
	): void {
		$del = $db->prepare('DELETE FROM Bookmarks WHERE SongId = :songId');
		$del->execute([':songId' => $songClientId]);

		foreach ($bookmarks as $bm) {
			$candidateValues = [
				'id' => $bm->id,
				'songid' => $songClientId,
				'name' => $bm->name,
				'pagenum' => max(0, $bm->pageNum - 1),
				'showinlibrary' => $bm->showInLibrary,
				'datecreated' => MobileSheetsTimestampUtil::secondsToMs($bm->createdAt),
				'lastmodified' => MobileSheetsTimestampUtil::secondsToMs($bm->updatedAt),
			];
			$bmRow = MobileSheetsTableHelper::filterToExistingColumns($existingBmCols, $candidateValues);
			MobileSheetsTableHelper::insertRow($db, 'Bookmarks', $bmRow);
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
				if ($lowerTable === 'files') {
					continue;
				}

				$existingColumns = MobileSheetsTableHelper::getTableColumns($db, $tableName);

				$rows = $this->miscMapper->getMiscDataForTable($libraryId, 'mobilesheets', $tableName, includeDeleted: false);
				foreach ($rows as $row) {
					if (!is_array($row) || $row === []) {
						continue;
					}

					// Never export tombstoned / deleted records or references via misc data
					$refSongClientId = match (true) {
						$lowerTable === 'songs' => isset($row['Id']) && is_numeric($row['Id']) ? (int)$row['Id'] : null,
						$lowerTable === 'recent' && isset($row['Song']) && is_numeric($row['Song']) => (int)$row['Song'],
						isset($row['SongId']) && is_numeric($row['SongId']) => (int)$row['SongId'],
						default => null,
					};
					if ($refSongClientId !== null && isset($deletedSongClientIds[$refSongClientId])) {
						continue;
					}

					if ($existingColumns !== []) {
						$row = MobileSheetsTableHelper::filterToExistingColumns($existingColumns, $row);
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

		MobileSheetsTableHelper::insertIfEmpty($db, 'android_metadata', ['locale' => 'en_US']);
		MobileSheetsTableHelper::insertIfEmpty($db, 'Defaults', ['Id' => 1, 'Initialized' => 1]);
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
				if (is_array($rootNodes) && !empty($rootNodes) && isset($rootNodes[0])) {
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

	private function ensureAccurateFileFacts(InternalSongFile $file, ?IUser $user): void {
		if ($user === null) {
			return;
		}

		if ($file->fileSize !== null && $file->fileSize > 0 && $file->fileHash !== null) {
			return;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$nodes = $userFolder->getById($file->fileId);
			if (empty($nodes) || !($nodes[0] instanceof File)) {
				return;
			}
			$node = $nodes[0];
			$realSize = $node->getSize();
			if ($file->fileSize === null || $file->fileSize <= 0) {
				$file->fileSize = $realSize;
			}
			if ($file->fileHash === null) {
				$content = $node->getContent();
				if ($content !== false && $content !== '') {
					$file->fileHash = MobileSheetsPdf::contentHash($content);
					$pageCount = MobileSheetsPdf::pageCount($content);
					$file->pageCount = $pageCount;
					if (empty($file->pageOrder)) {
						$file->pageOrder = MobileSheetsPdf::pageOrder($pageCount);
					}
				}
			}
			$this->songFileMapper->update($file);
		} catch (\Throwable) {
			// Best effort fallback
		}
	}

	private function ensureSongAndPageDefaults(PDO $db): void {
		if (!MobileSheetsTableHelper::tableExists($db, 'Songs')) {
			return;
		}

		$songIds = $db->query('SELECT Id FROM Songs')->fetchAll(PDO::FETCH_COLUMN);
		if (empty($songIds)) {
			return;
		}

		$hasAutoScroll = MobileSheetsTableHelper::tableExists($db, 'AutoScroll');
		$hasMetronome = MobileSheetsTableHelper::tableExists($db, 'MetronomeSettings');

		$autoScrollStmt = $hasAutoScroll ? $db->prepare('SELECT 1 FROM AutoScroll WHERE SongId = ? LIMIT 1') : null;
		$metroStmt = $hasMetronome ? $db->prepare('SELECT 1 FROM MetronomeSettings WHERE SongId = ? LIMIT 1') : null;

		$autoScrollCols = $hasAutoScroll ? MobileSheetsTableHelper::getTableColumns($db, 'AutoScroll') : [];
		$metroCols = $hasMetronome ? MobileSheetsTableHelper::getTableColumns($db, 'MetronomeSettings') : [];

		foreach ($songIds as $songClientId) {
			$songClientId = (int)$songClientId;

			// Ensure AutoScroll
			if ($hasAutoScroll && $autoScrollStmt !== null) {
				$autoScrollStmt->execute([$songClientId]);
				if ($autoScrollStmt->fetchColumn() === false) {
					$row = MobileSheetsTableHelper::filterToExistingColumns($autoScrollCols, [
						'SongId' => $songClientId,
						'Behavior' => 0,
						'PauseDuration' => 8000,
						'Speed' => 3,
						'FixedDuration' => 1000,
						'ScrollPercent' => 20,
						'ScrollOnLoad' => 0,
						'TimeBeforeScroll' => 2000,
					]);
					MobileSheetsTableHelper::insertRow($db, 'AutoScroll', $row);
				}
			}

			// Ensure MetronomeSettings
			if ($hasMetronome && $metroStmt !== null) {
				$metroStmt->execute([$songClientId]);
				if ($metroStmt->fetchColumn() === false) {
					$row = MobileSheetsTableHelper::filterToExistingColumns($metroCols, [
						'SongId' => $songClientId,
						'Sig1' => 2,
						'Sig2' => 0,
						'Subdivision' => 0,
						'SoundFX' => 0,
						'AccentFirst' => 0,
						'AutoStart' => 0,
						'CountIn' => 0,
						'NumberCount' => 1,
						'AutoTurn' => 0,
					]);
					MobileSheetsTableHelper::insertRow($db, 'MetronomeSettings', $row);
				}
			}
		}
	}
}
