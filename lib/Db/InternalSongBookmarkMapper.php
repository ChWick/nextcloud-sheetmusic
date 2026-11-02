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
	 * @param list<array{id?: int|null, name?: string, pageNum?: int, page_num?: int, endPage?: int|null, end_page?: int|null, position?: int, showInLibrary?: int, show_in_library?: int}> $bookmarks
	 */
	public function setBookmarksForSong(int $songId, array $bookmarks): void {
		$existing = $this->findForSong($songId);
		$existingById = [];
		foreach ($existing as $bm) {
			if ($bm->id !== null) {
				$existingById[$bm->id] = $bm;
			}
		}

		$now = time();
		$position = 0;
		$keptIds = [];

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

			$id = isset($bm['id']) && is_numeric($bm['id']) ? (int)$bm['id'] : null;
			if ($id === null) {
				foreach ($existingById as $existId => $existBm) {
					if (!isset($keptIds[$existId]) && $existBm->name === $name) {
						$id = $existId;
						break;
					}
				}
			}

			if ($id !== null && isset($existingById[$id])) {
				$existingBm = $existingById[$id];
				$existingBm->name = $name;
				$existingBm->pageNum = $pageNum;
				$existingBm->endPage = $endPage;
				$existingBm->position = $pos;
				$existingBm->showInLibrary = $showInLib;
				$existingBm->updatedAt = $now;
				$this->update($existingBm);
				$keptIds[$id] = true;
			} else {
				$newBm = new InternalSongBookmark(
					id: $id,
					songId: $songId,
					name: $name,
					pageNum: $pageNum,
					endPage: $endPage,
					position: $pos,
					showInLibrary: $showInLib,
					createdAt: $now,
					updatedAt: $now,
				);
				$this->insert($newBm);
				if ($newBm->id !== null) {
					$keptIds[$newBm->id] = true;
				}
			}
			$position++;
		}

		foreach ($existing as $bm) {
			if ($bm->id !== null && !isset($keptIds[$bm->id])) {
				$this->delete($bm->id);
			}
		}

		$touchQb = $this->db->getQueryBuilder();
		$touchQb->update('sheetmusic_songs')
			->set('updated_at', $touchQb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($touchQb->expr()->eq('id', $touchQb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function findById(int $id): ?InternalSongBookmark {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalSongBookmark::fromRow($row);
	}

	public function insert(InternalSongBookmark $bookmark): InternalSongBookmark {
		$now = time();
		$qb = $this->db->getQueryBuilder();
		$values = [
			'song_id' => $qb->createNamedParameter($bookmark->songId, IQueryBuilder::PARAM_INT),
			'name' => $qb->createNamedParameter($bookmark->name, IQueryBuilder::PARAM_STR),
			'page_num' => $qb->createNamedParameter($bookmark->pageNum, IQueryBuilder::PARAM_INT),
			'end_page' => $qb->createNamedParameter($bookmark->endPage, IQueryBuilder::PARAM_INT),
			'position' => $qb->createNamedParameter($bookmark->position, IQueryBuilder::PARAM_INT),
			'show_in_library' => $qb->createNamedParameter($bookmark->showInLibrary, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($bookmark->createdAt > 0 ? $bookmark->createdAt : $now, IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter($bookmark->updatedAt > 0 ? $bookmark->updatedAt : $now, IQueryBuilder::PARAM_INT),
		];
		if ($bookmark->id !== null && $bookmark->id > 0 && $this->findById($bookmark->id) === null) {
			$values['id'] = $qb->createNamedParameter($bookmark->id, IQueryBuilder::PARAM_INT);
		}

		$qb->insert(self::TABLE_NAME)->values($values);
		$qb->executeStatement();

		if ($bookmark->id === null || $bookmark->id <= 0 || !isset($values['id'])) {
			$bookmark->id = (int)$this->db->lastInsertId(self::TABLE_NAME);
		}
		$bookmark->createdAt = $bookmark->createdAt > 0 ? $bookmark->createdAt : $now;
		$bookmark->updatedAt = $bookmark->updatedAt > 0 ? $bookmark->updatedAt : $now;

		return $bookmark;
	}

	public function update(InternalSongBookmark $bookmark): void {
		$now = time();
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('name', $qb->createNamedParameter($bookmark->name, IQueryBuilder::PARAM_STR))
			->set('page_num', $qb->createNamedParameter($bookmark->pageNum, IQueryBuilder::PARAM_INT))
			->set('end_page', $qb->createNamedParameter($bookmark->endPage, IQueryBuilder::PARAM_INT))
			->set('position', $qb->createNamedParameter($bookmark->position, IQueryBuilder::PARAM_INT))
			->set('show_in_library', $qb->createNamedParameter($bookmark->showInLibrary, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($bookmark->updatedAt > 0 ? $bookmark->updatedAt : $now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($bookmark->id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
		$bookmark->updatedAt = $bookmark->updatedAt > 0 ? $bookmark->updatedAt : $now;
	}

	public function delete(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}
}
