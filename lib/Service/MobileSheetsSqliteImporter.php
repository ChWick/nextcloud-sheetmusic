<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

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
use OCA\SheetMusic\Service\MobileSheetsEntityConfig;
use OCA\SheetMusic\Service\MobileSheetsTableHelper;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use PDO;
use Psr\Log\LoggerInterface;

class MobileSheetsSqliteImporter {
	/**
	 * Mapping of table names to internal entity types and configuration.
	 */
	private const TABLE_TO_ENTITY_CONFIG = MobileSheetsEntityConfig::BY_TABLE;

	/** Standard tables natively handled by Sheet Music core. */
	public const CORE_MANAGED_TABLES = MobileSheetsEntityConfig::CORE_MANAGED_TABLES;

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
	 * Generate an RFC 4122 v4 compliant UUID.
	 */
	public static function generateUuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
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
		$lib = $this->resolveLibrary($library);
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

		$syncMeta = $this->loadSyncMetadata($db);

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
		$this->mergeEntities($db, $lib->id, $syncMeta, $stats);

		// 2. Songs
		$this->mergeSongs($db, $lib->id, $syncMeta, $stats, $updatedSongClientIds);

		// 3. Song-Entity Links
		$this->mergeSongEntityLinks($db, $lib->id, $updatedSongClientIds);

		// 4. Files
		$this->mergeFiles($db, $lib, $user, $syncMeta, $stats);

		// 5. Deletions (Tombstones) if sync metadata is present
		if ($syncMeta['hasMetadata']) {
			$this->processDeletions($db, $lib->id, $syncMeta, $stats);
		}

		// 6. Misc Tables (Setlists, Bookmarks, Annotations, AudioFiles, etc.)
		$this->mergeMiscTables($db, $lib->id, $stats);

		// 7. Update library modified timestamp
		$this->libraryMapper->touch($lib->id);

		return $stats;
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

