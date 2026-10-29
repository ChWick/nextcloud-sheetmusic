<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

use PDO;
use Psr\Log\LoggerInterface;

abstract class AbstractDatabaseMapper {
	/**
	 * Cache of connection handle -> schema information:
	 * [
	 *     'tables' => array<string, string>, // lowercase_table_name => exact_table_name
	 *     'columns' => array<string, array<string, string>> // lowercase_table_name => array<lowercase_column_name, exact_column_name>
	 * ]
	 *
	 * @var \WeakMap<PDO, array{
	 *     tables: array<string, string>,
	 *     columns: array<string, array<string, string>>
	 * }>|null
	 */
	protected ?\WeakMap $schemaMapCache = null;

	public function __construct(
		protected LoggerInterface $logger,
	) {
		$this->schemaMapCache = new \WeakMap();
	}

	protected function openConnection(string $databasePath): PDO {
		return new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
	}

	/**
	 * @return array{
	 *     tables: array<string, string>,
	 *     columns: array<string, array<string, string>>
	 * }
	 */
	protected function getSchemaMap(PDO $database): array {
		if ($this->schemaMapCache === null) {
			$this->schemaMapCache = new \WeakMap();
		}
		if (isset($this->schemaMapCache[$database])) {
			return $this->schemaMapCache[$database];
		}

		$tables = [];
		$tablesStmt = $database->query("SELECT name FROM sqlite_master WHERE type='table'");
		if ($tablesStmt !== false) {
			/** @var list<string> $rawTables */
			$rawTables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
			foreach ($rawTables as $tableName) {
				$tables[strtolower($tableName)] = $tableName;
			}
		}

		$this->schemaMapCache[$database] = [
			'tables' => $tables,
			'columns' => [],
		];

		return $this->schemaMapCache[$database];
	}

	protected function hasTable(PDO $database, string $table): bool {
		$schema = $this->getSchemaMap($database);
		return isset($schema['tables'][strtolower($table)]);
	}

	/**
	 * @param list<string> $candidates
	 */
	protected function resolveTable(PDO $database, array $candidates): ?string {
		$schema = $this->getSchemaMap($database);
		foreach ($candidates as $candidate) {
			$lower = strtolower($candidate);
			if (isset($schema['tables'][$lower])) {
				return $schema['tables'][$lower];
			}
		}
		return null;
	}

	/**
	 * @param list<string> $candidates
	 */
	protected function resolveColumn(PDO $database, ?string $table, array $candidates, string $default): string {
		if ($table === null) {
			return $default;
		}

		$cols = $this->columnNames($database, $table);
		foreach ($candidates as $candidate) {
			$lower = strtolower($candidate);
			if (isset($cols[$lower])) {
				return $cols[$lower];
			}
		}

		return $default;
	}

	/**
	 * Lower-cased names of the columns a table has => exact column name.
	 *
	 * @return array<string, string>
	 */
	protected function columnNames(PDO $database, string $table): array {
		$schema = $this->getSchemaMap($database);
		$tableLower = strtolower($table);

		if (isset($schema['columns'][$tableLower])) {
			return $schema['columns'][$tableLower];
		}

		$names = [];
		if (isset($schema['tables'][$tableLower])) {
			$exactTable = $schema['tables'][$tableLower];
			$stmt = $database->query('PRAGMA table_info("' . $exactTable . '")');
			if ($stmt !== false) {
				foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
					$colName = (string)$column['name'];
					$names[strtolower($colName)] = $colName;
				}
			}
		}

		$schema['columns'][$tableLower] = $names;
		$this->schemaMapCache[$database] = $schema;
		return $names;
	}

	/** Current time in milliseconds, the unit MobileSheets stores its dates in. */
	protected function nowMilliseconds(): int {
		return (int)round(microtime(true) * 1000);
	}

	/**
	 * Keep only the wanted column => value pairs the table actually has, keyed by
	 * column name, so a database with a reduced or extended schema still gets every
	 * column it knows filled in.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	protected function existingColumns(PDO $database, string $table, array $values): array {
		$existing = $this->columnNames($database, $table);

		$wanted = [];
		foreach ($values as $name => $value) {
			if (isset($existing[strtolower($name)])) {
				$wanted[$name] = $value;
			}
		}

		return $wanted;
	}

	/**
	 * The creation and modification date columns of a table, set to the current
	 * time. Songs and the entity tables each carry a creation date - named
	 * "DateCreated" or "CreationDate" depending on the table - and a "LastModified"
	 * column. Both are required: a table without them means the library schema has
	 * changed, and writing anyway would leave inconsistent rows behind.
	 *
	 * @param bool $withCreation also set the creation date; an update must leave it
	 *                           alone, so it is dropped there
	 * @return array<string, int> column name => timestamp in milliseconds
	 */
	protected function dateColumns(PDO $database, string $table, bool $withCreation = true): array {
		$existing = $this->columnNames($database, $table);
		$now = $this->nowMilliseconds();

		// The creation date is named differently per table; one of the two has to exist.
		$createdColumn = null;
		foreach (['DateCreated', 'CreationDate'] as $created) {
			if (isset($existing[strtolower($created)])) {
				$createdColumn = $created;
				break;
			}
		}
		if ($createdColumn === null) {
			$this->abortOnMissingColumn($table, 'DateCreated or CreationDate');
		}
		$this->requireColumn($database, $table, 'LastModified');

		$values = [];
		if ($withCreation) {
			$values[$createdColumn] = $now;
		}
		$values['LastModified'] = $now;

		return $values;
	}

	/**
	 * Assert a table has an expected column, aborting loudly when it does not.
	 */
	protected function requireColumn(PDO $database, string $table, string $column): void {
		if (!isset($this->columnNames($database, $table)[strtolower($column)])) {
			$this->abortOnMissingColumn($table, $column);
		}
	}

	/**
	 * Report a column the library table does not have and abort the run.
	 */
	protected function abortOnMissingColumn(string $table, string $column): never {
		$this->logger->error('MobileSheets library table is missing an expected column', [
			'table' => $table,
			'column' => $column,
		]);

		throw new \RuntimeException(sprintf(
			"MobileSheets table '%s' is missing the expected %s column; the app must be updated to match the library schema",
			$table,
			$column,
		));
	}

	/**
	 * Insert a row, filling in the columns the table has, and return its id.
	 *
	 * @param array<string, mixed> $values
	 */
	protected function insertRow(PDO $database, string $table, array $values): int {
		$columns = [];
		$params = [];
		foreach ($this->existingColumns($database, $table, $values) as $name => $value) {
			$columns[] = $name;
			$params[':' . $name] = $value;
		}

		$sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_keys($params)) . ')';
		$database->prepare($sql)->execute($params);

		return (int)$database->lastInsertId();
	}

	/**
	 * Update a row by id, touching only the columns the table has.
	 *
	 * @param array<string, mixed> $values
	 */
	protected function updateRowById(PDO $database, string $table, array $values, int $id): void {
		$assignments = [];
		$params = [':id' => $id];
		foreach ($this->existingColumns($database, $table, $values) as $name => $value) {
			$assignments[] = $name . ' = :' . $name;
			$params[':' . $name] = $value;
		}

		$sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $assignments) . ' WHERE Id = :id';
		$database->prepare($sql)->execute($params);
	}
}
