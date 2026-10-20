<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalMiscMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Util\MobileSheetsTimestampUtil;
use PDO;

/**
 * Handles merging entities (composers, artists, books, collections, etc.)
 * and their song-entity junction links during MobileSheets SQLite import.
 */
class MobileSheetsEntityMerger {
	private const TABLE_TO_ENTITY_CONFIG = MobileSheetsEntityConfig::BY_TABLE;

	public function __construct(
		private InternalEntityMapper $entityMapper,
		private InternalSongMapper $songMapper,
		private InternalMiscMapper $miscMapper,
	) {
	}

	/**
	 * Merge entity tables (Composer, Artists, Books, Collections, Genres, etc.) from the SQLite database.
	 *
	 * @param array<string, int> $stats
	 */
	public function mergeEntities(PDO $db, int $libraryId, MobileSheetsSyncMetadata $syncMeta, array &$stats): void {
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

				$lastModifiedSec = MobileSheetsTimestampUtil::extractLastModifiedSec($row);
				$dateCreatedSec = MobileSheetsTimestampUtil::extractDateCreatedSec($row);
				$sortBy = isset($row['SortBy']) ? (int)$row['SortBy'] : 1;
				$ascending = isset($row['Ascending']) ? (int)$row['Ascending'] : 1;

				// Determine original internal type if recorded in metadata
				$type = $config['type'];
				$uuid = null;

				// Look up in syncMeta
				$meta = $syncMeta->getEntityMeta($type, $clientId);
				if ($meta === null && $tableName === 'CustomGroup') {
					$meta = $syncMeta->getEntityMeta('instrument', $clientId);
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
					// New entity created in MobileSheets
					$entityUuid = ($uuid !== null && $this->entityMapper->findByUuid($uuid) === null)
						? $uuid
						: InternalEntity::generateUuid();
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
	 * Merge junction table song-entity links from the SQLite database.
	 *
	 * @param array<int, bool> $updatedSongClientIds
	 */
	public function mergeSongEntityLinks(PDO $db, int $libraryId, array $updatedSongClientIds): void {
		$songs = $this->songMapper->findAllForLibrary($libraryId, includeDeleted: false);
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
	 * Process deleted entities recorded in sync metadata.
	 *
	 * @param array<string, int> $stats
	 */
	public function processEntityDeletions(PDO $db, int $libraryId, MobileSheetsSyncMetadata $syncMeta, array &$stats): void {
		foreach ($syncMeta->byTypeAndId as $entityType => $entries) {
			$type = str_starts_with($entityType, 'entity:') ? substr($entityType, strlen('entity:')) : $entityType;
			$config = MobileSheetsEntityConfig::BY_TYPE[$type] ?? null;
			if ($config === null) {
				continue;
			}

			$tableName = MobileSheetsTableHelper::resolveTable($db, $config['table']);
			if ($tableName === null) {
				continue;
			}

			$uploadedEntitySet = MobileSheetsTableHelper::getTableIdSet($db, $tableName);

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
					$this->miscMapper->deleteMiscData($libraryId, 'mobilesheets', $tableName, (string)$clientId);
				}
			}
		}
	}
}

