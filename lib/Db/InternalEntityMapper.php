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

		$entity->updatedAt = time();

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
		$qb = $this->db->getQueryBuilder();

		if ($hardDelete) {
			// Unlink all songs
			$unlinkQb = $this->db->getQueryBuilder();
			$unlinkQb->delete(self::JUNCTION_TABLE_NAME)
				->where($unlinkQb->expr()->eq('entity_id', $unlinkQb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$unlinkQb->executeStatement();

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
			->andWhere($qb->expr()->isNull('deleted_at'));

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
		foreach ($duplicateIds as $dupId) {
			if ($dupId === $keepId) {
				continue;
			}

			// Find all songs linked to dupId
			$songIds = $this->findSongIdsForEntity($dupId);
			foreach ($songIds as $songId) {
				$this->linkSongEntity($songId, $keepId);
				$this->unlinkSongEntity($songId, $dupId);
			}

			// Delete duplicate entity
			$this->delete($dupId);
			$mergedCount++;
		}

		return $mergedCount;
	}

	/**
	 * @return list<array{song_id: int, entity_id: int, type: string}>
	 */
	public function findAllSongEntityLinksForLibrary(int $libraryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('se.song_id', 'se.entity_id', 'e.type')
			->from(self::JUNCTION_TABLE_NAME, 'se')
			->innerJoin('se', self::TABLE_NAME, 'e', $qb->expr()->eq('se.entity_id', 'e.id'))
			->where($qb->expr()->eq('e.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('se.deleted_at'))
			->andWhere($qb->expr()->isNull('e.deleted_at'));

		$result = $qb->executeQuery();
		$links = [];
		while ($row = $result->fetchAssociative()) {
			$links[] = [
				'song_id' => (int)$row['song_id'],
				'entity_id' => (int)$row['entity_id'],
				'type' => (string)$row['type'],
			];
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
	 * Find active entity records with the same label (case-insensitive, trimmed) within the library.
	 *
	 * @return list<array{entityType: string, name: string, keepId: int, duplicateIds: list<int>}>
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
					$duplicates[] = [
						'entityType' => $type,
						'name' => $entries[0]['name'],
						'keepId' => $keepId,
						'duplicateIds' => $duplicateIds,
					];
				}
			}
		}

		return $duplicates;
	}

	/**
	 * Find repeated links from songs to the same entity.
	 *
	 * @return list<array{songId: int, entityType: string, entityId: int, count: int}>
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
			$duplicates[] = [
				'songId' => (int)$row['song_id'],
				'entityType' => self::normalizeType((string)$row['type']),
				'entityId' => (int)$row['entity_id'],
				'count' => (int)$row['cnt'],
			];
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
		$qb->select('se.song_id', 'se.entity_id', $qb->expr()->min('se.id', 'min_id'))
			->addSelect($qb->expr()->count('se.id', 'cnt'))
			->from(self::JUNCTION_TABLE_NAME, 'se')
			->innerJoin('se', self::TABLE_NAME, 'e', $qb->expr()->eq('se.entity_id', 'e.id'))
			->where($qb->expr()->eq('e.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('se.deleted_at'))
			->andWhere($qb->expr()->isNull('e.deleted_at'));

		if ($songId !== null) {
			$qb->andWhere($qb->expr()->eq('se.song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)));
		}

		$qb->groupBy('se.song_id', 'se.entity_id')
			->having($qb->expr()->gt('cnt', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

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
}
