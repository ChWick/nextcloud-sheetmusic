<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class InternalAnnotationSyncMapper {
	public const TABLE_NAME = 'sheetmusic_annotation_sync';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function findAnnotationId(string $clientType, string $clientRecordId): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('annotation_id')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('client_type', $qb->createNamedParameter($clientType, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('client_record_id', $qb->createNamedParameter($clientRecordId, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();

		return $id !== false ? (int)$id : null;
	}

	public function findClientRecordId(int $annotationId, string $clientType): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('client_record_id')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('annotation_id', $qb->createNamedParameter($annotationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('client_type', $qb->createNamedParameter($clientType, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$recId = $result->fetchOne();
		$result->closeCursor();

		return $recId !== false ? (string)$recId : null;
	}

	public function link(int $annotationId, string $clientType, string $clientRecordId): void {
		$existing = $this->findAnnotationId($clientType, $clientRecordId);
		$qb = $this->db->getQueryBuilder();

		if ($existing !== null) {
			$qb->update(self::TABLE_NAME)
				->set('annotation_id', $qb->createNamedParameter($annotationId, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('client_type', $qb->createNamedParameter($clientType, IQueryBuilder::PARAM_STR)))
				->andWhere($qb->expr()->eq('client_record_id', $qb->createNamedParameter($clientRecordId, IQueryBuilder::PARAM_STR)));
		} else {
			$qb->insert(self::TABLE_NAME)
				->values([
					'annotation_id' => $qb->createNamedParameter($annotationId, IQueryBuilder::PARAM_INT),
					'client_type' => $qb->createNamedParameter($clientType, IQueryBuilder::PARAM_STR),
					'client_record_id' => $qb->createNamedParameter($clientRecordId, IQueryBuilder::PARAM_STR),
				]);
		}

		$qb->executeStatement();
	}

	public function unlink(int $annotationId, string $clientType): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('annotation_id', $qb->createNamedParameter($annotationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('client_type', $qb->createNamedParameter($clientType, IQueryBuilder::PARAM_STR)));
		$qb->executeStatement();
	}

	public function deleteForAnnotation(int $annotationId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('annotation_id', $qb->createNamedParameter($annotationId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
