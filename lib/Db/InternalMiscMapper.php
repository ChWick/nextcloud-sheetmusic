<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class InternalMiscMapper {
	public const SCHEMA_TABLE_NAME = 'sheetmusic_misc_schema';
	public const DATA_TABLE_NAME = 'sheetmusic_misc_data';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function saveMiscSchema(int $libraryId, string $clientType, string $tableName, string $createSql): void {
		$qbCheck = $this->db->getQueryBuilder();
		$qbCheck->select('id')
			->from(self::SCHEMA_TABLE_NAME)
			->where($qbCheck->expr()->eq('library_id', $qbCheck->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qbCheck->expr()->eq('client_type', $qbCheck->createNamedParameter($clientType, IQueryBuilder::PARAM_STR)))
			->andWhere($qbCheck->expr()->eq('table_name', $qbCheck->createNamedParameter($tableName, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		$existingId = $qbCheck->executeQuery()->fetchOne();

		$qb = $this->db->getQueryBuilder();
		if ($existingId !== false) {
			$qb->update(self::SCHEMA_TABLE_NAME)
				->set('create_sql', $qb->createNamedParameter($createSql, IQueryBuilder::PARAM_STR))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existingId, IQueryBuilder::PARAM_INT)));
		} else {
			$qb->insert(self::SCHEMA_TABLE_NAME)
				->values([
					'library_id' => $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT),
					'client_type' => $qb->createNamedParameter($clientType, IQueryBuilder::PARAM_STR),
					'table_name' => $qb->createNamedParameter($tableName, IQueryBuilder::PARAM_STR),
					'create_sql' => $qb->createNamedParameter($createSql, IQueryBuilder::PARAM_STR),
				]);
		}

		$qb->executeStatement();
	}

	/**
	 * @return array<string, string> table_name => create_sql
	 */
	public function getMiscSchemas(int $libraryId, string $clientType): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('table_name', 'create_sql')
			->from(self::SCHEMA_TABLE_NAME)
			->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('client_type', $qb->createNamedParameter($clientType, IQueryBuilder::PARAM_STR)));

		$result = $qb->executeQuery();
		$schemas = [];
		while ($row = $result->fetchAssociative()) {
			$schemas[(string)$row['table_name']] = (string)$row['create_sql'];
		}
		$result->closeCursor();

		return $schemas;
	}

	public function savePragmaUserVersion(int $libraryId, string $clientType, int $version): void {
		$this->saveMiscData($libraryId, $clientType, '__metadata', 'pragma_user_version', [
			'user_version' => $version,
		]);
	}

	public function getPragmaUserVersion(int $libraryId, string $clientType): ?int {
		$rows = $this->getMiscDataForTable($libraryId, $clientType, '__metadata');
		foreach ($rows as $row) {
			if (isset($row['user_version']) && is_numeric($row['user_version'])) {
				return (int)$row['user_version'];
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function saveMiscData(
		int $libraryId,
		string $clientType,
		string $tableName,
		string $recordId,
		array $data,
		?string $songUuid = null,
	): void {
		$now = time();
		$cleanData = self::sanitizeBinaryData($data);
		$json = json_encode($cleanData, JSON_THROW_ON_ERROR);

		$qbCheck = $this->db->getQueryBuilder();
		$qbCheck->select('id')
			->from(self::DATA_TABLE_NAME)
			->where($qbCheck->expr()->eq('library_id', $qbCheck->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qbCheck->expr()->eq('client_type', $qbCheck->createNamedParameter($clientType, IQueryBuilder::PARAM_STR)))
			->andWhere($qbCheck->expr()->eq('table_name', $qbCheck->createNamedParameter($tableName, IQueryBuilder::PARAM_STR)))
			->andWhere($qbCheck->expr()->eq('record_id', $qbCheck->createNamedParameter($recordId, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		$existingId = $qbCheck->executeQuery()->fetchOne();

		$qb = $this->db->getQueryBuilder();
		if ($existingId !== false) {
			$qb->update(self::DATA_TABLE_NAME)
				->set('data_json', $qb->createNamedParameter($json, IQueryBuilder::PARAM_STR))
				->set('song_uuid', $qb->createNamedParameter($songUuid, IQueryBuilder::PARAM_STR))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->set('deleted_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existingId, IQueryBuilder::PARAM_INT)));
		} else {
			$qb->insert(self::DATA_TABLE_NAME)
				->values([
					'library_id' => $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT),
					'client_type' => $qb->createNamedParameter($clientType, IQueryBuilder::PARAM_STR),
					'table_name' => $qb->createNamedParameter($tableName, IQueryBuilder::PARAM_STR),
					'record_id' => $qb->createNamedParameter($recordId, IQueryBuilder::PARAM_STR),
					'song_uuid' => $qb->createNamedParameter($songUuid, IQueryBuilder::PARAM_STR),
					'data_json' => $qb->createNamedParameter($json, IQueryBuilder::PARAM_STR),
					'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
					'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
					'deleted_at' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				]);
		}

		$qb->executeStatement();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function getMiscDataForTable(int $libraryId, string $clientType, string $tableName, bool $includeDeleted = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('data_json')
			->from(self::DATA_TABLE_NAME)
			->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('client_type', $qb->createNamedParameter($clientType, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('table_name', $qb->createNamedParameter($tableName, IQueryBuilder::PARAM_STR)));

		if (!$includeDeleted) {
			$qb->andWhere($qb->expr()->isNull('deleted_at'));
		}

		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetchAssociative()) {
			try {
				/** @var array<string, mixed> $decoded */
				$decoded = json_decode((string)$row['data_json'], true, 512, JSON_THROW_ON_ERROR);
				$rows[] = self::restoreBinaryData($decoded);
			} catch (\Throwable) {
				// Skip malformed rows
			}
		}
		$result->closeCursor();

		return $rows;
	}

	private static function sanitizeBinaryData(mixed $value): mixed {
		if (is_array($value)) {
			$res = [];
			foreach ($value as $k => $v) {
				$res[$k] = self::sanitizeBinaryData($v);
			}
			return $res;
		}
		if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
			return '__b64:' . base64_encode($value);
		}
		return $value;
	}

	private static function restoreBinaryData(mixed $value): mixed {
		if (is_array($value)) {
			$res = [];
			foreach ($value as $k => $v) {
				$res[$k] = self::restoreBinaryData($v);
			}
			return $res;
		}
		if (is_string($value) && str_starts_with($value, '__b64:')) {
			return base64_decode(substr($value, 6));
		}
		return $value;
	}

	public function deleteMiscData(int $libraryId, string $clientType, string $tableName, string $recordId, bool $hardDelete = false): bool {
		$qb = $this->db->getQueryBuilder();
		if ($hardDelete) {
			$qb->delete(self::DATA_TABLE_NAME)
				->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('client_type', $qb->createNamedParameter($clientType, IQueryBuilder::PARAM_STR)))
				->andWhere($qb->expr()->eq('table_name', $qb->createNamedParameter($tableName, IQueryBuilder::PARAM_STR)))
				->andWhere($qb->expr()->eq('record_id', $qb->createNamedParameter($recordId, IQueryBuilder::PARAM_STR)));
		} else {
			$qb->update(self::DATA_TABLE_NAME)
				->set('deleted_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('client_type', $qb->createNamedParameter($clientType, IQueryBuilder::PARAM_STR)))
				->andWhere($qb->expr()->eq('table_name', $qb->createNamedParameter($tableName, IQueryBuilder::PARAM_STR)))
				->andWhere($qb->expr()->eq('record_id', $qb->createNamedParameter($recordId, IQueryBuilder::PARAM_STR)));
		}

		return $qb->executeStatement() > 0;
	}

	public function deleteAllMiscDataForLibrary(int $libraryId): void {
		$qb1 = $this->db->getQueryBuilder();
		$qb1->delete(self::SCHEMA_TABLE_NAME)
			->where($qb1->expr()->eq('library_id', $qb1->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));
		$qb1->executeStatement();

		$qb2 = $this->db->getQueryBuilder();
		$qb2->delete(self::DATA_TABLE_NAME)
			->where($qb2->expr()->eq('library_id', $qb2->createNamedParameter($libraryId, IQueryBuilder::PARAM_INT)));
		$qb2->executeStatement();
	}
}
