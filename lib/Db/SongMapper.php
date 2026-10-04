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

class SongMapper extends AbstractDatabaseMapper {
	/** Type constants for MobileSheets Files.Type column */
	public const FILE_TYPE_OTHER = SongFileMapper::FILE_TYPE_OTHER;
	public const FILE_TYPE_PDF = SongFileMapper::FILE_TYPE_PDF;
	public const FILE_TYPE_IMAGE = SongFileMapper::FILE_TYPE_IMAGE;
	public const FILE_TYPE_TEXT = SongFileMapper::FILE_TYPE_TEXT;
	public const FILE_TYPE_ABC = SongFileMapper::FILE_TYPE_ABC;

	private SongFileMapper $fileMapper;
	private SongBookmarkMapper $bookmarkMapper;
	private SongAudioMapper $audioMapper;
	private LibraryEntityMapper $entityMapper;
	private SongNoteMapper $noteMapper;

	public function __construct(
		LoggerInterface $logger,
		?SongFileMapper $fileMapper = null,
		?SongBookmarkMapper $bookmarkMapper = null,
		?SongAudioMapper $audioMapper = null,
		?LibraryEntityMapper $entityMapper = null,
		?SongNoteMapper $noteMapper = null,
	) {
		parent::__construct($logger);
		$this->fileMapper = $fileMapper ?? new SongFileMapper($logger);
		$this->bookmarkMapper = $bookmarkMapper ?? new SongBookmarkMapper($logger);
		$this->audioMapper = $audioMapper ?? new SongAudioMapper($logger);
		$this->entityMapper = $entityMapper ?? new LibraryEntityMapper($logger);
		$this->noteMapper = $noteMapper ?? new SongNoteMapper($logger);
	}

	public static function determineFileType(string $path): int {
		return SongFileMapper::determineFileType($path);
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
		$database = $this->openConnection($databasePath);

		$composers = [];
		$composersById = [];
		if ($this->hasTable($database, 'Composer')) {
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
		if ($this->hasTable($database, 'Books')) {
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
		if ($this->hasTable($database, 'Collections')) {
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
		if ($this->hasTable($database, 'Artists')) {
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
		if ($this->hasTable($database, 'Genres')) {
			$genreCol = $this->resolveColumn($database, 'Genres', ['Type', 'Name'], 'Type');
			$stmt = $database->query('SELECT Id, ' . $genreCol . ' AS Type FROM Genres ORDER BY ' . $genreCol);
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
		$keyTable = $this->resolveTable($database, ['Key', 'Keys']);
		if ($keyTable !== null) {
			$keyCol = $this->resolveColumn($database, $keyTable, ['Name', 'Key'], 'Name');
			$stmt = $database->query('SELECT Id, ' . $keyCol . ' AS Name FROM "' . $keyTable . '" ORDER BY ' . $keyCol);
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
		if ($this->hasTable($database, 'CustomGroup')) {
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
		if ($this->hasTable($database, 'Years')) {
			$yearCol = $this->resolveColumn($database, 'Years', ['Year', 'Name'], 'Year');
			$stmt = $database->query('SELECT Id, ' . $yearCol . ' AS Year FROM Years ORDER BY ' . $yearCol . ' ASC');
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
		if ($this->hasTable($database, 'Signature')) {
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
		$sourceTypeTable = $this->resolveTable($database, ['SourceType', 'SourceTypes']);
		if ($sourceTypeTable !== null) {
			$sourceTypeCol = $this->resolveColumn($database, $sourceTypeTable, ['Type', 'Name'], 'Type');
			$stmt = $database->query('SELECT Id, ' . $sourceTypeCol . ' AS Type FROM "' . $sourceTypeTable . '" ORDER BY ' . $sourceTypeCol);
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$sourceType = new MobileSheetsSourceType((int)$row['Id'], (string)$row['Type']);
					$sourceTypes[] = $sourceType;
					$sourceTypesById[$sourceType->id] = $sourceType;
				}
			}
		}

		$songComposers = [];
		if ($this->hasTable($database, 'ComposerSongs')) {
			$stmt = $database->query('SELECT ComposerId, SongId FROM ComposerSongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songComposers[(int)$row['SongId']][] = (int)$row['ComposerId'];
				}
			}
		}

		$songBooks = [];
		if ($this->hasTable($database, 'BookSongs')) {
			$stmt = $database->query('SELECT BookId, SongId FROM BookSongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songBooks[(int)$row['SongId']][] = (int)$row['BookId'];
				}
			}
		}

		$songCollections = [];
		if ($this->hasTable($database, 'CollectionSong')) {
			$stmt = $database->query('SELECT CollectionId, SongId FROM CollectionSong');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songCollections[(int)$row['SongId']][] = (int)$row['CollectionId'];
				}
			}
		}

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

		$songArtists = [];
		if ($this->hasTable($database, 'ArtistsSongs')) {
			$stmt = $database->query('SELECT ArtistId, SongId FROM ArtistsSongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songArtists[(int)$row['SongId']][] = (int)$row['ArtistId'];
				}
			}
		}

		$songGenres = [];
		if ($this->hasTable($database, 'GenresSongs')) {
			$stmt = $database->query('SELECT GenreId, SongId FROM GenresSongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songGenres[(int)$row['SongId']][] = (int)$row['GenreId'];
				}
			}
		}

		$songKeys = [];
		if ($this->hasTable($database, 'KeySongs')) {
			$stmt = $database->query('SELECT KeyId, SongId FROM KeySongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songKeys[(int)$row['SongId']][] = (int)$row['KeyId'];
				}
			}
		}

		$songCustomGroups = [];
		if ($this->hasTable($database, 'CustomGroupSongs')) {
			$stmt = $database->query('SELECT GroupId, SongId FROM CustomGroupSongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songCustomGroups[(int)$row['SongId']][] = (int)$row['GroupId'];
				}
			}
		}

