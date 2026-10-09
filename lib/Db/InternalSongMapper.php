<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class InternalSongMapper {
	public const TABLE_NAME = 'sheetmusic_songs';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function findById(int $id): ?InternalSong {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalSong::fromRow($row);
	}

	public function findByUuid(string $uuid): ?InternalSong {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalSong::fromRow($row);
	}

	public function findByTitle(int $libraryId, string $title): ?InternalSong {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('title', $qb->createNamedParameter($title, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalSong::fromRow($row);
	}

	/**
	 * @return list<InternalSong>
	 */
	public function findAllForLibrary(int $libraryId, bool $includeDeleted = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));

		if (!$includeDeleted) {
			$qb->andWhere($qb->expr()->isNull('deleted_at'));
		}

		$qb->orderBy('title', 'ASC');

		$result = $qb->executeQuery();
		$songs = [];
		while ($row = $result->fetchAssociative()) {
			$songs[] = InternalSong::fromRow($row);
		}
		$result->closeCursor();

		return $songs;
	}

	/**
	 * @return list<InternalSong>
	 */
	public function findByLibrary(int $libraryId, bool $includeDeleted = false): array {
		return $this->findAllForLibrary($libraryId, $includeDeleted);
	}

	public function findByClientId(int $libraryId, int $clientId): ?InternalSong {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalSong::fromRow($row);
	}

	public function insert(InternalSong $song): InternalSong {
		$now = time();
		if ($song->createdAt === 0) {
			$song->createdAt = $now;
		}
		if ($song->updatedAt === 0) {
			$song->updatedAt = $now;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE_NAME)
			->values([
				'uuid' => $qb->createNamedParameter($song->uuid, IQueryBuilder::PARAM_STR),
				'library_id' => $qb->createNamedParameter($song->libraryId, IQueryBuilder::PARAM_INT),
				'title' => $qb->createNamedParameter($song->title, IQueryBuilder::PARAM_STR),
				'sort_title' => $qb->createNamedParameter($song->sortTitle, IQueryBuilder::PARAM_STR),
				'rating' => $qb->createNamedParameter($song->rating, IQueryBuilder::PARAM_INT),
				'difficulty' => $qb->createNamedParameter($song->difficulty, IQueryBuilder::PARAM_INT),
				'duration' => $qb->createNamedParameter($song->duration, IQueryBuilder::PARAM_INT),
				'keywords' => $qb->createNamedParameter($song->keywords, IQueryBuilder::PARAM_STR),
				'notes' => $qb->createNamedParameter($song->notes, IQueryBuilder::PARAM_STR),
				'opus' => $qb->createNamedParameter($song->opus, IQueryBuilder::PARAM_STR),
				'catalog_number' => $qb->createNamedParameter($song->catalogNumber, IQueryBuilder::PARAM_STR),
				'song_number' => $qb->createNamedParameter($song->songNumber, IQueryBuilder::PARAM_STR),
				'client_id' => $qb->createNamedParameter($song->clientId, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter($song->createdAt, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($song->updatedAt, IQueryBuilder::PARAM_INT),
				'deleted_at' => $qb->createNamedParameter($song->deletedAt, IQueryBuilder::PARAM_INT),
			]);

		$qb->executeStatement();
		$song->id = (int)$this->db->lastInsertId(self::TABLE_NAME);

		return $song;
	}

	public function update(InternalSong $song): InternalSong {
		if ($song->id === null) {
			throw new \InvalidArgumentException('Cannot update a song without an ID');
		}

		$song->updatedAt = time();

		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('title', $qb->createNamedParameter($song->title, IQueryBuilder::PARAM_STR))
			->set('sort_title', $qb->createNamedParameter($song->sortTitle, IQueryBuilder::PARAM_STR))
			->set('rating', $qb->createNamedParameter($song->rating, IQueryBuilder::PARAM_INT))
			->set('difficulty', $qb->createNamedParameter($song->difficulty, IQueryBuilder::PARAM_INT))
			->set('duration', $qb->createNamedParameter($song->duration, IQueryBuilder::PARAM_INT))
			->set('keywords', $qb->createNamedParameter($song->keywords, IQueryBuilder::PARAM_STR))
			->set('notes', $qb->createNamedParameter($song->notes, IQueryBuilder::PARAM_STR))
			->set('opus', $qb->createNamedParameter($song->opus, IQueryBuilder::PARAM_STR))
			->set('catalog_number', $qb->createNamedParameter($song->catalogNumber, IQueryBuilder::PARAM_STR))
			->set('song_number', $qb->createNamedParameter($song->songNumber, IQueryBuilder::PARAM_STR))
			->set('client_id', $qb->createNamedParameter($song->clientId, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($song->updatedAt, IQueryBuilder::PARAM_INT))
			->set('deleted_at', $qb->createNamedParameter($song->deletedAt, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($song->id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();

		return $song;
	}

	public function delete(int $id, bool $hardDelete = false): bool {
		$qb = $this->db->getQueryBuilder();

		if ($hardDelete) {
			$qb->delete(self::TABLE_NAME)
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		} else {
			$qb->update(self::TABLE_NAME)
				->set('deleted_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		}

		return $qb->executeStatement() > 0;
	}

	public function restore(int $id): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('deleted_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() > 0;
	}
}
