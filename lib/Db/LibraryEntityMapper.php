<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Db;

use PDO;

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
}
