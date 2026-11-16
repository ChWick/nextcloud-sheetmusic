<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class InternalSetlistFileMapper {
	public const TABLE_NAME = 'sheetmusic_setlist_files';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function findById(int $id): ?InternalSetlistFile {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalSetlistFile::fromRow($row);
	}

	/**
	 * @return list<InternalSetlistFile>
	 */
	public function findForSetlist(int $setlistId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('setlist_id', $qb->createNamedParameter($setlistId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$files = [];
		while ($row = $result->fetchAssociative()) {
			$files[] = InternalSetlistFile::fromRow($row);
		}
		$result->closeCursor();

		return $files;
	}

	/**
	 * @param list<int> $setlistIds
	 * @return array<int, int> map of setlist_id => file_count
	 */
	public function getFileCountForSetlists(array $setlistIds): array {
		if (empty($setlistIds)) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('setlist_id')
			->addSelect($qb->func()->count('id', 'cnt'))
			->from(self::TABLE_NAME)
			->where($qb->expr()->in('setlist_id', $qb->createNamedParameter($setlistIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->groupBy('setlist_id');

		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetchAssociative()) {
			$counts[(int)$row['setlist_id']] = (int)$row['cnt'];
		}
		$result->closeCursor();

		return $counts;
	}

	public function getNextSortOrder(int $setlistId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('sort_order', 'max_order'))
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('setlist_id', $qb->createNamedParameter($setlistId, IQueryBuilder::PARAM_INT)));

		$maxOrder = $qb->executeQuery()->fetchOne();
		return $maxOrder === null || $maxOrder === false ? 0 : ((int)$maxOrder + 1);
	}

	public function insert(InternalSetlistFile $file): InternalSetlistFile {
		$now = time();
		if ($file->createdAt <= 0) {
			$file->createdAt = $now;
		}
		if ($file->updatedAt <= 0) {
			$file->updatedAt = $now;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE_NAME)
			->values([
				'setlist_id' => $qb->createNamedParameter($file->setlistId, IQueryBuilder::PARAM_INT),
				'library_id' => $qb->createNamedParameter($file->libraryId, IQueryBuilder::PARAM_INT),
				'oc_file_id' => $qb->createNamedParameter($file->ocFileId, IQueryBuilder::PARAM_INT),
				'path' => $qb->createNamedParameter($file->path),
				'filename' => $qb->createNamedParameter($file->filename),
				'mime_type' => $qb->createNamedParameter($file->mimeType),
				'file_size' => $qb->createNamedParameter($file->fileSize, IQueryBuilder::PARAM_INT),
				'sort_order' => $qb->createNamedParameter($file->sortOrder, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter($file->createdAt, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($file->updatedAt, IQueryBuilder::PARAM_INT),
			]);
		$qb->executeStatement();

		$file->id = (int)$qb->getLastInsertId();
		return $file;
	}

	public function delete(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteForSetlist(int $setlistId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('setlist_id', $qb->createNamedParameter($setlistId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
