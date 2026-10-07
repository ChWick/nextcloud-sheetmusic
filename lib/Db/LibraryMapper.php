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

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @return list<array{id: int, path: string, autoPaths: bool}>
	 */
	public function findAllForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'path', 'auto_paths')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->orderBy('path', 'ASC');

		$result = $qb->executeQuery();
		$results = [];
		while ($row = $result->fetchAssociative()) {
			$results[] = [
				'id' => (int)$row['id'],
				'path' => (string)$row['path'],
				'autoPaths' => self::autoPathsOf($row),
			];
		}
		$result->closeCursor();

		return $results;
	}

	public function findById(int $libraryId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($libraryId)));

		$row = $qb->executeQuery()->fetch();

		return $row === false ? null : $row;
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
			->setValue('path', $qb->createNamedParameter($path, IQueryBuilder::PARAM_STR))
			->setValue('auto_paths', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL));
		$qb->executeStatement();
	}

	/**
	 * Remove the entry of a library from the user's list of libraries.
	 *
	 * Only the row in this app's own table goes away. The MobileSheets database and
	 * every file it references stay in the user's folder, so the library can be added
	 * again at any time.
	 *
	 * @return bool whether an entry was removed
	 */
	public function deleteForUser(string $userId, int $libraryId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() > 0;
	}

	/**
	 * @return array{id: int, path: string, autoPaths: bool}|null
	 */
	public function findForUser(string $userId, int $id): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'path', 'auto_paths')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();

		if ($row === false) {
			return null;
		}

		return [
			'id' => (int)$row['id'],
			'path' => (string)$row['path'],
			'autoPaths' => self::autoPathsOf($row),
		];
	}

	public function setAutoPathsForUser(string $userId, int $id, bool $autoPaths): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('auto_paths', $qb->createNamedParameter($autoPaths, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * @param array<string, mixed> $library
	 */
	public static function autoPathsOf(array $library): bool {
		if (array_key_exists('autoPaths', $library)) {
			return (bool)$library['autoPaths'];
		}
		if (array_key_exists('auto_paths', $library) && $library['auto_paths'] !== null) {
			return (bool)$library['auto_paths'];
		}
		return true;
	}
}
