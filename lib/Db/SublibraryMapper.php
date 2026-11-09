<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class SublibraryMapper {
	public const TABLE_NAME = 'sheetmusic_sublibraries';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function findById(int $id): ?InternalSublibrary {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalSublibrary::fromRow($row);
	}

	public function findBySlug(int $libraryId, string $slug): ?InternalSublibrary {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('slug', $qb->createNamedParameter($slug, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalSublibrary::fromRow($row);
	}

	/**
	 * @return list<InternalSublibrary>
	 */
	public function findAllForLibrary(int $libraryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->orderBy('name', 'ASC');

		$result = $qb->executeQuery();
		$sublibraries = [];
		while ($row = $result->fetchAssociative()) {
			$sublibraries[] = InternalSublibrary::fromRow($row);
		}
		$result->closeCursor();

		return $sublibraries;
	}

	public function insert(InternalSublibrary $sublibrary): InternalSublibrary {
		$now = time();
		if ($sublibrary->createdAt === 0) {
			$sublibrary->createdAt = $now;
		}
		if ($sublibrary->updatedAt === 0) {
			$sublibrary->updatedAt = $now;
		}

		$filtersJson = json_encode($sublibrary->filters, JSON_THROW_ON_ERROR);

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE_NAME)
			->values([
				'library_id' => $qb->createNamedParameter($sublibrary->libraryId, IQueryBuilder::PARAM_INT),
				'name' => $qb->createNamedParameter($sublibrary->name, IQueryBuilder::PARAM_STR),
				'slug' => $qb->createNamedParameter($sublibrary->slug, IQueryBuilder::PARAM_STR),
				'filters_json' => $qb->createNamedParameter($filtersJson, IQueryBuilder::PARAM_STR),
				'created_at' => $qb->createNamedParameter($sublibrary->createdAt, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($sublibrary->updatedAt, IQueryBuilder::PARAM_INT),
			]);

		$qb->executeStatement();
		$sublibrary->id = (int)$this->db->lastInsertId(self::TABLE_NAME);

		return $sublibrary;
	}

	public function update(InternalSublibrary $sublibrary): InternalSublibrary {
		if ($sublibrary->id === null) {
			throw new \InvalidArgumentException('Cannot update a sublibrary without an ID');
		}

		$sublibrary->updatedAt = time();
		$filtersJson = json_encode($sublibrary->filters, JSON_THROW_ON_ERROR);

		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('name', $qb->createNamedParameter($sublibrary->name, IQueryBuilder::PARAM_STR))
			->set('slug', $qb->createNamedParameter($sublibrary->slug, IQueryBuilder::PARAM_STR))
			->set('filters_json', $qb->createNamedParameter($filtersJson, IQueryBuilder::PARAM_STR))
			->set('updated_at', $qb->createNamedParameter($sublibrary->updatedAt, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($sublibrary->id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();

		return $sublibrary;
	}

	public function delete(int $id): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() > 0;
	}
}
