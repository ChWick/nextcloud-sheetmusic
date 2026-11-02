<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class InternalSetlistItemMapper {
	public const TABLE_NAME = 'sheetmusic_setlist_items';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function findById(int $id): ?InternalSetlistItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.*', 's.title AS song_title', 's.duration', 'b.name AS bookmark_name', 'b.page_num', 'b.end_page')
			->from(self::TABLE_NAME, 'i')
			->leftJoin('i', 'sheetmusic_songs', 's', $qb->expr()->eq('i.song_id', 's.id'))
			->leftJoin('i', 'sheetmusic_bookmarks', 'b', $qb->expr()->eq('i.bookmark_id', 'b.id'))
			->where($qb->expr()->eq('i.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalSetlistItem::fromRow($row);
	}

	/**
	 * @return list<InternalSetlistItem>
	 */
	public function findForSetlist(int $setlistId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.*', 's.title AS song_title', 's.duration', 'b.name AS bookmark_name', 'b.page_num', 'b.end_page')
			->from(self::TABLE_NAME, 'i')
			->leftJoin('i', 'sheetmusic_songs', 's', $qb->expr()->eq('i.song_id', 's.id'))
			->leftJoin('i', 'sheetmusic_bookmarks', 'b', $qb->expr()->eq('i.bookmark_id', 'b.id'))
			->where($qb->expr()->eq('i.setlist_id', $qb->createNamedParameter($setlistId, IQueryBuilder::PARAM_INT)))
			->orderBy('i.position', 'ASC')
			->addOrderBy('i.id', 'ASC');

		$result = $qb->executeQuery();
		$items = [];
		while ($row = $result->fetchAssociative()) {
			$items[] = InternalSetlistItem::fromRow($row);
		}
		$result->closeCursor();

		return $items;
	}

	/**
	 * @param list<int> $setlistIds
	 * @return array<int, list<InternalSetlistItem>>
	 */
	public function findForSetlists(array $setlistIds): array {
		if (empty($setlistIds)) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('i.*', 's.title AS song_title', 's.duration', 'b.name AS bookmark_name', 'b.page_num', 'b.end_page')
			->from(self::TABLE_NAME, 'i')
			->leftJoin('i', 'sheetmusic_songs', 's', $qb->expr()->eq('i.song_id', 's.id'))
			->leftJoin('i', 'sheetmusic_bookmarks', 'b', $qb->expr()->eq('i.bookmark_id', 'b.id'))
			->where($qb->expr()->in('i.setlist_id', $qb->createNamedParameter($setlistIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('i.setlist_id', 'ASC')
			->addOrderBy('i.position', 'ASC')
			->addOrderBy('i.id', 'ASC');

		$result = $qb->executeQuery();
		$grouped = [];
		while ($row = $result->fetchAssociative()) {
			$item = InternalSetlistItem::fromRow($row);
			$grouped[$item->setlistId][] = $item;
		}
		$result->closeCursor();

		return $grouped;
	}

	/**
	 * @param list<int> $setlistIds
	 * @return array<int, int> setlistId => count
	 */
	public function getItemCountForSetlists(array $setlistIds): array {
		if (empty($setlistIds)) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('setlist_id')
			->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
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

	public function insert(InternalSetlistItem $item): InternalSetlistItem {
		$time = time();
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE_NAME)
			->values([
				'setlist_id' => $qb->createNamedParameter($item->setlistId, IQueryBuilder::PARAM_INT),
				'song_id' => $qb->createNamedParameter($item->songId, IQueryBuilder::PARAM_INT),
				'bookmark_id' => $qb->createNamedParameter($item->bookmarkId, IQueryBuilder::PARAM_INT),
				'position' => $qb->createNamedParameter($item->position, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter($item->createdAt > 0 ? $item->createdAt : $time, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($item->updatedAt > 0 ? $item->updatedAt : $time, IQueryBuilder::PARAM_INT),
			]);

		$qb->executeStatement();
		$item->id = (int)$this->db->lastInsertId(self::TABLE_NAME);
		$item->createdAt = $item->createdAt > 0 ? $item->createdAt : $time;
		$item->updatedAt = $item->updatedAt > 0 ? $item->updatedAt : $time;

		return $item;
	}

	public function update(InternalSetlistItem $item): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('song_id', $qb->createNamedParameter($item->songId, IQueryBuilder::PARAM_INT))
			->set('bookmark_id', $qb->createNamedParameter($item->bookmarkId, IQueryBuilder::PARAM_INT))
			->set('position', $qb->createNamedParameter($item->position, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($item->id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
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

	public function deleteForSong(int $songId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	public function nullifyBookmark(int $bookmarkId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('bookmark_id', $qb->createNamedParameter(null))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('bookmark_id', $qb->createNamedParameter($bookmarkId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Reorder items in a setlist.
	 *
	 * @param list<int> $itemIds Ordered list of item IDs
	 */
	public function reorder(int $setlistId, array $itemIds): void {
		$now = time();
		foreach ($itemIds as $index => $itemId) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABLE_NAME)
				->set('position', $qb->createNamedParameter($index, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('setlist_id', $qb->createNamedParameter($setlistId, IQueryBuilder::PARAM_INT)));

			$qb->executeStatement();
		}
	}

	public function getNextPosition(int $setlistId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('MAX(' . $qb->getColumnName('position') . ')'), 'max_pos')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('setlist_id', $qb->createNamedParameter($setlistId, IQueryBuilder::PARAM_INT)));

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false || $row['max_pos'] === null) {
			return 0;
		}

		return ((int)$row['max_pos']) + 1;
	}
}
