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
	 *     composers: list<MobileSheetsComposer>,
	 *     books: list<MobileSheetsBook>,
	 *     collections: list<MobileSheetsCollection>,
	 *     artists: list<MobileSheetsArtist>
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

		$composers = [];
		$composersById = [];
		if (in_array('Composer', $existingTables, true)) {
			$stmt = $database->query('SELECT Id, Name FROM Composer ORDER BY Name');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$composer = new MobileSheetsComposer((int)$row['Id'], (string)$row['Name']);
					$composers[] = $composer;
					$composersById[$composer->id] = $composer;
				}
			}
		}

		$books = [];
		$booksById = [];
		if (in_array('Books', $existingTables, true)) {
			$stmt = $database->query('SELECT Id, Title FROM Books ORDER BY Title');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$book = new MobileSheetsBook((int)$row['Id'], (string)$row['Title']);
					$books[] = $book;
					$booksById[$book->id] = $book;
				}
			}
		}

		$collections = [];
		$collectionsById = [];
		if (in_array('Collections', $existingTables, true)) {
			$stmt = $database->query('SELECT Id, Name FROM Collections ORDER BY Name');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$collection = new MobileSheetsCollection((int)$row['Id'], (string)$row['Name']);
					$collections[] = $collection;
					$collectionsById[$collection->id] = $collection;
				}
			}
		}

		$artists = [];
		$artistsById = [];
		if (in_array('Artists', $existingTables, true)) {
			$stmt = $database->query('SELECT Id, Name FROM Artists ORDER BY Name');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$artist = new MobileSheetsArtist((int)$row['Id'], (string)$row['Name']);
					$artists[] = $artist;
					$artistsById[$artist->id] = $artist;
				}
			}
		}

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

				$songs[] = new MobileSheetsSong(
					$songId,
					(string)$row['Title'],
					$songFiles[$songId] ?? [],
					$this->resolveEntities($songComposers[$songId] ?? [], $composersById),
					$this->resolveEntities($songBooks[$songId] ?? [], $booksById),
					$this->resolveEntities($songCollections[$songId] ?? [], $collectionsById),
					$this->resolveEntities($songArtists[$songId] ?? [], $artistsById),
				);
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
	 * @param int|null $songId when given, only that song is returned
	 * @return list<MobileSheetsSong>
	 */
	public function findSongs(string $databasePath, ?int $songId = null): array {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$statement = $database->prepare('SELECT 
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
		LEFT JOIN Artists artist on artistsSongs.ArtistId = artist.Id'
			. ($songId === null ? '' : ' WHERE s.Id = :songId'));

		if ($statement === false) {
			return [];
		}

		if ($songId !== null) {
			$statement->bindValue(':songId', $songId, PDO::PARAM_INT);
		}
		$statement->execute();

		$songs = [];
		while ($row = $statement->fetch()) {
			$rowSongId = (int)$row['Id'];

			if (!isset($songs[$rowSongId])) {
				$songs[$rowSongId] = [
					'id' => $rowSongId,
					'title' => (string)$row['Title'],
					'composers' => [],
					'books' => [],
					'collections' => [],
					'artists' => [],
				];
			}

			$composerId = $row['ComposerId'] !== null ? (int)$row['ComposerId'] : null;
			if ($composerId !== null && !isset($songs[$rowSongId]['composers'][$composerId])) {
				$songs[$rowSongId]['composers'][$composerId] = new MobileSheetsComposer($composerId, (string)($row['Composer'] ?? ''));
			}

			$bookId = $row['BookId'] !== null ? (int)$row['BookId'] : null;
			if ($bookId !== null && !isset($songs[$rowSongId]['books'][$bookId])) {
				$songs[$rowSongId]['books'][$bookId] = new MobileSheetsBook($bookId, (string)($row['Book'] ?? ''));
			}

			$collectionId = $row['CollectionId'] !== null ? (int)$row['CollectionId'] : null;
			if ($collectionId !== null && !isset($songs[$rowSongId]['collections'][$collectionId])) {
				$songs[$rowSongId]['collections'][$collectionId] = new MobileSheetsCollection($collectionId, (string)($row['Collection'] ?? ''));
			}

			$artistId = $row['ArtistId'] !== null ? (int)$row['ArtistId'] : null;
			if ($artistId !== null && !isset($songs[$rowSongId]['artists'][$artistId])) {
				$songs[$rowSongId]['artists'][$artistId] = new MobileSheetsArtist($artistId, (string)($row['Artist'] ?? ''));
			}
		}

		$result = [];
		foreach ($songs as $song) {
			$result[] = new MobileSheetsSong(
				$song['id'],
				$song['title'],
				$this->filesOfSong($databasePath, $song['id']),
				array_values($song['composers']),
				array_values($song['books']),
				array_values($song['collections']),
				array_values($song['artists']),
			);
		}

		return $result;
	}

	/**
	 * A single song with its nested entities and files, or null when it is unknown.
	 *
	 * Files come back ordered by Id, i.e. in the order that decides the numeric prefix
	 * of their regenerated file names.
	 */
	public function findSong(string $databasePath, int $songId): ?MobileSheetsSong {
		$songs = $this->findSongs($databasePath, $songId);

		return $songs[0] ?? null;
	}

	/**
	 * @template T of object
	 * @param list<int> $ids
	 * @param array<int, T> $entitiesById
	 * @return list<T>
	 */
	private function resolveEntities(array $ids, array $entitiesById): array {
		$entities = [];
		foreach ($ids as $id) {
			if (isset($entitiesById[$id])) {
				$entities[] = $entitiesById[$id];
			}
		}

		return $entities;
	}

	/**
	 * @return list<MobileSheetsFile>
	 */
	public function filesOfSong(string $databasePath, int $songId): array {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		// Ordered by Id so a song's files always come back in the same order: their
		// position decides the numeric prefix in the regenerated file name.
		$statement = $database->query('SELECT Id, Path FROM Files WHERE SongId = '.$songId.' ORDER BY Id ASC');

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
	 * Create a song in the MobileSheets database.
	 *
	 * MobileSheets keeps many more columns per song, but only the title is needed to make a
	 * song show up in the library - the rest is filled in by the app the user syncs with.
	 * The relations are written by updateSong(), so creating and editing a song share that
	 * code.
	 *
	 * @param list<int> $composerIds
	 * @param list<int> $bookIds
	 * @param list<int> $collectionIds
	 * @param list<int> $artistIds
	 * @return int id of the created song
	 */
	public function createSong(
		string $databasePath,
		string $title,
		array $composerIds,
		array $bookIds,
		array $collectionIds,
		array $artistIds
	): int {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$insertStmt = $database->prepare('INSERT INTO Songs (Title, SortTitle) VALUES (:title, :sortTitle)');
		$insertStmt->execute([
			':title' => $title,
			':sortTitle' => $title,
		]);
		$songId = (int)$database->lastInsertId();

		$this->updateSong($databasePath, $songId, $title, $composerIds, $bookIds, $collectionIds, $artistIds);

		return $songId;
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

	/**
	 * Delete a song together with everything that references it.
	 *
	 * MobileSheets spreads a song over many tables: its files, the links to composers,
	 * books, collections and artists, but also settings such as its metronome, zoom or
	 * annotations. The rows are therefore dropped from every table that has a SongId
	 * column. The Songs row itself is removed by Id, because its own SongId column means
	 * something else and must not take other songs with it.
	 *
	 * @return list<string> WebDAV paths of the song's files, read before the rows were
	 *                      removed, so the caller can move them out of the user's folder
	 */
	public function deleteSong(string $databasePath, int $songId): array {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$tablesStatement = $database->query("SELECT name FROM sqlite_master WHERE type='table'");
		/** @var list<string> $existingTables */
		$existingTables = $tablesStatement !== false ? $tablesStatement->fetchAll(PDO::FETCH_COLUMN) : [];

		// Read the file list first: once the rows are gone, the database no longer knows
		// which files belonged to the song.
		$paths = [];
		if (in_array('Files', $existingTables, true)) {
			$fileStmt = $database->prepare('SELECT Path FROM Files WHERE SongId = :songId');
			$fileStmt->execute([':songId' => $songId]);
			while ($row = $fileStmt->fetch()) {
				$paths[] = (string)$row['Path'];
			}
		}

		$database->beginTransaction();
		try {
			foreach ($existingTables as $table) {
				if ($table === 'Songs' || !$this->hasSongIdColumn($database, $table)) {
					continue;
				}

				// Table names come from the schema itself, so quoting them is all that is
				// needed; the song to delete stays a bound parameter.
				$stmt = $database->prepare('DELETE FROM "' . $table . '" WHERE SongId = :songId');
				$stmt->execute([':songId' => $songId]);
			}

			if (in_array('Songs', $existingTables, true)) {
				$stmt = $database->prepare('DELETE FROM Songs WHERE Id = :songId');
				$stmt->execute([':songId' => $songId]);
			}

			$database->commit();
		} catch (\Throwable $exception) {
			$database->rollBack();
			throw $exception;
		}

		return $paths;
	}

	/** Whether a table links to songs, i.e. has a SongId column. */
	private function hasSongIdColumn(PDO $database, string $table): bool {
		$statement = $database->query('PRAGMA table_info("' . $table . '")');
		if ($statement === false) {
			return false;
		}

		foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
			if (strtolower((string)$column['name']) === 'songid') {
				return true;
			}
		}

		return false;
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

	/**
	 * Tables of the four entity kinds a song can carry, and how each of them links
	 * to songs.
	 *
	 * @var array<string, array{table: string, linkTable: string, linkColumn: string}>
	 */
	private const ENTITY_TABLES = [
		'composers' => ['table' => 'Composer', 'linkTable' => 'ComposerSongs', 'linkColumn' => 'ComposerId'],
		'books' => ['table' => 'Books', 'linkTable' => 'BookSongs', 'linkColumn' => 'BookId'],
		'collections' => ['table' => 'Collections', 'linkTable' => 'CollectionSong', 'linkColumn' => 'CollectionId'],
		'artists' => ['table' => 'Artists', 'linkTable' => 'ArtistsSongs', 'linkColumn' => 'ArtistId'],
	];

	/**
	 * Delete a composer, book, collection or artist from the library.
	 *
	 * The songs are kept: only the links to the deleted entry are removed, so it
	 * disappears from the songs' composers, books, collections or artists while the
	 * songs themselves stay untouched.
	 *
	 * @param 'composers'|'books'|'collections'|'artists' $entityType
	 * @return bool whether the entry itself existed and was removed
	 */
	public function deleteEntity(string $databasePath, string $entityType, int $entityId): bool {
		$tables = self::ENTITY_TABLES[$entityType] ?? null;
		if ($tables === null) {
			throw new \InvalidArgumentException('Unknown entity type: ' . $entityType);
		}

		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$tablesStatement = $database->query("SELECT name FROM sqlite_master WHERE type='table'");
		/** @var list<string> $existingTables */
		$existingTables = $tablesStatement !== false ? $tablesStatement->fetchAll(PDO::FETCH_COLUMN) : [];

		$removed = 0;
		$database->beginTransaction();
		try {
			// Table and column names come from the constant above, so quoting them is
			// all that is needed; the entry to delete stays a bound parameter.
			if (in_array($tables['linkTable'], $existingTables, true)) {
				$stmt = $database->prepare('DELETE FROM "' . $tables['linkTable'] . '" WHERE ' . $tables['linkColumn'] . ' = :entityId');
				$stmt->execute([':entityId' => $entityId]);
			}

			if (in_array($tables['table'], $existingTables, true)) {
				$stmt = $database->prepare('DELETE FROM "' . $tables['table'] . '" WHERE Id = :entityId');
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