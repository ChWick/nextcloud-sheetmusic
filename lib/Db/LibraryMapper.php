<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class LibraryMapper {
	public const TABLE_NAME = 'mobilesheets_libraries';

	public function __construct(private IDBConnection $db) {
	}

	/**
	 * @return list<string>
	 */
	public function findAllForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('path')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->orderBy('path', 'ASC');

		$result = $qb->executeQuery();
		$paths = [];
		while ($row = $result->fetchAssociative()) {
			$paths[] = (string)$row['path'];
		}
		$result->closeCursor();

		return $paths;
	}

	public function addForUser(string $userId, string $path): void {
		$check = $this->db->getQueryBuilder();
		$check->select('path')
			->from(self::TABLE_NAME)
			->where($check->expr()->eq('user_id', $check->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($check->expr()->eq('path', $check->createNamedParameter($path, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		if ($check->executeQuery()->fetchOne() !== false) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE_NAME)
			->setValue('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
			->setValue('path', $qb->createNamedParameter($path, IQueryBuilder::PARAM_STR));
		$qb->executeStatement();
	}
}