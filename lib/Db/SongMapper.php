<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Db;

use PDO;

class SongMapper {
	/**
	 * @return array{
	 *     songs: list<array{id: int, title: string, filePath: ?string, composerIds: list<int>, bookIds: list<int>, collectionIds: list<int>}>,
	 *     composers: list<array{id: int, name: string}>,
	 *     books: list<array{id: int, title: string}>,
	 *     collections: list<array{id: int, name: string}>
	 * }
	 */
	public function findLibraryData(string $databasePath): array {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$tablesStatement = $database->query("SELECT name FROM sqlite_master WHERE type='table'");
		/** @var list<string> $existingTables */
		$existingTables = $tablesStatement !== false ? $tablesStatement->fetchAll(PDO::FETCH_COLUMN) : [];

		$songComposers = [];
		if (in_array('ComposerSongs', $existingTables, true)) {
			$stmt = $database->query('SELECT ComposerId, SongId FROM ComposerSongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songComposers[(int)$row['SongId']][] = (int)$row['ComposerId'];
				}
			}
		}

		$songBooks = [];
		if (in_array('BookSongs', $existingTables, true)) {
			$stmt = $database->query('SELECT BookId, SongId FROM BookSongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songBooks[(int)$row['SongId']][] = (int)$row['BookId'];
				}
			}
		}

		$songCollections = [];
		if (in_array('CollectionSong', $existingTables, true)) {
			$stmt = $database->query('SELECT CollectionId, SongId FROM CollectionSong');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songCollections[(int)$row['SongId']][] = (int)$row['CollectionId'];
				}
			}
		}

		$statement = $database->query('SELECT Songs.Id, Songs.Title, Files.Path AS FilePath FROM Songs LEFT JOIN Files ON Files.SongId = Songs.Id ORDER BY Songs.SortTitle, Songs.Title');

		$songs = [];
		if ($statement !== false) {
			while ($row = $statement->fetch()) {
				$songId = (int)$row['Id'];
				$songs[] = [
					'id' => $songId,
					'title' => (string)$row['Title'],
					'filePath' => $row['FilePath'] !== null ? (string)$row['FilePath'] : null,
					'composerIds' => $songComposers[$songId] ?? [],
					'bookIds' => $songBooks[$songId] ?? [],
					'collectionIds' => $songCollections[$songId] ?? [],
				];
			}
		}

		$composers = [];
		if (in_array('Composer', $existingTables, true)) {
			$stmt = $database->query('SELECT Id, Name FROM Composer ORDER BY Name');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$composers[] = [
						'id' => (int)$row['Id'],
						'name' => (string)$row['Name'],
					];
				}
			}
		}

		$books = [];
		if (in_array('Books', $existingTables, true)) {
			$stmt = $database->query('SELECT Id, Title FROM Books ORDER BY Title');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$books[] = [
						'id' => (int)$row['Id'],
						'title' => (string)$row['Title'],
					];
				}
			}
		}

		$collections = [];
		if (in_array('Collections', $existingTables, true)) {
			$stmt = $database->query('SELECT Id, Name FROM Collections ORDER BY Name');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$collections[] = [
						'id' => (int)$row['Id'],
						'name' => (string)$row['Name'],
					];
				}
			}
		}

		return [
			'songs' => $songs,
			'composers' => $composers,
			'books' => $books,
			'collections' => $collections,
		];
	}

	/**
	 * @return list<array{id: int, title: string, filePath: ?string, composerIds: list<int>, bookIds: list<int>, collectionIds: list<int>}>
	 */
	public function findSongs(string $databasePath): array {
		return $this->findLibraryData($databasePath)['songs'];
	}

	/**
	 * @param list<int> $composerIds
	 * @param list<int> $bookIds
	 * @param list<int> $collectionIds
	 */
	public function updateSong(
		string $databasePath,
		int $songId,
		string $title,
		array $composerIds,
		array $bookIds,
		array $collectionIds,
	): void {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$tablesStatement = $database->query("SELECT name FROM sqlite_master WHERE type='table'");
		/** @var list<string> $existingTables */
		$existingTables = $tablesStatement !== false ? $tablesStatement->fetchAll(PDO::FETCH_COLUMN) : [];

		// Update Song Title and SortTitle
		$stmt = $database->prepare('UPDATE Songs SET Title = :title, SortTitle = :sortTitle WHERE Id = :id');
		$stmt->execute([
			':title' => $title,
			':sortTitle' => $title,
			':id' => $songId,
		]);

		// Update Composers
		if (in_array('ComposerSongs', $existingTables, true)) {
			$delStmt = $database->prepare('DELETE FROM ComposerSongs WHERE SongId = :songId');
			$delStmt->execute([':songId' => $songId]);

			$insStmt = $database->prepare('INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (:composerId, :songId)');
			foreach ($composerIds as $composerId) {
				$insStmt->execute([
					':composerId' => $composerId,
					':songId' => $songId,
				]);
			}
		}

		// Update Books
		if (in_array('BookSongs', $existingTables, true)) {
			$delStmt = $database->prepare('DELETE FROM BookSongs WHERE SongId = :songId');
			$delStmt->execute([':songId' => $songId]);

			$insStmt = $database->prepare('INSERT INTO BookSongs (BookId, SongId) VALUES (:bookId, :songId)');
			foreach ($bookIds as $bookId) {
				$insStmt->execute([
					':bookId' => $bookId,
					':songId' => $songId,
				]);
			}
		}

		// Update Collections
		if (in_array('CollectionSong', $existingTables, true)) {
			$delStmt = $database->prepare('DELETE FROM CollectionSong WHERE SongId = :songId');
			$delStmt->execute([':songId' => $songId]);

			$insStmt = $database->prepare('INSERT INTO CollectionSong (CollectionId, SongId) VALUES (:collectionId, :songId)');
			foreach ($collectionIds as $collectionId) {
				$insStmt->execute([
					':collectionId' => $collectionId,
					':songId' => $songId,
				]);
			}
		}
	}

	public function setSongFile(string $databasePath, int $songId, string $filePath): void {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$checkStmt = $database->prepare('SELECT Id FROM Files WHERE SongId = :songId LIMIT 1');
		$checkStmt->execute([':songId' => $songId]);
		$existing = $checkStmt->fetch();

		if ($existing !== false) {
			$updateStmt = $database->prepare('UPDATE Files SET Path = :path WHERE SongId = :songId');
			$updateStmt->execute([
				':path' => $filePath,
				':songId' => $songId,
			]);
		} else {
			$insertStmt = $database->prepare('INSERT INTO Files (SongId, Path) VALUES (:songId, :path)');
			$insertStmt->execute([
				':songId' => $songId,
				':path' => $filePath,
			]);
		}
	}

	public function createComposer(string $databasePath, string $name): int {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		$stmt = $database->prepare('INSERT INTO Composer (Name) VALUES (:name)');
		$stmt->execute([':name' => $name]);
		return (int)$database->lastInsertId();
	}

	public function createBook(string $databasePath, string $title): int {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		$stmt = $database->prepare('INSERT INTO Books (Title) VALUES (:title)');
		$stmt->execute([':title' => $title]);
		return (int)$database->lastInsertId();
	}

	public function createCollection(string $databasePath, string $name): int {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		$stmt = $database->prepare('INSERT INTO Collections (Name) VALUES (:name)');
		$stmt->execute([':name' => $name]);
		return (int)$database->lastInsertId();
	}
}