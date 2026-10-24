<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

use OCA\SheetMusic\Db\DuplicateEntityConflict;
use OCA\SheetMusic\Db\DuplicateSongEntityAssignment;
use OCA\SheetMusic\Db\InternalEntity;


class LibraryEntityMapper extends AbstractDatabaseMapper {
	/**
	 * Tables of the entity kinds a song can carry, and how each of them links
	 * to songs.
	 *
	 * @var array<string, array{table: string, linkTable: string, linkColumn: string}>
	 */
	private const ENTITY_TABLES = [
		'composers' => ['table' => 'Composer', 'linkTable' => 'ComposerSongs', 'linkColumn' => 'ComposerId'],
		'books' => ['table' => 'Books', 'linkTable' => 'BookSongs', 'linkColumn' => 'BookId'],
		'collections' => ['table' => 'Collections', 'linkTable' => 'CollectionSong', 'linkColumn' => 'CollectionId'],
		'artists' => ['table' => 'Artists', 'linkTable' => 'ArtistsSongs', 'linkColumn' => 'ArtistId'],
		'genres' => ['table' => 'Genres', 'linkTable' => 'GenresSongs', 'linkColumn' => 'GenreId'],
		'keys' => ['table' => 'Keys', 'linkTable' => 'KeySongs', 'linkColumn' => 'KeyId'],
		'customgroups' => ['table' => 'CustomGroup', 'linkTable' => 'CustomGroupSongs', 'linkColumn' => 'GroupId'],
		'years' => ['table' => 'Years', 'linkTable' => 'YearsSongs', 'linkColumn' => 'YearId'],
		'signatures' => ['table' => 'Signature', 'linkTable' => 'SignatureSongs', 'linkColumn' => 'SignatureId'],
		'sourcetypes' => ['table' => 'SourceTypes', 'linkTable' => 'SourceTypeSongs', 'linkColumn' => 'SourceTypeId'],
	];

	/**
	 * @var array<string, array{tables: list<string>, columns: list<string>}>
	 */
	private const ENTITY_LABELS = [
		'composers' => ['tables' => ['Composer'], 'columns' => ['Name']],
		'books' => ['tables' => ['Books'], 'columns' => ['Title']],
		'collections' => ['tables' => ['Collections'], 'columns' => ['Name']],
		'artists' => ['tables' => ['Artists'], 'columns' => ['Name']],
		'genres' => ['tables' => ['Genres'], 'columns' => ['Type', 'Name']],
		'keys' => ['tables' => ['Key', 'Keys'], 'columns' => ['Name', 'Key']],
		'customgroups' => ['tables' => ['CustomGroup'], 'columns' => ['Name']],
		'years' => ['tables' => ['Years'], 'columns' => ['Year', 'Name']],
		'signatures' => ['tables' => ['Signature'], 'columns' => ['Name']],
		'sourcetypes' => ['tables' => ['SourceType', 'SourceTypes'], 'columns' => ['Type', 'Name']],
	];

	public function createComposer(string $databasePath, string $name): int {
		$database = $this->openConnection($databasePath);
		return $this->insertRow($database, 'Composer', [
			'Name' => $name,
		] + $this->dateColumns($database, 'Composer'));
	}

	public function createBook(string $databasePath, string $title): int {
		$database = $this->openConnection($databasePath);
		return $this->insertRow($database, 'Books', [
			'Title' => $title,
		] + $this->dateColumns($database, 'Books'));
	}

	public function createCollection(string $databasePath, string $name): int {
		$database = $this->openConnection($databasePath);
		return $this->insertRow($database, 'Collections', [
			'Name' => $name,
		] + $this->dateColumns($database, 'Collections'));
	}

	public function createArtist(string $databasePath, string $name): int {
		$database = $this->openConnection($databasePath);
		return $this->insertRow($database, 'Artists', [
			'Name' => $name,
		] + $this->dateColumns($database, 'Artists'));
	}

