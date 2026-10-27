<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalMiscMapper;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongAttributeMapper;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use PDO;
use Psr\Log\LoggerInterface;

class MobileSheetsSqliteImporter {
	/** Standard tables natively handled by Sheet Music core. */
	public const CORE_MANAGED_TABLES = MobileSheetsEntityConfig::CORE_MANAGED_TABLES;

	private MobileSheetsEntityMerger $entityMerger;

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
		?MobileSheetsEntityMerger $entityMerger = null,
	) {
		$this->entityMerger = $entityMerger ?? new MobileSheetsEntityMerger(
			$this->entityMapper,
			$this->songMapper,
			$this->miscMapper,
		);
	}

	/**
	 * Generate an RFC 4122 v4 compliant UUID.
	 */
	public static function generateUuid(): string {
		return InternalEntity::generateUuid();
	}

	/**
	 * Import and merge an uploaded MobileSheets SQLite database into the internal database.
	 *
	 * @return array{
	 *     songsCreated: int,
	 *     songsUpdated: int,
	 *     songsDeleted: int,
	 *     entitiesCreated: int,
	 *     entitiesUpdated: int,
	 *     entitiesDeleted: int,
	 *     filesCreated: int,
	 *     filesUpdated: int,
	 *     filesDeleted: int,
	 *     miscTablesSynced: int
	 * }
	 */
	public function importFromFile(
		InternalLibrary|array|int $library,
		string $sqlitePath,
		IUser|string|null $user = null,
		?callable $onProgress = null,
	): array {
		$lib = $library instanceof InternalLibrary ? $library : $this->libraryMapper->resolveLibrary($library);
		if ($lib->id === null) {
			throw new \InvalidArgumentException('Library must have an ID to import');
		}

		if (!is_file($sqlitePath)) {
			throw new \InvalidArgumentException('SQLite database file does not exist: ' . $sqlitePath);
		}

		$db = new PDO('sqlite:' . $sqlitePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		if (is_string($user)) {
			$user = $this->userManager->get($user);
		}
		$user = $user ?? $this->userManager->get($lib->userId);

		$syncMeta = MobileSheetsSyncMetadata::fromDatabase($db);

		$stats = [
			'songsCreated' => 0,
			'songsUpdated' => 0,
			'songsDeleted' => 0,
			'entitiesCreated' => 0,
			'entitiesUpdated' => 0,
			'entitiesDeleted' => 0,
			'filesCreated' => 0,
			'filesUpdated' => 0,
			'filesDeleted' => 0,
			'miscTablesSynced' => 0,
		];

		$updatedSongClientIds = [];

		$isFreshLibrary = $this->songMapper->findAllForLibrary($lib->id, includeDeleted: true) === [];

		// 1. Entities
		$this->entityMerger->mergeEntities($db, $lib->id, $syncMeta, $stats);

		// 2. Songs
		$this->mergeSongs($db, $lib->id, $syncMeta, $stats, $updatedSongClientIds, $onProgress);

		// 3. Song-Entity Links
		$this->entityMerger->mergeSongEntityLinks($db, $lib->id, $updatedSongClientIds, $syncMeta);

		// 4. Files
		$uploadedFileNodeIds = [];
		$uploadedSongFileIds = [];
		$this->mergeFiles($db, $lib, $user, $syncMeta, $stats, $uploadedFileNodeIds, $uploadedSongFileIds, $isFreshLibrary, $onProgress);

		// 5. Deletions (Tombstones) if sync metadata is present
		if ($syncMeta->hasMetadata) {
			$this->processDeletions($db, $lib->id, $user, $syncMeta, $stats, $updatedSongClientIds, $uploadedFileNodeIds, $uploadedSongFileIds);
		}

		// 6. Misc Tables (Setlists, Bookmarks, Annotations, AudioFiles, etc.)
		$this->mergeMiscTables($db, $lib, $user, $stats);

		// 7. Update library modified timestamp
		$this->libraryMapper->touch($lib->id);

		return $stats;
	}

	/**
	 * @param array<string, mixed> $syncMeta
	 * @param array<string, int> $stats
	 * @param array<int, bool> $updatedSongClientIds
	 */
	private function mergeSongs(
		PDO $db,
		int $libraryId,
		MobileSheetsSyncMetadata $syncMeta,
		array &$stats,
		array &$updatedSongClientIds,
		?callable $onProgress = null,
	): void {
		if (!MobileSheetsTableHelper::tableExists($db, 'Songs')) {
			return;
		}

		$totalSongs = 0;
		$countStmt = $db->query('SELECT COUNT(*) FROM Songs');
		if ($countStmt !== false) {
			$totalSongs = (int)$countStmt->fetchColumn();
		}

		$notesBySongId = [];
		$noteCol = MobileSheetsTableHelper::getSongNotesContentColumn($db);
		if ($noteCol !== null) {
			$notesStmt = $db->query('SELECT SongId, "' . $noteCol . '" AS Note FROM SongNotes ORDER BY Id ASC');
			if ($notesStmt !== false) {
				while ($nrow = $notesStmt->fetch()) {
					$notesBySongId[(int)$nrow['SongId']] = (string)($nrow['Note'] ?? '');
				}
			}
		}

		$songsStmt = $db->query('SELECT * FROM Songs');
		if ($songsStmt === false) {
			return;
		}

		$songIndex = 0;
		while ($row = $songsStmt->fetch()) {
			$songIndex++;
			$clientId = (int)$row['Id'];
			$title = trim((string)($row['Title'] ?? ''));
			if ($onProgress !== null) {
				$onProgress('songs', $songIndex, $totalSongs, $title);
			}
			if ($title === '') {
				continue;
			}

			$sortTitle = (string)($row['SortTitle'] ?? '');
			$stars = (int)($row['Stars'] ?? 0);
			$difficulty = (int)($row['Difficulty'] ?? 0);
			$duration = (int)($row['Duration'] ?? 0);
			$keywords = (string)($row['Keywords'] ?? '');
			$custom = (string)($row['Custom'] ?? '');
			$custom2 = (string)($row['Custom2'] ?? '');
			$noteText = $notesBySongId[$clientId] ?? '';

			$lastModifiedSec = MobileSheetsTimestampUtil::extractLastModifiedSec($row);
			$dateCreatedSec = MobileSheetsTimestampUtil::extractDateCreatedSec($row);

			$meta = $syncMeta->getSongMeta($clientId);
			$uuid = $meta['uuid'] ?? null;
			$extra = $meta['extra'] ?? [];

			// Look up existing song
			$existing = null;
			if ($uuid !== null) {
				$existing = $this->songMapper->findByUuid($uuid);
				if ($existing !== null && $existing->libraryId !== $libraryId) {
					$existing = null;
					$uuid = null;
				}
			}
			if ($existing === null) {
				$existing = $this->songMapper->findByClientId($libraryId, $clientId);
			}
			if ($existing === null) {
				$cand = $this->songMapper->findByTitle($libraryId, $title);
				if ($cand !== null && ($cand->clientId === null || $cand->clientId === $clientId)) {
					$existing = $cand;
				}
			}

			$resolveField = static fn (string $col, string $extraKey, ?string $fallback = null): ?string
				=> !empty($row[$col]) ? (string)$row[$col] : ($fallback ?? (isset($extra[$extraKey]) ? (string)$extra[$extraKey] : null));

			$savedSongId = null;
			if ($existing !== null) {
				if (MobileSheetsTableHelper::shouldClientWinLww($existing, $lastModifiedSec, $meta, allowUntrackedClientLww: true)) {
					$existing->title = $title;
					$existing->sortTitle = $sortTitle;
					$existing->rating = $stars;
					$existing->difficulty = $difficulty;
					$existing->duration = $duration;
					$existing->keywords = $keywords;
					if ($noteText !== '') {
						$existing->notes = $noteText;
					}
					$existing->clientId = $clientId;
					$existing->updatedAt = $lastModifiedSec ?: $existing->updatedAt;

					$existing->opus = $resolveField('Opus', 'opus', $existing->opus);
					$existing->catalogNumber = $resolveField('CatalogNumber', 'catalog_number', $existing->catalogNumber);
					$existing->songNumber = $resolveField('SongNumber', 'song_number', $existing->songNumber);

					$this->songMapper->update($existing);
					$stats['songsUpdated']++;
					$updatedSongClientIds[$clientId] = true;
					$savedSongId = $existing->id;
				} elseif (MobileSheetsTableHelper::ensureClientId($existing, $clientId)) {
					$this->songMapper->update($existing);
				}
			} else {
				// New song created in MobileSheets
				$songUuid = ($uuid !== null && $this->songMapper->findByUuid($uuid) === null)
					? $uuid
					: self::generateUuid();
				$newSong = new InternalSong(
					uuid: $songUuid,
					libraryId: $libraryId,
					title: $title,
					sortTitle: $sortTitle,
					rating: $stars,
					difficulty: $difficulty,
					duration: $duration,
					keywords: $keywords,
					notes: $noteText,
					opus: $resolveField('Opus', 'opus'),
					catalogNumber: $resolveField('CatalogNumber', 'catalog_number'),
					songNumber: $resolveField('SongNumber', 'song_number'),
					clientId: $clientId,
					createdAt: $dateCreatedSec ?: time(),
					updatedAt: $lastModifiedSec ?: time(),
				);
				$this->songMapper->insert($newSong);

				$stats['songsCreated']++;
				$updatedSongClientIds[$clientId] = true;
				$savedSongId = $newSong->id;
			}

			if ($savedSongId !== null) {
				if ($custom !== '') {
					$this->attributeMapper->setAttribute($savedSongId, 'custom', $custom);
				}
				if ($custom2 !== '') {
					$this->attributeMapper->setAttribute($savedSongId, 'custom2', $custom2);
				}
			}
		}
	}

	/**
	 * @param array<string, int> $stats
	 * @param array<int, bool> $uploadedFileNodeIds
	 * @param array<int, bool> $uploadedSongFileIds
	 */
	private function mergeFiles(
		PDO $db,
		InternalLibrary $library,
		?IUser $user,
		MobileSheetsSyncMetadata $syncMeta,
		array &$stats,
		array &$uploadedFileNodeIds = [],
		array &$uploadedSongFileIds = [],
		bool $isFreshLibrary = false,
		?callable $onProgress = null,
	): void {
		if (!MobileSheetsTableHelper::tableExists($db, 'Files')) {
			return;
		}

		$totalFiles = 0;
		$countStmt = $db->query('SELECT COUNT(*) FROM Files');
		if ($countStmt !== false) {
			$totalFiles = (int)$countStmt->fetchColumn();
		}

		$existingCols = MobileSheetsTableHelper::getTableColumns($db, 'Files');
		$unknownCols = array_diff(array_keys($existingCols), MobileSheetsBaselineSchema::KNOWN_FILE_COLUMNS);
		if ($unknownCols !== []) {
			$this->logger->warning('MobileSheets database contains unknown column(s) in Files table: ' . implode(', ', $unknownCols), [
				'columns' => array_values($unknownCols),
				'libraryId' => $library->id,
			]);
		}

		$songs = $this->songMapper->findAllForLibrary($library->id, includeDeleted: false);
		$songByClientId = [];
		foreach ($songs as $s) {
			if ($s->clientId !== null) {
				$songByClientId[$s->clientId] = $s;
			}
		}

		$stmt = $db->query('SELECT * FROM Files');
		if ($stmt === false) {
			return;
		}

		$fileIndex = 0;
		while ($row = $stmt->fetch()) {
			$fileIndex++;
			$fileClientId = (int)$row['Id'];
			$songClientId = (int)$row['SongId'];
			$filePath = (string)($row['Path'] ?? '');
			if ($onProgress !== null) {
				$onProgress('files', $fileIndex, $totalFiles, basename($filePath));
			}
			$pageCount = (int)($row['SourceFilePageCount'] ?? 1);
			$pageOrder = InternalSongFile::normalizePageOrder((string)($row['PageOrder'] ?? ''), $pageCount) ?? MobileSheetsPdf::pageOrder($pageCount);
			$fileSize = (int)($row['FileSize'] ?? 0);
			$fileHash = isset($row['FileHash']) ? (int)$row['FileHash'] : null;
			$type = (int)($row['Type'] ?? 0);
			$lastModifiedSec = MobileSheetsTimestampUtil::extractLastModifiedSec($row);

			$song = $songByClientId[$songClientId] ?? null;
			if ($song === null || $song->id === null) {
				continue;
			}

			$meta = $syncMeta->getFileMeta($fileClientId);
			$uuid = $meta['uuid'] ?? null;
			$extra = $meta['extra'] ?? [];

			$metaFileId = isset($extra['file_id']) ? (int)$extra['file_id'] : null;
			$resolvedFileId = $this->resolveFileIdFromPath($filePath, $library, $user, allowPlaceholder: $isFreshLibrary && $syncMeta->getFileMeta($fileClientId) === null);

			if ($resolvedFileId !== null) {
				$fileId = $resolvedFileId;
				if ($metaFileId !== null && $metaFileId !== $resolvedFileId) {
					$uuid = null;
				}
			} else {
				$fileId = $metaFileId;
			}

			if ($fileId === null) {
				$this->logger->warning('Could not resolve file ID for MobileSheets file path: ' . $filePath);
				continue;
			}

			// Look up existing file
			$existing = null;
			if ($uuid !== null) {
				$existing = $this->songFileMapper->findByUuid($uuid);
			}
			if ($existing === null) {
				$filesOfSong = $this->songFileMapper->findAllForSong($song->id, includeDeleted: true);
				foreach ($filesOfSong as $f) {
					if ($f->fileId === $fileId) {
						$existing = $f;
						break;
					}
				}
			}
			if ($existing === null) {
				$byClientId = $this->songFileMapper->findByClientId($song->id, $fileClientId);
				if ($byClientId !== null) {
					if ($byClientId->fileId === $fileId) {
						$existing = $byClientId;
					} elseif ($byClientId->deletedAt !== null) {
						$byClientId->clientId = null;
						$this->songFileMapper->update($byClientId);
					}
				}
			}

			if ($existing !== null) {
				if (MobileSheetsTableHelper::shouldClientWinLww($existing, $lastModifiedSec, $meta, allowUntrackedClientLww: true)) {
					$existing->pageOrder = $pageOrder;
					$existing->pageCount = $pageCount;
					$existing->fileSize = $fileSize;
					$existing->fileHash = $fileHash;
					$existing->type = $type;
					$existing->clientId = $fileClientId;
					$existing->updatedAt = $lastModifiedSec ?: $existing->updatedAt;
					$this->songFileMapper->update($existing);
					$stats['filesUpdated']++;
				} else {
					$changed = false;
					if ($existing->pageOrder === '1' || $existing->pageOrder === '' || $existing->pageOrder === null) {
						$existing->pageOrder = $pageOrder;
						$changed = true;
					}
					if (MobileSheetsTableHelper::ensureClientId($existing, $fileClientId)) {
						$changed = true;
					}
					if ($changed) {
						$this->songFileMapper->update($existing);
					}
				}
			} else {
				$newFile = new InternalSongFile(
					uuid: $uuid ?? self::generateUuid(),
					songId: $song->id,
					fileId: $fileId,
					pageOrder: $pageOrder,
					pageCount: $pageCount,
					fileSize: $fileSize,
					fileHash: $fileHash,
					type: $type,
					clientId: $fileClientId,
					createdAt: $lastModifiedSec ?: time(),
					updatedAt: $lastModifiedSec ?: time(),
				);
				$this->songFileMapper->insert($newFile);
				$stats['filesCreated']++;
			}

			$activeRecordId = $existing !== null ? $existing->id : ($newFile->id ?? null);
			if ($activeRecordId !== null) {
				$uploadedSongFileIds[$activeRecordId] = true;
			}
			$uploadedFileNodeIds[$fileId] = true;

			// Clean up any unsynced auto-indexed stub song/file that was created when the physical file was uploaded
			$otherFiles = $this->songFileMapper->findByFileId($fileId, includeDeleted: false);
			foreach ($otherFiles as $otherFile) {
				if ($otherFile->songId === $song->id || $otherFile->id === null || $otherFile->clientId !== null) {
					continue;
				}
				$otherSong = $this->songMapper->findById($otherFile->songId);
				if ($otherSong !== null && $otherSong->id !== null && $otherSong->clientId === null) {
					$this->songFileMapper->delete($otherFile->id, hardDelete: true);
					$remaining = $this->songFileMapper->findAllForSong($otherSong->id, includeDeleted: false);
					if ($remaining === []) {
						$this->songMapper->delete($otherSong->id, hardDelete: true);
						unset($songByClientId[$otherSong->clientId ?? -1]);
					}
				}
			}
		}
	}

	/**
	 * @param array<string, int> $stats
	 * @param array<int, bool> $updatedSongClientIds
	 * @param array<int, bool> $uploadedFileNodeIds
	 * @param array<int, bool> $uploadedSongFileIds
	 */
	private function processDeletions(
		PDO $db,
		int $libraryId,
		?IUser $user,
		MobileSheetsSyncMetadata $syncMeta,
		array &$stats,
		array $updatedSongClientIds = [],
		array $uploadedFileNodeIds = [],
		array $uploadedSongFileIds = [],
	): void {
		// 1. Deleted Songs
		$songEntries = $syncMeta->getEntriesByType('song');
		if (!empty($songEntries)) {
			$uploadedSongSet = MobileSheetsTableHelper::getTableIdSet($db, 'Songs');

			foreach ($songEntries as $clientId => $meta) {
				if (!isset($uploadedSongSet[$clientId])) {
					// Deleted on client
					$song = $this->songMapper->findByUuid($meta['uuid']);
					if ($song === null) {
						$song = $this->songMapper->findByClientId($libraryId, $clientId);
					}
					if ($song !== null && $song->deletedAt === null) {
						$metaUpdatedAt = (int)($meta['updated_at'] ?? 0);
						// LWW: If Nextcloud has newer edits since the baseline export, Nextcloud wins
						if ($song->updatedAt > $metaUpdatedAt) {
							continue;
						}
						$filesOfSong = $this->songFileMapper->findAllForSong($song->id, includeDeleted: false);
						foreach ($filesOfSong as $songFile) {
							$this->deleteSongFileAndPhysicalNode($songFile, $user);
							$stats['filesDeleted']++;
						}
						$this->songMapper->delete($song->id, hardDelete: false);
						$stats['songsDeleted']++;
					}
					$this->miscMapper->deleteMiscData($libraryId, 'mobilesheets', 'Songs', (string)$clientId);
					$songUuid = $song?->uuid ?? $meta['uuid'] ?? null;
					if ($songUuid !== null) {
						$this->miscMapper->deleteMiscDataForSong($libraryId, 'mobilesheets', $songUuid);
					}
				}
			}
		}

		// 2. Deleted Entities
		$this->entityMerger->processEntityDeletions($db, $libraryId, $syncMeta, $stats);

		// 3. Deleted Files
		$fileEntries = $syncMeta->getEntriesByType('file');
		if (!empty($fileEntries) && MobileSheetsTableHelper::tableExists($db, 'Files')) {
			$uploadedFileSet = MobileSheetsTableHelper::getTableIdSet($db, 'Files');

			foreach ($fileEntries as $clientId => $meta) {
				$file = $this->songFileMapper->findByUuid($meta['uuid']);
				if ($file === null) {
					continue;
				}

				// If this physical file node or song file record is present anywhere in the uploaded files, it was not deleted
				if (isset($uploadedSongFileIds[$file->id]) || isset($uploadedFileNodeIds[$file->fileId])) {
					continue;
				}

				if ($file->deletedAt === null) {
					$metaUpdatedAt = (int)($meta['updated_at'] ?? 0);
					$parentSong = $this->songMapper->findById($file->songId);
					$parentSongMeta = ($parentSong !== null && $parentSong->clientId !== null)
						? $syncMeta->getSongMeta($parentSong->clientId)
						: null;
					$parentSongWonLww = $parentSong !== null
						&& $parentSong->clientId !== null
						&& !isset($updatedSongClientIds[$parentSong->clientId])
						&& $parentSongMeta !== null
						&& $parentSong->updatedAt > (int)($parentSongMeta['updated_at'] ?? 0);
					// LWW: If Nextcloud has newer edits to this file or its parent song since baseline export, Nextcloud wins
					if ($file->updatedAt > $metaUpdatedAt || $parentSongWonLww) {
						continue;
					}
					$this->deleteSongFileAndPhysicalNode($file, $user);
					$stats['filesDeleted']++;
				}
			}
		}
	}

	private function deleteSongFileAndPhysicalNode(InternalSongFile $songFile, ?IUser $user): void {
		$targetFileId = $songFile->fileId;
		$this->songFileMapper->delete($songFile->id, hardDelete: false);

		if ($user === null || $targetFileId <= 0) {
			return;
		}

		$others = $this->songFileMapper->findByFileId($targetFileId, includeDeleted: false);
		$activeOthers = array_filter($others, static fn (InternalSongFile $f): bool => $f->id !== $songFile->id && $f->deletedAt === null);

		if ($activeOthers === []) {
			try {
				$userFolder = $this->rootFolder->getUserFolder($user->getUID());
				$nodes = $userFolder->getById($targetFileId);
				if ($nodes !== []) {
					$nodes[0]->delete();
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Could not delete physical file node during sync deletion', [
					'fileId' => $targetFileId,
					'exception' => $e,
				]);
			}
		}
	}

	/**
	 * @param array<string, int> $stats
	 */
	private function mergeMiscTables(PDO $db, InternalLibrary $lib, ?IUser $user, array &$stats): void {
		$libraryId = $lib->id;
		$stmt = $db->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
		if ($stmt === false) {
			return;
		}

		$songs = $this->songMapper->findAllForLibrary($libraryId, includeDeleted: true);
		$songByClientId = [];
		$songUuidByClientId = [];
		$deletedSongClientIds = [];
		foreach ($songs as $s) {
			if ($s->clientId !== null) {
				$songByClientId[$s->clientId] = $s;
				$songUuidByClientId[$s->clientId] = $s->uuid;
				if ($s->deletedAt !== null) {
					$deletedSongClientIds[$s->clientId] = true;
				}
			}
		}

		$entities = $this->entityMapper->findAllForLibrary($libraryId, includeDeleted: true);
		$deletedEntityClientIdsByTable = [];
		foreach ($entities as $e) {
			if ($e->clientId !== null && $e->deletedAt !== null) {
				$cfg = MobileSheetsEntityConfig::BY_TYPE[$e->type] ?? MobileSheetsEntityConfig::BY_TYPE['customgroup'];
				$deletedEntityClientIdsByTable[strtolower($cfg['table'])][$e->clientId] = true;
			}
		}

		while ($row = $stmt->fetch()) {
			$tableName = (string)$row['name'];
			$lowerTable = strtolower($tableName);
			$sql = (string)$row['sql'];

			if ($lowerTable === '_nc_sync_metadata') {
				continue;
			}

			// Save DDL schema
			$this->miscMapper->saveMiscSchema($libraryId, 'mobilesheets', $tableName, $sql);

			// Files table rows are exclusively managed via sheetmusic_song_files and filesystem
			if ($lowerTable === 'files') {
				continue;
			}

			// Save rows
			$rowsStmt = $db->query('SELECT * FROM "' . str_replace('"', '""', $tableName) . '" ORDER BY rowid ASC');
			if ($rowsStmt === false) {
				continue;
			}

			$hasRows = false;
			$uploadedRecordIds = [];
			while ($dataRow = $rowsStmt->fetch()) {
				$hasRows = true;
				$recordId = self::extractRecordId($dataRow);

				$refSongClientId = match (true) {
					$lowerTable === 'songs' => isset($dataRow['Id']) && is_numeric($dataRow['Id']) ? (int)$dataRow['Id'] : null,
					$lowerTable === 'recent' && isset($dataRow['Song']) && is_numeric($dataRow['Song']) => (int)$dataRow['Song'],
					isset($dataRow['SongId']) && is_numeric($dataRow['SongId']) => (int)$dataRow['SongId'],
					default => null,
				};

				// Skip rows that correspond to records tombstoned in Nextcloud
				if ($refSongClientId !== null && isset($deletedSongClientIds[$refSongClientId])) {
					$this->miscMapper->deleteMiscData($libraryId, 'mobilesheets', $tableName, $recordId);
					continue;
				}
				if (isset($deletedEntityClientIdsByTable[$lowerTable]) && isset($dataRow['Id']) && isset($deletedEntityClientIdsByTable[$lowerTable][(int)$dataRow['Id']])) {
					$this->miscMapper->deleteMiscData($libraryId, 'mobilesheets', $tableName, $recordId);
					continue;
				}

				$uploadedRecordIds[$recordId] = true;
				$songUuid = $refSongClientId !== null ? ($songUuidByClientId[$refSongClientId] ?? null) : null;

				$this->miscMapper->saveMiscData($libraryId, 'mobilesheets', $tableName, $recordId, $dataRow, $songUuid);
			}

			// Reconcile deleted rows in misc data for this table
			$existingMiscRows = $this->miscMapper->getMiscDataForTable($libraryId, 'mobilesheets', $tableName, includeDeleted: false);
			foreach ($existingMiscRows as $existingRow) {
				$existingRecordId = self::extractRecordId($existingRow);
				if (!isset($uploadedRecordIds[$existingRecordId])) {
					$this->miscMapper->deleteMiscData($libraryId, 'mobilesheets', $tableName, $existingRecordId);
				}
			}

			if ($hasRows && !MobileSheetsEntityConfig::isCoreManaged($tableName)) {
				$stats['miscTablesSynced']++;
			}
		}

		// Preserve SQLite PRAGMA user_version so that exported databases match client schema version
		$userVersion = (int)$db->query('PRAGMA user_version')->fetchColumn();
		$this->miscMapper->savePragmaUserVersion($libraryId, 'mobilesheets', $userVersion);
	}

	private function resolveFileIdFromPath(
		string $filePath,
		InternalLibrary $library,
		?IUser $user,
		bool $allowPlaceholder = false,
	): ?int {
		if ($user === null) {
			return null;
		}

		try {
			$msPath = $this->pathResolver->fromStored($filePath, $user);
			if ($msPath !== null) {
				$userFolder = $this->rootFolder->getUserFolder($user->getUID());
				$rel = $this->pathResolver->relativeToUserFolder($msPath->internal, $user);
				if ($rel !== null) {
					if ($userFolder->nodeExists($rel)) {
						return $userFolder->get($rel)->getId();
					}
					if ($allowPlaceholder) {
						$placeholderId = $this->createPlaceholderFile($userFolder, $rel, $library);
						if ($placeholderId !== null) {
							return $placeholderId;
						}
					}
				}
			}
		} catch (\Throwable) {
			// Fall through
		}

		try {
			$clean = ltrim($filePath, '/');
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			if ($userFolder->nodeExists($clean)) {
				return $userFolder->get($clean)->getId();
			}

			$libPath = trim($library->path, '/');
			if (str_ends_with(strtolower($libPath), '.db')) {
				$libFolder = dirname($libPath);
				$cleanLibFolder = $libFolder === '.' ? '' : $libFolder;
			} else {
				$cleanLibFolder = $libPath;
			}

			$libRel = $cleanLibFolder !== '' ? $cleanLibFolder . '/' . $clean : $clean;
			if ($userFolder->nodeExists($libRel)) {
				return $userFolder->get($libRel)->getId();
			}

			if ($allowPlaceholder && !str_contains($filePath, ':') && !str_starts_with($filePath, '/storage/')) {
				$placeholderId = $this->createPlaceholderFile($userFolder, $libRel, $library);
				if ($placeholderId !== null) {
					return $placeholderId;
				}
			}
		} catch (\Throwable) {
			// Fall through
		}

		return null;
	}

	private function createPlaceholderFile(Folder $userFolder, string $relPath, InternalLibrary $library): ?int {
		if (!$this->isPathInsideLibrary($relPath, $library)) {
			return null;
		}

		try {
			$parent = dirname($relPath);
			if ($parent !== '.' && $parent !== '') {
				$this->ensureParentFolderExists($userFolder, $parent);
			}

			if (!$userFolder->nodeExists($relPath)) {
				$file = $userFolder->newFile($relPath);
				return $file->getId();
			}

			return $userFolder->get($relPath)->getId();
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to create placeholder file for MobileSheets sync: ' . $e->getMessage(), [
				'exception' => $e,
				'path' => $relPath,
			]);
			return null;
		}
	}

	private function isPathInsideLibrary(string $relPath, InternalLibrary $library): bool {
		$libPath = trim($library->path, '/');
		if (str_ends_with(strtolower($libPath), '.db')) {
			$libFolder = dirname($libPath);
			$cleanLibFolder = $libFolder === '.' ? '' : $libFolder;
		} else {
			$cleanLibFolder = $libPath;
		}

		if ($cleanLibFolder === '') {
			return false;
		}

		return $relPath === $cleanLibFolder || str_starts_with($relPath, $cleanLibFolder . '/');
	}

	private function ensureParentFolderExists(Folder $userFolder, string $parent): void {
		$current = '';
		foreach (explode('/', $parent) as $segment) {
			if ($segment === '') {
				continue;
			}
			$current = $current === '' ? $segment : "$current/$segment";
			if (!$userFolder->nodeExists($current)) {
				$userFolder->newFolder($current);
			}
		}
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function extractRecordId(array $row): string {
		return isset($row['Id']) ? (string)$row['Id'] : md5(json_encode($row, JSON_THROW_ON_ERROR));
	}
}
