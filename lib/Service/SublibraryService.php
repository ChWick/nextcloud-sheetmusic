<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalSublibrary;
use OCA\SheetMusic\Db\SublibraryMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class SublibraryService {
	public function __construct(
		private SublibraryMapper $sublibraryMapper,
		private InternalEntityMapper $entityMapper,
		private IDBConnection $db,
	) {
	}

	public function getSublibrary(int $id): ?InternalSublibrary {
		return $this->sublibraryMapper->findById($id);
	}

	public function getSublibraryBySlug(int $libraryId, string $slug): ?InternalSublibrary {
		return $this->sublibraryMapper->findBySlug($libraryId, $slug);
	}

	/**
	 * @return list<InternalSublibrary>
	 */
	public function getSublibraries(int $libraryId): array {
		return $this->sublibraryMapper->findAllForLibrary($libraryId);
	}

	/**
	 * @param array<string, list<string>|string> $filters
	 */
	public function createSublibrary(int $libraryId, string $name, string $slug, array $filters = []): InternalSublibrary {
		$cleanSlug = $this->sanitizeSlug($slug !== '' ? $slug : $name);
		$sublibrary = new InternalSublibrary(
			libraryId: $libraryId,
			name: $name,
			slug: $cleanSlug,
			filters: $filters,
		);

		return $this->sublibraryMapper->insert($sublibrary);
	}

	/**
	 * @param array<string, list<string>|string> $filters
	 */
	public function updateSublibrary(int $id, string $name, string $slug, array $filters = []): InternalSublibrary {
		$sublibrary = $this->sublibraryMapper->findById($id);
		if ($sublibrary === null) {
			throw new \InvalidArgumentException("Sublibrary not found with ID: {$id}");
		}

		$cleanSlug = $this->sanitizeSlug($slug !== '' ? $slug : $name);
		$sublibrary->name = $name;
		$sublibrary->slug = $cleanSlug;
		$sublibrary->filters = $filters;

		return $this->sublibraryMapper->update($sublibrary);
	}

	public function deleteSublibrary(int $id): bool {
		return $this->sublibraryMapper->delete($id);
	}

	/**
	 * Get map of songId => true for all songs in the library matching the sublibrary's filters.
	 *
	 * @return array<int, bool>
	 */
	public function getMatchingSongIds(InternalSublibrary $sublibrary): array {
		$filters = $sublibrary->filters;
		if (empty($filters)) {
			// No filters: all non-deleted songs in the library match
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')
				->from('sheetmusic_songs')
				->where($qb->expr()->eq('library_id', $qb->createNamedParameter($sublibrary->libraryId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->isNull('deleted_at'));

			$res = $qb->executeQuery();
			$matches = [];
			while ($row = $res->fetchAssociative()) {
				$matches[(int)$row['id']] = true;
			}
			$res->closeCursor();

			return $matches;
		}

		$matchingSongIds = null;

		foreach ($filters as $type => $values) {
			$type = InternalEntityMapper::normalizeType((string)$type);
			$allowedValues = is_array($values) ? array_values($values) : [$values];
			$cleanValues = array_values(array_filter(array_map(static fn ($v) => trim((string)$v), $allowedValues), static fn ($v) => $v !== ''));

			if (empty($cleanValues)) {
				continue;
			}

			// Find entity IDs matching these names
			$qbEntities = $this->db->getQueryBuilder();
			$qbEntities->select('id')
				->from(InternalEntityMapper::TABLE_NAME)
				->where($qbEntities->expr()->eq('library_id', $qbEntities->createNamedParameter($sublibrary->libraryId, IQueryBuilder::PARAM_INT)))
				->andWhere($qbEntities->expr()->eq('type', $qbEntities->createNamedParameter($type, IQueryBuilder::PARAM_STR)))
				->andWhere($qbEntities->expr()->in('name', $qbEntities->createNamedParameter($cleanValues, IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($qbEntities->expr()->isNull('deleted_at'));

			$entityRes = $qbEntities->executeQuery();
			$entityIds = [];
			while ($row = $entityRes->fetchAssociative()) {
				$entityIds[] = (int)$row['id'];
			}
			$entityRes->closeCursor();

			if (empty($entityIds)) {
				// No entities match this required filter in this library, so 0 songs match
				return [];
			}

			// Find all active song IDs linked to these entities
			$qbSongs = $this->db->getQueryBuilder();
			$qbSongs->select('se.song_id')
				->from(InternalEntityMapper::JUNCTION_TABLE_NAME, 'se')
				->innerJoin('se', 'sheetmusic_songs', 's', $qbSongs->expr()->eq('se.song_id', 's.id'))
				->where($qbSongs->expr()->in('se.entity_id', $qbSongs->createNamedParameter($entityIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qbSongs->expr()->eq('s.library_id', $qbSongs->createNamedParameter($sublibrary->libraryId, IQueryBuilder::PARAM_INT)))
				->andWhere($qbSongs->expr()->isNull('se.deleted_at'))
				->andWhere($qbSongs->expr()->isNull('s.deleted_at'));

			$songRes = $qbSongs->executeQuery();
			$currentTypeMatches = [];
			while ($row = $songRes->fetchAssociative()) {
				$currentTypeMatches[(int)$row['song_id']] = true;
			}
			$songRes->closeCursor();

			if ($matchingSongIds === null) {
				$matchingSongIds = $currentTypeMatches;
			} else {
				$matchingSongIds = array_intersect_key($matchingSongIds, $currentTypeMatches);
			}

			if (empty($matchingSongIds)) {
				return [];
			}
		}

		return $matchingSongIds ?? [];
	}

	/**
	 * Check whether a single song matches the sublibrary filters.
	 */
	public function matchesSong(InternalSublibrary $sublibrary, int $songId): bool {
		$filters = $sublibrary->filters;
		if (empty($filters)) {
			return true;
		}

		foreach ($filters as $type => $values) {
			$type = InternalEntityMapper::normalizeType((string)$type);
			$allowedValues = is_array($values) ? array_values($values) : [$values];
			$cleanValues = array_map(static fn ($v) => mb_strtolower(trim((string)$v)), $allowedValues);

			if (empty($cleanValues)) {
				continue;
			}

			$entities = $this->entityMapper->findEntitiesForSong($songId, $type);
			$found = false;
			foreach ($entities as $entity) {
				if (in_array(mb_strtolower(trim($entity->name)), $cleanValues, true)) {
					$found = true;
					break;
				}
			}

			if (!$found) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Enforce sublibrary invariant on an incoming/updated song ("Prevent Dropping").
	 * If the song does not currently satisfy any filter criteria, inject the first
	 * specified filter value so it remains in the sublibrary.
	 *
	 * @return bool True if any attributes were injected/linked
	 */
	public function ensureSongMatchesSublibrary(InternalSublibrary $sublibrary, int $songId): bool {
		$filters = $sublibrary->filters;
		if (empty($filters)) {
			return false;
		}

		$modified = false;

		foreach ($filters as $type => $values) {
			$type = InternalEntityMapper::normalizeType((string)$type);
			$allowedValues = is_array($values) ? array_values($values) : [$values];
			$cleanValues = array_values(array_filter(array_map(static fn ($v) => trim((string)$v), $allowedValues), static fn ($v) => $v !== ''));

			if (empty($cleanValues)) {
				continue;
			}

			// Check if already matches this filter
			$entities = $this->entityMapper->findEntitiesForSong($songId, $type);
			$alreadyMatches = false;
			$lowerAllowed = array_map(static fn ($v) => mb_strtolower($v), $cleanValues);

			foreach ($entities as $entity) {
				if (in_array(mb_strtolower(trim($entity->name)), $lowerAllowed, true)) {
					$alreadyMatches = true;
					break;
				}
			}

			if (!$alreadyMatches) {
				// Inject the primary (first) value
				$targetName = $cleanValues[0];
				$entity = $this->entityMapper->findByName($sublibrary->libraryId, $type, $targetName);
				if ($entity === null) {
					$entity = new InternalEntity(
						uuid: InternalEntity::generateUuid(),
						libraryId: $sublibrary->libraryId,
						type: $type,
						name: $targetName,
					);
					$entity = $this->entityMapper->insert($entity);
				}

				if ($entity->id !== null) {
					$this->entityMapper->linkSongEntity($songId, $entity->id);
					$modified = true;
				}
			}
		}

		return $modified;
	}

	/**
	 * Remove sublibrary filter attributes from a song (used when song is deleted on client).
	 */
	public function removeSublibraryAttributes(InternalSublibrary $sublibrary, int $songId): void {
		$filters = $sublibrary->filters;
		if (empty($filters)) {
			return;
		}

		foreach ($filters as $type => $values) {
			$type = InternalEntityMapper::normalizeType((string)$type);
			$allowedValues = is_array($values) ? array_values($values) : [$values];
			$cleanValues = array_map(static fn ($v) => mb_strtolower(trim((string)$v)), $allowedValues);

			$entities = $this->entityMapper->findEntitiesForSong($songId, $type);
			foreach ($entities as $entity) {
				if ($entity->id !== null && in_array(mb_strtolower(trim($entity->name)), $cleanValues, true)) {
					$this->entityMapper->unlinkSongEntity($songId, $entity->id);
				}
			}
		}
	}

	private function sanitizeSlug(string $name): string {
		$slug = mb_strtolower(trim($name));
		$slug = (string)preg_replace('/[^a-z0-9_-]+/u', '-', $slug);
		$slug = trim($slug, '-');
		return $slug !== '' ? $slug : 'sublib-' . substr(md5($name), 0, 6);
	}
}