		$songYears = [];
		if ($this->hasTable($database, 'YearsSongs')) {
			$stmt = $database->query('SELECT YearId, SongId FROM YearsSongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songYears[(int)$row['SongId']][] = (int)$row['YearId'];
				}
			}
		}

		$songSignatures = [];
		if ($this->hasTable($database, 'SignatureSongs')) {
			$stmt = $database->query('SELECT SignatureId, SongId FROM SignatureSongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songSignatures[(int)$row['SongId']][] = (int)$row['SignatureId'];
				}
			}
		}

		$songSourceTypes = [];
		if ($this->hasTable($database, 'SourceTypeSongs')) {
			$stmt = $database->query('SELECT SourceTypeId, SongId FROM SourceTypeSongs');
			if ($stmt !== false) {
				while ($row = $stmt->fetch()) {
					$songSourceTypes[(int)$row['SongId']][] = (int)$row['SourceTypeId'];
				}
			}
		}

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
					$songBookmarks[$songId] ?? [],
					$songAudioFiles[$songId] ?? [],
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
		$database = $this->openConnection($databasePath);

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
		$keyTable = $this->resolveTable($database, ['Key', 'Keys']);
		$sourceTypeTable = $this->resolveTable($database, ['SourceType', 'SourceTypes']);

		$keyCol = $this->resolveColumn($database, $keyTable, ['Name', 'Key'], 'Name');
		$sourceTypeCol = $this->resolveColumn($database, $sourceTypeTable, ['Type', 'Name'], 'Type');
		$yearCol = $this->resolveColumn($database, 'Years', ['Year', 'Name'], 'Year');
		$genreCol = $this->resolveColumn($database, 'Genres', ['Type', 'Name'], 'Type');

		$joins = [];

		if ($this->hasTable($database, 'ComposerSongs') && $this->hasTable($database, 'Composer')) {
			$selectFields[] = 'c.Name AS Composer';
			$selectFields[] = 'cs.ComposerId AS ComposerId';
			$joins[] = 'LEFT JOIN ComposerSongs cs ON s.Id = cs.SongId LEFT JOIN Composer c ON cs.ComposerId = c.Id';
		}

		if ($this->hasTable($database, 'CollectionSong') && $this->hasTable($database, 'Collections')) {
			$selectFields[] = 'coll.Name AS Collection';
			$selectFields[] = 'colls.CollectionId AS CollectionId';
			$joins[] = 'LEFT JOIN CollectionSong colls ON s.Id = colls.SongId LEFT JOIN Collections coll ON colls.CollectionId = coll.Id';
		}

		if ($this->hasTable($database, 'GenresSongs') && $this->hasTable($database, 'Genres')) {
			$selectFields[] = 'gs.GenreId AS GenreId';
			$selectFields[] = 'g.' . $genreCol . ' AS Genre';
			$joins[] = 'LEFT JOIN GenresSongs gs ON s.Id = gs.SongId LEFT JOIN Genres g ON gs.GenreId = g.Id';
		}

		if ($this->hasTable($database, 'KeySongs') && $keyTable !== null) {
			$selectFields[] = 'ks.KeyId AS KeyId';
			$selectFields[] = 'k.' . $keyCol . ' AS Key';
			$joins[] = 'LEFT JOIN KeySongs ks ON s.Id = ks.SongId LEFT JOIN "' . $keyTable . '" k ON ks.KeyId = k.Id';
		}

		if ($this->hasTable($database, 'CustomGroupSongs') && $this->hasTable($database, 'CustomGroup')) {
			$selectFields[] = 'cgs.GroupId AS CustomGroupId';
			$selectFields[] = 'cg.Name AS CustomGroup';
			$joins[] = 'LEFT JOIN CustomGroupSongs cgs ON s.Id = cgs.SongId LEFT JOIN CustomGroup cg ON cgs.GroupId = cg.Id';
		}

		if ($this->hasTable($database, 'YearsSongs') && $this->hasTable($database, 'Years')) {
			$selectFields[] = 'ys.YearId AS YearId';
			$selectFields[] = 'y.' . $yearCol . ' AS Year';
			$joins[] = 'LEFT JOIN YearsSongs ys ON s.Id = ys.SongId LEFT JOIN Years y ON ys.YearId = y.Id';
		}

		if ($this->hasTable($database, 'SignatureSongs') && $this->hasTable($database, 'Signature')) {
			$selectFields[] = 'sigs.SignatureId AS SignatureId';
			$selectFields[] = 'sig.Name AS Signature';
			$joins[] = 'LEFT JOIN SignatureSongs sigs ON s.Id = sigs.SongId LEFT JOIN Signature sig ON sigs.SignatureId = sig.Id';
		}

		if ($this->hasTable($database, 'SourceTypeSongs') && $sourceTypeTable !== null) {
			$selectFields[] = 'srctypes.SourceTypeId AS SourceTypeId';
			$selectFields[] = 'srctype.' . $sourceTypeCol . ' AS SourceType';
			$joins[] = 'LEFT JOIN SourceTypeSongs srctypes ON s.Id = srctypes.SongId LEFT JOIN "' . $sourceTypeTable . '" srctype ON srctypes.SourceTypeId = srctype.Id';
		}

		if ($this->hasTable($database, 'BookSongs') && $this->hasTable($database, 'Books')) {
			$selectFields[] = 'books.Title AS Book';
			$selectFields[] = 'bookSongs.BookId AS BookId';
			$joins[] = 'LEFT JOIN BookSongs bookSongs ON s.Id = bookSongs.SongId LEFT JOIN Books books ON bookSongs.BookId = books.Id';
		}

		if ($this->hasTable($database, 'ArtistsSongs') && $this->hasTable($database, 'Artists')) {
			$selectFields[] = 'artist.Name AS Artist';
			$selectFields[] = 'artistsSongs.ArtistId AS ArtistId';
			$joins[] = 'LEFT JOIN ArtistsSongs artistsSongs ON artistsSongs.SongId = s.Id LEFT JOIN Artists artist on artistsSongs.ArtistId = artist.Id';
		}

		$sql = 'SELECT ' . implode(",\n\t\t\t", $selectFields) . "\n\t\tFROM Songs s\n\t\t" . implode("\n\t\t", $joins) . ($songId === null ? '' : ' WHERE s.Id = :songId');
		$statement = $database->prepare($sql);

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

			$composerId = isset($row['ComposerId']) && $row['ComposerId'] !== null ? (int)$row['ComposerId'] : null;
			if ($composerId !== null && !isset($songs[$rowSongId]['composers'][$composerId])) {
				$songs[$rowSongId]['composers'][$composerId] = new MobileSheetsComposer($composerId, (string)($row['Composer'] ?? ''));
			}

			$bookId = isset($row['BookId']) && $row['BookId'] !== null ? (int)$row['BookId'] : null;
			if ($bookId !== null && !isset($songs[$rowSongId]['books'][$bookId])) {
				$songs[$rowSongId]['books'][$bookId] = new MobileSheetsBook($bookId, (string)($row['Book'] ?? ''));
			}

			$collectionId = isset($row['CollectionId']) && $row['CollectionId'] !== null ? (int)$row['CollectionId'] : null;
			if ($collectionId !== null && !isset($songs[$rowSongId]['collections'][$collectionId])) {
				$songs[$rowSongId]['collections'][$collectionId] = new MobileSheetsCollection($collectionId, (string)($row['Collection'] ?? ''));
			}

			$artistId = isset($row['ArtistId']) && $row['ArtistId'] !== null ? (int)$row['ArtistId'] : null;
			if ($artistId !== null && !isset($songs[$rowSongId]['artists'][$artistId])) {
				$songs[$rowSongId]['artists'][$artistId] = new MobileSheetsArtist($artistId, (string)($row['Artist'] ?? ''));
			}

			$genreId = isset($row['GenreId']) && $row['GenreId'] !== null ? (int)$row['GenreId'] : null;
			if ($genreId !== null && !isset($songs[$rowSongId]['genres'][$genreId])) {
				$songs[$rowSongId]['genres'][$genreId] = new MobileSheetsGenre($genreId, (string)($row['Genre'] ?? ''));
			}

			$keyId = isset($row['KeyId']) && $row['KeyId'] !== null ? (int)$row['KeyId'] : null;
			if ($keyId !== null && !isset($songs[$rowSongId]['keys'][$keyId])) {
				$songs[$rowSongId]['keys'][$keyId] = new MobileSheetsKey($keyId, (string)($row['Key'] ?? ''));
			}

			$customGroupId = isset($row['CustomGroupId']) && $row['CustomGroupId'] !== null ? (int)$row['CustomGroupId'] : null;
			if ($customGroupId !== null && !isset($songs[$rowSongId]['customGroups'][$customGroupId])) {
				$songs[$rowSongId]['customGroups'][$customGroupId] = new MobileSheetsCustomGroup($customGroupId, (string)($row['CustomGroup'] ?? ''));
			}

			$yearId = isset($row['YearId']) && $row['YearId'] !== null ? (int)$row['YearId'] : null;
			if ($yearId !== null && !isset($songs[$rowSongId]['years'][$yearId])) {
				$songs[$rowSongId]['years'][$yearId] = new MobileSheetsYear($yearId, (int)($row['Year'] ?? 0));
			}

			$signatureId = isset($row['SignatureId']) && $row['SignatureId'] !== null ? (int)$row['SignatureId'] : null;
			if ($signatureId !== null && !isset($songs[$rowSongId]['signatures'][$signatureId])) {
				$songs[$rowSongId]['signatures'][$signatureId] = new MobileSheetsSignature($signatureId, (string)($row['Signature'] ?? ''));
			}

			$sourceTypeId = isset($row['SourceTypeId']) && $row['SourceTypeId'] !== null ? (int)$row['SourceTypeId'] : null;
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
				$this->bookmarksOfSong($databasePath, $song['id']),
				$this->audioFilesOfSong($databasePath, $song['id']),
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

	public function findSong(string $databasePath, int $songId): ?MobileSheetsSong {
		$songs = $this->findSongs($databasePath, $songId);

		return $songs[0] ?? null;
	}

	/**
	 * @return list<array{songId: int, entityType: string, entityId: int, count: int}>
	 */
	public function findDuplicateEntityAssignments(string $databasePath): array {
		return $this->entityMapper->findDuplicateSongEntityAssignments($databasePath);
	}

	/** Remove repeated song-to-entity relation rows, preserving one assignment each. */
	public function removeDuplicateEntityAssignments(string $databasePath, int $songId): int {
		return $this->entityMapper->removeDuplicateSongEntityAssignments($databasePath, $songId);
	}

	/**
	 * @return list<array{entityType: string, name: string, keepId: int, duplicateIds: list<int>}>
	 */
	public function findDuplicateEntities(string $databasePath): array {
		return $this->entityMapper->findDuplicateEntities($databasePath);
	}

	/** @param list<int> $duplicateIds */
	public function mergeDuplicateEntities(string $databasePath, string $entityType, int $keepId, array $duplicateIds): int {
		return $this->entityMapper->mergeDuplicateEntities($databasePath, $entityType, $keepId, $duplicateIds);
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
		return $this->fileMapper->filesOfSong($databasePath, $songId);
	}

	/**
	 * @return list<MobileSheetsBookmark>
	 */
	public function bookmarksOfSong(string $databasePath, int $songId): array {
		return $this->bookmarkMapper->bookmarksOfSong($databasePath, $songId);
	}

	/**
	 * @param list<array{name: string, pageNum: int, showInLibrary?: int}> $bookmarks
	 */
	public function updateBookmarks(string $databasePath, int $songId, array $bookmarks): void {
		$this->bookmarkMapper->updateBookmarks($databasePath, $songId, $bookmarks);
	}

	/**
	 * @return list<MobileSheetsAudioFile>
	 */
	public function audioFilesOfSong(string $databasePath, int $songId): array {
		return $this->audioMapper->audioFilesOfSong($databasePath, $songId);
	}

	/**
	 * @param list<array{title?: string, file: string}> $audioFiles
	 */
	public function updateAudioFiles(string $databasePath, int $songId, array $audioFiles): void {
		$this->audioMapper->updateAudioFiles($databasePath, $songId, $audioFiles);
	}

	/**
	 * @return list<MobileSheetsSongNote>
	 */
	public function notesOfSong(string $databasePath, int $songId): array {
		return $this->noteMapper->notesOfSong($databasePath, $songId);
	}

	/**
	 * @param list<array{note?: string, text?: string}> $notes
	 */
	public function updateNotes(string $databasePath, int $songId, array $notes): void {
		$this->noteMapper->updateNotes($databasePath, $songId, $notes);
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
		$database = $this->openConnection($databasePath);

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
		$database = $this->openConnection($databasePath);

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
		if ($this->hasTable($database, 'ComposerSongs')) {
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
		if ($this->hasTable($database, 'BookSongs')) {
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
		if ($this->hasTable($database, 'CollectionSong')) {
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
		if ($this->hasTable($database, 'ArtistsSongs')) {
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
		if ($this->hasTable($database, 'GenresSongs')) {
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
		if ($this->hasTable($database, 'KeySongs')) {
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
		if ($this->hasTable($database, 'CustomGroupSongs')) {
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
		if ($this->hasTable($database, 'YearsSongs')) {
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
		if ($this->hasTable($database, 'SignatureSongs')) {
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
		if ($this->hasTable($database, 'SourceTypeSongs')) {
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

		$database = $this->openConnection($databasePath);

		$database->beginTransaction();
		try {
			foreach ($songIds as $songId) {
				$this->updateRowById($database, 'Songs', $this->dateColumns($database, 'Songs', withCreation: false), $songId);

				if ($composerUpdate !== null && $this->hasTable($database, 'ComposerSongs')) {
					$this->updateCategoryRelations($database, 'ComposerSongs', 'ComposerId', $songId, $composerUpdate);
				}
				if ($bookUpdate !== null && $this->hasTable($database, 'BookSongs')) {
					$this->updateCategoryRelations($database, 'BookSongs', 'BookId', $songId, $bookUpdate);
				}
				if ($collectionUpdate !== null && $this->hasTable($database, 'CollectionSong')) {
					$this->updateCategoryRelations($database, 'CollectionSong', 'CollectionId', $songId, $collectionUpdate);
				}
				if ($artistUpdate !== null && $this->hasTable($database, 'ArtistsSongs')) {
					$this->updateCategoryRelations($database, 'ArtistsSongs', 'ArtistId', $songId, $artistUpdate);
				}
				if ($genreUpdate !== null && $this->hasTable($database, 'GenresSongs')) {
					$this->updateCategoryRelations($database, 'GenresSongs', 'GenreId', $songId, $genreUpdate);
				}
				if ($keyUpdate !== null && $this->hasTable($database, 'KeySongs')) {
					$this->updateCategoryRelations($database, 'KeySongs', 'KeyId', $songId, $keyUpdate);
				}
				if ($customGroupUpdate !== null && $this->hasTable($database, 'CustomGroupSongs')) {
					$this->updateCategoryRelations($database, 'CustomGroupSongs', 'GroupId', $songId, $customGroupUpdate);
				}
				if ($yearUpdate !== null && $this->hasTable($database, 'YearsSongs')) {
					$this->updateCategoryRelations($database, 'YearsSongs', 'YearId', $songId, $yearUpdate);
				}
				if ($signatureUpdate !== null && $this->hasTable($database, 'SignatureSongs')) {
					$this->updateCategoryRelations($database, 'SignatureSongs', 'SignatureId', $songId, $signatureUpdate);
				}
				if ($sourceTypeUpdate !== null && $this->hasTable($database, 'SourceTypeSongs')) {
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
	 * Merge two songs: re-point all SongId references from $droppedId to $keepId,
	 * de-duplicate relation rows, then delete the dropped song row.
	 *
	 * All tables that have a SongId column (except Songs itself) are updated.
	 * The Songs row for $keepId is left untouched – the caller is responsible for
	 * updating its fields beforehand with updateSong() if needed.
	 */
	public function mergeSongs(string $databasePath, int $keepId, int $droppedId): void {
		if ($keepId === $droppedId) {
			return;
		}

		$database = $this->openConnection($databasePath);
		$schema = $this->getSchemaMap($database);
		$existingTables = array_values($schema['tables']);

		$database->beginTransaction();
		try {
			foreach ($existingTables as $table) {
				$lc = strtolower($table);
				if ($lc === 'songs' || !$this->hasSongIdColumn($database, $table)) {
					continue;
				}

				// Fetch all columns so we can build a unique-key check for relation tables
				$cols = array_values($this->columnNames($database, $table));

				// For pure relation tables (2 columns: SongId + entity FK) we move
				// only the rows that are not already present for $keepId.
				if (count($cols) === 2) {
					$otherCol = strtolower($cols[0]) === 'songid' ? $cols[1] : $cols[0];

					// Find entity IDs already linked to $keepId
					$existStmt = $database->prepare(
						'SELECT "' . $otherCol . '" FROM "' . $table . '" WHERE SongId = :keepId'
					);
					$existStmt->execute([':keepId' => $keepId]);
					$existing = array_map('intval', $existStmt->fetchAll(PDO::FETCH_COLUMN));

					// Move references that are not yet linked to $keepId
					$moveStmt = $database->prepare(
						'UPDATE "' . $table . '" SET SongId = :keepId'
						. ' WHERE SongId = :droppedId AND "' . $otherCol . '" NOT IN ('
						. 'SELECT "' . $otherCol . '" FROM "' . $table . '" WHERE SongId = :keepId2'
						. ')'
					);
					$moveStmt->execute([
						':keepId' => $keepId,
						':droppedId' => $droppedId,
						':keepId2' => $keepId,
					]);

					// Remove remaining (duplicate) rows for $droppedId
					$delStmt = $database->prepare(
						'DELETE FROM "' . $table . '" WHERE SongId = :droppedId'
					);
					$delStmt->execute([':droppedId' => $droppedId]);
				} else {
					// Other tables (e.g. Files, Bookmarks, AudioFiles, Notes…)
					// just reassign the SongId.
					$updateStmt = $database->prepare(
						'UPDATE "' . $table . '" SET SongId = :keepId WHERE SongId = :droppedId'
					);
					$updateStmt->execute([':keepId' => $keepId, ':droppedId' => $droppedId]);
				}
			}

			// Delete the dropped Songs row
			if ($this->hasTable($database, 'Songs')) {
				$stmt = $database->prepare('DELETE FROM Songs WHERE Id = :droppedId');
				$stmt->execute([':droppedId' => $droppedId]);
			}

			$database->commit();
		} catch (\Throwable $exception) {
			$database->rollBack();
			throw $exception;
		}
	}

	public function deleteSong(string $databasePath, int $songId): array {
		$database = $this->openConnection($databasePath);

		$schema = $this->getSchemaMap($database);
		$existingTables = array_values($schema['tables']);

		$paths = [];
		if ($this->hasTable($database, 'Files')) {
			$fileStmt = $database->prepare('SELECT Path FROM Files WHERE SongId = :songId');
			$fileStmt->execute([':songId' => $songId]);
			while ($row = $fileStmt->fetch()) {
				$paths[] = (string)$row['Path'];
			}
		}

		$database->beginTransaction();
		try {
			foreach ($existingTables as $table) {
				if (strtolower($table) === 'songs' || !$this->hasSongIdColumn($database, $table)) {
					continue;
				}

				$stmt = $database->prepare('DELETE FROM "' . $table . '" WHERE SongId = :songId');
				$stmt->execute([':songId' => $songId]);
			}

			if ($this->hasTable($database, 'Songs')) {
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

	private function hasSongIdColumn(PDO $database, string $table): bool {
		$cols = $this->columnNames($database, $table);
		return isset($cols['songid']);
	}

	public function setSongFile(string $databasePath, int $songId, string $filePath, MobileSheetsFileFacts $facts): void {
		$this->fileMapper->setSongFile($databasePath, $songId, $filePath, $facts);
	}

	public function removeSongFile(string $databasePath, int $songId, int $fileId): ?string {
		return $this->fileMapper->removeSongFile($databasePath, $songId, $fileId);
	}

	public function updateFile(string $databasePath, int $fileId, string $newPath, MobileSheetsFileFacts $facts): void {
		$this->fileMapper->updateFile($databasePath, $fileId, $newPath, $facts);
	}

	public function createComposer(string $databasePath, string $name): int {
		return $this->entityMapper->createComposer($databasePath, $name);
	}

	public function createBook(string $databasePath, string $title): int {
		return $this->entityMapper->createBook($databasePath, $title);
	}

	public function createCollection(string $databasePath, string $name): int {
		return $this->entityMapper->createCollection($databasePath, $name);
	}

	public function createArtist(string $databasePath, string $name): int {
		return $this->entityMapper->createArtist($databasePath, $name);
	}

	public function createGenre(string $databasePath, string $name): int {
		return $this->entityMapper->createGenre($databasePath, $name);
	}

	public function createKey(string $databasePath, string $name): int {
		return $this->entityMapper->createKey($databasePath, $name);
	}

	public function createCustomGroup(string $databasePath, string $name): int {
		return $this->entityMapper->createCustomGroup($databasePath, $name);
	}

	public function createYear(string $databasePath, int|string $year): int {
		return $this->entityMapper->createYear($databasePath, $year);
	}

	public function createSignature(string $databasePath, string $name): int {
		return $this->entityMapper->createSignature($databasePath, $name);
	}

	public function createSourceType(string $databasePath, string $name): int {
		return $this->entityMapper->createSourceType($databasePath, $name);
	}

	public function deleteEntity(string $databasePath, string $entityType, int $entityId): bool {
		return $this->entityMapper->deleteEntity($databasePath, $entityType, $entityId);
	}

	public function isPathReferencedByOtherSong(string $databasePath, string $filePath, int $songId): bool {
		return $this->fileMapper->isPathReferencedByOtherSong($databasePath, $filePath, $songId);
	}
}
