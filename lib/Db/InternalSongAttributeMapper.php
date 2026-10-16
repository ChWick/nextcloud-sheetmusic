<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class InternalSongAttributeMapper {
	public const TABLE_NAME = 'sheetmusic_song_attributes';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @return array<string, string> name => value
	 */
	public function findAllForSong(int $songId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('name', 'value')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$attributes = [];
		while ($row = $result->fetchAssociative()) {
			$attributes[(string)$row['name']] = (string)$row['value'];
		}
		$result->closeCursor();

		return $attributes;
	}

	public function findForSong(int $songId, string $name): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('value')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return (string)$row['value'];
	}

	public function setAttribute(int $songId, string $name, string $value): void {
		$now = time();
		$existing = $this->findForSong($songId, $name);

		$qb = $this->db->getQueryBuilder();
		if ($existing !== null) {
			$qb->update(self::TABLE_NAME)
				->set('value', $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR)));
		} else {
			$qb->insert(self::TABLE_NAME)
				->values([
					'song_id' => $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT),
					'name' => $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR),
					'value' => $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR),
					'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
					'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				]);
		}

		$qb->executeStatement();
	}

	public function removeAttribute(int $songId, string $name): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR)));

		return $qb->executeStatement() > 0;
	}

	public function removeAllForSong(int $songId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * @return array<int, array<string, string>> song_id => [name => value]
	 */
	public function findAllForLibrary(int $libraryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('a.song_id', 'a.name', 'a.value')
			->from(self::TABLE_NAME, 'a')
			->innerJoin('a', 'sheetmusic_songs', 's', $qb->expr()->eq('a.song_id', 's.id'))
			->where($qb->expr()->eq('s.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$map = [];
		while ($row = $result->fetchAssociative()) {
			$songId = (int)$row['song_id'];
			if (!isset($map[$songId])) {
				$map[$songId] = [];
			}
			$map[$songId][(string)$row['name']] = (string)$row['value'];
		}
		$result->closeCursor();

		return $map;
	}

	/**
	 * Find attribute records for songs that are deleted or do not exist in the library.
	 *
	 * @return list<int> list of attribute IDs
	 */
	public function findOrphanSongAttributes(int $libraryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('a.id')
			->from(self::TABLE_NAME, 'a')
			->innerJoin('a', 'sheetmusic_songs', 's', $qb->expr()->eq('a.song_id', 's.id'))
			->where($qb->expr()->eq('s.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('s.deleted_at'));

		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetchAssociative()) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * Remove orphan attributes belonging to deleted songs.
	 *
	 * @return int number of removed rows
	 */
	public function removeOrphanSongAttributes(int $libraryId): int {
		$ids = $this->findOrphanSongAttributes($libraryId);
		if ($ids === []) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		return $qb->executeStatement();
	}
}
