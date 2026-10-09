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
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserManager;
use PDO;
use Psr\Log\LoggerInterface;

class MobileSheetsSqliteImporter {
	/**
	 * Mapping of table names to internal entity types and configuration.
	 */
	private const TABLE_TO_ENTITY_CONFIG = [
		'Composer' => [
			'type' => 'composer',
			'nameCol' => 'Name',
			'linkTable' => 'ComposerSongs',
			'linkCol' => 'ComposerId',
		],
		'Artists' => [
			'type' => 'artist',
			'nameCol' => 'Name',
			'linkTable' => 'ArtistsSongs',
			'linkCol' => 'ArtistId',
		],
		'Books' => [
			'type' => 'book',
			'nameCol' => 'Title',
			'linkTable' => 'BookSongs',
			'linkCol' => 'BookId',
		],
		'Collections' => [
			'type' => 'collection',
			'nameCol' => 'Name',
			'linkTable' => 'CollectionSong',
			'linkCol' => 'CollectionId',
		],
		'Genres' => [
			'type' => 'genre',
			'nameCol' => 'Type',
			'linkTable' => 'GenresSongs',
			'linkCol' => 'GenreId',
		],
		'Keys' => [
			'type' => 'key',
			'nameCol' => 'Name',
			'linkTable' => 'KeySongs',
			'linkCol' => 'KeyId',
		],
		'Key' => [
			'type' => 'key',
			'nameCol' => 'Name',
			'linkTable' => 'KeySongs',
			'linkCol' => 'KeyId',
		],
		'CustomGroup' => [
			'type' => 'customgroup',
			'nameCol' => 'Name',
			'linkTable' => 'CustomGroupSongs',
			'linkCol' => 'GroupId',
		],
		'Years' => [
			'type' => 'year',
			'nameCol' => 'Year',
			'linkTable' => 'YearsSongs',
			'linkCol' => 'YearId',
		],
		'Signature' => [
			'type' => 'signature',
			'nameCol' => 'Name',
			'linkTable' => 'SignatureSongs',
			'linkCol' => 'SignatureId',
		],
		'SourceTypes' => [
			'type' => 'sourcetype',
			'nameCol' => 'Type',
			'linkTable' => 'SourceTypeSongs',
			'linkCol' => 'SourceTypeId',
		],
		'SourceType' => [
			'type' => 'sourcetype',
			'nameCol' => 'Type',
			'linkTable' => 'SourceTypeSongs',
			'linkCol' => 'SourceTypeId',
		],
	];

