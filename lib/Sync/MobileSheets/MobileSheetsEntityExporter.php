<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Util\SongSortingUtil;
use PDO;

/**
 * Handles exporting entities (composers, artists, books, collections, etc.)
 * and their song-entity junction links into a MobileSheets SQLite database.
 */
class MobileSheetsEntityExporter {
	/**
	 * Mapping of internal entity types to MobileSheets table specifications.
	 *
	 * @var array<string, array{table: string, nameCol: string, linkTable: string, linkCol: string, hasSort: bool}>
	 */
	public const ENTITY_TABLE_CONFIG = MobileSheetsEntityConfig::BY_TYPE;

	public function __construct(
		private InternalEntityMapper $entityMapper,
		private InternalSongMapper $songMapper,
	) {
	}

	/**
	 * Export all active entities to SQLite tables.
	 */
	public function exportEntities(PDO $db, int $libraryId): void {
		$entities = $this->entityMapper->findAllForLibrary($libraryId, includeDeleted: false);
		[$usedIds, $nextId] = MobileSheetsTableHelper::initClientIdTracking($entities);

		$hasSyncMeta = MobileSheetsTableHelper::tableExists($db, '_nc_sync_metadata');
		$metaStmt = $hasSyncMeta ? $db->prepare('INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES (:type, :uuid, :client_id, :updated_at, :extra_json)') : null;

		foreach ($entities as $entity) {
			$config = self::ENTITY_TABLE_CONFIG[$entity->type] ?? self::ENTITY_TABLE_CONFIG['customgroup'];

			$tableName = MobileSheetsTableHelper::resolveTable($db, $config['table']);
			if ($tableName === null) {
				continue;
			}

			$clientId = MobileSheetsTableHelper::nextClientId($usedIds, $nextId, $entity->clientId);
			if ($entity->clientId !== $clientId) {
				$entity->clientId = $clientId;
				$this->entityMapper->update($entity);
			}

			$existingEntityRow = MobileSheetsTableHelper::findRowById($db, $tableName, $clientId);

			$existingCols = MobileSheetsTableHelper::getTableColumns($db, $tableName);
			$nameCol = MobileSheetsTableHelper::resolveEntityNameColumn($existingCols, $config['nameCol']) ?? $config['nameCol'];
			$val = (strtolower($nameCol) === 'year') ? (int)$entity->name : $entity->name;
			$hasSortCols = isset($existingCols['sortby']) && isset($existingCols['ascending']);
			$dateCreatedCol = $existingCols['datecreated'] ?? ($existingCols['creationdate'] ?? null);
			$lastModCol = $existingCols['lastmodified'] ?? null;

			if ($existingEntityRow === null) {
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
			} else {
				$existingModSec = MobileSheetsTimestampUtil::extractLastModifiedSec($existingEntityRow);
				$sortChanged = $hasSortCols && (
					(int)($existingEntityRow[$existingCols['sortby']] ?? 1) !== $entity->sortBy
					|| (int)($existingEntityRow[$existingCols['ascending']] ?? 1) !== $entity->ascending
				);
				if (($existingModSec > 0 && $entity->updatedAt > $existingModSec) || trim((string)($existingEntityRow[$nameCol] ?? '')) !== (string)$val || $sortChanged) {
					$updateValues = [$nameCol => $val];
					if ($hasSortCols) {
						$updateValues[$existingCols['sortby']] = $entity->sortBy;
						$updateValues[$existingCols['ascending']] = $entity->ascending;
					}
					if ($lastModCol !== null) {
						$updateValues[$lastModCol] = MobileSheetsTimestampUtil::secondsToMs($entity->updatedAt);
					}
					MobileSheetsTableHelper::updateRow($db, $tableName, $updateValues, 'Id', $clientId);
				}
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
	 * Export junction links between songs and entities to SQLite link tables.
	 *
	 * @param array<int, bool> $validSongIds Set of song IDs that have valid files and are exported.
	 */
	public function exportSongEntityLinks(PDO $db, int $libraryId, array $validSongIds): void {
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

		$activePairsByLinkTable = [];

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

			$orderedSongClientIds = [];
			$insertedSongClientIds = [];
			foreach ($entityLinks as $link) {
				$songId = $link->songId;
				$songClientId = $songClientMap[$songId] ?? null;
				if ($songClientId === null || isset($insertedSongClientIds[$songClientId])) {
					continue;
				}
				$insertedSongClientIds[$songClientId] = true;
				$orderedSongClientIds[] = (int)$songClientId;
				$activePairsByLinkTable[$linkTable][(int)$entityClientId . ':' . (int)$songClientId] = true;
			}

			$quotedTable = '"' . str_replace('"', '""', $linkTable) . '"';
			$quotedCol = '"' . str_replace('"', '""', $linkCol) . '"';
			$existingOrderStmt = $db->prepare("SELECT SongId FROM {$quotedTable} WHERE {$quotedCol} = ? ORDER BY Id ASC");
			$existingOrderStmt->execute([$entityClientId]);
			$existingOrder = array_map('intval', $existingOrderStmt->fetchAll(PDO::FETCH_COLUMN));

			$isManual = ($entity->sortBy === SongSortingUtil::MODE_MANUAL);
			if ($isManual) {
				if ($existingOrder === $orderedSongClientIds) {
					continue;
				}
				// In manual sort mode, if order or membership changed, replace rows in the exact manual sequence
				$delExisting = $db->prepare("DELETE FROM {$quotedTable} WHERE {$quotedCol} = ?");
				$delExisting->execute([$entityClientId]);
				$existingSet = [];
			} else {
				$existingSet = array_fill_keys($existingOrder, true);
			}

			$stmt = $db->prepare("INSERT INTO {$quotedTable} ({$quotedCol}, SongId) VALUES (?, ?)");
			foreach ($orderedSongClientIds as $songClientId) {
				if (!isset($existingSet[$songClientId])) {
					$stmt->execute([$entityClientId, $songClientId]);
				}
			}
		}

		// Prune stale link rows that were pre-populated from misc_data but are no longer active in Nextcloud
		foreach (MobileSheetsEntityConfig::BY_TABLE as $cfg) {
			$linkTable = $cfg['linkTable'];
			$linkCol = $cfg['linkCol'];
			if (!MobileSheetsTableHelper::tableExists($db, $linkTable)) {
				continue;
			}
			$quotedTable = '"' . str_replace('"', '""', $linkTable) . '"';
			$quotedCol = '"' . str_replace('"', '""', $linkCol) . '"';
			$existingStmt = $db->query("SELECT Id, {$quotedCol} AS entity_client_id, SongId AS song_client_id FROM {$quotedTable}");
			if ($existingStmt === false) {
				continue;
			}
			$idsToDelete = [];
			while ($row = $existingStmt->fetch()) {
				$pairKey = (int)$row['entity_client_id'] . ':' . (int)$row['song_client_id'];
				if (!isset($activePairsByLinkTable[$linkTable][$pairKey])) {
					$idsToDelete[] = (int)$row['Id'];
				}
			}
			if ($idsToDelete !== []) {
				$db->exec("DELETE FROM {$quotedTable} WHERE Id IN (" . implode(',', $idsToDelete) . ')');
			}
		}
	}
}
