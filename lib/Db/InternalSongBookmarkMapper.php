<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class InternalSongBookmarkMapper {
	public const TABLE_NAME = 'sheetmusic_bookmarks';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @return list<InternalSongBookmark>
	 */
	public function findForSong(int $songId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)))
			->orderBy('position', 'ASC')
			->addOrderBy('page_num', 'ASC')
			->addOrderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$bookmarks = [];
		while ($row = $result->fetchAssociative()) {
			$bookmarks[] = InternalSongBookmark::fromRow($row);
		}
		$result->closeCursor();

		return $bookmarks;
	}

	/**
	 * @param list<int> $songIds
	 * @return array<int, list<InternalSongBookmark>>
	 */
	public function findForSongs(array $songIds): array {
		if (empty($songIds)) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->in('song_id', $qb->createNamedParameter($songIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('song_id', 'ASC')
			->addOrderBy('position', 'ASC')
			->addOrderBy('page_num', 'ASC')
			->addOrderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$grouped = [];
		while ($row = $result->fetchAssociative()) {
			$bm = InternalSongBookmark::fromRow($row);
			$grouped[$bm->songId][] = $bm;
		}
		$result->closeCursor();

		return $grouped;
	}

	/**
	 * @return array<int, list<InternalSongBookmark>> song_id => list<InternalSongBookmark>
	 */
	public function findAllForLibrary(int $libraryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('b.*')
			->from(self::TABLE_NAME, 'b')
			->innerJoin('b', 'sheetmusic_songs', 's', $qb->expr()->eq('b.song_id', 's.id'))
			->where($qb->expr()->eq('s.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('s.deleted_at'))
			->orderBy('b.song_id', 'ASC')
			->addOrderBy('b.position', 'ASC')
			->addOrderBy('b.page_num', 'ASC')
			->addOrderBy('b.id', 'ASC');

		$result = $qb->executeQuery();
		$map = [];
		while ($row = $result->fetchAssociative()) {
			$bm = InternalSongBookmark::fromRow($row);
			$map[$bm->songId][] = $bm;
		}
		$result->closeCursor();

		return $map;
	}

	public function deleteForSong(int $songId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * @param list<array{name?: string, pageNum?: int, page_num?: int, endPage?: int|null, end_page?: int|null, position?: int, showInLibrary?: int, show_in_library?: int}> $bookmarks
	 */
	public function setBookmarksForSong(int $songId, array $bookmarks): void {
		$this->deleteForSong($songId);

		$now = time();
		$position = 0;

		foreach ($bookmarks as $bm) {
			$name = trim((string)($bm['name'] ?? ''));
			if ($name === '') {
				continue;
			}

			$pageNum = isset($bm['pageNum']) ? (int)$bm['pageNum'] : (isset($bm['page_num']) ? (int)$bm['page_num'] : 1);
			if ($pageNum < 1) {
				$pageNum = 1;
			}

			$endPage = null;
			if (isset($bm['endPage']) && $bm['endPage'] !== null && $bm['endPage'] !== '') {
				$endPage = (int)$bm['endPage'];
			} elseif (isset($bm['end_page']) && $bm['end_page'] !== null && $bm['end_page'] !== '') {
				$endPage = (int)$bm['end_page'];
			}

			if ($endPage !== null && $endPage < $pageNum) {
				$endPage = $pageNum;
			}

			$pos = isset($bm['position']) ? (int)$bm['position'] : $position;
			$showInLib = isset($bm['showInLibrary']) ? (int)$bm['showInLibrary'] : (isset($bm['show_in_library']) ? (int)$bm['show_in_library'] : 1);

			$qb = $this->db->getQueryBuilder();
			$qb->insert(self::TABLE_NAME)
				->values([
					'song_id' => $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT),
					'name' => $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR),
					'page_num' => $qb->createNamedParameter($pageNum, IQueryBuilder::PARAM_INT),
					'end_page' => $qb->createNamedParameter($endPage, IQueryBuilder::PARAM_INT),
					'position' => $qb->createNamedParameter($pos, IQueryBuilder::PARAM_INT),
					'show_in_library' => $qb->createNamedParameter($showInLib, IQueryBuilder::PARAM_INT),
					'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
					'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				]);

			$qb->executeStatement();
			$position++;
		}

		$touchQb = $this->db->getQueryBuilder();
		$touchQb->update('sheetmusic_songs')
			->set('updated_at', $touchQb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($touchQb->expr()->eq('id', $touchQb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
