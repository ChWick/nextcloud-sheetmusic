<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

use PDO;
use Psr\Log\LoggerInterface;
use OCA\SheetMusic\Db\LibraryData;


class SongReader extends AbstractDatabaseMapper {
	public function __construct(
		LoggerInterface $logger,
		?SongFileMapper $fileMapper = null,
		?SongBookmarkMapper $bookmarkMapper = null,
		?SongAudioMapper $audioMapper = null,
	) {
		parent::__construct($logger);
	}

	/**
	 * @return LibraryData
	 */
	public function findLibraryData(string $databasePath): LibraryData {
		$database = $this->openConnection($databasePath);

		[$composers, $composersById] = $this->loadEntities($database, 'Composer', 'Name', fn(int $id, string $val) => new MobileSheetsComposer($id, $val));
		[$books, $booksById] = $this->loadEntities($database, 'Books', 'Title', fn(int $id, string $val) => new MobileSheetsBook($id, $val));
		[$collections, $collectionsById] = $this->loadEntities($database, 'Collections', 'Name', fn(int $id, string $val) => new MobileSheetsCollection($id, $val));
		[$artists, $artistsById] = $this->loadEntities($database, 'Artists', 'Name', fn(int $id, string $val) => new MobileSheetsArtist($id, $val));
		[$genres, $genresById] = $this->loadEntities($database, 'Genres', ['Type', 'Name'], fn(int $id, string $val) => new MobileSheetsGenre($id, $val));
		[$keys, $keysById] = $this->loadEntities($database, ['Key', 'Keys'], ['Name', 'Key'], fn(int $id, string $val) => new MobileSheetsKey($id, $val));

		$customGroups = [];
		$customGroupsById = [];
		$instruments = [];
		$instrumentsById = [];
		$instrumentClientIds = [];
		if ($this->hasTable($database, '_nc_sync_metadata')) {
			$instStmt = $database->query("SELECT client_id FROM _nc_sync_metadata WHERE entity_type = 'instrument' OR entity_type = 'entity:instrument'");
			if ($instStmt !== false) {
				while ($irow = $instStmt->fetch()) {
					$instrumentClientIds[(int)$irow['client_id']] = true;
				}
			}
		}

		if ($this->hasTable($database, 'CustomGroup')) {
			$stmt = $database->query('SELECT Id, Name FROM CustomGroup ORDER BY Name');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$cid = (int)$row['Id'];
					if (isset($instrumentClientIds[$cid])) {
						$instrument = new MobileSheetsCustomGroup($cid, (string)$row['Name']);
						$instruments[] = $instrument;
						$instrumentsById[$cid] = $instrument;
					} else {
						$customGroup = new MobileSheetsCustomGroup($cid, (string)$row['Name']);
						$customGroups[] = $customGroup;
						$customGroupsById[$customGroup->id] = $customGroup;
					}
				}
			}
		}

		[$years, $yearsById] = $this->loadEntities($database, 'Years', ['Year', 'Name'], fn(int $id, string $val) => new MobileSheetsYear($id, (int)$val));
		[$signatures, $signaturesById] = $this->loadEntities($database, 'Signature', 'Name', fn(int $id, string $val) => new MobileSheetsSignature($id, $val));
		[$sourceTypes, $sourceTypesById] = $this->loadEntities($database, ['SourceType', 'SourceTypes'], ['Type', 'Name'], fn(int $id, string $val) => new MobileSheetsSourceType($id, $val));

		$songComposers = $this->loadSongLinks($database, 'ComposerSongs', 'ComposerId');
		$songBooks = $this->loadSongLinks($database, 'BookSongs', 'BookId');
		$songCollections = $this->loadSongLinks($database, 'CollectionSong', 'CollectionId');

		$songFiles = [];
		if ($this->hasTable($database, 'Files')) {
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

		$songArtists = $this->loadSongLinks($database, 'ArtistsSongs', 'ArtistId');
		$songGenres = $this->loadSongLinks($database, 'GenresSongs', 'GenreId');
		$songKeys = $this->loadSongLinks($database, 'KeySongs', 'KeyId');

		$songCustomGroups = [];
		$songInstruments = [];
		if ($this->hasTable($database, 'CustomGroupSongs')) {
			$stmt = $database->query('SELECT GroupId, SongId FROM CustomGroupSongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$gid = (int)$row['GroupId'];
					$sid = (int)$row['SongId'];
					if (isset($instrumentClientIds[$gid])) {
						$songInstruments[$sid][] = $gid;
					} else {
						$songCustomGroups[$sid][] = $gid;
					}
				}
			}
		}

		$songYears = $this->loadSongLinks($database, 'YearsSongs', 'YearId');
		$songSignatures = $this->loadSongLinks($database, 'SignatureSongs', 'SignatureId');
		$songSourceTypes = $this->loadSongLinks($database, 'SourceTypeSongs', 'SourceTypeId');

		$songBookmarks = [];
		if ($this->hasTable($database, 'Bookmarks')) {
			$cols = $this->columnNames($database, 'Bookmarks');
			$hasShowInLibrary = isset($cols['showinlibrary']);
			$stmt = $database->query('SELECT Id, SongId, Name, PageNum' . ($hasShowInLibrary ? ', ShowInLibrary' : '') . ' FROM Bookmarks ORDER BY PageNum ASC, Name ASC');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songBookmarks[(int)$row['SongId']][] = new MobileSheetsBookmark(
						(int)$row['Id'],
						(int)$row['SongId'],
						(string)$row['Name'],
						(int)$row['PageNum'],
						isset($row['ShowInLibrary']) ? (int)$row['ShowInLibrary'] : 0,
					);
				}
			}
		}

		$songAudioFiles = [];
		if ($this->hasTable($database, 'AudioFiles')) {
			$titleCol = $this->resolveColumn($database, 'AudioFiles', ['Title', 'Name'], 'Title');
			$fileCol = $this->resolveColumn($database, 'AudioFiles', ['File', 'Path'], 'File');
			$stmt = $database->query('SELECT Id, SongId, ' . $titleCol . ' AS Title, ' . $fileCol . ' AS File FROM AudioFiles ORDER BY Id ASC');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songAudioFiles[(int)$row['SongId']][] = new MobileSheetsAudioFile(
						(int)$row['Id'],
						(int)$row['SongId'],
						(string)($row['Title'] ?? ''),
						(string)($row['File'] ?? ''),
					);
				}
			}
		}

		$statement = $database->query('SELECT * FROM Songs ORDER BY Title COLLATE NOCASE ASC');

		$songs = [];
		if ($statement !== false) {
			while ($row = $statement->fetch()) {
				$songId = (int)$row['Id'];
				$created = $row['DateCreated'] ?? $row['CreationDate'] ?? null;

				$songs[] = new MobileSheetsSong(
					$songId,
					(string)$row['Title'],
					$songFiles[$songId] ?? [],
					$this->resolveEntities($songComposers[$songId] ?? [], $composersById),
					$this->resolveEntities($songBooks[$songId] ?? [], $booksById),
					$this->resolveEntities($songCollections[$songId] ?? [], $collectionsById),
					$this->resolveEntities($songArtists[$songId] ?? [], $artistsById),
					$this->resolveEntities($songGenres[$songId] ?? [], $genresById),
					$this->resolveEntities($songKeys[$songId] ?? [], $keysById),
					$this->resolveEntities($songCustomGroups[$songId] ?? [], $customGroupsById),
					$this->resolveEntities($songYears[$songId] ?? [], $yearsById),
					$this->resolveEntities($songSignatures[$songId] ?? [], $signaturesById),
					$this->resolveEntities($songSourceTypes[$songId] ?? [], $sourceTypesById),
					$songBookmarks[$songId] ?? [],
					$songAudioFiles[$songId] ?? [],
					isset($row['LastModified']) && $row['LastModified'] !== null ? (int)$row['LastModified'] : null,
					$created !== null ? (int)$created : null,
					isset($row['Stars']) ? (int)$row['Stars'] : 0,
					isset($row['Difficulty']) ? (int)$row['Difficulty'] : 0,
					isset($row['Duration']) ? (int)$row['Duration'] : 0,
					isset($row['Keywords']) ? (string)$row['Keywords'] : '',
					isset($row['Custom']) ? (string)$row['Custom'] : '',
					isset($row['Custom2']) ? (string)$row['Custom2'] : '',
					isset($row['SortTitle']) ? (string)$row['SortTitle'] : '',
					isset($row['Opus']) ? (string)$row['Opus'] : '',
					isset($row['CatalogNumber']) ? (string)$row['CatalogNumber'] : '',
					isset($row['SongNumber']) ? (string)$row['SongNumber'] : '',
					$this->resolveEntities($songInstruments[$songId] ?? [], $instrumentsById),
				);
			}
		}

		return new LibraryData(
			songs: $songs,
			composers: $composers,
			books: $books,
			collections: $collections,
			artists: $artists,
			genres: $genres,
			keys: $keys,
			customGroups: $customGroups,
			years: $years,
			signatures: $signatures,
			sourceTypes: $sourceTypes,
			instruments: $instruments,
		);
	}

	/**
	 * @param int|null $songId when given, only that song is returned
	 * @return list<MobileSheetsSong>
	 */
	public function findSongs(string $databasePath, ?int $songId = null): array {
		$all = $this->findLibraryData($databasePath)->songs;
		if ($songId === null) {
			return $all;
		}
		foreach ($all as $song) {
			if ($song->id === $songId) {
				return [$song];
			}
		}
		return [];
	}

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
	 * @return array<int, list<int>> [songId => [entityId, ...]]
	 */
	private function loadSongLinks(PDO $database, string $tableName, string $entityIdCol): array {
		if (!$this->hasTable($database, $tableName)) {
			return [];
		}

		$stmt = $database->query('SELECT ' . $entityIdCol . ', SongId FROM "' . $tableName . '"');
		if ($stmt === false) {
			return [];
		}

		$links = [];
		while ($row = $stmt->fetch()) {
			$links[(int)$row['SongId']][] = (int)$row[$entityIdCol];
		}

		return $links;
	}

	/**
	 * @template T of object
	 * @param list<string>|string $table
	 * @param list<string>|string $column
	 * @param callable(int, string): T $factory
	 * @return array{0: list<T>, 1: array<int, T>}
	 */
	private function loadEntities(PDO $database, array|string $table, array|string $column, callable $factory): array {
		$tableName = is_array($table) ? $this->resolveTable($database, $table) : ($this->hasTable($database, $table) ? $table : null);
		if ($tableName === null) {
			return [[], []];
		}

		$colName = is_array($column)
			? $this->resolveColumn($database, $tableName, $column, $column[0])
			: $column;

		$stmt = $database->query('SELECT Id, ' . $colName . ' AS val FROM "' . $tableName . '" ORDER BY ' . $colName);
		if ($stmt === false) {
			return [[], []];
		}

		$list = [];
		$byId = [];
		while ($row = $stmt->fetch()) {
			$entity = $factory((int)$row['Id'], (string)$row['val']);
			$list[] = $entity;
			$byId[(int)$row['Id']] = $entity;
		}

		return [$list, $byId];
	}
}