	public function createGenre(string $databasePath, string $name): int {
		$database = $this->openConnection($databasePath);
		return $this->insertRow($database, 'Genres', [
			'Type' => $name,
		] + $this->dateColumns($database, 'Genres'));
	}

	public function createKey(string $databasePath, string $name): int {
		$database = $this->openConnection($databasePath);
		$table = $this->resolveTable($database, ['Key', 'Keys']) ?? 'Keys';
		$col = $this->resolveColumn($database, $table, ['Name', 'Key'], 'Name');
		return $this->insertRow($database, $table, [
			$col => $name,
		] + $this->dateColumns($database, $table));
	}

	public function createCustomGroup(string $databasePath, string $name): int {
		$database = $this->openConnection($databasePath);
		return $this->insertRow($database, 'CustomGroup', [
			'Name' => $name,
		] + $this->dateColumns($database, 'CustomGroup'));
	}

	public function createInstrument(string $databasePath, string $name): int {
		$database = $this->openConnection($databasePath);
		$id = $this->insertRow($database, 'CustomGroup', [
			'Name' => $name,
		] + $this->dateColumns($database, 'CustomGroup'));

		if (!$this->hasTable($database, '_nc_sync_metadata')) {
			$database->exec('CREATE TABLE IF NOT EXISTS _nc_sync_metadata (entity_type TEXT NOT NULL, uuid TEXT NOT NULL, client_id INTEGER, updated_at INTEGER, extra_json TEXT, PRIMARY KEY (entity_type, uuid))');
			$database->exec('CREATE INDEX IF NOT EXISTS idx_nc_sync_client ON _nc_sync_metadata (entity_type, client_id)');
		}

		$uuid = self::generateUuid();
		$cols = $this->columnNames($database, '_nc_sync_metadata');
		if (isset($cols['extra_json'])) {
			$stmt = $database->prepare('INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES (?, ?, ?, ?, ?)');
			$stmt->execute([
				'entity:instrument',
				$uuid,
				$id,
				time(),
				json_encode(['original_type' => 'instrument', 'name' => $name], JSON_THROW_ON_ERROR),
			]);
		} else {
			$stmt = $database->prepare('INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at) VALUES (?, ?, ?, ?)');
			$stmt->execute(['entity:instrument', $uuid, $id, time()]);
		}

		return $id;
	}

	private static function generateUuid(): string {
		return InternalEntity::generateUuid();
	}

	public function createYear(string $databasePath, int|string $year): int {
		$database = $this->openConnection($databasePath);
		$col = $this->resolveColumn($database, 'Years', ['Year', 'Name'], 'Year');
		$val = ($col === 'Year') ? (int)$year : (string)$year;
		return $this->insertRow($database, 'Years', [
			$col => $val,
		] + $this->dateColumns($database, 'Years'));
	}

	public function createSignature(string $databasePath, string $name): int {
		$database = $this->openConnection($databasePath);
		return $this->insertRow($database, 'Signature', [
			'Name' => $name,
		] + $this->dateColumns($database, 'Signature'));
	}

	public function createSourceType(string $databasePath, string $name): int {
		$database = $this->openConnection($databasePath);
		$table = $this->resolveTable($database, ['SourceType', 'SourceTypes']) ?? 'SourceTypes';
		$col = $this->resolveColumn($database, $table, ['Type', 'Name'], 'Type');
		return $this->insertRow($database, $table, [
			$col => $name,
		] + $this->dateColumns($database, $table));
	}

