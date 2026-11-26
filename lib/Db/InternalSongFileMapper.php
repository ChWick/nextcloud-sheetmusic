<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class InternalSongFileMapper {
	public const TABLE_NAME = 'sheetmusic_song_files';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function findById(int $id): ?InternalSongFile {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalSongFile::fromRow($row);
	}

	public function findByUuid(string $uuid): ?InternalSongFile {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalSongFile::fromRow($row);
	}

	/**
	 * @return list<InternalSongFile>
	 */
	public function findAllForSong(int $songId, bool $includeDeleted = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)));

		if (!$includeDeleted) {
			$qb->andWhere($qb->expr()->isNull('deleted_at'));
		}

		$qb->orderBy('id', 'ASC');

		return $this->fetchFiles($qb);
	}

	/**
	 * @return list<InternalSongFile>
	 */
	public function findByFileId(int $fileId, bool $includeDeleted = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		if (!$includeDeleted) {
			$qb->andWhere($qb->expr()->isNull('deleted_at'));
		}

		return $this->fetchFiles($qb);
	}

	public function findByClientId(int $songId, int $clientId): ?InternalSongFile {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			return null;
		}

		return InternalSongFile::fromRow($row);
	}

	public function insert(InternalSongFile $file): InternalSongFile {
		$now = time();
		if ($file->createdAt === 0) {
			$file->createdAt = $now;
		}
		if ($file->updatedAt === 0) {
			$file->updatedAt = $now;
		}
		if ($file->pageOrder !== null) {
			$file->pageOrder = InternalSongFile::normalizePageOrder($file->pageOrder, $file->pageCount);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE_NAME)
			->values([
				'uuid' => $qb->createNamedParameter($file->uuid, IQueryBuilder::PARAM_STR),
				'song_id' => $qb->createNamedParameter($file->songId, IQueryBuilder::PARAM_INT),
				'file_id' => $qb->createNamedParameter($file->fileId, IQueryBuilder::PARAM_INT),
				'page_order' => $qb->createNamedParameter($file->pageOrder, IQueryBuilder::PARAM_STR),
				'page_count' => $qb->createNamedParameter($file->pageCount, IQueryBuilder::PARAM_INT),
				'file_size' => $qb->createNamedParameter($file->fileSize, IQueryBuilder::PARAM_INT),
				'file_hash' => $qb->createNamedParameter($file->fileHash, IQueryBuilder::PARAM_INT),
				'type' => $qb->createNamedParameter($file->type, IQueryBuilder::PARAM_INT),
				'client_id' => $qb->createNamedParameter($file->clientId, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter($file->createdAt, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($file->updatedAt, IQueryBuilder::PARAM_INT),
				'deleted_at' => $qb->createNamedParameter($file->deletedAt, IQueryBuilder::PARAM_INT),
				'crop_boxes' => $qb->createNamedParameter($file->cropBoxes, IQueryBuilder::PARAM_STR),
				'crop_boxes_hash' => $qb->createNamedParameter($file->cropBoxesHash, IQueryBuilder::PARAM_INT),
			]);

		$qb->executeStatement();
		$file->id = (int)$this->db->lastInsertId(self::TABLE_NAME);

		return $file;
	}

	public function update(InternalSongFile $file): InternalSongFile {
		if ($file->id === null) {
			throw new \InvalidArgumentException('Cannot update a file without an ID');
		}

		if ($file->updatedAt === null || $file->updatedAt <= 0) {
			$file->updatedAt = time();
		}
		if ($file->pageOrder !== null) {
			$file->pageOrder = InternalSongFile::normalizePageOrder($file->pageOrder, $file->pageCount);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('page_order', $qb->createNamedParameter($file->pageOrder, IQueryBuilder::PARAM_STR))
			->set('page_count', $qb->createNamedParameter($file->pageCount, IQueryBuilder::PARAM_INT))
			->set('file_size', $qb->createNamedParameter($file->fileSize, IQueryBuilder::PARAM_INT))
			->set('file_hash', $qb->createNamedParameter($file->fileHash, IQueryBuilder::PARAM_INT))
			->set('type', $qb->createNamedParameter($file->type, IQueryBuilder::PARAM_INT))
			->set('client_id', $qb->createNamedParameter($file->clientId, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($file->updatedAt, IQueryBuilder::PARAM_INT))
			->set('deleted_at', $qb->createNamedParameter($file->deletedAt, IQueryBuilder::PARAM_INT))
			->set('crop_boxes', $qb->createNamedParameter($file->cropBoxes, IQueryBuilder::PARAM_STR))
			->set('crop_boxes_hash', $qb->createNamedParameter($file->cropBoxesHash, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($file->id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();

		return $file;
	}

	public function updateCropBoxesByFileId(int $fileId, ?string $cropBoxesJson, ?int $cropBoxesHash): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('crop_boxes', $qb->createNamedParameter($cropBoxesJson, IQueryBuilder::PARAM_STR))
			->set('crop_boxes_hash', $qb->createNamedParameter($cropBoxesHash, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'));

		return $qb->executeStatement();
	}

	public function invalidateCropBoxesByFileId(int $fileId): int {
		return $this->updateCropBoxesByFileId($fileId, null, null);
	}

	/**
	 * Find distinct active PDF files that have no crop boxes yet (or whose cached hash is stale).
	 *
	 * @return list<array{userId: string, fileId: int}>
	 */
	public function findUncroppedPdfFiles(int $limit = 2): array {
		$limit = max(1, $limit);
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('l.user_id', 'f.file_id')
			->from(self::TABLE_NAME, 'f')
			->innerJoin('f', InternalSongMapper::TABLE_NAME, 's', $qb->expr()->eq('f.song_id', 's.id'))
			->innerJoin('s', LibraryMapper::TABLE_NAME, 'l', $qb->expr()->eq('s.library_id', 'l.id'))
			->where($qb->expr()->eq('f.type', $qb->createNamedParameter(InternalSongFile::FILE_TYPE_PDF, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('f.deleted_at'))
			->andWhere($qb->expr()->isNull('s.deleted_at'))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('f.crop_boxes'),
					$qb->expr()->eq('f.crop_boxes', $qb->createNamedParameter('', IQueryBuilder::PARAM_STR)),
					$qb->expr()->andX(
						$qb->expr()->isNotNull('f.file_hash'),
						$qb->expr()->isNotNull('f.crop_boxes_hash'),
						$qb->expr()->neq('f.crop_boxes_hash', 'f.file_hash'),
					),
				),
			)
			->orderBy('f.file_id', 'ASC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$items = [];
		while ($row = $result->fetchAssociative()) {
			$userId = (string)($row['user_id'] ?? '');
			$fileId = (int)($row['file_id'] ?? 0);
			if ($userId !== '' && $fileId > 0) {
				$items[] = ['userId' => $userId, 'fileId' => $fileId];
			}
		}
		$result->closeCursor();

		return $items;
	}

	public function delete(int $id, bool $hardDelete = false): bool {
		$file = $this->findById($id);
		$now = time();

		if ($file !== null) {
			$this->deleteAssociatedMiscFileRecord($file, $hardDelete, $now);
		}

		$qb = $this->db->getQueryBuilder();
		if ($hardDelete) {
			$qb->delete(self::TABLE_NAME);
		} else {
			$qb->update(self::TABLE_NAME)
				->set('deleted_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() > 0;
	}

	public function deleteAllForSong(int $songId, bool $hardDelete = false): void {
		$qb = $this->db->getQueryBuilder();
		if ($hardDelete) {
			$qb->delete(self::TABLE_NAME);
		} else {
			$now = time();
			$qb->update(self::TABLE_NAME)
				->set('deleted_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
		}
		$qb->where($qb->expr()->eq('song_id', $qb->createNamedParameter($songId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * @return list<InternalSongFile>
	 */
	public function findAllForLibrary(int $libraryId, bool $includeDeleted = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.*')
			->from(self::TABLE_NAME, 'f')
			->innerJoin('f', 'sheetmusic_songs', 's', $qb->expr()->eq('f.song_id', 's.id'))
			->where($qb->expr()->eq('s.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));

		if (!$includeDeleted) {
			$qb->andWhere($qb->expr()->isNull('f.deleted_at'))
				->andWhere($qb->expr()->isNull('s.deleted_at'));
		}

		return $this->fetchFiles($qb);
	}

	private function deleteAssociatedMiscFileRecord(InternalSongFile $file, bool $hardDelete, int $now): void {
		if ($file->clientId === null) {
			return;
		}

		$songQb = $this->db->getQueryBuilder();
		$songQb->select('library_id')
			->from(InternalSongMapper::TABLE_NAME)
			->where($songQb->expr()->eq('id', $songQb->createNamedParameter($file->songId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$libId = $songQb->executeQuery()->fetchOne();
		if ($libId === false) {
			return;
		}

		$miscQb = $this->db->getQueryBuilder();
		if ($hardDelete) {
			$miscQb->delete(InternalMiscMapper::DATA_TABLE_NAME);
		} else {
			$miscQb->update(InternalMiscMapper::DATA_TABLE_NAME)
				->set('deleted_at', $miscQb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->set('updated_at', $miscQb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
		}

		$miscQb->where($miscQb->expr()->eq('library_id', $miscQb->createNamedParameter((int)$libId, IQueryBuilder::PARAM_INT)))
			->andWhere($miscQb->expr()->eq('table_name', $miscQb->createNamedParameter('Files', IQueryBuilder::PARAM_STR)))
			->andWhere($miscQb->expr()->eq('record_id', $miscQb->createNamedParameter((string)$file->clientId, IQueryBuilder::PARAM_STR)));
		$miscQb->executeStatement();
	}

	/**
	 * @return list<InternalSongFile>
	 */
	private function fetchFiles(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$files = [];
		while ($row = $result->fetchAssociative()) {
			$files[] = InternalSongFile::fromRow($row);
		}
		$result->closeCursor();

		return $files;
	}

	/**
	 * Find active file records whose song is deleted or does not exist in this library.
	 *
	 * @return list<OrphanSongFile>
	 */
	public function findOrphanSongFiles(int $libraryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id', 'f.song_id', 'f.file_id', 'f.uuid')
			->from(self::TABLE_NAME, 'f')
			->leftJoin('f', 'sheetmusic_songs', 's', $qb->expr()->eq('f.song_id', 's.id'))
			->where($qb->expr()->isNull('f.deleted_at'))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->andX(
						$qb->expr()->eq('s.library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)),
						$qb->expr()->isNotNull('s.deleted_at')
					),
					$qb->expr()->isNull('s.id')
				)
			)
			->orderBy('f.id', 'ASC');

		$result = $qb->executeQuery();
		$orphans = [];
		while ($row = $result->fetchAssociative()) {
			$orphans[] = new OrphanSongFile(
				id: (int)$row['id'],
				songId: (int)$row['song_id'],
				fileId: (int)$row['file_id'],
				uuid: (string)$row['uuid'],
			);
		}
		$result->closeCursor();

		return $orphans;
	}

	/**
	 * Soft delete file records whose song was deleted.
	 *
	 * @return int number of soft-deleted file rows
	 */
	public function removeOrphanSongFiles(int $libraryId): int {
		$orphans = $this->findOrphanSongFiles($libraryId);
		if ($orphans === []) {
			return 0;
		}

		$ids = array_column($orphans, 'id');
		$now = time();
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('deleted_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		return $qb->executeStatement();
	}
}
