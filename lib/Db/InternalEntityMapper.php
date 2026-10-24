<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class InternalEntityMapper {
	public const TABLE_NAME = 'sheetmusic_entities';
	public const JUNCTION_TABLE_NAME = 'sheetmusic_song_entities';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function findById(int $id): ?InternalEntity {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalEntity::fromRow($row);
	}

	public function findByUuid(string $uuid): ?InternalEntity {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalEntity::fromRow($row);
	}

	public function findByName(int $libraryId, string $type, string $name): ?InternalEntity {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalEntity::fromRow($row);
	}

	/**
	 * @return list<InternalEntity>
	 */
	public function findAllForLibrary(int $libraryId, ?string $type = null, bool $includeDeleted = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));

		if ($type !== null) {
			$qb->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR)));
		}

		if (!$includeDeleted) {
			$qb->andWhere($qb->expr()->isNull('deleted_at'));
		}

		$qb->orderBy('name', 'ASC');

		$result = $qb->executeQuery();
		$entities = [];
		while ($row = $result->fetchAssociative()) {
			$entities[] = InternalEntity::fromRow($row);
		}
		$result->closeCursor();

		return $entities;
	}

	public function findByClientId(int $libraryId, string $type, int $clientId): ?InternalEntity {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalEntity::fromRow($row);
	}

	public function insert(InternalEntity $entity): InternalEntity {
		$now = time();
		if ($entity->createdAt === 0) {
			$entity->createdAt = $now;
		}
		if ($entity->updatedAt === 0) {
			$entity->updatedAt = $now;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE_NAME)
			->values([
				'uuid' => $qb->createNamedParameter($entity->uuid, IQueryBuilder::PARAM_STR),
				'library_id' => $qb->createNamedParameter($entity->libraryId, IQueryBuilder::PARAM_INT),
				'type' => $qb->createNamedParameter($entity->type, IQueryBuilder::PARAM_STR),
				'name' => $qb->createNamedParameter($entity->name, IQueryBuilder::PARAM_STR),
				'sort_by' => $qb->createNamedParameter($entity->sortBy, IQueryBuilder::PARAM_INT),
				'ascending' => $qb->createNamedParameter($entity->ascending, IQueryBuilder::PARAM_INT),
				'client_id' => $qb->createNamedParameter($entity->clientId, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter($entity->createdAt, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($entity->updatedAt, IQueryBuilder::PARAM_INT),
				'deleted_at' => $qb->createNamedParameter($entity->deletedAt, IQueryBuilder::PARAM_INT),
			]);

		$qb->executeStatement();
		$entity->id = (int)$this->db->lastInsertId(self::TABLE_NAME);

		return $entity;
	}

	public function update(InternalEntity $entity): InternalEntity {
		if ($entity->id === null) {
			throw new \InvalidArgumentException('Cannot update an entity without an ID');
		}

		if ($entity->updatedAt === null || $entity->updatedAt <= 0) {
			$entity->updatedAt = time();
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('name', $qb->createNamedParameter($entity->name, IQueryBuilder::PARAM_STR))
			->set('sort_by', $qb->createNamedParameter($entity->sortBy, IQueryBuilder::PARAM_INT))
			->set('ascending', $qb->createNamedParameter($entity->ascending, IQueryBuilder::PARAM_INT))
			->set('client_id', $qb->createNamedParameter($entity->clientId, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($entity->updatedAt, IQueryBuilder::PARAM_INT))
			->set('deleted_at', $qb->createNamedParameter($entity->deletedAt, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($entity->id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();

		return $entity;
	}

	public function delete(int $id, bool $hardDelete = false): bool {
		$entity = $this->findById($id);
		$qb = $this->db->getQueryBuilder();

		if ($hardDelete) {
			// Unlink all songs
			$unlinkQb = $this->db->getQueryBuilder();
			$unlinkQb->delete(self::JUNCTION_TABLE_NAME)
				->where($unlinkQb->expr()->eq('entity_id', $unlinkQb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$unlinkQb->executeStatement();

			if ($entity !== null && $entity->clientId !== null) {
				$cfg = \OCA\SheetMusic\Sync\MobileSheets\MobileSheetsEntityConfig::BY_TYPE[$entity->type]
					?? \OCA\SheetMusic\Sync\MobileSheets\MobileSheetsEntityConfig::BY_TYPE['customgroup'];
				$miscQb = $this->db->getQueryBuilder();
				$miscQb->delete(InternalMiscMapper::DATA_TABLE_NAME)
					->where($miscQb->expr()->eq('library_id', $miscQb->createNamedParameter($entity->libraryId, IQueryBuilder::PARAM_INT)))
					->andWhere($miscQb->expr()->eq('table_name', $miscQb->createNamedParameter($cfg['table'], IQueryBuilder::PARAM_STR)))
					->andWhere($miscQb->expr()->eq('record_id', $miscQb->createNamedParameter((string)$entity->clientId, IQueryBuilder::PARAM_STR)));
				$miscQb->executeStatement();
			}

			$qb->delete(self::TABLE_NAME)
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		} else {
			$now = time();
			$qb->update(self::TABLE_NAME)
				->set('deleted_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

			// Also mark junction links
			$unlinkQb = $this->db->getQueryBuilder();
			$unlinkQb->update(self::JUNCTION_TABLE_NAME)
				->set('deleted_at', $unlinkQb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($unlinkQb->expr()->eq('entity_id', $unlinkQb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($unlinkQb->expr()->isNull('deleted_at'));
			$unlinkQb->executeStatement();

			if ($entity !== null && $entity->clientId !== null) {
				$cfg = \OCA\SheetMusic\Sync\MobileSheets\MobileSheetsEntityConfig::BY_TYPE[$entity->type]
					?? \OCA\SheetMusic\Sync\MobileSheets\MobileSheetsEntityConfig::BY_TYPE['customgroup'];
				$miscQb = $this->db->getQueryBuilder();
				$miscQb->update(InternalMiscMapper::DATA_TABLE_NAME)
					->set('deleted_at', $miscQb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
					->set('updated_at', $miscQb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
					->where($miscQb->expr()->eq('library_id', $miscQb->createNamedParameter($entity->libraryId, IQueryBuilder::PARAM_INT)))
					->andWhere($miscQb->expr()->eq('table_name', $miscQb->createNamedParameter($cfg['table'], IQueryBuilder::PARAM_STR)))
					->andWhere($miscQb->expr()->eq('record_id', $miscQb->createNamedParameter((string)$entity->clientId, IQueryBuilder::PARAM_STR)));
				$miscQb->executeStatement();
			}
		}

		return $qb->executeStatement() > 0;
	}

	/**
	 * @return list<InternalEntity>
	 */
	public function findEntitiesForSong(int $songId, ?string $type = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('e.*')
			->from(self::JUNCTION_TABLE_NAME, 'se')
			->innerJoin('se', self::TABLE_NAME, 'e', $qb->expr()->eq('se.entity_id', 'e.id'))
			->where($qb->expr()->eq('se.song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('se.deleted_at'))
			->andWhere($qb->expr()->isNull('e.deleted_at'));

		if ($type !== null) {
			$qb->andWhere($qb->expr()->eq('e.type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR)));
		}

		$qb->orderBy('e.name', 'ASC');

		$result = $qb->executeQuery();
		$entities = [];
		while ($row = $result->fetchAssociative()) {
			$entities[] = InternalEntity::fromRow($row);
		}
		$result->closeCursor();

		return $entities;
	}

	/**
	 * @return list<int>
	 */
	public function findEntityIdsForSong(int $songId, ?string $type = null): array {
		$entities = $this->findEntitiesForSong($songId, $type);
		return array_values(array_filter(array_map(fn (InternalEntity $e) => $e->id, $entities), fn ($id) => $id !== null));
	}

	/**
	 * @return list<int>
	 */
	public function findSongIdsForEntity(int $entityId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('song_id')
			->from(self::JUNCTION_TABLE_NAME)
			->where($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$songIds = [];
		while ($row = $result->fetchAssociative()) {
			$songIds[] = (int)$row['song_id'];
		}
		$result->closeCursor();

		return $songIds;
	}

	public function linkSongEntity(int $songId, int $entityId): void {
		$qbCheck = $this->db->getQueryBuilder();
		$qbCheck->select('id', 'deleted_at')
			->from(self::JUNCTION_TABLE_NAME)
			->where($qbCheck->expr()->eq('song_id', $qbCheck->createNamedParameter($songId, IQueryBuilder::PARAM_INT)))
			->andWhere($qbCheck->expr()->eq('entity_id', $qbCheck->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$row = $qbCheck->executeQuery()->fetchAssociative();
		if ($row !== false) {
			if ($row['deleted_at'] !== null) {
				// Re-enable link
				$qbRestore = $this->db->getQueryBuilder();
				$qbRestore->update(self::JUNCTION_TABLE_NAME)
					->set('deleted_at', $qbRestore->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
					->where($qbRestore->expr()->eq('id', $qbRestore->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
				$qbRestore->executeStatement();
			}
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::JUNCTION_TABLE_NAME)
			->values([
				'song_id' => $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT),
				'entity_id' => $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				'deleted_at' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			]);
		$qb->executeStatement();
	}

	public function unlinkSongEntity(int $songId, int $entityId, bool $hardDelete = false): void {
		$qb = $this->db->getQueryBuilder();
		if ($hardDelete) {
			$qb->delete(self::JUNCTION_TABLE_NAME)
				->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)));
		} else {
			$qb->update(self::JUNCTION_TABLE_NAME)
				->set('deleted_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->isNull('deleted_at'));
		}
		$qb->executeStatement();
	}

	/**
	 * @param list<int> $entityIds
	 */
	public function setSongEntitiesForType(int $songId, string $type, array $entityIds): void {
		$currentEntities = $this->findEntitiesForSong($songId, $type);
		$currentIds = array_values(array_filter(array_map(fn (InternalEntity $e) => $e->id, $currentEntities), fn ($id) => $id !== null));

		$toAdd = array_diff($entityIds, $currentIds);
		$toRemove = array_diff($currentIds, $entityIds);

		foreach ($toAdd as $id) {
			$this->linkSongEntity($songId, (int)$id);
		}
		foreach ($toRemove as $id) {
			$this->unlinkSongEntity($songId, (int)$id);
		}
	}

	/**
	 * Merge duplicate entities into keepId. All songs linked to duplicate entities
	 * are re-linked to keepId, and duplicates are marked deleted.
	 *
	 * @param list<int> $duplicateIds
	 */
	public function mergeEntities(int $libraryId, string $type, int $keepId, array $duplicateIds): int {
		$mergedCount = 0;
		$now = time();
		$affectedSongIds = [];
		foreach ($duplicateIds as $dupId) {
			if ($dupId === $keepId) {
				continue;
			}

			// Find all songs linked to dupId
			$songIds = $this->findSongIdsForEntity($dupId);
			foreach ($songIds as $songId) {
				$this->linkSongEntity($songId, $keepId);
				$this->unlinkSongEntity($songId, $dupId);
				$affectedSongIds[$songId] = true;
			}

			// Delete duplicate entity
			$this->delete($dupId);
			$mergedCount++;
		}

		if ($mergedCount > 0) {
			$qbKeep = $this->db->getQueryBuilder();
			$qbKeep->update(self::TABLE_NAME)
				->set('updated_at', $qbKeep->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qbKeep->expr()->eq('id', $qbKeep->createNamedParameter($keepId, IQueryBuilder::PARAM_INT)));
			$qbKeep->executeStatement();

			if ($affectedSongIds !== []) {
				$qbSongs = $this->db->getQueryBuilder();
				$qbSongs->update('sheetmusic_songs')
					->set('updated_at', $qbSongs->createNamedParameter($now, IQueryBuilder::PARAM_INT))
					->where($qbSongs->expr()->in('id', $qbSongs->createNamedParameter(array_keys($affectedSongIds), IQueryBuilder::PARAM_INT_ARRAY)));
				$qbSongs->executeStatement();
			}
		}

		return $mergedCount;
	}

	/**
	 * Set the songs linked to an entity in a specific sequence.
	 *
	 * @param list<int> $orderedSongIds
	 */
	public function setEntitySongs(int $entityId, array $orderedSongIds): void {
		$currentSongIds = $this->findSongIdsForEntity($entityId);
		if ($currentSongIds === $orderedSongIds) {
			return;
		}

		// Hard delete existing junction rows for this entity
		$qbDel = $this->db->getQueryBuilder();
		$qbDel->delete(self::JUNCTION_TABLE_NAME)
			->where($qbDel->expr()->eq('entity_id', $qbDel->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)));
		$qbDel->executeStatement();

		$now = time();
		foreach ($orderedSongIds as $songId) {
			$qbIns = $this->db->getQueryBuilder();
			$qbIns->insert(self::JUNCTION_TABLE_NAME)
				->values([
					'song_id' => $qbIns->createNamedParameter($songId, IQueryBuilder::PARAM_INT),
					'entity_id' => $qbIns->createNamedParameter($entityId, IQueryBuilder::PARAM_INT),
					'created_at' => $qbIns->createNamedParameter($now, IQueryBuilder::PARAM_INT),
					'deleted_at' => $qbIns->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				]);
			$qbIns->executeStatement();
		}
	}

	/**
	 * @return list<SongEntityLink>
	 */
	public function findAllSongEntityLinksForLibrary(int $libraryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('se.id AS link_id', 'se.song_id', 'se.entity_id', 'e.type')
			->from(self::JUNCTION_TABLE_NAME, 'se')
			->innerJoin('se', self::TABLE_NAME, 'e', $qb->expr()->eq('se.entity_id', 'e.id'))
			->where($qb->expr()->eq('e.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('se.deleted_at'))
			->andWhere($qb->expr()->isNull('e.deleted_at'))
			->orderBy('se.entity_id', 'ASC')
			->addOrderBy('se.id', 'ASC');

		$result = $qb->executeQuery();
		$links = [];
		while ($row = $result->fetchAssociative()) {
			$links[] = SongEntityLink::fromRow($row);
		}
		$result->closeCursor();

		return $links;
	}

	public static function normalizeType(string $type): string {
		$t = strtolower(trim($type));
		return match ($t) {
			'composers' => 'composer',
			'books' => 'book',
			'collections' => 'collection',
			'artists' => 'artist',
			'genres' => 'genre',
			'keys' => 'key',
			'customgroups' => 'customgroup',
			'years' => 'year',
			'signatures' => 'signature',
			'sourcetypes' => 'sourcetype',
			'instruments' => 'instrument',
			default => $t,
		};
	}

	/**
	 * Returns a human-readable display label for an entity type.
	 */
	public static function getLabel(string $type, bool $plural = false): string {
		$norm = self::normalizeType($type);
		if ($plural) {
			return match ($norm) {
				'composer' => 'composers',
				'book' => 'books',
				'collection' => 'collections',
				'artist' => 'artists',
				'genre' => 'genres',
				'key' => 'keys',
				'customgroup' => 'custom groups',
				'year' => 'years',
				'signature' => 'time signatures',
				'sourcetype' => 'source types',
				'instrument' => 'instruments',
				default => $norm . 's',
			};
		}

		return match ($norm) {
			'customgroup' => 'custom group',
			'signature' => 'time signature',
			'sourcetype' => 'source type',
			default => $norm,
		};
	}

	/**
	 * Find active entity records with the same label (case-insensitive, trimmed) within the library.
	 *
	 * @return list<DuplicateEntityConflict>
	 */
	public function findDuplicateEntities(int $libraryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'type', 'name')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->orderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$grouped = [];
		while ($row = $result->fetchAssociative()) {
			$type = self::normalizeType((string)$row['type']);
			$cleanName = trim((string)$row['name']);
			if ($cleanName === '') {
				continue;
			}
			$key = mb_strtolower($cleanName);
			$grouped[$type][$key][] = [
				'id' => (int)$row['id'],
				'name' => (string)$row['name'],
			];
		}
		$result->closeCursor();

		$duplicates = [];
		foreach ($grouped as $type => $byName) {
			foreach ($byName as $entries) {
				if (count($entries) > 1) {
					$keepId = $entries[0]['id'];
					$duplicateIds = array_map(fn ($e) => $e['id'], array_slice($entries, 1));
					$duplicates[] = new DuplicateEntityConflict(
						entityType: $type,
						name: $entries[0]['name'],
						keepId: $keepId,
						duplicateIds: $duplicateIds,
					);
				}
			}
		}

		return $duplicates;
	}

	/**
	 * Find repeated links from songs to the same entity.
	 *
	 * @return list<DuplicateSongEntityAssignment>
	 */
	public function findDuplicateSongEntityAssignments(int $libraryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('se.song_id', 'se.entity_id', 'e.type')
			->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from(self::JUNCTION_TABLE_NAME, 'se')
			->innerJoin('se', self::TABLE_NAME, 'e', $qb->expr()->eq('se.entity_id', 'e.id'))
			->where($qb->expr()->eq('e.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('se.deleted_at'))
			->andWhere($qb->expr()->isNull('e.deleted_at'))
			->groupBy('se.song_id', 'se.entity_id', 'e.type')
			->having($qb->expr()->gt($qb->createFunction('COUNT(*)'), $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$duplicates = [];
		while ($row = $result->fetchAssociative()) {
			$duplicates[] = new DuplicateSongEntityAssignment(
				songId: (int)$row['song_id'],
				entityType: self::normalizeType((string)$row['type']),
				entityId: (int)$row['entity_id'],
				count: (int)$row['cnt'],
			);
		}
		$result->closeCursor();

		return $duplicates;
	}

	/**
	 * Remove repeated song-to-entity links while preserving one row per pair.
	 *
	 * @return int number of removed relation rows
	 */
	public function removeDuplicateSongEntityAssignments(int $libraryId, ?int $songId = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('se.song_id', 'se.entity_id')
			->selectAlias($qb->createFunction('MIN(' . $qb->getColumnName('se.id') . ')'), 'min_id')
			->from(self::JUNCTION_TABLE_NAME, 'se')
			->innerJoin('se', self::TABLE_NAME, 'e', $qb->expr()->eq('se.entity_id', 'e.id'))
			->where($qb->expr()->eq('e.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('se.deleted_at'))
			->andWhere($qb->expr()->isNull('e.deleted_at'));

		if ($songId !== null) {
			$qb->andWhere($qb->expr()->eq('se.song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)));
		}

		$qb->groupBy('se.song_id', 'se.entity_id')
			->having($qb->expr()->gt($qb->createFunction('COUNT(*)'), $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

		$duplicates = $qb->executeQuery()->fetchAllAssociative();
		$removed = 0;

		foreach ($duplicates as $dup) {
			$sId = (int)$dup['song_id'];
			$eId = (int)$dup['entity_id'];
			$minId = (int)$dup['min_id'];

			$qbDel = $this->db->getQueryBuilder();
			$qbDel->delete(self::JUNCTION_TABLE_NAME)
				->where($qbDel->expr()->eq('song_id', $qbDel->createNamedParameter($sId, IQueryBuilder::PARAM_INT)))
				->andWhere($qbDel->expr()->eq('entity_id', $qbDel->createNamedParameter($eId, IQueryBuilder::PARAM_INT)))
				->andWhere($qbDel->expr()->neq('id', $qbDel->createNamedParameter($minId, IQueryBuilder::PARAM_INT)));

			$removed += $qbDel->executeStatement();
		}

		return $removed;
	}

	/**
	 * Find junction records linking to missing or deleted songs/entities, or across libraries.
	 *
	 * @return list<OrphanSongEntityAssignment>
	 */
	public function findOrphanSongEntityAssignments(int $libraryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('se.id', 'se.song_id', 'se.entity_id', 's.id as valid_song_id', 'e.id as valid_entity_id', 's.library_id as song_lib', 'e.library_id as ent_lib')
			->from(self::JUNCTION_TABLE_NAME, 'se')
			->leftJoin('se', 'sheetmusic_songs', 's', $qb->expr()->andX(
				$qb->expr()->eq('se.song_id', 's.id'),
				$qb->expr()->isNull('s.deleted_at')
			))
			->leftJoin('se', self::TABLE_NAME, 'e', $qb->expr()->andX(
				$qb->expr()->eq('se.entity_id', 'e.id'),
				$qb->expr()->isNull('e.deleted_at')
			))
			->where($qb->expr()->isNull('se.deleted_at'))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('s.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)),
				$qb->expr()->eq('e.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT))
			))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('s.id'),
				$qb->expr()->isNull('e.id'),
				$qb->expr()->neq('s.library_id', 'e.library_id')
			));

		$result = $qb->executeQuery();
		$orphans = [];
		while ($row = $result->fetchAssociative()) {
			$reason = 'Unknown orphan reference';
			if ($row['valid_song_id'] === null && $row['valid_entity_id'] === null) {
				$reason = 'Song and entity both deleted or missing';
			} elseif ($row['valid_song_id'] === null) {
				$reason = 'Referenced song is deleted or missing';
			} elseif ($row['valid_entity_id'] === null) {
				$reason = 'Referenced entity is deleted or missing';
			} elseif ((int)$row['song_lib'] !== (int)$row['ent_lib']) {
				$reason = 'Song and entity belong to different libraries';
			}

			$orphans[] = new OrphanSongEntityAssignment(
				id: (int)$row['id'],
				songId: (int)$row['song_id'],
				entityId: (int)$row['entity_id'],
				reason: $reason,
			);
		}
		$result->closeCursor();

		return $orphans;
	}

	/**
	 * Remove junction records that point to non-existent/deleted songs or entities.
	 *
	 * @return int number of removed rows
	 */
	public function removeOrphanSongEntityAssignments(int $libraryId): int {
		$orphans = $this->findOrphanSongEntityAssignments($libraryId);
		if ($orphans === []) {
			return 0;
		}

		$ids = array_column($orphans, 'id');
		$qbDel = $this->db->getQueryBuilder();
		$qbDel->delete(self::JUNCTION_TABLE_NAME)
			->where($qbDel->expr()->in('id', $qbDel->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		return $qbDel->executeStatement();
	}

	/**
	 * Find entities of the same type sharing the same client_id in the library.
	 *
	 * @return list<DuplicateEntityClientIdConflict>
	 */
	public function findDuplicateClientIds(int $libraryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'type', 'client_id', 'name')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->isNotNull('client_id'))
			->andWhere($qb->expr()->gt('client_id', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$grouped = [];
		while ($row = $result->fetchAssociative()) {
			$normType = self::normalizeType((string)$row['type']);
			$cId = (int)$row['client_id'];
			$grouped[$normType][$cId][] = [
				'id' => (int)$row['id'],
				'name' => (string)$row['name'],
			];
		}
		$result->closeCursor();

		$conflicts = [];
		foreach ($grouped as $type => $byClient) {
			foreach ($byClient as $cId => $entries) {
				if (count($entries) > 1) {
					$conflicts[] = new DuplicateEntityClientIdConflict(
						type: $type,
						clientId: $cId,
						entityIds: array_column($entries, 'id'),
						names: array_column($entries, 'name'),
					);
				}
			}
		}

		return $conflicts;
	}

	/**
	 * Re-assign unique client_ids to entities that have conflicting duplicates.
	 *
	 * @return int number of reassigned entities
	 */
	public function fixDuplicateClientIds(int $libraryId): int {
		$conflicts = $this->findDuplicateClientIds($libraryId);
		if ($conflicts === []) {
			return 0;
		}

		$qbMax = $this->db->getQueryBuilder();
		$qbMax->select($qbMax->createFunction('MAX(' . $qbMax->getColumnName('client_id') . ')'))
			->from(self::TABLE_NAME)
			->where($qbMax->expr()->eq('library_id', $qbMax->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));
		$maxId = (int)$qbMax->executeQuery()->fetchOne();
		if ($maxId < 1) {
			$maxId = 1;
		}

		$reassigned = 0;
		foreach ($conflicts as $conflict) {
			$duplicates = array_slice($conflict->entityIds, 1);
			foreach ($duplicates as $entId) {
				$maxId++;
				$qbUp = $this->db->getQueryBuilder();
				$qbUp->update(self::TABLE_NAME)
					->set('client_id', $qbUp->createNamedParameter($maxId, IQueryBuilder::PARAM_INT))
					->set('updated_at', $qbUp->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
					->where($qbUp->expr()->eq('id', $qbUp->createNamedParameter($entId, IQueryBuilder::PARAM_INT)));
				$reassigned += $qbUp->executeStatement();
			}
		}

		return $reassigned;
	}
}