	/**
	 * Delete a composer, book, collection, artist, genre, key, custom group, year, signature or source type from the library.
	 *
	 * @param 'composers'|'books'|'collections'|'artists'|'genres'|'keys'|'customgroups'|'years'|'signatures'|'sourcetypes' $entityType
	 * @return bool whether the entry itself existed and was removed
	 */
	public function deleteEntity(string $databasePath, string $entityType, int $entityId): bool {
		$entityType = self::normalizeEntityType($entityType);
		$tables = self::ENTITY_TABLES[$entityType] ?? null;
		if ($tables === null) {
			throw new \InvalidArgumentException('Unknown entity type: ' . $entityType);
		}

		$database = $this->openConnection($databasePath);
		$targetTable = $this->resolveTable($database, [$tables['table'], $tables['table'] . 's', rtrim($tables['table'], 's')]);

		$removed = 0;
		$database->beginTransaction();
		try {
			if ($this->hasTable($database, $tables['linkTable'])) {
				$stmt = $database->prepare('DELETE FROM "' . $tables['linkTable'] . '" WHERE ' . $tables['linkColumn'] . ' = :entityId');
				$stmt->execute([':entityId' => $entityId]);
			}

			if ($targetTable !== null) {
				$stmt = $database->prepare('DELETE FROM "' . $targetTable . '" WHERE Id = :entityId');
				$stmt->execute([':entityId' => $entityId]);
				$removed = $stmt->rowCount();
			}

			$database->commit();
		} catch (\Throwable $exception) {
			$database->rollBack();
			throw $exception;
		}

		return $removed > 0;
	}

	/**
	 * Find repeated links from songs to the same entity.
	 *
	 * @return list<DuplicateSongEntityAssignment>
	 */
	public function findDuplicateSongEntityAssignments(string $databasePath): array {
		$database = $this->openConnection($databasePath);
		$duplicates = [];

		foreach (self::ENTITY_TABLES as $entityType => $tables) {
			$linkTable = $this->resolveTable($database, [$tables['linkTable']]);
			if ($linkTable === null) {
				continue;
			}

			$columns = $this->columnNames($database, $linkTable);
			$songIdColumn = $columns['songid'] ?? null;
			$entityIdColumn = $columns[strtolower($tables['linkColumn'])] ?? null;
			if ($songIdColumn === null || $entityIdColumn === null) {
				continue;
			}

			$sql = 'SELECT "' . $songIdColumn . '" AS SongId, "'
				. $entityIdColumn . '" AS EntityId, COUNT(*) AS AssignmentCount'
				. ' FROM "' . $linkTable . '" WHERE "' . $songIdColumn . '" IS NOT NULL AND "'
				. $entityIdColumn . '" IS NOT NULL GROUP BY "' . $songIdColumn . '", "'
				. $entityIdColumn . '" HAVING COUNT(*) > 1';
			$statement = $database->query($sql);
			if ($statement === false) {
				throw new \RuntimeException(sprintf('Could not inspect duplicate assignments in "%s".', $linkTable));
			}

			while ($row = $statement->fetch()) {
				$duplicates[] = new DuplicateSongEntityAssignment(
					songId: (int)$row['SongId'],
					entityType: $entityType,
					entityId: (int)$row['EntityId'],
					count: (int)$row['AssignmentCount'],
				);
			}
		}

		return $duplicates;
	}

	/**
	 * Remove repeated song-to-entity links while preserving one row per pair.
	 *
	 * @return int number of removed relation rows
	 */
	public function removeDuplicateSongEntityAssignments(string $databasePath, int $songId): int {
		$database = $this->openConnection($databasePath);
		$removed = 0;

		$database->beginTransaction();
		try {
			foreach (self::ENTITY_TABLES as $tables) {
				$linkTable = $this->resolveTable($database, [$tables['linkTable']]);
				if ($linkTable === null) {
					continue;
				}

				$columns = $this->columnNames($database, $linkTable);
				$songIdColumn = $columns['songid'] ?? null;
				$entityIdColumn = $columns[strtolower($tables['linkColumn'])] ?? null;
				if ($songIdColumn === null || $entityIdColumn === null) {
					continue;
				}

				$sql = 'DELETE FROM "' . $linkTable . '" WHERE rowid NOT IN ('
					. 'SELECT MIN(rowid) FROM "' . $linkTable . '" WHERE "' . $songIdColumn
					. '" = :songId AND "' . $entityIdColumn . '" IS NOT NULL GROUP BY "'
					. $entityIdColumn . '") AND "' . $songIdColumn . '" = :songId AND "'
					. $entityIdColumn . '" IS NOT NULL';
				$statement = $database->prepare($sql);
				$statement->execute([':songId' => $songId]);
				$removed += $statement->rowCount();
			}

			$database->commit();
		} catch (\Throwable $exception) {
			$database->rollBack();
			throw $exception;
		}

		return $removed;
	}