	/** Standard tables natively handled by Sheet Music core. */
	private const CORE_MANAGED_TABLES = [
		'songs',
		'files',
		'composer',
		'composersongs',
		'artists',
		'artistssongs',
		'books',
		'booksongs',
		'collections',
		'collectionsong',
		'genres',
		'genressongs',
		'keys',
		'key',
		'keysongs',
		'customgroup',
		'customgroupsongs',
		'years',
		'yearssongs',
		'signature',
		'signaturesongs',
		'sourcetypes',
		'sourcetype',
		'sourcetypesongs',
		'songnotes',
		'_nc_sync_metadata',
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
		?IUser $user = null,
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

		$check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='_nc_sync_metadata'");
		if ($check === false || $check->fetch() === false) {
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
			$check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . str_replace("'", "''", $tableName) . "'");
			if ($check === false || $check->fetch() === false) {
				continue;
			}

			$stmt = $db->query('PRAGMA table_info("' . str_replace('"', '""', $tableName) . '")');
			$cols = [];
			if ($stmt !== false) {
				foreach ($stmt->fetchAll() as $col) {
					$cols[strtolower((string)$col['name'])] = (string)$col['name'];
				}
			}

			$nameCol = $config['nameCol'];
			if (!isset($cols[strtolower($nameCol)])) {
				// Alternative fallback
				if (isset($cols['name'])) {
					$nameCol = $cols['name'];
				} elseif (isset($cols['title'])) {
					$nameCol = $cols['title'];
				} elseif (isset($cols['type'])) {
					$nameCol = $cols['type'];
				} elseif (isset($cols['year'])) {
					$nameCol = $cols['year'];
				} else {
					continue;
				}
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
				$meta = $syncMeta['byTypeAndId']['entity:' . $type][$clientId] ?? null;
				if ($meta === null) {
					// Check any entity:* in syncMeta for this clientId
					foreach ($syncMeta['byTypeAndId'] as $metaType => $byClient) {
						if (str_starts_with($metaType, 'entity:') && isset($byClient[$clientId])) {
							$meta = $byClient[$clientId];
							$type = substr($metaType, strlen('entity:'));
							break;
						}
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
				}
				if ($existing === null) {
					$existing = $this->entityMapper->findByClientId($libraryId, $type, $clientId);
				}
				if ($existing === null) {
					$existing = $this->entityMapper->findByName($libraryId, $type, $name);
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
					$newEntity = new InternalEntity(
						uuid: $uuid ?? self::generateUuid(),
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
		$check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Songs'");
		if ($check === false || $check->fetch() === false) {
			return;
		}

		$notesBySongId = [];
		$noteCheck = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='SongNotes'");
		if ($noteCheck !== false && $noteCheck->fetch() !== false) {
			$notesStmt = $db->query('SELECT SongId, Note FROM SongNotes ORDER BY Id ASC');
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
			}
			if ($existing === null) {
				$existing = $this->songMapper->findByClientId($libraryId, $clientId);
			}
			if ($existing === null) {
				$existing = $this->songMapper->findByTitle($libraryId, $title);
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
				$newSong = new InternalSong(
					uuid: $uuid ?? self::generateUuid(),
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
		if ($updatedSongClientIds === []) {
			return;
		}

		$songs = $this->songMapper->findByLibrary($libraryId, includeDeleted: false);
		$songByClientId = [];
		foreach ($songs as $s) {
			if ($s->clientId !== null) {
				$songByClientId[$s->clientId] = $s;
			}
		}

		$entities = $this->entityMapper->findAllForLibrary($libraryId, includeDeleted: false);
		$entityIdByClientAndType = [];
		foreach ($entities as $e) {
			if ($e->clientId !== null) {
				$entityIdByClientAndType[$e->type . ':' . $e->clientId] = $e->id;
			}
		}

		foreach (self::TABLE_TO_ENTITY_CONFIG as $tableName => $config) {
			$linkTable = $config['linkTable'];
			$linkCol = $config['linkCol'];
			$type = $config['type'];

			$check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . str_replace("'", "''", $linkTable) . "'");
			if ($check === false || $check->fetch() === false) {
				continue;
			}

			$stmt = $db->query('SELECT "' . str_replace('"', '""', $linkCol) . '" AS entity_client_id, SongId AS song_client_id FROM "' . str_replace('"', '""', $linkTable) . '"');
			if ($stmt === false) {
				continue;
			}

			$linksBySongClientId = [];
			while ($row = $stmt->fetch()) {
				$sClientId = (int)$row['song_client_id'];
				$eClientId = (int)$row['entity_client_id'];
				$linksBySongClientId[$sClientId][] = $eClientId;
			}

			foreach (array_keys($updatedSongClientIds) as $songClientId) {
				$song = $songByClientId[$songClientId] ?? null;
				if ($song === null || $song->id === null) {
					continue;
				}

				$clientEntityIds = $linksBySongClientId[$songClientId] ?? [];
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
		$check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Files'");
		if ($check === false || $check->fetch() === false) {
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
						$this->songMapper->delete($song->id, hardDelete: false);
						$stats['songsDeleted']++;
					}
				}
			}
		}

		// 2. Deleted Entities
		foreach ($syncMeta['byTypeAndId'] as $entityType => $entries) {
			if (!str_starts_with($entityType, 'entity:')) {
				continue;
			}
			$type = substr($entityType, strlen('entity:'));
			$config = MobileSheetsSqliteExporter::ENTITY_TABLE_CONFIG[$type] ?? null;
			if ($config === null) {
				continue;
			}

			$tableName = $config['table'];
			$check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . str_replace("'", "''", $tableName) . "'");
			if ($check === false || $check->fetch() === false) {
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
						$this->entityMapper->delete($entity->id, hardDelete: false);
						$stats['entitiesDeleted']++;
					}
				}
			}
		}

		// 3. Deleted Files
		if (isset($syncMeta['byTypeAndId']['file'])) {
			$check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Files'");
			if ($check !== false && $check->fetch() !== false) {
				$uploadedFileIds = $db->query('SELECT Id FROM Files')->fetchAll(PDO::FETCH_COLUMN);
				$uploadedFileSet = array_flip(array_map('intval', $uploadedFileIds));

				foreach ($syncMeta['byTypeAndId']['file'] as $clientId => $meta) {
					if (!isset($uploadedFileSet[$clientId])) {
						$file = $this->songFileMapper->findByUuid($meta['uuid']);
						if ($file !== null && $file->deletedAt === null) {
							$this->songFileMapper->delete($file->id, hardDelete: false);
							$stats['filesDeleted']++;
						}
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

			if (in_array(strtolower($tableName), self::CORE_MANAGED_TABLES, true)) {
				continue;
			}

			// Save DDL schema
			$this->miscMapper->saveMiscSchema($libraryId, 'mobilesheets', $tableName, $sql);

			// Save rows
			$rowsStmt = $db->query('SELECT * FROM "' . str_replace('"', '""', $tableName) . '"');
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

			if ($hasRows) {
				$stats['miscTablesSynced']++;
			}
		}
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

			$libRel = rtrim($library->path, '/') . '/' . $clean;
			if ($userFolder->nodeExists($libRel)) {
				return $userFolder->get($libRel)->getId();
			}
		} catch (\Throwable) {
			// Fall through
		}

		return null;
	}
}
