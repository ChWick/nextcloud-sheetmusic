<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use PDO;
use Psr\Log\LoggerInterface;

class SongReader extends AbstractDatabaseMapper {
	private SongFileMapper $fileMapper;
	private SongBookmarkMapper $bookmarkMapper;
	private SongAudioMapper $audioMapper;

	public function __construct(
		LoggerInterface $logger,
		?SongFileMapper $fileMapper = null,
		?SongBookmarkMapper $bookmarkMapper = null,
		?SongAudioMapper $audioMapper = null,
	) {
		parent::__construct($logger);
		$this->fileMapper = $fileMapper ?? new SongFileMapper($logger);
		$this->bookmarkMapper = $bookmarkMapper ?? new SongBookmarkMapper($logger);
		$this->audioMapper = $audioMapper ?? new SongAudioMapper($logger);
	}

	/**
	 * @return LibraryData
	 */
	public function findLibraryData(string $databasePath): LibraryData {
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
		$hasOpus = isset($songCols['opus']);
		$hasCatalogNumber = isset($songCols['catalognumber']);
		$hasSongNumber = isset($songCols['songnumber']);
		if ($hasOpus) {
			$selectFields[] = 'Songs.Opus';
		}
		if ($hasCatalogNumber) {
			$selectFields[] = 'Songs.CatalogNumber';
		}
		if ($hasSongNumber) {
			$selectFields[] = 'Songs.SongNumber';
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
		$hasOpus = isset($songCols['opus']);
		$hasCatalogNumber = isset($songCols['catalognumber']);
		$hasSongNumber = isset($songCols['songnumber']);
		if ($hasOpus) {
			$selectFields[] = 's.Opus';
		}
		if ($hasCatalogNumber) {
			$selectFields[] = 's.CatalogNumber';
		}
		if ($hasSongNumber) {
			$selectFields[] = 's.SongNumber';
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
					'opus' => isset($row['Opus']) ? (string)$row['Opus'] : '',
					'catalogNumber' => isset($row['CatalogNumber']) ? (string)$row['CatalogNumber'] : '',
					'songNumber' => isset($row['SongNumber']) ? (string)$row['SongNumber'] : '',
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
					'instruments' => [],
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
				$this->fileMapper->filesOfSong($databasePath, $song['id']),
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
				$this->bookmarkMapper->bookmarksOfSong($databasePath, $song['id']),
				$this->audioMapper->audioFilesOfSong($databasePath, $song['id']),
				$song['lastModified'],
				$song['dateCreated'],
				$song['stars'] ?? 0,
				$song['difficulty'] ?? 0,
				$song['duration'] ?? 0,
				$song['keywords'] ?? '',
				$song['custom'] ?? '',
				$song['custom2'] ?? '',
				$song['sortTitle'] ?? '',
				$song['opus'] ?? '',
				$song['catalogNumber'] ?? '',
				$song['songNumber'] ?? '',
				array_values($song['instruments'] ?? []),
			);
		}

		return $result;
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
}
