<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class LibraryMapper {
	public const TABLE_NAME = 'sheetmusic_libraries';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @return list<InternalLibrary>
	 */
	public function findAllForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->orderBy('path', 'ASC');

		$result = $qb->executeQuery();
		$results = [];
		while ($row = $result->fetchAssociative()) {
			$results[] = InternalLibrary::fromRow($row);
		}
		$result->closeCursor();

		return $results;
	}

	public function findById(int $libraryId): ?InternalLibrary {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();

		return $row === false ? null : InternalLibrary::fromRow($row);
	}

	public function findModelById(int $libraryId): ?InternalLibrary {
		return $this->findById($libraryId);
	}

	public function resolveLibrary(InternalLibrary|array|int $library): InternalLibrary {
		if ($library instanceof InternalLibrary) {
			return $library;
		}

		if (is_int($library)) {
			$model = $this->findModelById($library);
			if ($model === null) {
				throw new \InvalidArgumentException('Library not found: ' . $library);
			}
			return $model;
		}

		return InternalLibrary::fromRow($library);
	}

	public function touch(int $libraryId, ?int $timestamp = null): void {
		$now = $timestamp ?? time();
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function addForUser(string $userId, string $path, ?int $folderId = null, bool $mobilesheetsSyncEnabled = false): void {
		$check = $this->db->getQueryBuilder();
		$check->select('path')
			->from(self::TABLE_NAME)
			->where($check->expr()->eq('user_id', $check->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($check->expr()->eq('path', $check->createNamedParameter($path, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		if ($check->executeQuery()->fetchOne() !== false) {
			return;
		}

		$now = time();
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE_NAME)
			->setValue('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
			->setValue('path', $qb->createNamedParameter($path, IQueryBuilder::PARAM_STR))
			->setValue('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT))
			->setValue('auto_paths', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->setValue('auto_pdf_metadata', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->setValue('naming_rule_preset', $qb->createNamedParameter('auto', IQueryBuilder::PARAM_STR))
			->setValue('mobilesheets_sync_enabled', $qb->createNamedParameter($mobilesheetsSyncEnabled, IQueryBuilder::PARAM_BOOL))
			->setValue('created_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->setValue('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
		$qb->executeStatement();
	}

	public function updateFolderId(int $libraryId, int $folderId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->set('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function findByFolderId(int $folderId): ?InternalLibrary {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();

		return $row === false ? null : InternalLibrary::fromRow($row);
	}

	/**
	 * @return list<InternalLibrary>
	 */
	public function findAllNotOwnedBy(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->neq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->orderBy('path', 'ASC');

		$result = $qb->executeQuery();
		$results = [];
		while ($row = $result->fetchAssociative()) {
			$results[] = InternalLibrary::fromRow($row);
		}
		$result->closeCursor();

		return $results;
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
	 * @return InternalLibrary|null
	 */
	public function findForUser(string $userId, int $id): ?InternalLibrary {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();

		if ($row === false) {
			return null;
		}

		return InternalLibrary::fromRow($row);
	}

	public function findByPathForUser(string $userId, string $path): ?InternalLibrary {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('path', $qb->createNamedParameter($path, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		$row = $qb->executeQuery()->fetchAssociative();

		if ($row === false) {
			return null;
		}

		return InternalLibrary::fromRow($row);
	}

	public function setSettingsForUser(
		string $userId,
		int $id,
		?bool $autoPaths = null,
		?bool $autoPdfMetadata = null,
		?string $namingRulePreset = null,
		?bool $mobilesheetsSyncEnabled = null,
	): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE_NAME)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$hasChanges = false;
		if ($autoPaths !== null) {
			$qb->set('auto_paths', $qb->createNamedParameter($autoPaths, IQueryBuilder::PARAM_BOOL));
			$hasChanges = true;
		}
		if ($autoPdfMetadata !== null) {
			$qb->set('auto_pdf_metadata', $qb->createNamedParameter($autoPdfMetadata, IQueryBuilder::PARAM_BOOL));
			$hasChanges = true;
		}
		if ($namingRulePreset !== null) {
			$qb->set('naming_rule_preset', $qb->createNamedParameter($namingRulePreset, IQueryBuilder::PARAM_STR));
			$hasChanges = true;
		}
		if ($mobilesheetsSyncEnabled !== null) {
			$qb->set('mobilesheets_sync_enabled', $qb->createNamedParameter($mobilesheetsSyncEnabled, IQueryBuilder::PARAM_BOOL));
			$hasChanges = true;
		}

		if ($hasChanges) {
			$qb->executeStatement();
		}
	}
}