	/**
	 * Find entity records with the same non-empty label, ignoring case and
	 * surrounding whitespace. The lowest ID is the canonical record.
	 *
	 * @return list<DuplicateEntityConflict>
	 */
	public function findDuplicateEntities(string $databasePath): array {
		$database = $this->openConnection($databasePath);
		$duplicates = [];

		foreach (self::ENTITY_LABELS as $entityType => $definition) {
			$table = $this->resolveTable($database, $definition['tables']);
			if ($table === null) {
				continue;
			}

			$columns = $this->columnNames($database, $table);
			$labelColumn = null;
			foreach ($definition['columns'] as $candidate) {
				if (isset($columns[strtolower($candidate)])) {
					$labelColumn = $columns[strtolower($candidate)];
					break;
				}
			}
			if ($labelColumn === null || !isset($columns['id'])) {
				continue;
			}

			$statement = $database->query(sprintf(
				'SELECT "%s" AS EntityId, CAST("%s" AS TEXT) AS EntityName FROM "%s"',
				$columns['id'],
				$labelColumn,
				$table,
			));
			if ($statement === false) {
				throw new \RuntimeException(sprintf('Could not inspect duplicate %s records.', $entityType));
			}

			$groups = [];
			while ($row = $statement->fetch()) {
				$name = trim((string)$row['EntityName']);
				if ($name === '') {
					continue;
				}

				$groups[mb_strtolower($name, 'UTF-8')][(int)$row['EntityId']] = $name;
			}

			foreach ($groups as $group) {
				$ids = array_keys($group);
				if (count($ids) < 2) {
					continue;
				}
				sort($ids, SORT_NUMERIC);

				$duplicates[] = new DuplicateEntityConflict(
					entityType: $entityType,
					name: $group[$ids[0]],
					keepId: $ids[0],
					duplicateIds: array_slice($ids, 1),
				);
			}
		}

		return $duplicates;
	}

	public static function normalizeEntityType(string $entityType): string {
		$lower = strtolower(trim($entityType));
		return match ($lower) {
			'composer' => 'composers',
			'book' => 'books',
			'collection' => 'collections',
			'artist' => 'artists',
			'genre' => 'genres',
			'key' => 'keys',
			'customgroup' => 'customgroups',
			'instrument', 'instruments' => 'customgroups',
			'year' => 'years',
			'signature' => 'signatures',
			'sourcetype' => 'sourcetypes',
			default => $lower,
		};
	}

	/**
	 * Find all song IDs associated with one or more entity IDs.
	 *
	 * @param int|list<int> $entityIds
	 * @return list<int>
	 */
	public function getSongIdsForEntity(string $databasePath, string $entityType, int|array $entityIds): array {
		$entityType = self::normalizeEntityType($entityType);
		$tables = self::ENTITY_TABLES[$entityType] ?? null;
		if ($tables === null) {
			throw new \InvalidArgumentException('Unknown entity type: ' . $entityType);
		}

		$ids = is_array($entityIds)
			? array_values(array_filter(array_map('intval', $entityIds), static fn (int $id): bool => $id > 0))
			: array_values(array_filter([(int)$entityIds], static fn (int $id): bool => $id > 0));

		if ($ids === []) {
			return [];
		}

		$database = $this->openConnection($databasePath);
		$linkTable = $this->resolveTable($database, [$tables['linkTable']]);
		if ($linkTable === null) {
			return [];
		}

		$columns = $this->columnNames($database, $linkTable);
		$songIdColumn = $columns['songid'] ?? null;
		$entityIdColumn = $columns[strtolower($tables['linkColumn'])] ?? null;
		if ($songIdColumn === null || $entityIdColumn === null) {
			return [];
		}

		$placeholders = implode(',', array_fill(0, count($ids), '?'));
		$statement = $database->prepare(sprintf(
			'SELECT DISTINCT "%s" AS SongId FROM "%s" WHERE "%s" IN (%s) AND "%s" IS NOT NULL',
			$songIdColumn,
			$linkTable,
			$entityIdColumn,
			$placeholders,
			$songIdColumn,
		));
		$statement->execute($ids);

		$songIds = [];
		while ($row = $statement->fetch()) {
			$songIds[] = (int)$row['SongId'];
		}
		sort($songIds, SORT_NUMERIC);

		return $songIds;
	}

