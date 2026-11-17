<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class InternalSongAnnotationMapper {
	public const TABLE_NAME = 'sheetmusic_annotations';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Return a map of song IDs (`songId => true`) that have at least one annotation in the library.
	 *
	 * @return array<int, true>
	 */
	public function findSongIdsWithAnnotations(int $libraryId, bool $includeDeleted = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('a.song_id')
			->from(self::TABLE_NAME, 'a')
			->innerJoin('a', InternalSongMapper::TABLE_NAME, 's', $qb->expr()->eq('a.song_id', 's.id'))
			->where($qb->expr()->eq('s.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));

		if (!$includeDeleted) {
			$qb->andWhere($qb->expr()->isNull('a.deleted_at'));
		}

		$result = $qb->executeQuery();
		$songIds = [];
		while ($row = $result->fetchAssociative()) {
			$songIds[(int)$row['song_id']] = true;
		}
		$result->closeCursor();

		return $songIds;
	}

	/**
	 * @return list<InternalSongAnnotation>
	 */
	public function findForSong(int $songId, bool $includeDeleted = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)));

		if (!$includeDeleted) {
			$qb->andWhere($qb->expr()->isNull('deleted_at'));
		}

		$qb->orderBy('page_number', 'ASC')
			->addOrderBy('layer_index', 'ASC')
			->addOrderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$annotations = [];
		while ($row = $result->fetchAssociative()) {
			$annotations[] = InternalSongAnnotation::fromRow($row);
		}
		$result->closeCursor();

		return $annotations;
	}

	/**
	 * @return list<InternalSongAnnotation>
	 */
	public function findForFile(int $fileId, bool $includeDeleted = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		if (!$includeDeleted) {
			$qb->andWhere($qb->expr()->isNull('deleted_at'));
		}

		$qb->orderBy('page_number', 'ASC')
			->addOrderBy('layer_index', 'ASC')
			->addOrderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$annotations = [];
		while ($row = $result->fetchAssociative()) {
			$annotations[] = InternalSongAnnotation::fromRow($row);
		}
		$result->closeCursor();

		return $annotations;
	}

	/**
	 * @return list<InternalSongAnnotation>
	 */
	public function findForSongFile(int $songId, int $fileId, bool $includeDeleted = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		if (!$includeDeleted) {
			$qb->andWhere($qb->expr()->isNull('deleted_at'));
		}

		$qb->orderBy('page_number', 'ASC')
			->addOrderBy('layer_index', 'ASC')
			->addOrderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$annotations = [];
		while ($row = $result->fetchAssociative()) {
			$annotations[] = InternalSongAnnotation::fromRow($row);
		}
		$result->closeCursor();

		return $annotations;
	}

	public function findByUuid(string $uuid): ?InternalSongAnnotation {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetchAssociative();
		$result->closeCursor();

		if ($row === false) {
			return null;
		}

		return InternalSongAnnotation::fromRow($row);
	}

	public function findById(int $id): ?InternalSongAnnotation {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetchAssociative();
		$result->closeCursor();

		if ($row === false) {
			return null;
		}

		return InternalSongAnnotation::fromRow($row);
	}

	public function insert(InternalSongAnnotation $annotation): InternalSongAnnotation {
		$now = time();
		$createdAt = $annotation->createdAt > 0 ? $annotation->createdAt : $now;
		$updatedAt = $annotation->updatedAt > 0 ? $annotation->updatedAt : $now;

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE_NAME)
			->values([
				'uuid' => $qb->createNamedParameter($annotation->uuid, IQueryBuilder::PARAM_STR),
				'song_id' => $qb->createNamedParameter($annotation->songId, IQueryBuilder::PARAM_INT),
				'file_id' => $qb->createNamedParameter($annotation->fileId, IQueryBuilder::PARAM_INT),
				'page_number' => $qb->createNamedParameter($annotation->pageNumber, IQueryBuilder::PARAM_INT),
				'layer_index' => $qb->createNamedParameter($annotation->layerIndex, IQueryBuilder::PARAM_INT),
				'type' => $qb->createNamedParameter($annotation->type, IQueryBuilder::PARAM_STR),
				'x' => $qb->createNamedParameter($annotation->x),
				'y' => $qb->createNamedParameter($annotation->y),
				'width' => $qb->createNamedParameter($annotation->width),
				'height' => $qb->createNamedParameter($annotation->height),
				'style_json' => $qb->createNamedParameter(json_encode($annotation->style, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), IQueryBuilder::PARAM_STR),
				'data_json' => $qb->createNamedParameter(json_encode($annotation->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), IQueryBuilder::PARAM_STR),
				'created_at' => $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($updatedAt, IQueryBuilder::PARAM_INT),
				'deleted_at' => $qb->createNamedParameter($annotation->deletedAt, IQueryBuilder::PARAM_INT),
			]);

		$qb->executeStatement();
		$id = (int)$this->db->lastInsertId(self::TABLE_NAME);

		$annotation->id = $id;
		$annotation->createdAt = $createdAt;
		$annotation->updatedAt = $updatedAt;

		return $annotation;
	}

	public function update(InternalSongAnnotation $annotation): InternalSongAnnotation {
		if ($annotation->id === null) {
			return $this->insert($annotation);
		}

		$now = time();
		$updatedAt = $now;

		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('song_id', $qb->createNamedParameter($annotation->songId, IQueryBuilder::PARAM_INT))
			->set('file_id', $qb->createNamedParameter($annotation->fileId, IQueryBuilder::PARAM_INT))
			->set('page_number', $qb->createNamedParameter($annotation->pageNumber, IQueryBuilder::PARAM_INT))
			->set('layer_index', $qb->createNamedParameter($annotation->layerIndex, IQueryBuilder::PARAM_INT))
			->set('type', $qb->createNamedParameter($annotation->type, IQueryBuilder::PARAM_STR))
			->set('x', $qb->createNamedParameter($annotation->x))
			->set('y', $qb->createNamedParameter($annotation->y))
			->set('width', $qb->createNamedParameter($annotation->width))
			->set('height', $qb->createNamedParameter($annotation->height))
			->set('style_json', $qb->createNamedParameter(json_encode($annotation->style, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), IQueryBuilder::PARAM_STR))
			->set('data_json', $qb->createNamedParameter(json_encode($annotation->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), IQueryBuilder::PARAM_STR))
			->set('updated_at', $qb->createNamedParameter($updatedAt, IQueryBuilder::PARAM_INT))
			->set('deleted_at', $qb->createNamedParameter($annotation->deletedAt, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($annotation->id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
		$annotation->updatedAt = $updatedAt;

		return $annotation;
	}

	public function save(InternalSongAnnotation $annotation): InternalSongAnnotation {
		if ($annotation->id !== null) {
			return $this->update($annotation);
		}

		$existing = $this->findByUuid($annotation->uuid);
		if ($existing !== null) {
			$annotation->id = $existing->id;
			return $this->update($annotation);
		}

		return $this->insert($annotation);
	}

	public function delete(int $id, bool $soft = true): void {
		$qb = $this->db->getQueryBuilder();
		if ($soft) {
			$qb->update(self::TABLE_NAME)
				->set('deleted_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		} else {
			$qb->delete(self::TABLE_NAME)
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		}
		$qb->executeStatement();
	}
}
