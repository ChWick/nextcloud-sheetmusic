<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

use PDO;

/**
 * Utility helper for inspecting SQLite tables, columns, and variants in MobileSheets databases.
 */
final class MobileSheetsTableHelper {
	/**
	 * Table name alternates between MobileSheets database versions (singular vs plural).
	 */
	private const TABLE_ALTERNATIVES = [
		'key' => 'Keys',
		'keys' => 'Key',
		'sourcetype' => 'SourceTypes',
		'sourcetypes' => 'SourceType',
	];

	/**
	 * Check if a table exists in the SQLite database.
	 */
	public static function tableExists(PDO $db, string $tableName): bool {
		$stmt = $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='" . str_replace("'", "''", $tableName) . "'");
		return $stmt !== false && $stmt->fetch() !== false;
	}

	/**
	 * Resolve an existing table name, falling back to known MobileSheets schema variants
	 * (e.g. Key vs Keys, SourceType vs SourceTypes).
	 */
	public static function resolveTable(PDO $db, string $tableName): ?string {
		if (self::tableExists($db, $tableName)) {
			return $tableName;
		}

		$alt = self::TABLE_ALTERNATIVES[strtolower($tableName)] ?? null;
		if ($alt !== null && self::tableExists($db, $alt)) {
			return $alt;
		}

		return null;
	}

	/**
	 * Query table column info and return a map of [lowercase_name => real_name].
	 *
	 * @return array<string, string>
	 */
	public static function getTableColumns(PDO $db, string $tableName): array {
		$stmt = $db->query(sprintf('PRAGMA table_info("%s")', str_replace('"', '""', $tableName)));
		$cols = [];
		if ($stmt !== false) {
			while ($col = $stmt->fetch()) {
				if (isset($col['name'])) {
					$cols[strtolower((string)$col['name'])] = (string)$col['name'];
				}
			}
		}
		return $cols;
	}

	/**
	 * Determine the content column name used in the SongNotes table (Notes, Note, or Text).
	 */
	public static function getSongNotesContentColumn(PDO $db): ?string {
		if (!self::tableExists($db, 'SongNotes')) {
			return null;
		}

		$cols = self::getTableColumns($db, 'SongNotes');
		if (isset($cols['notes'])) {
			return $cols['notes'];
		}
		if (isset($cols['note'])) {
			return $cols['note'];
		}
		if (isset($cols['text'])) {
			return $cols['text'];
		}

		return null;
	}

	/**
	 * Determine the entity name/title column from table columns, falling back to standard alternatives.
	 *
	 * @param array<string, string> $cols [lowercase_name => real_name]
	 */
	public static function resolveEntityNameColumn(array $cols, string $preferredCol): ?string {
		$lowerPref = strtolower($preferredCol);
		if (isset($cols[$lowerPref])) {
			return $cols[$lowerPref];
		}

		foreach (['name', 'title', 'type', 'year'] as $fallback) {
			if (isset($cols[$fallback])) {
				return $cols[$fallback];
			}
		}

		return null;
	}

	/**
	 * Insert a single row into an SQLite table, with optional INSERT OR REPLACE.
	 *
	 * @param PDO $db
	 * @param string $tableName
	 * @param array<string, mixed> $row Column names and their corresponding values.
	 * @param bool $orReplace Whether to execute INSERT OR REPLACE instead of INSERT.
	 */
	public static function insertRow(PDO $db, string $tableName, array $row, bool $orReplace = false): void {
		if ($row === []) {
			return;
		}

		$cols = array_keys($row);
		$escapedTable = '"' . str_replace('"', '""', $tableName) . '"';
		$escapedCols = array_map(static fn ($c) => '"' . str_replace('"', '""', (string)$c) . '"', $cols);
		$placeholders = array_fill(0, count($cols), '?');

		$verb = $orReplace ? 'INSERT OR REPLACE' : 'INSERT';
		$sql = sprintf(
			'%s INTO %s (%s) VALUES (%s)',
			$verb,
			$escapedTable,
			implode(', ', $escapedCols),
			implode(', ', $placeholders),
		);

		$stmt = $db->prepare($sql);
		$stmt->execute(array_values($row));
	}

	/**
	 * Query all IDs from an SQLite table and return as an integer set [id => int].
	 *
	 * @return array<int, int>
	 */
	public static function getTableIdSet(PDO $db, string $tableName): array {
		if (!self::tableExists($db, $tableName)) {
			return [];
		}

		$escapedTable = '"' . str_replace('"', '""', $tableName) . '"';
		$ids = $db->query(sprintf('SELECT Id FROM %s', $escapedTable))->fetchAll(PDO::FETCH_COLUMN);
		return array_flip(array_map('intval', $ids));
	}
}
