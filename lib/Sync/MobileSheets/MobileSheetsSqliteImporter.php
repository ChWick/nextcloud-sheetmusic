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

		// 1. Entities
		$this->entityMerger->mergeEntities($db, $lib->id, $syncMeta, $stats);

		// 2. Songs
		$this->mergeSongs($db, $lib->id, $syncMeta, $stats, $updatedSongClientIds);

		// 3. Song-Entity Links
		$this->entityMerger->mergeSongEntityLinks($db, $lib->id, $updatedSongClientIds, $syncMeta);

		// 4. Files
		$uploadedFileNodeIds = [];
		$uploadedSongFileIds = [];
		$this->mergeFiles($db, $lib, $user, $syncMeta, $stats, $uploadedFileNodeIds, $uploadedSongFileIds);

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
	): void {
		if (!MobileSheetsTableHelper::tableExists($db, 'Songs')) {
			return;
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

		while ($row = $songsStmt->fetch()) {
			$clientId = (int)$row['Id'];
			$title = trim((string)($row['Title'] ?? ''));
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

			if ($existing !== null) {
				if ($existing->deletedAt !== null) {
					// Tombstone check: only revive if modified on client after both deletion and baseline export
					$metaUpdatedAt = (int)($meta['updated_at'] ?? 0);
					if ($lastModifiedSec > $existing->deletedAt && ($meta === null || $lastModifiedSec > $metaUpdatedAt)) {
						// Revive song!
						$existing->deletedAt = null;
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
						$existing->updatedAt = $lastModifiedSec;
						$this->songMapper->update($existing);

						if ($custom !== '') {
							$this->attributeMapper->setAttribute($existing->id, 'custom', $custom);
						}
						if ($custom2 !== '') {
							$this->attributeMapper->setAttribute($existing->id, 'custom2', $custom2);
						}

						$stats['songsUpdated']++;
						$updatedSongClientIds[$clientId] = true;
					}
				} else {
					// Active song: LWW (or unsynced auto-indexed stub song)
					if ($lastModifiedSec > $existing->updatedAt || ($existing->clientId === null && $uuid === null)) {
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

						if (!empty($row['Opus'])) {
							$existing->opus = (string)$row['Opus'];
						} elseif ($existing->opus === null && !empty($extra['opus'])) {
							$existing->opus = (string)$extra['opus'];
						}
						if (!empty($row['CatalogNumber'])) {
							$existing->catalogNumber = (string)$row['CatalogNumber'];
						} elseif ($existing->catalogNumber === null && !empty($extra['catalog_number'])) {
							$existing->catalogNumber = (string)$extra['catalog_number'];
						}
						if (!empty($row['SongNumber'])) {
							$existing->songNumber = (string)$row['SongNumber'];
						} elseif ($existing->songNumber === null && !empty($extra['song_number'])) {
							$existing->songNumber = (string)$extra['song_number'];
						}

						$this->songMapper->update($existing);

						if ($custom !== '') {
							$this->attributeMapper->setAttribute($existing->id, 'custom', $custom);
						}
						if ($custom2 !== '') {
							$this->attributeMapper->setAttribute($existing->id, 'custom2', $custom2);
						}

						$stats['songsUpdated']++;
						$updatedSongClientIds[$clientId] = true;
					} else {
						// Nextcloud is newer or equal
						if ($existing->clientId === null || $existing->clientId !== $clientId) {
							$existing->clientId = $clientId;
							$this->songMapper->update($existing);
						}
					}
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
					opus: !empty($row['Opus']) ? (string)$row['Opus'] : (isset($extra['opus']) ? (string)$extra['opus'] : null),
					catalogNumber: !empty($row['CatalogNumber']) ? (string)$row['CatalogNumber'] : (isset($extra['catalog_number']) ? (string)$extra['catalog_number'] : null),
					songNumber: !empty($row['SongNumber']) ? (string)$row['SongNumber'] : (isset($extra['song_number']) ? (string)$extra['song_number'] : null),
					clientId: $clientId,
					createdAt: $dateCreatedSec ?: time(),
					updatedAt: $lastModifiedSec ?: time(),
				);
				$this->songMapper->insert($newSong);

				if ($custom !== '') {
					$this->attributeMapper->setAttribute($newSong->id, 'custom', $custom);
				}
				if ($custom2 !== '') {
					$this->attributeMapper->setAttribute($newSong->id, 'custom2', $custom2);
				}

				$stats['songsCreated']++;
				$updatedSongClientIds[$clientId] = true;
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
	): void {
		if (!MobileSheetsTableHelper::tableExists($db, 'Files')) {
			return;
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

		while ($row = $stmt->fetch()) {
			$fileClientId = (int)$row['Id'];
			$songClientId = (int)$row['SongId'];
			$filePath = (string)($row['Path'] ?? '');
			$pageOrder = (string)($row['PageOrder'] ?? '');
			$fileSize = (int)($row['FileSize'] ?? 0);
			$fileHash = isset($row['FileHash']) ? (int)$row['FileHash'] : null;
			$pageCount = (int)($row['SourceFilePageCount'] ?? 1);
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
			$resolvedFileId = $this->resolveFileIdFromPath($filePath, $library, $user);

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
				if ($existing->deletedAt !== null) {
					$metaUpdatedAt = (int)($meta['updated_at'] ?? 0);
					if ($lastModifiedSec > $existing->deletedAt && ($meta === null || $lastModifiedSec > $metaUpdatedAt)) {
						$existing->deletedAt = null;
						$existing->pageOrder = $pageOrder;
						$existing->pageCount = $pageCount;
						$existing->fileSize = $fileSize;
						$existing->fileHash = $fileHash;
						$existing->type = $type;
						$existing->clientId = $fileClientId;
						$existing->updatedAt = $lastModifiedSec;
						$this->songFileMapper->update($existing);
						$stats['filesUpdated']++;
					}
				} else {
					if ($lastModifiedSec > $existing->updatedAt || ($existing->clientId === null && $uuid === null)) {
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
						if ($existing->clientId === null || $existing->clientId !== $fileClientId) {
							$existing->clientId = $fileClientId;
							$this->songFileMapper->update($existing);
						}
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
							if ($songFile->clientId !== null) {
								$this->miscMapper->deleteMiscData($libraryId, 'mobilesheets', 'Files', (string)$songFile->clientId);
							}
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
					if (!isset($uploadedFileSet[$clientId])) {
						$this->miscMapper->deleteMiscData($libraryId, 'mobilesheets', 'Files', (string)$clientId);
					}
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
				$recordId = isset($dataRow['Id']) ? (string)$dataRow['Id'] : md5(json_encode($dataRow, JSON_THROW_ON_ERROR));

				// Skip rows that correspond to records tombstoned in Nextcloud
				if ($lowerTable === 'songs' && isset($dataRow['Id']) && isset($deletedSongClientIds[(int)$dataRow['Id']])) {
					$this->miscMapper->deleteMiscData($libraryId, 'mobilesheets', $tableName, $recordId);
					continue;
				}
				if (isset($dataRow['SongId']) && is_numeric($dataRow['SongId']) && isset($deletedSongClientIds[(int)$dataRow['SongId']])) {
					$this->miscMapper->deleteMiscData($libraryId, 'mobilesheets', $tableName, $recordId);
					continue;
				}
				if (isset($deletedEntityClientIdsByTable[$lowerTable]) && isset($dataRow['Id']) && isset($deletedEntityClientIdsByTable[$lowerTable][(int)$dataRow['Id']])) {
					$this->miscMapper->deleteMiscData($libraryId, 'mobilesheets', $tableName, $recordId);
					continue;
				}

				$uploadedRecordIds[$recordId] = true;

				$songUuid = null;
				if ($lowerTable === 'songs') {
					if (isset($dataRow['Id']) && is_numeric($dataRow['Id'])) {
						$songUuid = $songUuidByClientId[(int)$dataRow['Id']] ?? null;
					}
				} elseif (isset($dataRow['SongId']) && is_numeric($dataRow['SongId'])) {
					$songUuid = $songUuidByClientId[(int)$dataRow['SongId']] ?? null;
				}

				$this->miscMapper->saveMiscData($libraryId, 'mobilesheets', $tableName, $recordId, $dataRow, $songUuid);
			}

			// Reconcile deleted rows in misc data for this table
			$existingMiscRows = $this->miscMapper->getMiscDataForTable($libraryId, 'mobilesheets', $tableName, includeDeleted: false);
			foreach ($existingMiscRows as $existingRow) {
				$existingRecordId = isset($existingRow['Id']) ? (string)$existingRow['Id'] : md5(json_encode($existingRow, JSON_THROW_ON_ERROR));
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

	private function resolveFileIdFromPath(string $filePath, InternalLibrary $library, ?IUser $user): ?int {
		if ($user === null) {
			return null;
		}

		try {
			$msPath = $this->pathResolver->fromStored($filePath, $user);
			if ($msPath !== null) {
				$userFolder = $this->rootFolder->getUserFolder($user->getUID());
				$rel = $this->pathResolver->relativeToUserFolder($msPath->internal, $user);
				if ($rel !== null && $userFolder->nodeExists($rel)) {
					$node = $userFolder->get($rel);
					return $node->getId();
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
		} catch (\Throwable) {
			// Fall through
		}

		return null;
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
