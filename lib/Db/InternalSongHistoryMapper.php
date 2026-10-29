<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class InternalSongHistoryMapper {
	public const TABLE_NAME = 'sheetmusic_song_history';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function findById(int $id): ?InternalSongHistory {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalSongHistory::fromRow($row);
	}

	/**
	 * @return list<InternalSongHistory>
	 */
	public function findForSong(int $songId, int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$entries = [];
		while ($row = $result->fetchAssociative()) {
			$entries[] = InternalSongHistory::fromRow($row);
		}

		return $entries;
	}

	/**
	 * @return list<InternalSongHistory>
	 */
	public function findForSongUuid(string $songUuid, int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('song_uuid', $qb->createNamedParameter($songUuid, IQueryBuilder::PARAM_STR)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$entries = [];
		while ($row = $result->fetchAssociative()) {
			$entries[] = InternalSongHistory::fromRow($row);
		}

		return $entries;
	}

	public function findLatestForSong(int $songId): ?InternalSongHistory {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalSongHistory::fromRow($row);
	}

	public function insert(InternalSongHistory $history): InternalSongHistory {
		$qb = $this->db->getQueryBuilder();
		$now = $history->createdAt ?: time();
		$snapshotJson = is_string($history->snapshot) ? $history->snapshot : json_encode($history->snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		$qb->insert(self::TABLE_NAME)
			->values([
				'library_id' => $qb->createNamedParameter($history->libraryId, IQueryBuilder::PARAM_INT),
				'song_id' => $qb->createNamedParameter($history->songId, IQueryBuilder::PARAM_INT),
				'song_uuid' => $qb->createNamedParameter($history->songUuid, IQueryBuilder::PARAM_STR),
				'user_id' => $qb->createNamedParameter($history->userId, IQueryBuilder::PARAM_STR),
				'action' => $qb->createNamedParameter($history->action, IQueryBuilder::PARAM_STR),
				'summary' => $qb->createNamedParameter($history->summary, IQueryBuilder::PARAM_STR),
				'snapshot' => $qb->createNamedParameter($snapshotJson, IQueryBuilder::PARAM_STR),
				'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			]);

		$qb->executeStatement();
		$history->id = (int)$this->db->lastInsertId(self::TABLE_NAME);
		$history->createdAt = $now;

		return $history;
	}

	public function pruneOldEntries(int $songId, int $keep = 30): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setFirstResult($keep)
			->setMaxResults(100);

		$result = $qb->executeQuery();
		$idsToDelete = [];
		while ($row = $result->fetchAssociative()) {
			$idsToDelete[] = (int)$row['id'];
		}

		if (!empty($idsToDelete)) {
			$deleteQb = $this->db->getQueryBuilder();
			$deleteQb->delete(self::TABLE_NAME)
				->where($deleteQb->expr()->in('id', $deleteQb->createNamedParameter($idsToDelete, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}

	public function deleteForSong(int $songId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function deleteForLibrary(int $libraryId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
