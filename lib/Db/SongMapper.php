<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Db;

use OCA\MobileSheetsManager\Service\MobileSheetsFileFacts;
use PDO;
use Psr\Log\LoggerInterface;

class SongMapper {
	/** Source of a file that lives in the library folder. */
	private const FILE_SOURCE_LOCAL = 1;

	/** Type constants for MobileSheets Files.Type column */
	public const FILE_TYPE_OTHER = 0;
	public const FILE_TYPE_PDF = 1;
	public const FILE_TYPE_IMAGE = 2;
	public const FILE_TYPE_TEXT = 3;
	public const FILE_TYPE_ABC = 4;

	/** Width/Height of a file whose dimensions were not measured. */
	private const FILE_DIMENSION_UNKNOWN = -1;

	/**
	 * Map a file path's extension to MobileSheets Files.Type.
	 */
	public static function determineFileType(string $path): int {
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return match ($extension) {
			'pdf' => self::FILE_TYPE_PDF,
			'png', 'jpg', 'jpeg', 'bmp', 'gif', 'webp', 'svg' => self::FILE_TYPE_IMAGE,
			'txt', 'cho', 'chopro', 'crd', 'chordpro' => self::FILE_TYPE_TEXT,
			'abc' => self::FILE_TYPE_ABC,
			default => self::FILE_TYPE_OTHER,
		};
	}

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{
	 *     songs: list<MobileSheetsSong>,
	 *     composers: list<MobileSheetsComposer>,
	 *     books: list<MobileSheetsBook>,
	 *     collections: list<MobileSheetsCollection>,
	 *     artists: list<MobileSheetsArtist>,
	 *     genres: list<MobileSheetsGenre>,
	 *     keys: list<MobileSheetsKey>,
	 *     customGroups: list<MobileSheetsCustomGroup>,
	 *     years: list<MobileSheetsYear>,
	 *     signatures: list<MobileSheetsSignature>
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

		$genres = [];
		$genresById = [];
		if (in_array('Genres', $existingTables, true)) {
			$stmt = $database->query('SELECT Id, Type FROM Genres ORDER BY Type');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$genre = new MobileSheetsGenre((int)$row['Id'], (string)$row['Type']);
					$genres[] = $genre;
					$genresById[$genre->id] = $genre;
				}
			}
		}

		$keys = [];
		$keysById = [];
		if (in_array('Keys', $existingTables, true)) {
			$stmt = $database->query('SELECT Id, Name FROM Keys ORDER BY Name');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$key = new MobileSheetsKey((int)$row['Id'], (string)$row['Name']);
					$keys[] = $key;
					$keysById[$key->id] = $key;
				}
			}
		}

		$customGroups = [];
		$customGroupsById = [];
		if (in_array('CustomGroup', $existingTables, true)) {
			$stmt = $database->query('SELECT Id, Name FROM CustomGroup ORDER BY Name');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$customGroup = new MobileSheetsCustomGroup((int)$row['Id'], (string)$row['Name']);
					$customGroups[] = $customGroup;
					$customGroupsById[$customGroup->id] = $customGroup;
				}
			}
		}

		$years = [];
		$yearsById = [];
		if (in_array('Years', $existingTables, true)) {
			$stmt = $database->query('SELECT Id, Year FROM Years ORDER BY Year ASC');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$year = new MobileSheetsYear((int)$row['Id'], (int)$row['Year']);
					$years[] = $year;
					$yearsById[$year->id] = $year;
				}
			}
		}

		$signatures = [];
		$signaturesById = [];
		if (in_array('Signature', $existingTables, true)) {
			$stmt = $database->query('SELECT Id, Name FROM Signature ORDER BY Name');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$signature = new MobileSheetsSignature((int)$row['Id'], (string)$row['Name']);
					$signatures[] = $signature;
					$signaturesById[$signature->id] = $signature;
				}
			}
		}

		$sourceTypes = [];
		$sourceTypesById = [];
		if (in_array('SourceTypes', $existingTables, true)) {
			$stmt = $database->query('SELECT Id, Type FROM SourceTypes ORDER BY Type');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$sourceType = new MobileSheetsSourceType((int)$row['Id'], (string)$row['Type']);
					$sourceTypes[] = $sourceType;
					$sourceTypesById[$sourceType->id] = $sourceType;
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

		$songGenres = [];
		if (in_array('GenresSongs', $existingTables, true)) {
			$stmt = $database->query('SELECT GenreId, SongId FROM GenresSongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songGenres[(int)$row['SongId']][] = (int)$row['GenreId'];
				}
			}
		}

		$songKeys = [];
		if (in_array('KeySongs', $existingTables, true)) {
			$stmt = $database->query('SELECT KeyId, SongId FROM KeySongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songKeys[(int)$row['SongId']][] = (int)$row['KeyId'];
				}
			}
		}

		$songCustomGroups = [];
		if (in_array('CustomGroupSongs', $existingTables, true)) {
			$stmt = $database->query('SELECT GroupId, SongId FROM CustomGroupSongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songCustomGroups[(int)$row['SongId']][] = (int)$row['GroupId'];
				}
			}
		}

		$songYears = [];
		if (in_array('YearsSongs', $existingTables, true)) {
			$stmt = $database->query('SELECT YearId, SongId FROM YearsSongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songYears[(int)$row['SongId']][] = (int)$row['YearId'];
				}
			}
		}

		$songSignatures = [];
		if (in_array('SignatureSongs', $existingTables, true)) {
			$stmt = $database->query('SELECT SignatureId, SongId FROM SignatureSongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songSignatures[(int)$row['SongId']][] = (int)$row['SignatureId'];
				}
			}
		}

		$songSourceTypes = [];
		if (in_array('SourceTypeSongs', $existingTables, true)) {
			$stmt = $database->query('SELECT SourceTypeId, SongId FROM SourceTypeSongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songSourceTypes[(int)$row['SongId']][] = (int)$row['SourceTypeId'];
				}
			}
		}

		$songCols = $this->columnNames($database, 'Songs');
		$hasLastModified = isset($songCols['lastmodified']);
		$createdCol = isset($songCols['datecreated']) ? 'DateCreated' : (isset($songCols['creationdate']) ? 'CreationDate' : null);
		$hasStars = isset($songCols['stars']);
		$hasDifficulty = isset($songCols['difficulty']);
		$hasDuration = isset($songCols['duration']);
		$hasKeywords = isset($songCols['keywords']);
		$hasCustom = isset($songCols['custom']);
		$hasCustom2 = isset($songCols['custom2']);
		$hasSortTitle = isset($songCols['sorttitle']);

		$selectFields = ['Songs.Id', 'Songs.Title'];
		if ($hasLastModified) {
			$selectFields[] = 'Songs.LastModified';
		}
		if ($createdCol !== null) {
			$selectFields[] = 'Songs.' . $createdCol . ' AS DateCreated';
		}
		if ($hasStars) {
			$selectFields[] = 'Songs.Stars';
		}
		if ($hasDifficulty) {
			$selectFields[] = 'Songs.Difficulty';
		}
		if ($hasDuration) {
			$selectFields[] = 'Songs.Duration';
		}
		if ($hasKeywords) {
			$selectFields[] = 'Songs.Keywords';
		}
		if ($hasCustom) {
			$selectFields[] = 'Songs.Custom';
		}
		if ($hasCustom2) {
			$selectFields[] = 'Songs.Custom2';
		}
		if ($hasSortTitle) {
			$selectFields[] = 'Songs.SortTitle';
		}

		$statement = $database->query('SELECT ' . implode(', ', $selectFields) . ' FROM Songs ORDER BY Songs.Title COLLATE NOCASE ASC');

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
					$this->resolveEntities($songGenres[$songId] ?? [], $genresById),
					$this->resolveEntities($songKeys[$songId] ?? [], $keysById),
					$this->resolveEntities($songCustomGroups[$songId] ?? [], $customGroupsById),
					$this->resolveEntities($songYears[$songId] ?? [], $yearsById),
					$this->resolveEntities($songSignatures[$songId] ?? [], $signaturesById),
					$this->resolveEntities($songSourceTypes[$songId] ?? [], $sourceTypesById),
					isset($row['LastModified']) && $row['LastModified'] !== null ? (int)$row['LastModified'] : null,
					isset($row['DateCreated']) && $row['DateCreated'] !== null ? (int)$row['DateCreated'] : null,
					isset($row['Stars']) ? (int)$row['Stars'] : 0,
					isset($row['Difficulty']) ? (int)$row['Difficulty'] : 0,
					isset($row['Duration']) ? (int)$row['Duration'] : 0,
					isset($row['Keywords']) ? (string)$row['Keywords'] : '',
					isset($row['Custom']) ? (string)$row['Custom'] : '',
					isset($row['Custom2']) ? (string)$row['Custom2'] : '',
					isset($row['SortTitle']) ? (string)$row['SortTitle'] : '',
				);
			}
		}

		return [
			'songs' => $songs,
			'composers' => $composers,
			'books' => $books,
			'collections' => $collections,
			'artists' => $artists,
			'genres' => $genres,
			'keys' => $keys,
			'customGroups' => $customGroups,
			'years' => $years,
			'signatures' => $signatures,
			'sourceTypes' => $sourceTypes,
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

		$songCols = $this->columnNames($database, 'Songs');
		$hasLastModified = isset($songCols['lastmodified']);
		$createdCol = isset($songCols['datecreated']) ? 'DateCreated' : (isset($songCols['creationdate']) ? 'CreationDate' : null);
		$hasStars = isset($songCols['stars']);
		$hasDifficulty = isset($songCols['difficulty']);
		$hasDuration = isset($songCols['duration']);
		$hasKeywords = isset($songCols['keywords']);
		$hasCustom = isset($songCols['custom']);
		$hasCustom2 = isset($songCols['custom2']);
		$hasSortTitle = isset($songCols['sorttitle']);

		$selectFields = [
			's.Title',
			's.Id',
		];
		if ($hasLastModified) {
			$selectFields[] = 's.LastModified';
		}
		if ($createdCol !== null) {
			$selectFields[] = 's.' . $createdCol . ' AS DateCreated';
		}
		if ($hasStars) {
			$selectFields[] = 's.Stars';
		}
		if ($hasDifficulty) {
			$selectFields[] = 's.Difficulty';
		}
		if ($hasDuration) {
			$selectFields[] = 's.Duration';
		}
		if ($hasKeywords) {
			$selectFields[] = 's.Keywords';
		}
		if ($hasCustom) {
			$selectFields[] = 's.Custom';
		}
		if ($hasCustom2) {
			$selectFields[] = 's.Custom2';
		}
		if ($hasSortTitle) {
			$selectFields[] = 's.SortTitle';
		}
		$selectFields = array_merge($selectFields, [
			'gs.GenreId AS GenreId',
			'g.Type AS Genre',
			'ks.KeyId AS KeyId',
			'k.Name AS Key',
			'cgs.GroupId AS CustomGroupId',
			'cg.Name AS CustomGroup',
			'ys.YearId AS YearId',
			'y.Year AS Year',
			'sigs.SignatureId AS SignatureId',
			'sig.Name AS Signature',
			'srctypes.SourceTypeId AS SourceTypeId',
			'srctype.Type AS SourceType',
			'c.Name AS Composer',
			'cs.ComposerId AS ComposerId',
			'coll.Name AS Collection',
			'colls.CollectionId AS CollectionId',
			'books.Title AS Book',
			'bookSongs.BookId AS BookId',
			'artist.Name AS Artist',
			'artistsSongs.ArtistId AS ArtistId',
		]);

		$statement = $database->prepare('SELECT ' . implode(",\n\t\t\t", $selectFields) . '
		FROM Songs s
		LEFT JOIN ComposerSongs cs ON s.Id = cs.SongId
		LEFT JOIN Composer c ON cs.ComposerId = c.Id
		LEFT JOIN CollectionSong colls ON s.Id = colls.SongId
		LEFT JOIN Collections coll ON colls.CollectionId = coll.Id
		LEFT JOIN GenresSongs gs ON s.Id = gs.SongId
		LEFT JOIN Genres g ON gs.GenreId = g.Id
		LEFT JOIN KeySongs ks ON s.Id = ks.SongId
		LEFT JOIN Keys k ON ks.KeyId = k.Id
		LEFT JOIN CustomGroupSongs cgs ON s.Id = cgs.SongId
		LEFT JOIN CustomGroup cg ON cgs.GroupId = cg.Id
		LEFT JOIN YearsSongs ys ON s.Id = ys.SongId
		LEFT JOIN Years y ON ys.YearId = y.Id
		LEFT JOIN SignatureSongs sigs ON s.Id = sigs.SongId
		LEFT JOIN Signature sig ON sigs.SignatureId = sig.Id
		LEFT JOIN SourceTypeSongs srctypes ON s.Id = srctypes.SongId
		LEFT JOIN SourceTypes srctype ON srctypes.SourceTypeId = srctype.Id
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
					'lastModified' => isset($row['LastModified']) && $row['LastModified'] !== null ? (int)$row['LastModified'] : null,
					'dateCreated' => isset($row['DateCreated']) && $row['DateCreated'] !== null ? (int)$row['DateCreated'] : null,
					'stars' => isset($row['Stars']) ? (int)$row['Stars'] : 0,
					'difficulty' => isset($row['Difficulty']) ? (int)$row['Difficulty'] : 0,
					'duration' => isset($row['Duration']) ? (int)$row['Duration'] : 0,
					'keywords' => isset($row['Keywords']) ? (string)$row['Keywords'] : '',
					'custom' => isset($row['Custom']) ? (string)$row['Custom'] : '',
					'custom2' => isset($row['Custom2']) ? (string)$row['Custom2'] : '',
					'sortTitle' => isset($row['SortTitle']) ? (string)$row['SortTitle'] : '',
					'composers' => [],
					'books' => [],
					'collections' => [],
					'artists' => [],
					'genres' => [],
					'keys' => [],
					'customGroups' => [],
					'years' => [],
					'signatures' => [],
					'sourceTypes' => [],
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

			$genreId = $row['GenreId'] !== null ? (int)$row['GenreId'] : null;
			if ($genreId !== null && !isset($songs[$rowSongId]['genres'][$genreId])) {
				$songs[$rowSongId]['genres'][$genreId] = new MobileSheetsGenre($genreId, (string)($row['Genre'] ?? ''));
			}

			$keyId = $row['KeyId'] !== null ? (int)$row['KeyId'] : null;
			if ($keyId !== null && !isset($songs[$rowSongId]['keys'][$keyId])) {
				$songs[$rowSongId]['keys'][$keyId] = new MobileSheetsKey($keyId, (string)($row['Key'] ?? ''));
			}

			$customGroupId = $row['CustomGroupId'] !== null ? (int)$row['CustomGroupId'] : null;
			if ($customGroupId !== null && !isset($songs[$rowSongId]['customGroups'][$customGroupId])) {
				$songs[$rowSongId]['customGroups'][$customGroupId] = new MobileSheetsCustomGroup($customGroupId, (string)($row['CustomGroup'] ?? ''));
			}

			$yearId = $row['YearId'] !== null ? (int)$row['YearId'] : null;
			if ($yearId !== null && !isset($songs[$rowSongId]['years'][$yearId])) {
				$songs[$rowSongId]['years'][$yearId] = new MobileSheetsYear($yearId, (int)($row['Year'] ?? 0));
			}

			$signatureId = $row['SignatureId'] !== null ? (int)$row['SignatureId'] : null;
			if ($signatureId !== null && !isset($songs[$rowSongId]['signatures'][$signatureId])) {
				$songs[$rowSongId]['signatures'][$signatureId] = new MobileSheetsSignature($signatureId, (string)($row['Signature'] ?? ''));
			}

			$sourceTypeId = $row['SourceTypeId'] !== null ? (int)$row['SourceTypeId'] : null;
			if ($sourceTypeId !== null && !isset($songs[$rowSongId]['sourceTypes'][$sourceTypeId])) {
				$songs[$rowSongId]['sourceTypes'][$sourceTypeId] = new MobileSheetsSourceType($sourceTypeId, (string)($row['SourceType'] ?? ''));
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
				array_values($song['genres']),
				array_values($song['keys']),
				array_values($song['customGroups']),
				array_values($song['years']),
				array_values($song['signatures']),
				array_values($song['sourceTypes']),
				$song['lastModified'],
				$song['dateCreated'],
				$song['stars'] ?? 0,
				$song['difficulty'] ?? 0,
				$song['duration'] ?? 0,
				$song['keywords'] ?? '',
				$song['custom'] ?? '',
				$song['custom2'] ?? '',
				$song['sortTitle'] ?? '',
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
		$statement = $database->query('SELECT Id, Path FROM Files WHERE SongId = ' . $songId . ' ORDER BY Id ASC');

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
	 * @param list<int> $composerIds
	 * @param list<int> $bookIds
	 * @param list<int> $collectionIds
	 * @param list<int> $artistIds
	 * @param list<int> $genreIds
	 * @param list<int> $keyIds
	 * @param list<int> $customGroupIds
	 * @param list<int> $yearIds
	 * @param list<int> $signatureIds
	 * @param list<int> $sourceTypeIds
	 * @return int id of the created song
	 */
	public function createSong(
		string $databasePath,
		string $title,
		array $composerIds = [],
		array $bookIds = [],
		array $collectionIds = [],
		array $artistIds = [],
		array $genreIds = [],
		array $keyIds = [],
		array $customGroupIds = [],
		array $yearIds = [],
		array $signatureIds = [],
		array $sourceTypeIds = [],
		int $stars = 0,
		int $difficulty = 0,
		int $duration = 0,
		string $keywords = '',
		string $custom = '',
		string $custom2 = '',
		string $sortTitle = '',
	): int {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$songId = $this->insertRow($database, 'Songs', [
			'Title' => $title,
			'SortTitle' => $sortTitle !== '' ? $sortTitle : $title,
			'Stars' => $stars,
			'Difficulty' => $difficulty,
			'Duration' => $duration,
			'Keywords' => $keywords,
			'Custom' => $custom,
			'Custom2' => $custom2,
		] + $this->dateColumns($database, 'Songs'));

		$this->updateSong(
			$databasePath,
			$songId,
			$title,
			$composerIds,
			$bookIds,
			$collectionIds,
			$artistIds,
			$genreIds,
			$keyIds,
			$customGroupIds,
			$yearIds,
			$signatureIds,
			$sourceTypeIds,
			$stars,
			$difficulty,
			$duration,
			$keywords,
			$custom,
			$custom2,
			$sortTitle,
		);

		return $songId;
	}

	/**
	 * @param list<int> $composerIds
	 * @param list<int> $bookIds
	 * @param list<int> $collectionIds
	 * @param list<int> $artistIds
	 * @param list<int> $genreIds
	 * @param list<int> $keyIds
	 * @param list<int> $customGroupIds
	 * @param list<int> $yearIds
	 * @param list<int> $signatureIds
	 * @param list<int> $sourceTypeIds
	 */
	public function updateSong(
		string $databasePath,
		int $songId,
		string $title,
		array $composerIds = [],
		array $bookIds = [],
		array $collectionIds = [],
		array $artistIds = [],
		array $genreIds = [],
		array $keyIds = [],
		array $customGroupIds = [],
		array $yearIds = [],
		array $signatureIds = [],
		array $sourceTypeIds = [],
		int $stars = 0,
		int $difficulty = 0,
		int $duration = 0,
		string $keywords = '',
		string $custom = '',
		string $custom2 = '',
		string $sortTitle = '',
	): void {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$tablesStatement = $database->query("SELECT name FROM sqlite_master WHERE type='table'");
		/** @var list<string> $existingTables */
		$existingTables = $tablesStatement !== false ? $tablesStatement->fetchAll(PDO::FETCH_COLUMN) : [];

		// Update Song Title, SortTitle, attributes, stamping the change on LastModified.
		$this->updateRowById($database, 'Songs', [
			'Title' => $title,
			'SortTitle' => $sortTitle !== '' ? $sortTitle : $title,
			'Stars' => $stars,
			'Difficulty' => $difficulty,
			'Duration' => $duration,
			'Keywords' => $keywords,
			'Custom' => $custom,
			'Custom2' => $custom2,
		] + $this->dateColumns($database, 'Songs', withCreation: false), $songId);

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
			$delStmt = $database->prepare('DELETE FROM ArtistsSongs WHERE SongId = :songId');
			$delStmt->execute([':songId' => $songId]);

			$insStmt = $database->prepare('INSERT INTO ArtistsSongs (ArtistId, SongId) VALUES (:artistId, :songId)');
			foreach ($artistIds as $artistId) {
				$insStmt->execute([
					':artistId' => $artistId,
					':songId' => $songId,
				]);
			}
		}

		// Update Genres
		if (in_array('GenresSongs', $existingTables, true)) {
			$delStmt = $database->prepare('DELETE FROM GenresSongs WHERE SongId = :songId');
			$delStmt->execute([':songId' => $songId]);

			$insStmt = $database->prepare('INSERT INTO GenresSongs (GenreId, SongId) VALUES (:genreId, :songId)');
			foreach ($genreIds as $genreId) {
				$insStmt->execute([
					':genreId' => $genreId,
					':songId' => $songId,
				]);
			}
		}

		// Update Keys
		if (in_array('KeySongs', $existingTables, true)) {
			$delStmt = $database->prepare('DELETE FROM KeySongs WHERE SongId = :songId');
			$delStmt->execute([':songId' => $songId]);

			$insStmt = $database->prepare('INSERT INTO KeySongs (KeyId, SongId) VALUES (:keyId, :songId)');
			foreach ($keyIds as $keyId) {
				$insStmt->execute([
					':keyId' => $keyId,
					':songId' => $songId,
				]);
			}
		}

		// Update Custom Groups
		if (in_array('CustomGroupSongs', $existingTables, true)) {
			$delStmt = $database->prepare('DELETE FROM CustomGroupSongs WHERE SongId = :songId');
			$delStmt->execute([':songId' => $songId]);

			$insStmt = $database->prepare('INSERT INTO CustomGroupSongs (GroupId, SongId) VALUES (:groupId, :songId)');
			foreach ($customGroupIds as $groupId) {
				$insStmt->execute([
					':groupId' => $groupId,
					':songId' => $songId,
				]);
			}
		}

		// Update Years
		if (in_array('YearsSongs', $existingTables, true)) {
			$delStmt = $database->prepare('DELETE FROM YearsSongs WHERE SongId = :songId');
			$delStmt->execute([':songId' => $songId]);

			$insStmt = $database->prepare('INSERT INTO YearsSongs (YearId, SongId) VALUES (:yearId, :songId)');
			foreach ($yearIds as $yearId) {
				$insStmt->execute([
					':yearId' => $yearId,
					':songId' => $songId,
				]);
			}
		}

		// Update Signatures
		if (in_array('SignatureSongs', $existingTables, true)) {
			$delStmt = $database->prepare('DELETE FROM SignatureSongs WHERE SongId = :songId');
			$delStmt->execute([':songId' => $songId]);

			$insStmt = $database->prepare('INSERT INTO SignatureSongs (SignatureId, SongId) VALUES (:signatureId, :songId)');
			foreach ($signatureIds as $signatureId) {
				$insStmt->execute([
					':signatureId' => $signatureId,
					':songId' => $songId,
				]);
			}
		}

		// Update SourceTypes
		if (in_array('SourceTypeSongs', $existingTables, true)) {
			$delStmt = $database->prepare('DELETE FROM SourceTypeSongs WHERE SongId = :songId');
			$delStmt->execute([':songId' => $songId]);

			$insStmt = $database->prepare('INSERT INTO SourceTypeSongs (SourceTypeId, SongId) VALUES (:sourceTypeId, :songId)');
			foreach ($sourceTypeIds as $sourceTypeId) {
				$insStmt->execute([
					':sourceTypeId' => $sourceTypeId,
					':songId' => $songId,
				]);
			}
		}
	}

	/**
	 * Batch update metadata for multiple songs.
	 *
	 * @param list<int> $songIds
	 * @param array{action?: string, ids?: list<int>}|null $composerUpdate
	 * @param array{action?: string, ids?: list<int>}|null $bookUpdate
	 * @param array{action?: string, ids?: list<int>}|null $collectionUpdate
	 * @param array{action?: string, ids?: list<int>}|null $artistUpdate
	 * @param array{action?: string, ids?: list<int>}|null $genreUpdate
	 * @param array{action?: string, ids?: list<int>}|null $keyUpdate
	 * @param array{action?: string, ids?: list<int>}|null $customGroupUpdate
	 * @param array{action?: string, ids?: list<int>}|null $yearUpdate
	 * @param array{action?: string, ids?: list<int>}|null $signatureUpdate
	 * @param array{action?: string, ids?: list<int>}|null $sourceTypeUpdate
	 */
	public function batchUpdateSongs(
		string $databasePath,
		array $songIds,
		?array $composerUpdate = null,
		?array $bookUpdate = null,
		?array $collectionUpdate = null,
		?array $artistUpdate = null,
		?array $genreUpdate = null,
		?array $keyUpdate = null,
		?array $customGroupUpdate = null,
		?array $yearUpdate = null,
		?array $signatureUpdate = null,
		?array $sourceTypeUpdate = null,
	): void {
		if (empty($songIds)) {
			return;
		}

		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		$tablesStatement = $database->query("SELECT name FROM sqlite_master WHERE type='table'");
		/** @var list<string> $existingTables */
		$existingTables = $tablesStatement !== false ? $tablesStatement->fetchAll(PDO::FETCH_COLUMN) : [];

		$database->beginTransaction();
		try {
			foreach ($songIds as $songId) {
				$this->updateRowById($database, 'Songs', $this->dateColumns($database, 'Songs', withCreation: false), $songId);

				if ($composerUpdate !== null && in_array('ComposerSongs', $existingTables, true)) {
					$this->updateCategoryRelations($database, 'ComposerSongs', 'ComposerId', $songId, $composerUpdate);
				}
				if ($bookUpdate !== null && in_array('BookSongs', $existingTables, true)) {
					$this->updateCategoryRelations($database, 'BookSongs', 'BookId', $songId, $bookUpdate);
				}
				if ($collectionUpdate !== null && in_array('CollectionSong', $existingTables, true)) {
					$this->updateCategoryRelations($database, 'CollectionSong', 'CollectionId', $songId, $collectionUpdate);
				}
				if ($artistUpdate !== null && in_array('ArtistsSongs', $existingTables, true)) {
					$this->updateCategoryRelations($database, 'ArtistsSongs', 'ArtistId', $songId, $artistUpdate);
				}
				if ($genreUpdate !== null && in_array('GenresSongs', $existingTables, true)) {
					$this->updateCategoryRelations($database, 'GenresSongs', 'GenreId', $songId, $genreUpdate);
				}
				if ($keyUpdate !== null && in_array('KeySongs', $existingTables, true)) {
					$this->updateCategoryRelations($database, 'KeySongs', 'KeyId', $songId, $keyUpdate);
				}
				if ($customGroupUpdate !== null && in_array('CustomGroupSongs', $existingTables, true)) {
					$this->updateCategoryRelations($database, 'CustomGroupSongs', 'GroupId', $songId, $customGroupUpdate);
				}
				if ($yearUpdate !== null && in_array('YearsSongs', $existingTables, true)) {
					$this->updateCategoryRelations($database, 'YearsSongs', 'YearId', $songId, $yearUpdate);
				}
				if ($signatureUpdate !== null && in_array('SignatureSongs', $existingTables, true)) {
					$this->updateCategoryRelations($database, 'SignatureSongs', 'SignatureId', $songId, $signatureUpdate);
				}
				if ($sourceTypeUpdate !== null && in_array('SourceTypeSongs', $existingTables, true)) {
					$this->updateCategoryRelations($database, 'SourceTypeSongs', 'SourceTypeId', $songId, $sourceTypeUpdate);
				}
			}
			$database->commit();
		} catch (\Throwable $e) {
			$database->rollBack();
			throw $e;
		}
	}

	private function updateCategoryRelations(
		PDO $database,
		string $tableName,
		string $entityIdCol,
		int $songId,
		array $updateSpec,
	): void {
		$action = $updateSpec['action'] ?? 'add';
		$targetIds = array_map('intval', $updateSpec['ids'] ?? []);

		if ($action === 'replace') {
			$delStmt = $database->prepare('DELETE FROM "' . $tableName . '" WHERE SongId = :songId');
			$delStmt->execute([':songId' => $songId]);

			$insStmt = $database->prepare('INSERT INTO "' . $tableName . '" (' . $entityIdCol . ', SongId) VALUES (:entityId, :songId)');
			foreach ($targetIds as $entityId) {
				$insStmt->execute([':entityId' => $entityId, ':songId' => $songId]);
			}
		} elseif ($action === 'add') {
			$existingStmt = $database->prepare('SELECT ' . $entityIdCol . ' FROM "' . $tableName . '" WHERE SongId = :songId');
			$existingStmt->execute([':songId' => $songId]);
			$existing = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));

			$insStmt = $database->prepare('INSERT INTO "' . $tableName . '" (' . $entityIdCol . ', SongId) VALUES (:entityId, :songId)');
			foreach ($targetIds as $entityId) {
				if (!in_array($entityId, $existing, true)) {
					$insStmt->execute([':entityId' => $entityId, ':songId' => $songId]);
				}
			}
		} elseif ($action === 'remove') {
			if (!empty($targetIds)) {
				$inClause = implode(',', array_fill(0, count($targetIds), '?'));
				$delStmt = $database->prepare('DELETE FROM "' . $tableName . '" WHERE SongId = ? AND ' . $entityIdCol . ' IN (' . $inClause . ')');
				$delStmt->execute(array_merge([$songId], $targetIds));
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

	/**
	 * Record a file of a song, filling in every column MobileSheets keeps next to
	 * the path: the file's size, its modification time (LastModified, in
	 * milliseconds) and the derived content metadata.
	 */
	public function setSongFile(string $databasePath, int $songId, string $filePath, MobileSheetsFileFacts $facts): void {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		// Files carries no creation date, but its LastModified is required.
		$this->requireColumn($database, 'Files', 'LastModified');

		$values = [
			'SongId' => $songId,
			'Path' => $filePath,
			'PageOrder' => $facts->pageOrder,
			'FileSize' => $facts->fileSize,
			'LastModified' => $facts->lastModified,
			'Source' => self::FILE_SOURCE_LOCAL,
			'Type' => self::determineFileType($filePath),
			'Password' => '',
			'SourceFilePageCount' => $facts->sourceFilePageCount,
			'FileHash' => $facts->fileHash,
			'Width' => self::FILE_DIMENSION_UNKNOWN,
			'Height' => self::FILE_DIMENSION_UNKNOWN,
		];

		$columns = [];
		$params = [];
		foreach ($this->existingColumns($database, 'Files', $values) as $name => $value) {
			$columns[] = $name;
			$params[':' . $name] = $value;
		}

		$sql = 'INSERT INTO Files (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_keys($params)) . ')';
		$insertStmt = $database->prepare($sql);
		$insertStmt->execute($params);
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

	/**
	 * Persist a file's new location and refresh the metadata that a change to the
	 * file invalidates - notably LastModified, which must track the file's
	 * modification time whenever the file is created or changed. Source, Password,
	 * Width and Height are fixed at creation and left untouched. Type is updated
	 * if the file extension changed.
	 */
	public function updateFile(string $databasePath, int $fileId, string $newPath, MobileSheetsFileFacts $facts): void {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);

		// Files carries no creation date, but its LastModified is required.
		$this->requireColumn($database, 'Files', 'LastModified');

		$values = [
			'Path' => $newPath,
			'Type' => self::determineFileType($newPath),
			'PageOrder' => $facts->pageOrder,
			'FileSize' => $facts->fileSize,
			'LastModified' => $facts->lastModified,
			'SourceFilePageCount' => $facts->sourceFilePageCount,
			'FileHash' => $facts->fileHash,
		];

		$assignments = [];
		$params = [':id' => $fileId];
		foreach ($this->existingColumns($database, 'Files', $values) as $name => $value) {
			$assignments[] = $name . ' = :' . $name;
			$params[':' . $name] = $value;
		}

		$sql = 'UPDATE Files SET ' . implode(', ', $assignments) . ' WHERE Id = :id';
		$updateStmt = $database->prepare($sql);
		$updateStmt->execute($params);
	}

	/** Current time in milliseconds, the unit MobileSheets stores its dates in. */
	private function nowMilliseconds(): int {
		return (int)round(microtime(true) * 1000);
	}

	/**
	 * Lower-cased names of the columns a table has.
	 *
	 * @return array<string, true>
	 */
	private function columnNames(PDO $database, string $table): array {
		$names = [];
		foreach ($database->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $column) {
			$names[strtolower((string)$column['name'])] = true;
		}

		return $names;
	}

	/**
	 * Keep only the wanted column => value pairs the table actually has, keyed by
	 * column name, so a database with a reduced or extended schema still gets every
	 * column it knows filled in.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private function existingColumns(PDO $database, string $table, array $values): array {
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
	private function dateColumns(PDO $database, string $table, bool $withCreation = true): array {
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
	private function requireColumn(PDO $database, string $table, string $column): void {
		if (!isset($this->columnNames($database, $table)[strtolower($column)])) {
			$this->abortOnMissingColumn($table, $column);
		}
	}

	/**
	 * Report a column the library table does not have and abort the run.
	 *
	 * Skipping a column we mean to write would silently drop data and leave the
	 * library inconsistent with what MobileSheets expects, so a missing column is
	 * logged and thrown instead - the app has to be updated to match the schema.
	 */
	private function abortOnMissingColumn(string $table, string $column): never {
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
	private function insertRow(PDO $database, string $table, array $values): int {
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
	private function updateRowById(PDO $database, string $table, array $values, int $id): void {
		$assignments = [];
		$params = [':id' => $id];
		foreach ($this->existingColumns($database, $table, $values) as $name => $value) {
			$assignments[] = $name . ' = :' . $name;
			$params[':' . $name] = $value;
		}

		$sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $assignments) . ' WHERE Id = :id';
		$database->prepare($sql)->execute($params);
	}

	public function createComposer(string $databasePath, string $name): int {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		return $this->insertRow($database, 'Composer', [
			'Name' => $name,
		] + $this->dateColumns($database, 'Composer'));
	}

	public function createBook(string $databasePath, string $title): int {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		return $this->insertRow($database, 'Books', [
			'Title' => $title,
		] + $this->dateColumns($database, 'Books'));
	}

	public function createCollection(string $databasePath, string $name): int {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		return $this->insertRow($database, 'Collections', [
			'Name' => $name,
		] + $this->dateColumns($database, 'Collections'));
	}

	public function createArtist(string $databasePath, string $name): int {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		return $this->insertRow($database, 'Artists', [
			'Name' => $name,
		] + $this->dateColumns($database, 'Artists'));
	}

	public function createGenre(string $databasePath, string $name): int {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		return $this->insertRow($database, 'Genres', [
			'Type' => $name,
		] + $this->dateColumns($database, 'Genres'));
	}

	public function createKey(string $databasePath, string $name): int {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		return $this->insertRow($database, 'Keys', [
			'Name' => $name,
		] + $this->dateColumns($database, 'Keys'));
	}

	public function createCustomGroup(string $databasePath, string $name): int {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		return $this->insertRow($database, 'CustomGroup', [
			'Name' => $name,
		] + $this->dateColumns($database, 'CustomGroup'));
	}

	public function createYear(string $databasePath, int|string $year): int {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		return $this->insertRow($database, 'Years', [
			'Year' => (int)$year,
		] + $this->dateColumns($database, 'Years'));
	}

	public function createSignature(string $databasePath, string $name): int {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		return $this->insertRow($database, 'Signature', [
			'Name' => $name,
		] + $this->dateColumns($database, 'Signature'));
	}

	public function createSourceType(string $databasePath, string $name): int {
		$database = new PDO('sqlite:' . $databasePath, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		return $this->insertRow($database, 'SourceTypes', [
			'Type' => $name,
		] + $this->dateColumns($database, 'SourceTypes'));
	}

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
	 * Delete a composer, book, collection, artist, genre, key, custom group, year, signature or source type from the library.
	 *
	 * The songs are kept: only the links to the deleted entry are removed, so it
	 * disappears from the songs' composers, books, collections, artists, genres, keys, custom groups, years, signatures or source types while the
	 * songs themselves stay untouched.
	 *
	 * @param 'composers'|'books'|'collections'|'artists'|'genres'|'keys'|'customgroups'|'years'|'signatures'|'sourcetypes' $entityType
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
