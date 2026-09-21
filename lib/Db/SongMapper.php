<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Db;

use PDO;
use Psr\Log\LoggerInterface;

class SongMapper {
	public function __construct(
		private LoggerInterface $logger,
    ) {}

	/**
	 * @return array{
	 *     songs: list<MobileSheetsSong>,
	 *     composers: list<array{id: int, name: string}>,
	 *     books: list<array{id: int, title: string}>,
	 *     collections: list<array{id: int, name: string}>,
	 *     artists: list<array{id: int, name: string}>
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

		$songFiles = [];
		if (in_array('Files', $existingTables, true)) {
			$stmt = $database->query('SELECT Id, SongId, Path FROM Files');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songFiles[(int)$row['SongId']][] = new MobileSheetsFile(
						(int)$row['Id'],
						(string)$row['Path'],
					);
				}
			}
		}
		
		$songArtists = [];
		if (in_array('ArtistsSongs', $existingTables, true)) {
			$stmt = $database->query('SELECT ArtistId, SongId FROM ArtistsSongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songArtists[(int)$row['SongId']][] = (int)$row['ArtistId'];
				}
			}
		}

		$statement = $database->query('SELECT Songs.Id, Songs.Title FROM Songs ORDER BY Songs.Title COLLATE NOCASE ASC');

		$songs = [];
		if ($statement !== false) {
			while ($row = $statement->fetch()) {
				$songId = (int)$row['Id'];
				$files = $songFiles[$songId] ?? [];

				$songs[] = new MobileSheetsSong(
					$songId,
					(string)$row['Title'],
					$files,
					$songComposers[$songId] ?? [],
					$songBooks[$songId] ?? [],
					$songCollections[$songId] ?? [],
					$songArtists[$songId] ?? [],
				);
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

		$artists = [];
		if (in_array('Artists', $existingTables, true)) {
			$stmt = $database->query('SELECT Id, Name FROM Artists ORDER BY Name');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$artists[] = [
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
			'artists' => $artists,
		];
	}

	/**
	 * @return list<MobileSheetsSong>
	 */
	public function findSongs(string $databasePath): array {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$statement = $database->query('SELECT 
			s.Title,
			s.Id,
			g.Type as Genre,
			c.Name AS Composer,
			cs.ComposerId AS ComposerId,
			coll.Name AS Collection,
			colls.CollectionId AS CollectionId,
			books.Title AS Book,
			bookSongs.BookId AS BookId,
			artist.Name AS Artist,
			artistsSongs.ArtistId AS ArtistId
		FROM Songs s
		LEFT JOIN ComposerSongs cs ON s.Id = cs.SongId
		LEFT JOIN Composer c ON cs.ComposerId = c.Id
		LEFT JOIN CollectionSong colls ON s.Id = colls.SongId
		LEFT JOIN Collections coll ON colls.CollectionId = coll.Id
		LEFT JOIN GenresSongs gs ON s.Id = gs.SongId
		LEFT JOIN Genres g ON gs.GenreId = g.Id
		LEFT JOIN BookSongs bookSongs ON s.Id = bookSongs.SongId
		LEFT JOIN Books books ON bookSongs.BookId = books.Id
		LEFT JOIN ArtistsSongs artistsSongs ON artistsSongs.SongId = s.Id
		LEFT JOIN Artists artist on artistsSongs.ArtistId = artist.Id');

		$songs = [];
		if ($statement !== false) {
			while ($row = $statement->fetch()) {
				$songId = (int)$row['Id'];

				if (!isset($songs[$songId])) {
					$songs[$songId] = [
						'id' => $songId,
						'title' => (string)$row['Title'],
						'composer' => null,
						'artist' => null,
						'book' => null,
						'collection' => null,
						'composerIds' => [],
						'bookIds' => [],
						'collectionIds' => [],
						'artistIds' => [],
					];
				}

				foreach (['Composer' => 'composer', 'Artist' => 'artist', 'Book' => 'book', 'Collection' => 'collection'] as $column => $key) {
					if ($songs[$songId][$key] === null && $row[$column] !== null && $row[$column] !== '') {
						$songs[$songId][$key] = (string)$row[$column];
					}
				}

				foreach (['ComposerId' => 'composerIds', 'BookId' => 'bookIds', 'CollectionId' => 'collectionIds', 'ArtistId' => 'artistIds'] as $column => $key) {
					if ($row[$column] !== null) {
						$id = (int)$row[$column];
						if (!in_array($id, $songs[$songId][$key], true)) {
							$songs[$songId][$key][] = $id;
						}
					}
				}
			}
		}

		$result = [];
		foreach ($songs as $song) {
			$result[] = new MobileSheetsSong(
				$song['id'],
				$song['title'],
				$this->filesOfSong($databasePath, $song['id']),
				$song['composerIds'],
				$song['bookIds'],
				$song['collectionIds'],
				$song['artistIds'],
				$song['composer'],
				$song['artist'],
				$song['book'],
				$song['collection'],
			);
		}

		return $result;
	}

	/**
	 * @return list<MobileSheetsFile>
	 */
	public function filesOfSong(string $databasePath, int $songId): array {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$statement = $database->query('SELECT Id, Path FROM Files WHERE SongId = '.$songId);

		$files = [];
		if ($statement !== false) {
			while ($row = $statement->fetch()) {
				$files[] = new MobileSheetsFile(
					(int)$row['Id'],
					(string)$row['Path'],
				);
			}
		}

		return $files;
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
		array $artistIds
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

		// Update Artists
		if (in_array('ArtistsSongs', $existingTables, true)) {
			$delStmt = $database->prepare('DELETE FROM ArtistsSongs WHERE SongId =	 :songId');
			$delStmt->execute([':songId' => $songId]);

			$insStmt = $database->prepare('INSERT INTO ArtistsSongs (ArtistId, SongId) VALUES (:artistId, :songId)');
			foreach ($artistIds as $artistId) {
				$insStmt->execute([
					':artistId' => $artistId,
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

		$insertStmt = $database->prepare('INSERT INTO Files (SongId, Path) VALUES (:songId, :path)');
		$insertStmt->execute([
			':songId' => $songId,
			':path' => $filePath,
		]);
	}

	public function removeSongFile(string $databasePath, int $songId, int $fileId): ?string {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$stmt = $database->prepare('SELECT Path FROM Files WHERE SongId = :songId AND Id = :fileId');
		$stmt->execute([':songId' => $songId, ':fileId' => $fileId]);
		$row = $stmt->fetch();
		if ($row === false) {
			return null;
		}

		$delStmt = $database->prepare('DELETE FROM Files WHERE SongId = :songId AND Id = :fileId');
		$delStmt->execute([':songId' => $songId, ':fileId' => $fileId]);

		return (string)$row['Path'];
	}
	
	public function updateFile(string $databasePath, int $fileId, string $newPath): void {
        $database = new PDO('sqlite:' . $databasePath, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $updateStmt = $database->prepare('UPDATE Files SET Path = :newPath WHERE Id = :fileId');
        $updateStmt->execute([
            ':newPath' => $newPath,
            ':fileId' => $fileId,
        ]);
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

	public function createArtist(string $databasePath, string $name): int {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		$stmt = $database->prepare('INSERT INTO Artists (Name) VALUES (:name)');
		$stmt->execute([':name' => $name]);
		return (int)$database->lastInsertId();
	}
}