<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Util\MobileSheetsTimestampUtil;
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
}