	/**
	 * Redirect songs from duplicate entity records to the canonical record and
	 * remove the duplicate records as one transaction.
	 *
	 * @param list<int> $duplicateIds
	 */
	public function mergeDuplicateEntities(string $databasePath, string $entityType, int $keepId, array $duplicateIds): int {
		$entityType = self::normalizeEntityType($entityType);
		$tables = self::ENTITY_TABLES[$entityType] ?? null;
		if ($tables === null) {
			throw new \InvalidArgumentException('Unknown entity type: ' . $entityType);
		}

		$ids = array_values(array_unique(array_map('intval', $duplicateIds)));
		$ids = array_values(array_filter($ids, static fn (int $id): bool => $id !== $keepId && $id > 0));
		sort($ids, SORT_NUMERIC);
		if ($ids === [] || $keepId <= 0) {
			return 0;
		}

		$database = $this->openConnection($databasePath);
		$entityTable = $this->resolveTable($database, self::ENTITY_LABELS[$entityType]['tables']);
		if ($entityTable === null) {
			throw new \RuntimeException(sprintf('Entity table for "%s" was not found.', $entityType));
		}

		$merged = 0;
		$database->beginTransaction();
		try {
			$linkTable = $this->resolveTable($database, [$tables['linkTable']]);
			if ($linkTable !== null) {
				$linkColumns = $this->columnNames($database, $linkTable);
				$songIdColumn = $linkColumns['songid'] ?? null;
				$entityIdColumn = $linkColumns[strtolower($tables['linkColumn'])] ?? null;
				if ($songIdColumn !== null && $entityIdColumn !== null) {
					$insert = $database->prepare(sprintf(
						'INSERT INTO "%s" ("%s", "%s") '
						. 'SELECT :keepId, duplicateSongs."%s" FROM ('
						. 'SELECT DISTINCT "%s" FROM "%s" WHERE "%s" = :duplicateId AND "%s" IS NOT NULL'
						. ') AS duplicateSongs WHERE NOT EXISTS ('
						. 'SELECT 1 FROM "%s" AS existing WHERE existing."%s" = duplicateSongs."%s" AND existing."%s" = :keepId'
						. ')',
						$linkTable,
						$entityIdColumn,
						$songIdColumn,
						$songIdColumn,
						$songIdColumn,
						$linkTable,
						$entityIdColumn,
						$songIdColumn,
						$linkTable,
						$songIdColumn,
						$songIdColumn,
						$entityIdColumn,
					));
					$deleteLinks = $database->prepare(sprintf(
						'DELETE FROM "%s" WHERE "%s" = :duplicateId',
						$linkTable,
						$entityIdColumn,
					));
					foreach ($ids as $duplicateId) {
						$insert->execute([':keepId' => $keepId, ':duplicateId' => $duplicateId]);
						$deleteLinks->execute([':duplicateId' => $duplicateId]);
					}
				}
			}

			$deleteEntity = $database->prepare(sprintf('DELETE FROM "%s" WHERE Id = :duplicateId', $entityTable));
			foreach ($ids as $duplicateId) {
				$deleteEntity->execute([':duplicateId' => $duplicateId]);
				$merged += $deleteEntity->rowCount();
			}

			$database->commit();
		} catch (\Throwable $exception) {
			$database->rollBack();
			throw $exception;
		}

		return $merged;
	}
}