	/**
	 * @return array{
	 *     byTypeAndId: array<string, array<int, array{uuid: string, updated_at: int, extra: array<string, mixed>}>>,
	 *     byTypeAndUuid: array<string, array<string, array{client_id: int, updated_at: int, extra: array<string, mixed>}>>,
	 *     hasMetadata: bool
	 * }
	 */
	private function loadSyncMetadata(PDO $db): array {
		$result = [
			'byTypeAndId' => [],
			'byTypeAndUuid' => [],
			'hasMetadata' => false,
		];

		if (!MobileSheetsTableHelper::tableExists($db, '_nc_sync_metadata')) {
			return $result;
		}

		$result['hasMetadata'] = true;

		$stmt = $db->query('SELECT entity_type, uuid, client_id, updated_at, extra_json FROM _nc_sync_metadata');
		if ($stmt === false) {
			return $result;
		}

		while ($row = $stmt->fetch()) {
			$type = (string)$row['entity_type'];
			$uuid = (string)$row['uuid'];
			$clientId = isset($row['client_id']) && $row['client_id'] !== null ? (int)$row['client_id'] : null;
			$updatedAt = (int)($row['updated_at'] ?? 0);

			$extra = [];
			if (!empty($row['extra_json'])) {
				try {
					/** @var array<string, mixed> $extra */
					$extra = json_decode((string)$row['extra_json'], true, 512, JSON_THROW_ON_ERROR);
				} catch (\Throwable) {
					$extra = [];
				}
			}

			$entry = [
				'uuid' => $uuid,
				'client_id' => $clientId,
				'updated_at' => $updatedAt,
				'extra' => $extra,
			];

			if ($clientId !== null) {
				$result['byTypeAndId'][$type][$clientId] = $entry;
			}
			$result['byTypeAndUuid'][$type][$uuid] = $entry;
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $syncMeta
	 * @param array<string, int> $stats
	 */
	private function mergeEntities(PDO $db, int $libraryId, array $syncMeta, array &$stats): void {
		foreach (self::TABLE_TO_ENTITY_CONFIG as $tableName => $config) {
			if (!MobileSheetsTableHelper::tableExists($db, $tableName)) {
				continue;
			}

			$cols = MobileSheetsTableHelper::getTableColumns($db, $tableName);
			$nameCol = MobileSheetsTableHelper::resolveEntityNameColumn($cols, $config['nameCol']);
			if ($nameCol === null) {
				continue;
			}

			$rowsStmt = $db->query('SELECT * FROM "' . str_replace('"', '""', $tableName) . '"');
			if ($rowsStmt === false) {
				continue;
			}

			while ($row = $rowsStmt->fetch()) {
				$clientId = (int)$row['Id'];
				$name = trim((string)($row[$nameCol] ?? ''));
				if ($name === '') {
					continue;
				}

				$lastModifiedMs = (int)($row['LastModified'] ?? 0);
				$lastModifiedSec = (int)round($lastModifiedMs / 1000);
				$dateCreatedMs = (int)($row['DateCreated'] ?? $row['CreationDate'] ?? 0);
				$dateCreatedSec = (int)round($dateCreatedMs / 1000);
				$sortBy = isset($row['SortBy']) ? (int)$row['SortBy'] : 1;
				$ascending = isset($row['Ascending']) ? (int)$row['Ascending'] : 1;

				// Determine original internal type if recorded in metadata
				$type = $config['type'];
				$uuid = null;

				// Look up in syncMeta
				$meta = $syncMeta['byTypeAndId']['entity:' . $type][$clientId]
					?? $syncMeta['byTypeAndId'][$type][$clientId]
					?? null;
				if ($meta === null && $tableName === 'CustomGroup') {
					$meta = $syncMeta['byTypeAndId']['entity:instrument'][$clientId]
						?? $syncMeta['byTypeAndId']['instrument'][$clientId]
						?? null;
					if ($meta !== null) {
						$type = 'instrument';
					}
				}

				if ($meta !== null) {
					$uuid = $meta['uuid'];
					if (!empty($meta['extra']['original_type'])) {
						$type = (string)$meta['extra']['original_type'];
					}
				}

				// Find existing internal entity
				$existing = null;
				if ($uuid !== null) {
					$existing = $this->entityMapper->findByUuid($uuid);
					if ($existing !== null && $existing->libraryId !== $libraryId) {
						$existing = null;
						$uuid = null;
					}
				}
				if ($existing === null) {
					$existing = $this->entityMapper->findByClientId($libraryId, $type, $clientId);
				}
				if ($existing === null) {
					$cand = $this->entityMapper->findByName($libraryId, $type, $name);
					if ($cand !== null && ($cand->clientId === null || $cand->clientId === $clientId)) {
						$existing = $cand;
					}
				}

				if ($existing !== null) {
					if ($existing->deletedAt !== null) {
						// Tombstoned: check if MobileSheets modified after tombstone
						if ($lastModifiedSec > $existing->deletedAt) {
							$existing->deletedAt = null;
							$existing->name = $name;
							$existing->sortBy = $sortBy;
							$existing->ascending = $ascending;
							$existing->clientId = $clientId;
							$existing->updatedAt = $lastModifiedSec;
							$this->entityMapper->update($existing);
							$stats['entitiesUpdated']++;
						}
					} else {
						// Active: LWW comparison
						if ($lastModifiedSec > $existing->updatedAt) {
							$existing->name = $name;
							$existing->sortBy = $sortBy;
							$existing->ascending = $ascending;
							$existing->clientId = $clientId;
							$existing->updatedAt = $lastModifiedSec;
							$this->entityMapper->update($existing);
							$stats['entitiesUpdated']++;
						} else {
							// Nextcloud wins or equals; just ensure clientId is recorded
							if ($existing->clientId === null || $existing->clientId !== $clientId) {
								$existing->clientId = $clientId;
								$this->entityMapper->update($existing);
							}
						}
					}
				} else {
					// New entity
					$entityUuid = ($uuid !== null && $this->entityMapper->findByUuid($uuid) === null)
						? $uuid
						: self::generateUuid();
					$newEntity = new InternalEntity(
						uuid: $entityUuid,
						libraryId: $libraryId,
						type: $type,
						name: $name,
						sortBy: $sortBy,
						ascending: $ascending,
						clientId: $clientId,
						createdAt: $dateCreatedSec ?: time(),
						updatedAt: $lastModifiedSec ?: time(),
					);
					$this->entityMapper->insert($newEntity);
					$stats['entitiesCreated']++;
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $syncMeta
	 * @param array<string, int> $stats
	 * @param array<int, bool> $updatedSongClientIds
	 */
	private function mergeSongs(
		PDO $db,
		int $libraryId,
		array $syncMeta,
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

			$lastModifiedMs = (int)($row['LastModified'] ?? 0);
			$lastModifiedSec = (int)round($lastModifiedMs / 1000);
			$dateCreatedMs = (int)($row['DateCreated'] ?? $row['CreationDate'] ?? 0);
			$dateCreatedSec = (int)round($dateCreatedMs / 1000);

			$meta = $syncMeta['byTypeAndId']['song'][$clientId] ?? null;
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
					// Tombstone check
					if ($lastModifiedSec > $existing->deletedAt) {
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
					// Active song: LWW
					if ($lastModifiedSec > $existing->updatedAt) {
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
	 * @param array<int, bool> $updatedSongClientIds
	 */
	private function mergeSongEntityLinks(PDO $db, int $libraryId, array $updatedSongClientIds): void {
		$songs = $this->songMapper->findByLibrary($libraryId, includeDeleted: false);
		$songByClientId = [];
		foreach ($songs as $s) {
			if ($s->clientId !== null) {
				$songByClientId[$s->clientId] = $s;
			}
		}

		$entities = $this->entityMapper->findAllForLibrary($libraryId, includeDeleted: true);
		$entityIdByClientAndType = [];
		$allEntityIdsByType = [];
		foreach ($entities as $e) {
			if ($e->clientId !== null && $e->id !== null) {
				$entityIdByClientAndType[$e->type . ':' . $e->clientId] = $e->id;
				$allEntityIdsByType[$e->type][$e->clientId] = $e->id;
			}
		}

		foreach (self::TABLE_TO_ENTITY_CONFIG as $tableName => $config) {
			$linkTable = $config['linkTable'];
			$linkCol = $config['linkCol'];
			$type = $config['type'];

			if (!MobileSheetsTableHelper::tableExists($db, $linkTable)) {
				continue;
			}

			$stmt = $db->query('SELECT "' . str_replace('"', '""', $linkCol) . '" AS entity_client_id, SongId AS song_client_id FROM "' . str_replace('"', '""', $linkTable) . '" ORDER BY Id ASC');
			if ($stmt === false) {
				continue;
			}

			$songsByEntityClientId = [];
			$linksBySongClientId = [];
			while ($row = $stmt->fetch()) {
				$sClientId = (int)$row['song_client_id'];
				$eClientId = (int)$row['entity_client_id'];
				$songsByEntityClientId[$eClientId][] = $sClientId;
				$linksBySongClientId[$sClientId][] = $eClientId;
			}

			$knownClientIds = array_unique(array_merge(
				array_keys($songsByEntityClientId),
				array_keys($allEntityIdsByType[$type] ?? []),
				($type === 'customgroup') ? array_keys($allEntityIdsByType['instrument'] ?? []) : []
			));

			foreach ($knownClientIds as $eClientId) {
				$internalEntityId = null;
				if ($type === 'customgroup') {
					$internalEntityId = $entityIdByClientAndType['instrument:' . $eClientId]
						?? $entityIdByClientAndType['customgroup:' . $eClientId]
						?? null;
				} else {
					$internalEntityId = $entityIdByClientAndType[$type . ':' . $eClientId] ?? null;
				}

				if ($internalEntityId === null) {
					continue;
				}

				$songClientIds = $songsByEntityClientId[$eClientId] ?? [];
				$orderedInternalSongIds = [];
				foreach ($songClientIds as $sClientId) {
					$song = $songByClientId[$sClientId] ?? null;
					if ($song !== null && $song->id !== null && $song->deletedAt === null) {
						$orderedInternalSongIds[] = $song->id;
					}
				}

				$this->entityMapper->setEntitySongs($internalEntityId, $orderedInternalSongIds);
			}

			$candidateSongClientIds = array_unique(array_merge(array_keys($updatedSongClientIds), array_keys($linksBySongClientId)));
			foreach ($candidateSongClientIds as $songClientId) {
				$song = $songByClientId[$songClientId] ?? null;
				if ($song === null || $song->id === null) {
					continue;
				}

				$clientEntityIds = $linksBySongClientId[$songClientId] ?? [];
				if ($type === 'customgroup') {
					$internalCustomGroupIds = [];
					$internalInstrumentIds = [];
					foreach ($clientEntityIds as $cId) {
						if (isset($entityIdByClientAndType['instrument:' . $cId])) {
							$internalInstrumentIds[] = $entityIdByClientAndType['instrument:' . $cId];
						} elseif (isset($entityIdByClientAndType['customgroup:' . $cId])) {
							$internalCustomGroupIds[] = $entityIdByClientAndType['customgroup:' . $cId];
						}
					}
					$this->entityMapper->setSongEntitiesForType($song->id, 'customgroup', $internalCustomGroupIds);
					$this->entityMapper->setSongEntitiesForType($song->id, 'instrument', $internalInstrumentIds);
				} else {
					$internalEntityIds = [];
					foreach ($clientEntityIds as $cId) {
						$entId = $entityIdByClientAndType[$type . ':' . $cId] ?? null;
						if ($entId !== null) {
							$internalEntityIds[] = $entId;
						}
					}
					$this->entityMapper->setSongEntitiesForType($song->id, $type, $internalEntityIds);
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $syncMeta
	 * @param array<string, int> $stats
	 */
	private function mergeFiles(
		PDO $db,
		InternalLibrary $library,
		?IUser $user,
		array $syncMeta,
		array &$stats,
	): void {
		if (!MobileSheetsTableHelper::tableExists($db, 'Files')) {
			return;
		}

		$songs = $this->songMapper->findByLibrary($library->id, includeDeleted: false);
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
			$lastModifiedMs = (int)($row['LastModified'] ?? 0);
			$lastModifiedSec = (int)round($lastModifiedMs / 1000);

			$song = $songByClientId[$songClientId] ?? null;
			if ($song === null || $song->id === null) {
				continue;
			}

			$meta = $syncMeta['byTypeAndId']['file'][$fileClientId] ?? null;
			$uuid = $meta['uuid'] ?? null;
			$extra = $meta['extra'] ?? [];

			$fileId = isset($extra['file_id']) ? (int)$extra['file_id'] : null;
			if ($fileId === null) {
				$fileId = $this->resolveFileIdFromPath($filePath, $library, $user);
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
				$existing = $this->songFileMapper->findByClientId($song->id, $fileClientId);
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

			if ($existing !== null) {
				if ($existing->deletedAt !== null) {
					if ($lastModifiedSec > $existing->deletedAt) {
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
					if ($lastModifiedSec > $existing->updatedAt) {
						$existing->pageOrder = $pageOrder;
						$existing->pageCount = $pageCount;
						$existing->fileSize = $fileSize;
						$existing->fileHash = $fileHash;
						$existing->type = $type;
						$existing->clientId = $fileClientId;
						$existing->updatedAt = $lastModifiedSec;
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
		}
	}

	/**
	 * @param array<string, mixed> $syncMeta
	 * @param array<string, int> $stats
	 */
	private function processDeletions(PDO $db, int $libraryId, array $syncMeta, array &$stats): void {
		// 1. Deleted Songs
		if (isset($syncMeta['byTypeAndId']['song'])) {
			$uploadedSongIds = $db->query('SELECT Id FROM Songs')->fetchAll(PDO::FETCH_COLUMN);
			$uploadedSongSet = array_flip(array_map('intval', $uploadedSongIds));

			foreach ($syncMeta['byTypeAndId']['song'] as $clientId => $meta) {
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
						$this->songMapper->delete($song->id, hardDelete: false);
						$stats['songsDeleted']++;
					}
				}
			}
		}

		// 2. Deleted Entities
		foreach ($syncMeta['byTypeAndId'] as $entityType => $entries) {
			$type = str_starts_with($entityType, 'entity:') ? substr($entityType, strlen('entity:')) : $entityType;
			$config = MobileSheetsEntityConfig::BY_TYPE[$type] ?? null;
			if ($config === null) {
				continue;
			}

			$tableName = MobileSheetsTableHelper::resolveTable($db, $config['table']);
			if ($tableName === null) {
				continue;
			}

			$uploadedEntityIds = $db->query('SELECT Id FROM "' . str_replace('"', '""', $tableName) . '"')->fetchAll(PDO::FETCH_COLUMN);
			$uploadedEntitySet = array_flip(array_map('intval', $uploadedEntityIds));

			foreach ($entries as $clientId => $meta) {
				if (!isset($uploadedEntitySet[$clientId])) {
					$entity = $this->entityMapper->findByUuid($meta['uuid']);
					if ($entity === null) {
						$entity = $this->entityMapper->findByClientId($libraryId, $type, $clientId);
					}
					if ($entity !== null && $entity->deletedAt === null) {
						$metaUpdatedAt = (int)($meta['updated_at'] ?? 0);
						// LWW: If Nextcloud has newer edits since the baseline export, Nextcloud wins
						if ($entity->updatedAt > $metaUpdatedAt) {
							continue;
						}
						$this->entityMapper->delete($entity->id, hardDelete: false);
						$stats['entitiesDeleted']++;
					}
				}
			}
		}

		// 3. Deleted Files
		if (isset($syncMeta['byTypeAndId']['file']) && MobileSheetsTableHelper::tableExists($db, 'Files')) {
				$uploadedFileIds = $db->query('SELECT Id FROM Files')->fetchAll(PDO::FETCH_COLUMN);
				$uploadedFileSet = array_flip(array_map('intval', $uploadedFileIds));

				foreach ($syncMeta['byTypeAndId']['file'] as $clientId => $meta) {
					if (!isset($uploadedFileSet[$clientId])) {
						$file = $this->songFileMapper->findByUuid($meta['uuid']);
						if ($file !== null && $file->deletedAt === null) {
							$metaUpdatedAt = (int)($meta['updated_at'] ?? 0);
							// LWW: If Nextcloud has newer edits since the baseline export, Nextcloud wins
							if ($file->updatedAt > $metaUpdatedAt) {
								continue;
							}
							$this->songFileMapper->delete($file->id, hardDelete: false);
							$stats['filesDeleted']++;
						}
					}
				}
		}
	}

	/**
	 * @param array<string, int> $stats
	 */
	private function mergeMiscTables(PDO $db, int $libraryId, array &$stats): void {
		$stmt = $db->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
		if ($stmt === false) {
			return;
		}

		$songs = $this->songMapper->findByLibrary($libraryId, includeDeleted: true);
		$songUuidByClientId = [];
		foreach ($songs as $s) {
			if ($s->clientId !== null) {
				$songUuidByClientId[$s->clientId] = $s->uuid;
			}
		}

		while ($row = $stmt->fetch()) {
			$tableName = (string)$row['name'];
			$sql = (string)$row['sql'];

			if (strtolower($tableName) === '_nc_sync_metadata') {
				continue;
			}

			// Save DDL schema
			$this->miscMapper->saveMiscSchema($libraryId, 'mobilesheets', $tableName, $sql);

			// Save rows
			$rowsStmt = $db->query('SELECT * FROM "' . str_replace('"', '""', $tableName) . '" ORDER BY rowid ASC');
			if ($rowsStmt === false) {
				continue;
			}

			$hasRows = false;
			while ($dataRow = $rowsStmt->fetch()) {
				$hasRows = true;
				$recordId = isset($dataRow['Id']) ? (string)$dataRow['Id'] : md5(json_encode($dataRow, JSON_THROW_ON_ERROR));

				$songUuid = null;
				if (isset($dataRow['SongId']) && is_numeric($dataRow['SongId'])) {
					$songUuid = $songUuidByClientId[(int)$dataRow['SongId']] ?? null;
				}

				$this->miscMapper->saveMiscData($libraryId, 'mobilesheets', $tableName, $recordId, $dataRow, $songUuid);
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
}
