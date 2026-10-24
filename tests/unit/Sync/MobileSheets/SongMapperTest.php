<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsArtist;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsBook;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsCollection;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsComposer;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsCustomGroup;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFile;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsGenre;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsKey;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSignature;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSourceType;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsYear;
use OCA\SheetMusic\Sync\MobileSheets\SongMapper;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFileFacts;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class SongMapperTest extends TestCase {
	private string $databasePath;

	protected function setUp(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$this->databasePath = $fixture['path'];
		$database = $fixture['connection'];

		$database->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, Keywords, Custom, Custom2) VALUES (1, 'Zebra', 'Zebra', 5, 3, 240, 'jazz, fast', 'Key C', 'Arrangement A'), (2, 'Alpha', 'Alpha', 0, 0, 0, '', '', '')");
		$database->exec("INSERT INTO Files (Id, SongId, Path) VALUES (1, 1, '/remote.php/dav/files/admin/Noten/Zebra.pdf'), (2, 2, '/remote.php/dav/files/admin/Noten/Alpha.pdf')");
		$database->exec("INSERT INTO Bookmarks (Id, SongId, Name, PageNum, ShowInLibrary) VALUES (1, 1, 'Chorus', 2, 1)");
		$database->exec("INSERT INTO AudioFiles (Id, SongId, Title, File) VALUES (1, 1, 'Audio Track', 'track1.mp3')");

		$database->exec("INSERT INTO Composer (Id, Name) VALUES (10, 'Beethoven'), (20, 'Bach')");
		$database->exec('INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (10, 1), (20, 2)');

		$database->exec("INSERT INTO Books (Id, Title) VALUES (100, 'Real Book 1')");
		$database->exec('INSERT INTO BookSongs (BookId, SongId) VALUES (100, 2)');

		$database->exec("INSERT INTO Collections (Id, Name) VALUES (1000, 'Jazz Standards')");
		$database->exec('INSERT INTO CollectionSong (CollectionId, SongId) VALUES (1000, 1), (1000, 2)');

		$database->exec("INSERT INTO Artists (Id, Name) VALUES (200, 'Coltrane')");
		$database->exec('INSERT INTO ArtistsSongs (ArtistId, SongId) VALUES (200, 1)');

		$database->exec("INSERT INTO Genres (Id, Type) VALUES (50, 'Jazz')");
		$database->exec('INSERT INTO GenresSongs (GenreId, SongId) VALUES (50, 1)');

		$database->exec("INSERT INTO Keys (Id, Name) VALUES (70, 'C Major')");
		$database->exec('INSERT INTO KeySongs (KeyId, SongId) VALUES (70, 1)');

		$database->exec("INSERT INTO CustomGroup (Id, Name) VALUES (90, 'Ensemble A')");
		$database->exec('INSERT INTO CustomGroupSongs (GroupId, SongId) VALUES (90, 1)');

		$database->exec('INSERT INTO Years (Id, Year) VALUES (80, 1959)');
		$database->exec('INSERT INTO YearsSongs (YearId, SongId) VALUES (80, 1)');

		$database->exec("INSERT INTO Signature (Id, Name) VALUES (60, '4/4')");
		$database->exec('INSERT INTO SignatureSongs (SignatureId, SongId) VALUES (60, 1)');

		$database->exec("INSERT INTO SourceTypes (Id, Type) VALUES (40, 'Sheet Music')");
		$database->exec('INSERT INTO SourceTypeSongs (SourceTypeId, SongId) VALUES (40, 1)');

		// A song may point at another song through its own SongId column, and a song has
		// more rows than its files and links: settings such as the metronome live in their
		// own tables. Both matter when a song is deleted.
		$database->exec('UPDATE Songs SET SongId = 2 WHERE Id = 1');
		$database->exec('INSERT INTO MetronomeSettings (Id, SongId, Bpm) VALUES (300, 2, 120)');
	}

	protected function tearDown(): void {
		unlink($this->databasePath);
	}

	private function mapper(): SongMapper {
		return new SongMapper(new NullLogger());
	}

	public function testFindSongsReturnsSongsWithNestedEntities(): void {
		$songs = $this->mapper()->findSongs($this->databasePath);

		$this->assertCount(2, $songs);
		$this->assertContainsOnlyInstancesOf(MobileSheetsSong::class, $songs);

		$byId = [];
		foreach ($songs as $song) {
			$byId[$song->id] = $song;
		}

		$zebra = $byId[1];
		$this->assertSame('Zebra', $zebra->title);
		$this->assertSame(['Beethoven'], $this->names($zebra->composers));
		$this->assertSame(['Coltrane'], $this->names($zebra->artists));
		$this->assertSame(['Jazz'], $this->names($zebra->genres));
		$this->assertSame(['C Major'], $this->names($zebra->keys));
		$this->assertSame(['Ensemble A'], $this->names($zebra->customGroups));
		$this->assertSame(['1959'], $this->names($zebra->years));
		$this->assertSame(['4/4'], $this->names($zebra->signatures));
		$this->assertSame([], $zebra->books);
		$this->assertSame(['Jazz Standards'], $this->names($zebra->collections));
		$this->assertContainsOnlyInstancesOf(MobileSheetsComposer::class, $zebra->composers);
		$this->assertContainsOnlyInstancesOf(MobileSheetsArtist::class, $zebra->artists);
		$this->assertContainsOnlyInstancesOf(MobileSheetsCollection::class, $zebra->collections);
		$this->assertContainsOnlyInstancesOf(MobileSheetsGenre::class, $zebra->genres);
		$this->assertContainsOnlyInstancesOf(MobileSheetsKey::class, $zebra->keys);
		$this->assertContainsOnlyInstancesOf(MobileSheetsCustomGroup::class, $zebra->customGroups);
		$this->assertContainsOnlyInstancesOf(MobileSheetsYear::class, $zebra->years);
		$this->assertContainsOnlyInstancesOf(MobileSheetsSignature::class, $zebra->signatures);
		$this->assertCount(1, $zebra->files);
		$this->assertContainsOnlyInstancesOf(MobileSheetsFile::class, $zebra->files);
		$this->assertSame(1, $zebra->files[0]->id);
		$this->assertSame('/remote.php/dav/files/admin/Noten/Zebra.pdf', $zebra->files[0]->path);
		$this->assertSame(10, $zebra->composers[0]->id);
		$this->assertSame(200, $zebra->artists[0]->id);
		$this->assertSame(50, $zebra->genres[0]->id);
		$this->assertSame(70, $zebra->keys[0]->id);
		$this->assertSame(90, $zebra->customGroups[0]->id);
		$this->assertSame(80, $zebra->years[0]->id);
		$this->assertSame(1959, $zebra->years[0]->year);
		$this->assertSame(60, $zebra->signatures[0]->id);
		$this->assertSame('4/4', $zebra->signatures[0]->name);
		$this->assertSame(1000, $zebra->collections[0]->id);

		$alpha = $byId[2];
		$this->assertSame('Alpha', $alpha->title);
		$this->assertSame(['Bach'], $this->names($alpha->composers));
		$this->assertSame([], $alpha->artists);
		$this->assertSame([], $alpha->genres);
		$this->assertSame([], $alpha->keys);
		$this->assertSame([], $alpha->customGroups);
		$this->assertSame([], $alpha->years);
		$this->assertSame([], $alpha->signatures);
		$this->assertSame(['Real Book 1'], $this->titles($alpha->books));
		$this->assertSame(['Jazz Standards'], $this->names($alpha->collections));
		$this->assertContainsOnlyInstancesOf(MobileSheetsBook::class, $alpha->books);
		$this->assertSame(20, $alpha->composers[0]->id);
		$this->assertSame(100, $alpha->books[0]->id);
		$this->assertSame(1000, $alpha->collections[0]->id);
	}

	public function testFindSongReturnsOnlyTheRequestedSong(): void {
		$song = $this->mapper()->findSong($this->databasePath, 1);

		$this->assertNotNull($song);
		$this->assertSame(1, $song->id);
		$this->assertSame('Zebra', $song->title);
		$this->assertSame(['Beethoven'], $this->names($song->composers));
		$this->assertSame(['Coltrane'], $this->names($song->artists));
		$this->assertSame(['Jazz Standards'], $this->names($song->collections));
		$this->assertSame([], $song->books);
		$this->assertCount(1, $song->files);
		$this->assertSame(1, $song->files[0]->id);
		$this->assertSame('/remote.php/dav/files/admin/Noten/Zebra.pdf', $song->files[0]->path);
	}

	public function testFindSongReturnsNullForAnUnknownSong(): void {
		$this->assertNull($this->mapper()->findSong($this->databasePath, 999));
	}

	public function testFindSongsCanBeLimitedToOneSong(): void {
		$songs = $this->mapper()->findSongs($this->databasePath, 2);

		$this->assertCount(1, $songs);
		$this->assertSame(2, $songs[0]->id);
		$this->assertSame('Alpha', $songs[0]->title);
	}
	public function testFindLibraryDataReturnsAllEntities(): void {
		$data = $this->mapper()->findLibraryData($this->databasePath);

		$this->assertCount(2, $data->songs);
		$this->assertContainsOnlyInstancesOf(MobileSheetsSong::class, $data->songs);
		$this->assertContainsOnlyInstancesOf(MobileSheetsComposer::class, $data->composers);
		$this->assertContainsOnlyInstancesOf(MobileSheetsBook::class, $data->books);
		$this->assertContainsOnlyInstancesOf(MobileSheetsCollection::class, $data->collections);
		$this->assertContainsOnlyInstancesOf(MobileSheetsArtist::class, $data->artists);
		$this->assertContainsOnlyInstancesOf(MobileSheetsGenre::class, $data->genres);
		$this->assertContainsOnlyInstancesOf(MobileSheetsKey::class, $data->keys);
		$this->assertSame(['Bach', 'Beethoven'], $this->names($data->composers));
		$this->assertSame(['Real Book 1'], $this->titles($data->books));
		$this->assertSame(['Jazz Standards'], $this->names($data->collections));
		$this->assertSame(['Coltrane'], $this->names($data->artists));
		$this->assertSame(['Jazz'], $this->names($data->genres));
		$this->assertSame(['C Major'], $this->names($data->keys));

		// Songs are ordered by title (Alpha, Zebra) and carry nested entities.
		$alpha = $data->songs[0];
		$this->assertSame('Alpha', $alpha->title);
		$this->assertSame(20, $alpha->composers[0]->id);
		$this->assertSame('Bach', $alpha->composers[0]->name);
		$this->assertSame(100, $alpha->books[0]->id);
		$this->assertCount(1, $alpha->files);
	}

	public function testUpdateSongAndAssociations(): void {
		$mapper = $this->mapper();
		$mapper->updateSong($this->databasePath, 2, 'Alpha (Updated)', [10], [100], [1000], []);

		$data = $mapper->findLibraryData($this->databasePath);
		$updatedSong = array_values(array_filter($data->songs, fn (MobileSheetsSong $s) => $s->id === 2))[0];

		$this->assertSame('Alpha (Updated)', $updatedSong->title);
		$this->assertSame(['Beethoven'], $this->names($updatedSong->composers));
		$this->assertSame(['Real Book 1'], $this->titles($updatedSong->books));
		$this->assertSame(['Jazz Standards'], $this->names($updatedSong->collections));
		$this->assertSame([], $updatedSong->artists);
	}

	public function testCreateSongInsertsTheSongWithItsRelations(): void {
		$mapper = $this->mapper();
		$songId = $mapper->createSong($this->databasePath, 'New Song', [10], [100], [1000], [200]);

		$this->assertGreaterThan(0, $songId);

		$data = $mapper->findLibraryData($this->databasePath);
		$created = array_values(array_filter($data->songs, fn (MobileSheetsSong $s) => $s->id === $songId))[0];

		$this->assertSame('New Song', $created->title);
		$this->assertSame([], $created->files);
		$this->assertSame(['Beethoven'], $this->names($created->composers));
		$this->assertSame(['Real Book 1'], $this->titles($created->books));
		$this->assertSame(['Jazz Standards'], $this->names($created->collections));
		$this->assertSame(['Coltrane'], $this->names($created->artists));
	}

	public function testCreateSongWithoutRelations(): void {
		$mapper = $this->mapper();
		$songId = $mapper->createSong($this->databasePath, 'Bare Song', [], [], [], []);

		$data = $mapper->findLibraryData($this->databasePath);
		$created = array_values(array_filter($data->songs, fn (MobileSheetsSong $s) => $s->id === $songId))[0];

		$this->assertSame('Bare Song', $created->title);
		$this->assertSame([], $created->composers);
		$this->assertSame([], $created->books);
		$this->assertSame([], $created->collections);
		$this->assertSame([], $created->artists);
	}

	public function testCreateEntitiesFillCreationAndModificationDates(): void {
		$mapper = $this->mapper();
		$before = (int)round(microtime(true) * 1000);
		$composerId = $mapper->createComposer($this->databasePath, 'Vivaldi');
		$bookId = $mapper->createBook($this->databasePath, 'Seasons');
		$collectionId = $mapper->createCollection($this->databasePath, 'Baroque');
		$artistId = $mapper->createArtist($this->databasePath, 'Vivaldi');
		$after = (int)round(microtime(true) * 1000);

		$database = new \PDO('sqlite:' . $this->databasePath);
		foreach ([['Composer', $composerId], ['Books', $bookId], ['Collections', $collectionId], ['Artists', $artistId]] as [$table, $id]) {
			$row = $database->query('SELECT DateCreated, LastModified FROM ' . $table . ' WHERE Id = ' . $id)->fetch();
			$created = (int)$row['DateCreated'];
			self::assertSame($created, (int)$row['LastModified'], $table . ' creation and modification date');
			self::assertGreaterThanOrEqual($before, $created, $table . ' creation date');
			self::assertLessThanOrEqual($after, $created, $table . ' creation date');
		}
	}

	/**
	 * Songs name their creation date "CreationDate" rather than "DateCreated", and
	 * it has to be filled in just the same.
	 */
	public function testCreateSongFillsTheDatesWhateverTheCreationColumnIsCalled(): void {
		$databasePath = $this->songsDatabase('CreationDate');

		try {
			$mapper = $this->mapper();
			$before = (int)round(microtime(true) * 1000);
			$songId = $mapper->createSong($databasePath, 'Cantata', [], [], [], []);
			$after = (int)round(microtime(true) * 1000);

			$row = (new \PDO('sqlite:' . $databasePath))
				->query('SELECT CreationDate, LastModified FROM Songs WHERE Id = ' . $songId)
				->fetch();
			foreach (['CreationDate', 'LastModified'] as $column) {
				self::assertGreaterThanOrEqual($before, (int)$row[$column], $column);
				self::assertLessThanOrEqual($after, (int)$row[$column], $column);
			}
		} finally {
			unlink($databasePath);
		}
	}

	public function testUpdateSongBumpsLastModifiedButKeepsTheCreationDate(): void {
		$databasePath = $this->songsDatabase('CreationDate');

		try {
			$mapper = $this->mapper();
			$songId = $mapper->createSong($databasePath, 'Cantata', [], [], [], []);

			$database = new \PDO('sqlite:' . $databasePath);
			// Backdate both so the effect of the update is visible.
			$database->exec('UPDATE Songs SET CreationDate = 1000, LastModified = 1000 WHERE Id = ' . $songId);

			$mapper->updateSong($databasePath, $songId, 'Cantata (rev)', [], [], [], []);

			$row = $database->query('SELECT CreationDate, LastModified FROM Songs WHERE Id = ' . $songId)->fetch();
			self::assertSame(1000, (int)$row['CreationDate'], 'the creation date must survive an update');
			self::assertGreaterThan(1000, (int)$row['LastModified'], 'LastModified must move forward on a change');
		} finally {
			unlink($databasePath);
		}
	}

	/** A library without a creation-date column no longer matches the schema. */
	public function testCreateSongRejectsALibraryWithoutACreationDate(): void {
		$databasePath = $this->songsDatabase(null);

		try {
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method('error');
			$mapper = new SongMapper($logger);

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('DateCreated or CreationDate');
			$mapper->createSong($databasePath, 'Cantata', [], [], [], []);
		} finally {
			unlink($databasePath);
		}
	}

	/** A library without LastModified no longer matches the schema. */
	public function testCreateSongRejectsALibraryWithoutLastModified(): void {
		$databasePath = $this->songsDatabase('CreationDate', false);

		try {
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method('error');
			$mapper = new SongMapper($logger);

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('LastModified');
			$mapper->createSong($databasePath, 'Cantata', [], [], [], []);
		} finally {
			unlink($databasePath);
		}
	}

	/** Files has no creation date, but its LastModified is required. */
	public function testSetSongFileRejectsAFilesTableWithoutLastModified(): void {
		$databasePath = tempnam(sys_get_temp_dir(), 'sheetmusic-');
		self::assertNotFalse($databasePath);
		$database = new \PDO('sqlite:' . $databasePath);
		$database->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), SongId INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec('CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255))');

		try {
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method('error');
			$mapper = new SongMapper($logger);

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('LastModified');
			$mapper->setSongFile($databasePath, 1, '/a.pdf', new MobileSheetsFileFacts(
				fileHash: 0,
				fileSize: 0,
				lastModified: 0,
				sourceFilePageCount: 1,
				pageOrder: '1-1',
			));
		} finally {
			unlink($databasePath);
		}
	}

	public function testDeleteSongRemovesTheSongItsFilesAndEveryReference(): void {
		$mapper = $this->mapper();

		// Another song links to the one that is deleted, which the sweep must not touch.
		$database = new \PDO('sqlite:' . $this->databasePath);
		$database->exec('INSERT INTO ArtistsSongs (ArtistId, SongId) VALUES (200, 2)');

		$removedPaths = $mapper->deleteSong($this->databasePath, 2);

		$this->assertSame(['/remote.php/dav/files/admin/Noten/Alpha.pdf'], $removedPaths);

		foreach (['Files', 'ComposerSongs', 'BookSongs', 'CollectionSong', 'ArtistsSongs', 'MetronomeSettings'] as $table) {
			$this->assertSame(
				0,
				(int)$database->query('SELECT COUNT(*) FROM ' . $table . ' WHERE SongId = 2')->fetchColumn(),
				$table . ' still has rows pointing at the deleted song',
			);
		}
		$this->assertSame(0, (int)$database->query('SELECT COUNT(*) FROM Songs WHERE Id = 2')->fetchColumn());

		$songs = $mapper->findLibraryData($this->databasePath)->songs;
		$this->assertCount(1, $songs);
		$this->assertSame(1, $songs[0]->id);
		$this->assertSame('Zebra', $songs[0]->title);
	}

	/**
	 * The Songs table has a SongId column of its own that means something else, so the row
	 * of the deleted song has to go by Id - the songs pointing at it have to stay.
	 */
	public function testDeleteSongKeepsSongsPointingAtIt(): void {
		$this->mapper()->deleteSong($this->databasePath, 2);

		$database = new \PDO('sqlite:' . $this->databasePath);
		$this->assertSame(1, (int)$database->query('SELECT COUNT(*) FROM Songs WHERE Id = 1')->fetchColumn());
		$this->assertSame(2, (int)$database->query('SELECT SongId FROM Songs WHERE Id = 1')->fetchColumn());
	}

	public function testSetSongFile(): void {
		$mapper = $this->mapper();
		$mapper->setSongFile(
			$this->databasePath,
			2,
			'/remote.php/dav/files/admin/Noten/AlphaNew.pdf',
			new MobileSheetsFileFacts(
				fileHash: -432302017,
				fileSize: 138196,
				lastModified: 1789222407000,
				sourceFilePageCount: 2,
				pageOrder: '1-2',
			),
		);

		$data = $mapper->findLibraryData($this->databasePath);
		$updatedSong = array_values(array_filter($data->songs, fn (MobileSheetsSong $s) => $s->id === 2))[0];

		$this->assertCount(2, $updatedSong->files);
		$this->assertContainsOnlyInstancesOf(MobileSheetsFile::class, $updatedSong->files);

		// Every column MobileSheets keeps next to the path is filled in.
		$row = (new \PDO('sqlite:' . $this->databasePath))
			->query("SELECT * FROM Files WHERE Path = '/remote.php/dav/files/admin/Noten/AlphaNew.pdf'")
			->fetch();
		self::assertSame(2, (int)$row['SongId']);
		self::assertSame('1-2', (string)$row['PageOrder']);
		self::assertSame(138196, (int)$row['FileSize']);
		self::assertSame(1789222407000, (int)$row['LastModified']);
		self::assertSame(1, (int)$row['Source']);
		self::assertSame(1, (int)$row['Type']);
		self::assertSame('', (string)$row['Password']);
		self::assertSame(2, (int)$row['SourceFilePageCount']);
		self::assertSame(-432302017, (int)$row['FileHash']);
		self::assertSame(-1, (int)$row['Width']);
		self::assertSame(-1, (int)$row['Height']);
	}

	public function testRemoveSongFile(): void {
		$mapper = $this->mapper();
		$deletedPath = $mapper->removeSongFile($this->databasePath, 2, 2);

		$this->assertSame('/remote.php/dav/files/admin/Noten/Alpha.pdf', $deletedPath);

		$data = $mapper->findLibraryData($this->databasePath);
		$updatedSong = array_values(array_filter($data->songs, fn (MobileSheetsSong $s) => $s->id === 2))[0];

		$this->assertCount(0, $updatedSong->files);
	}

	public function testCreateEntities(): void {
		$mapper = $this->mapper();
		$composerId = $mapper->createComposer($this->databasePath, 'Mozart');
		$bookId = $mapper->createBook($this->databasePath, 'Real Book 2');
		$collectionId = $mapper->createCollection($this->databasePath, 'Classical');
		$genreId = $mapper->createGenre($this->databasePath, 'Swing');

		$this->assertGreaterThan(0, $composerId);
		$this->assertGreaterThan(0, $bookId);
		$this->assertGreaterThan(0, $collectionId);
		$this->assertGreaterThan(0, $genreId);

		$data = $mapper->findLibraryData($this->databasePath);
		$this->assertCount(3, $data->composers);
		$this->assertCount(2, $data->books);
		$this->assertCount(2, $data->collections);
		$this->assertCount(2, $data->genres);
	}

	/**
	 * Deleting an entity removes only that entry and its links to songs. The songs
	 * themselves stay, and so does everything else that is attached to them.
	 */
	public function testDeleteComposerKeepsTheSongsAndOnlyRemovesTheComposer(): void {
		$mapper = $this->mapper();

		$this->assertTrue($mapper->deleteEntity($this->databasePath, 'composers', 10));

		$database = new \PDO('sqlite:' . $this->databasePath);
		$this->assertSame(0, (int)$database->query('SELECT COUNT(*) FROM Composer WHERE Id = 10')->fetchColumn());
		$this->assertSame(0, (int)$database->query('SELECT COUNT(*) FROM ComposerSongs WHERE ComposerId = 10')->fetchColumn());

		// The songs survive, the untouched composer link stays, and song 1 keeps its
		// other fields - only its composers are gone.
		$this->assertSame(2, (int)$database->query('SELECT COUNT(*) FROM Songs')->fetchColumn());
		$this->assertSame(1, (int)$database->query('SELECT COUNT(*) FROM ComposerSongs WHERE ComposerId = 20')->fetchColumn());
		$this->assertSame(1, (int)$database->query('SELECT COUNT(*) FROM Files WHERE SongId = 1')->fetchColumn());

		$data = $mapper->findLibraryData($this->databasePath);
		$this->assertSame(['Bach'], $this->names($data->composers));
		$zebra = $data->songs[1];
		$this->assertSame(1, $zebra->id);
		$this->assertSame([], $zebra->composers);
		$this->assertSame(['Jazz Standards'], $this->names($zebra->collections));
		$this->assertSame(['Coltrane'], $this->names($zebra->artists));
		$this->assertCount(1, $zebra->files);
	}

	public function testDeleteBookKeepsTheSongsAndOnlyRemovesTheBook(): void {
		$mapper = $this->mapper();

		$this->assertTrue($mapper->deleteEntity($this->databasePath, 'books', 100));

		$database = new \PDO('sqlite:' . $this->databasePath);
		$this->assertSame(0, (int)$database->query('SELECT COUNT(*) FROM Books WHERE Id = 100')->fetchColumn());
		$this->assertSame(0, (int)$database->query('SELECT COUNT(*) FROM BookSongs WHERE BookId = 100')->fetchColumn());
		$this->assertSame(2, (int)$database->query('SELECT COUNT(*) FROM Songs')->fetchColumn());

		$data = $mapper->findLibraryData($this->databasePath);
		$alpha = $data->songs[0];
		$this->assertSame(2, $alpha->id);
		$this->assertSame([], $alpha->books);
		$this->assertSame(['Bach'], $this->names($alpha->composers));
		$this->assertSame(['Jazz Standards'], $this->names($alpha->collections));
	}

	public function testDeleteCollectionKeepsTheSongsAndOnlyRemovesTheCollection(): void {
		$mapper = $this->mapper();

		$this->assertTrue($mapper->deleteEntity($this->databasePath, 'collections', 1000));

		$database = new \PDO('sqlite:' . $this->databasePath);
		$this->assertSame(0, (int)$database->query('SELECT COUNT(*) FROM Collections WHERE Id = 1000')->fetchColumn());
		// Both songs were in the collection; both links go, both songs stay.
		$this->assertSame(0, (int)$database->query('SELECT COUNT(*) FROM CollectionSong')->fetchColumn());
		$this->assertSame(2, (int)$database->query('SELECT COUNT(*) FROM Songs')->fetchColumn());

		$data = $mapper->findLibraryData($this->databasePath);
		$this->assertSame([], $data->collections);
		foreach ($data->songs as $song) {
			$this->assertSame([], $song->collections);
			$this->assertNotEmpty($song->composers);
		}
	}

	public function testDeleteArtistKeepsTheSongsAndOnlyRemovesTheArtist(): void {
		$mapper = $this->mapper();

		$this->assertTrue($mapper->deleteEntity($this->databasePath, 'artists', 200));

		$database = new \PDO('sqlite:' . $this->databasePath);
		$this->assertSame(0, (int)$database->query('SELECT COUNT(*) FROM Artists WHERE Id = 200')->fetchColumn());
		$this->assertSame(0, (int)$database->query('SELECT COUNT(*) FROM ArtistsSongs WHERE ArtistId = 200')->fetchColumn());
		$this->assertSame(2, (int)$database->query('SELECT COUNT(*) FROM Songs')->fetchColumn());

		$data = $mapper->findLibraryData($this->databasePath);
		$zebra = $data->songs[1];
		$this->assertSame(1, $zebra->id);
		$this->assertSame([], $zebra->artists);
		$this->assertSame(['Beethoven'], $this->names($zebra->composers));
	}

	public function testDeleteGenreKeepsTheSongsAndOnlyRemovesTheGenre(): void {
		$mapper = $this->mapper();

		$this->assertTrue($mapper->deleteEntity($this->databasePath, 'genres', 50));

		$database = new \PDO('sqlite:' . $this->databasePath);
		$this->assertSame(0, (int)$database->query('SELECT COUNT(*) FROM Genres WHERE Id = 50')->fetchColumn());
		$this->assertSame(0, (int)$database->query('SELECT COUNT(*) FROM GenresSongs WHERE GenreId = 50')->fetchColumn());
		$this->assertSame(2, (int)$database->query('SELECT COUNT(*) FROM Songs')->fetchColumn());

		$data = $mapper->findLibraryData($this->databasePath);
		$zebra = $data->songs[1];
		$this->assertSame(1, $zebra->id);
		$this->assertSame([], $zebra->genres);
		$this->assertSame(['Beethoven'], $this->names($zebra->composers));
	}

	public function testDeleteEntityOfAnUnknownIdRemovesNothing(): void {
		$mapper = $this->mapper();

		$this->assertFalse($mapper->deleteEntity($this->databasePath, 'composers', 999));

		$database = new \PDO('sqlite:' . $this->databasePath);
		$this->assertSame(2, (int)$database->query('SELECT COUNT(*) FROM Composer')->fetchColumn());
		$this->assertSame(2, (int)$database->query('SELECT COUNT(*) FROM ComposerSongs')->fetchColumn());
		$this->assertSame(2, (int)$database->query('SELECT COUNT(*) FROM Songs')->fetchColumn());
	}

	public function testDeleteEntityOfAnUnknownTypeIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->mapper()->deleteEntity($this->databasePath, 'unknown_type', 1);
	}

	public function testUpdateFileRenamesTheStoredPath(): void {
		$mapper = $this->mapper();

		// The file carries a previous modification time, so a change has to move
		// LastModified forward rather than leave the old timestamp in place.
		$database = new \PDO('sqlite:' . $this->databasePath);
		$database->exec('UPDATE Files SET LastModified = 1789222400000, FileSize = 100000, PageOrder = \'1-1\', SourceFilePageCount = 1, FileHash = 1 WHERE Id = 2');

		$mapper->updateFile(
			$this->databasePath,
			2,
			'/remote.php/dav/files/admin/Noten/AlphaRenamed.pdf',
			new MobileSheetsFileFacts(
				fileHash: 1058935524,
				fileSize: 147100,
				lastModified: 1789222408000,
				sourceFilePageCount: 3,
				pageOrder: '1-3',
			),
		);

		$alpha = $mapper->findSong($this->databasePath, 2);
		self::assertNotNull($alpha);
		self::assertSame(2, $alpha->files[0]->id);
		self::assertSame('/remote.php/dav/files/admin/Noten/AlphaRenamed.pdf', $alpha->files[0]->path);

		// A change to the file refreshes LastModified and the derived metadata.
		$row = (new \PDO('sqlite:' . $this->databasePath))
			->query('SELECT * FROM Files WHERE Id = 2')
			->fetch();
		self::assertSame('/remote.php/dav/files/admin/Noten/AlphaRenamed.pdf', (string)$row['Path']);
		self::assertSame(1789222408000, (int)$row['LastModified']);
		self::assertSame(147100, (int)$row['FileSize']);
		self::assertSame('1-3', (string)$row['PageOrder']);
		self::assertSame(3, (int)$row['SourceFilePageCount']);
		self::assertSame(1058935524, (int)$row['FileHash']);
	}

	public function testRemoveSongFileOfAnUnknownFileChangesNothing(): void {
		$mapper = $this->mapper();

		self::assertNull($mapper->removeSongFile($this->databasePath, 2, 999));

		$alpha = $mapper->findSong($this->databasePath, 2);
		self::assertNotNull($alpha);
		self::assertCount(1, $alpha->files);
	}

	/** The file of another song may not be removed through this song. */
	public function testRemoveSongFileOfAnotherSongsFileChangesNothing(): void {
		$mapper = $this->mapper();

		self::assertNull($mapper->removeSongFile($this->databasePath, 2, 1));

		$database = new \PDO('sqlite:' . $this->databasePath);
		self::assertSame(1, (int)$database->query('SELECT COUNT(*) FROM Files WHERE Id = 1')->fetchColumn());
	}

	/** The order decides the numeric prefix of the regenerated file names. */
	public function testFilesOfSongKeepsTheOrderOfTheIds(): void {
		$database = new \PDO('sqlite:' . $this->databasePath);
		$database->exec("INSERT INTO Files (Id, SongId, Path) VALUES (7, 2, '/b.pdf'), (5, 2, '/a.pdf')");

		$files = $this->mapper()->filesOfSong($this->databasePath, 2);

		self::assertSame([2, 5, 7], array_map(fn (MobileSheetsFile $file) => $file->id, $files));
	}

	/**
	 * Link rows without their entry are leftovers of a broken database. They are
	 * cleaned up, but nothing is reported as removed because the entry is gone.
	 */
	public function testDeleteEntityRemovesLeftoverLinksEvenWithoutTheEntry(): void {
		$mapper = $this->mapper();
		$database = new \PDO('sqlite:' . $this->databasePath);
		$database->exec('DELETE FROM Composer WHERE Id = 10');

		self::assertFalse($mapper->deleteEntity($this->databasePath, 'composers', 10));

		self::assertSame(0, (int)$database->query('SELECT COUNT(*) FROM ComposerSongs WHERE ComposerId = 10')->fetchColumn());
		self::assertSame(1, (int)$database->query('SELECT COUNT(*) FROM ComposerSongs WHERE ComposerId = 20')->fetchColumn());
	}

	public function testDeleteEntityWithoutItsTablesDoesNotFail(): void {
		$barePath = $this->bareDatabase();

		try {
			self::assertFalse($this->mapper()->deleteEntity($barePath, 'composers', 10));
		} finally {
			unlink($barePath);
		}
	}

	/** A library without the optional tables has no entities but still its songs. */
	public function testFindLibraryDataOnADatabaseWithoutTheOptionalTables(): void {
		$barePath = $this->bareDatabase();

		try {
			$data = $this->mapper()->findLibraryData($barePath);

			self::assertCount(1, $data->songs);
			self::assertSame('Solo', $data->songs[0]->title);
			self::assertSame([], $data->songs[0]->files);
			self::assertSame([], $data->songs[0]->composers);
			self::assertSame([], $data->composers);
			self::assertSame([], $data->books);
			self::assertSame([], $data->collections);
			self::assertSame([], $data->artists);
			self::assertSame([], $data->genres);
		} finally {
			unlink($barePath);
		}
	}

	/** A database that only knows the Songs table, like a minimal export. */
	private function bareDatabase(): string {
		$path = tempnam(sys_get_temp_dir(), 'sheetmusic-');
		self::assertNotFalse($path);
		$database = new \PDO('sqlite:' . $path);
		$database->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), SongId INTEGER)');
		$database->exec("INSERT INTO Songs (Id, Title, SortTitle) VALUES (1, 'Solo', 'Solo')");

		return $path;
	}

	/**
	 * A library whose Songs table carries the given creation-date column (or none
	 * when null) and, optionally, a LastModified column.
	 */
	private function songsDatabase(?string $createdColumn, bool $lastModified = true): string {
		$path = tempnam(sys_get_temp_dir(), 'sheetmusic-');
		self::assertNotFalse($path);
		$database = new \PDO('sqlite:' . $path);

		$columns = 'Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), SongId INTEGER';
		if ($createdColumn !== null) {
			$columns .= ', ' . $createdColumn . ' INTEGER DEFAULT 0';
		}
		if ($lastModified) {
			$columns .= ', LastModified INTEGER DEFAULT 0';
		}
		$database->exec('CREATE TABLE Songs(' . $columns . ')');

		return $path;
	}

	public function testBatchUpdateSongsAddsAndReplacesMetadata(): void {
		$mapper = $this->mapper();

		// Batch add Bach (id 20) to song 1, replace books for song 1 with book 100
		$mapper->batchUpdateSongs(
			$this->databasePath,
			[1, 2],
			composerUpdate: ['action' => 'add', 'ids' => [20]],
			bookUpdate: ['action' => 'replace', 'ids' => [100]],
		);

		$song1 = $mapper->findSong($this->databasePath, 1);
		self::assertNotNull($song1);
		self::assertCount(2, $song1->composers);
		self::assertSame(['Beethoven', 'Bach'], $this->names($song1->composers));
		self::assertSame(['Real Book 1'], $this->titles($song1->books));

		$song2 = $mapper->findSong($this->databasePath, 2);
		self::assertNotNull($song2);
		self::assertSame(['Bach'], $this->names($song2->composers));
		self::assertSame(['Real Book 1'], $this->titles($song2->books));
	}

	public function testBatchUpdateSongsRemovesMetadata(): void {
		$mapper = $this->mapper();

		// Remove Beethoven (id 10) from song 1, remove Jazz Standards (id 1000) from songs 1 and 2
		$mapper->batchUpdateSongs(
			$this->databasePath,
			[1, 2],
			composerUpdate: ['action' => 'remove', 'ids' => [10]],
			collectionUpdate: ['action' => 'remove', 'ids' => [1000]],
		);

		$song1 = $mapper->findSong($this->databasePath, 1);
		self::assertNotNull($song1);
		self::assertSame([], $song1->composers);
		self::assertSame([], $song1->collections);

		$song2 = $mapper->findSong($this->databasePath, 2);
		self::assertNotNull($song2);
		self::assertSame(['Bach'], $this->names($song2->composers));
		self::assertSame([], $song2->collections);
	}

	public function testBatchUpdateSongsWithEmptySelectionDoesNothing(): void {
		$mapper = $this->mapper();

		$mapper->batchUpdateSongs(
			$this->databasePath,
			[],
			composerUpdate: ['action' => 'add', 'ids' => [20]],
		);

		$song1 = $mapper->findSong($this->databasePath, 1);
		self::assertNotNull($song1);
		self::assertSame(['Beethoven'], $this->names($song1->composers));
	}

	public function testDetermineFileType(): void {
		self::assertSame(SongMapper::FILE_TYPE_PDF, SongMapper::determineFileType('/path/to/song.pdf'));
		self::assertSame(SongMapper::FILE_TYPE_IMAGE, SongMapper::determineFileType('/path/to/image.PNG'));
		self::assertSame(SongMapper::FILE_TYPE_IMAGE, SongMapper::determineFileType('/path/to/photo.jpg'));
		self::assertSame(SongMapper::FILE_TYPE_TEXT, SongMapper::determineFileType('/path/to/lyrics.txt'));
		self::assertSame(SongMapper::FILE_TYPE_TEXT, SongMapper::determineFileType('/path/to/chords.cho'));
		self::assertSame(SongMapper::FILE_TYPE_ABC, SongMapper::determineFileType('/path/to/tune.abc'));
		self::assertSame(SongMapper::FILE_TYPE_OTHER, SongMapper::determineFileType('/path/to/track.mp3'));
		self::assertSame(SongMapper::FILE_TYPE_OTHER, SongMapper::determineFileType('/path/to/score.sib'));
	}

	public function testSetSongFileRecordsCorrectFileType(): void {
		$mapper = $this->mapper();
		$facts = new MobileSheetsFileFacts(12345, 1024, 1600000000000, 1, '1');

		$mapper->setSongFile($this->databasePath, 1, '/remote.php/dav/files/admin/Noten/image.png', $facts);

		$database = new \PDO('sqlite:' . $this->databasePath);
		$stmt = $database->query("SELECT Type FROM Files WHERE Path = '/remote.php/dav/files/admin/Noten/image.png'");
		$row = $stmt->fetch();
		self::assertSame(SongMapper::FILE_TYPE_IMAGE, (int)$row['Type']);
	}

	public function testSongAttributesAreLoadedAndSaved(): void {
		$mapper = $this->mapper();
		$song1 = $mapper->findSong($this->databasePath, 1);
		self::assertNotNull($song1);
		self::assertSame(5, $song1->stars);
		self::assertSame(3, $song1->difficulty);
		self::assertSame(240, $song1->duration);
		self::assertSame('jazz, fast', $song1->keywords);
		self::assertSame('Key C', $song1->custom);
		self::assertSame('Arrangement A', $song1->custom2);

		$createdId = $mapper->createSong(
			$this->databasePath,
			'New Piece',
			[], [], [], [],
			stars: 4,
			difficulty: 2,
			duration: 180,
			keywords: 'baroque',
			custom: 'Solo',
			custom2: 'Op. 1',
		);

		$createdSong = $mapper->findSong($this->databasePath, $createdId);
		self::assertNotNull($createdSong);
		self::assertSame('New Piece', $createdSong->title);
		self::assertSame(4, $createdSong->stars);
		self::assertSame(2, $createdSong->difficulty);
		self::assertSame(180, $createdSong->duration);
		self::assertSame('baroque', $createdSong->keywords);
		self::assertSame('Solo', $createdSong->custom);
		self::assertSame('Op. 1', $createdSong->custom2);

		$mapper->updateSong(
			$this->databasePath,
			$createdId,
			'Updated Piece',
			[], [], [], [],
			stars: 5,
			difficulty: 4,
			duration: 210,
			keywords: 'baroque, revised',
			custom: 'Duo',
			custom2: 'Op. 1b',
		);

		$updatedSong = $mapper->findSong($this->databasePath, $createdId);
		self::assertNotNull($updatedSong);
		self::assertSame('Updated Piece', $updatedSong->title);
		self::assertSame(5, $updatedSong->stars);
		self::assertSame(4, $updatedSong->difficulty);
		self::assertSame(210, $updatedSong->duration);
		self::assertSame('baroque, revised', $updatedSong->keywords);
		self::assertSame('Duo', $updatedSong->custom);
		self::assertSame('Op. 1b', $updatedSong->custom2);
	}

	public function testKeyOperations(): void {
		$mapper = $this->mapper();
		$keyId = $mapper->createKey($this->databasePath, 'D Minor');
		self::assertGreaterThan(0, $keyId);

		$songId = $mapper->createSong($this->databasePath, 'Key Test Song', keyIds: [$keyId]);
		$song = $mapper->findSong($this->databasePath, $songId);
		self::assertNotNull($song);
		self::assertSame(['D Minor'], $this->names($song->keys));

		// Batch update with replace
		$keyId2 = $mapper->createKey($this->databasePath, 'G Major');
		$mapper->batchUpdateSongs($this->databasePath, [$songId], keyUpdate: ['action' => 'replace', 'ids' => [$keyId2]]);
		$song = $mapper->findSong($this->databasePath, $songId);
		self::assertSame(['G Major'], $this->names($song->keys));

		// Delete key entity
		$deleted = $mapper->deleteEntity($this->databasePath, 'keys', $keyId2);
		self::assertTrue($deleted);
		$song = $mapper->findSong($this->databasePath, $songId);
		self::assertSame([], $song->keys);
	}

	public function testCustomGroupOperations(): void {
		$mapper = $this->mapper();
		$groupId = $mapper->createCustomGroup($this->databasePath, 'Choir');
		self::assertGreaterThan(0, $groupId);

		$songId = $mapper->createSong($this->databasePath, 'Group Test Song', customGroupIds: [$groupId]);
		$song = $mapper->findSong($this->databasePath, $songId);
		self::assertNotNull($song);
		self::assertSame(['Choir'], $this->names($song->customGroups));

		// Batch update with replace
		$groupId2 = $mapper->createCustomGroup($this->databasePath, 'Orchestra');
		$mapper->batchUpdateSongs($this->databasePath, [$songId], customGroupUpdate: ['action' => 'replace', 'ids' => [$groupId2]]);
		$song = $mapper->findSong($this->databasePath, $songId);
		self::assertSame(['Orchestra'], $this->names($song->customGroups));

		// Delete customgroup entity
		$deleted = $mapper->deleteEntity($this->databasePath, 'customgroups', $groupId2);
		self::assertTrue($deleted);
		$song = $mapper->findSong($this->databasePath, $songId);
		self::assertSame([], $song->customGroups);
	}

	public function testYearOperations(): void {
		$mapper = $this->mapper();
		$yearId = $mapper->createYear($this->databasePath, 1960);
		self::assertGreaterThan(0, $yearId);

		$songId = $mapper->createSong($this->databasePath, 'Year Test Song', yearIds: [$yearId]);
		$song = $mapper->findSong($this->databasePath, $songId);
		self::assertNotNull($song);
		self::assertSame(['1960'], $this->names($song->years));

		// Batch update with replace
		$yearId2 = $mapper->createYear($this->databasePath, 1975);
		$mapper->batchUpdateSongs($this->databasePath, [$songId], yearUpdate: ['action' => 'replace', 'ids' => [$yearId2]]);
		$song = $mapper->findSong($this->databasePath, $songId);
		// Delete year entity
		$deleted = $mapper->deleteEntity($this->databasePath, 'years', $yearId2);
		self::assertTrue($deleted);
		$song = $mapper->findSong($this->databasePath, $songId);
		self::assertSame([], $song->years);
	}

	public function testSignatureOperations(): void {
		$mapper = $this->mapper();
		$signatureId = $mapper->createSignature($this->databasePath, '3/4');
		self::assertGreaterThan(0, $signatureId);

		$songId = $mapper->createSong($this->databasePath, 'Signature Test Song', signatureIds: [$signatureId]);
		$song = $mapper->findSong($this->databasePath, $songId);
		self::assertNotNull($song);
		self::assertSame(['3/4'], $this->names($song->signatures));

		// Batch update with replace
		$signatureId2 = $mapper->createSignature($this->databasePath, '6/8');
		$mapper->batchUpdateSongs($this->databasePath, [$songId], signatureUpdate: ['action' => 'replace', 'ids' => [$signatureId2]]);
		$song = $mapper->findSong($this->databasePath, $songId);
		self::assertSame(['6/8'], $this->names($song->signatures));

		// Delete signature entity
		$deleted = $mapper->deleteEntity($this->databasePath, 'signatures', $signatureId2);
		self::assertTrue($deleted);
		$song = $mapper->findSong($this->databasePath, $songId);
		self::assertSame([], $song->signatures);
	}

	public function testSourceTypeOperations(): void {
		$mapper = $this->mapper();
		$sourceTypeId = $mapper->createSourceType($this->databasePath, 'Lead Sheet');
		self::assertGreaterThan(0, $sourceTypeId);

		$songId = $mapper->createSong($this->databasePath, 'SourceType Test Song', sourceTypeIds: [$sourceTypeId]);
		$song = $mapper->findSong($this->databasePath, $songId);
		self::assertNotNull($song);
		self::assertSame(['Lead Sheet'], $this->names($song->sourceTypes));

		// Batch update with replace
		$sourceTypeId2 = $mapper->createSourceType($this->databasePath, 'Chord Chart');
		$mapper->batchUpdateSongs($this->databasePath, [$songId], sourceTypeUpdate: ['action' => 'replace', 'ids' => [$sourceTypeId2]]);
		$song = $mapper->findSong($this->databasePath, $songId);
		self::assertSame(['Chord Chart'], $this->names($song->sourceTypes));

		// Delete source type entity
		$deleted = $mapper->deleteEntity($this->databasePath, 'sourcetypes', $sourceTypeId2);
		self::assertTrue($deleted);
		$song = $mapper->findSong($this->databasePath, $songId);
		self::assertSame([], $song->sourceTypes);
	}

	public function testBookmarksOfSongAndUpdateBookmarks(): void {
		$mapper = $this->mapper();

		$bookmarks = $mapper->bookmarksOfSong($this->databasePath, 1);
		self::assertCount(1, $bookmarks);
		self::assertSame('Chorus', $bookmarks[0]->name);
		self::assertSame(2, $bookmarks[0]->pageNum);

		$mapper->updateBookmarks($this->databasePath, 1, [
			['name' => 'Intro', 'pageNum' => 1],
			['name' => 'Outro', 'pageNum' => 4],
		]);

		$updatedBookmarks = $mapper->bookmarksOfSong($this->databasePath, 1);
		self::assertCount(2, $updatedBookmarks);
		self::assertSame('Intro', $updatedBookmarks[0]->name);
		self::assertSame(1, $updatedBookmarks[0]->pageNum);
		self::assertSame('Outro', $updatedBookmarks[1]->name);
		self::assertSame(4, $updatedBookmarks[1]->pageNum);
	}

	public function testAudioFilesOfSongAndUpdateAudioFiles(): void {
		$mapper = $this->mapper();

		$audioFiles = $mapper->audioFilesOfSong($this->databasePath, 1);
		self::assertCount(1, $audioFiles);
		self::assertSame('Audio Track', $audioFiles[0]->title);
		self::assertSame('track1.mp3', $audioFiles[0]->file);

		$mapper->updateAudioFiles($this->databasePath, 1, [
			['title' => 'Backing Track', 'file' => 'backing.mp3'],
		]);

		$updatedAudioFiles = $mapper->audioFilesOfSong($this->databasePath, 1);
		self::assertCount(1, $updatedAudioFiles);
		self::assertSame('Backing Track', $updatedAudioFiles[0]->title);
		self::assertSame('backing.mp3', $updatedAudioFiles[0]->file);
	}

	public function testSchemaVariationsKeySingularAndYearsNameColumn(): void {
		$dbPath = tempnam(sys_get_temp_dir(), 'sheetmusic-schema-test-');
		self::assertNotFalse($dbPath);
		$db = new \PDO('sqlite:' . $dbPath);
		$db->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255))');
		$db->exec('CREATE TABLE "Key"(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE KeySongs(Id INTEGER PRIMARY KEY, KeyId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE Years(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE YearsSongs(Id INTEGER PRIMARY KEY, YearId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE SourceType(Id INTEGER PRIMARY KEY, Type VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE SourceTypeSongs(Id INTEGER PRIMARY KEY, SourceTypeId INTEGER, SongId INTEGER)');

		$db->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Test Song')");
		$db->exec("INSERT INTO \"Key\" (Id, Name) VALUES (1, 'C Major')");
		$db->exec('INSERT INTO KeySongs (KeyId, SongId) VALUES (1, 1)');
		$db->exec("INSERT INTO Years (Id, Name) VALUES (1, '1999')");
		$db->exec('INSERT INTO YearsSongs (YearId, SongId) VALUES (1, 1)');
		$db->exec("INSERT INTO SourceType (Id, Type) VALUES (1, 'Score')");
		$db->exec('INSERT INTO SourceTypeSongs (SourceTypeId, SongId) VALUES (1, 1)');

		$mapper = $this->mapper();
		$data = $mapper->findLibraryData($dbPath);
		self::assertCount(1, $data->songs);
		self::assertSame('C Major', $data->keys[0]->name);
		self::assertSame(1999, $data->years[0]->year);
		self::assertSame('Score', $data->sourceTypes[0]->type);

		$songs = $mapper->findSongs($dbPath);
		self::assertCount(1, $songs);
		self::assertSame('C Major', $songs[0]->keys[0]->name);
		self::assertSame(1999, $songs[0]->years[0]->year);
		self::assertSame('Score', $songs[0]->sourceTypes[0]->name);

		unlink($dbPath);
	}

	public function testCreateInstrumentAndFindLibraryDataSeparatesInstrumentsFromCustomGroups(): void {
		$database = new \PDO('sqlite:' . $this->databasePath);
		$database->exec('CREATE TABLE IF NOT EXISTS _nc_sync_metadata (entity_type TEXT NOT NULL, uuid TEXT NOT NULL, client_id INTEGER, updated_at INTEGER, extra_json TEXT, PRIMARY KEY (entity_type, uuid))');

		$mapper = $this->mapper();
		$instrumentId = $mapper->createInstrument($this->databasePath, 'Pipe Organ');
		$customGroupId = $mapper->createCustomGroup($this->databasePath, 'Jazz Ensemble');

		// Link both to song 1
		$database->exec("INSERT INTO CustomGroupSongs (GroupId, SongId) VALUES ($instrumentId, 1)");
		$database->exec("INSERT INTO CustomGroupSongs (GroupId, SongId) VALUES ($customGroupId, 1)");

		// Add another instrument with legacy entity_type = 'instrument'
		$database->exec("INSERT INTO CustomGroup (Id, Name) VALUES (95, 'Harpsichord')");
		$database->exec("INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at) VALUES ('instrument', 'uuid-harpsichord', 95, 12345)");
		$database->exec('INSERT INTO CustomGroupSongs (GroupId, SongId) VALUES (95, 1)');

		$data = $mapper->findLibraryData($this->databasePath);

		$instrumentNames = $this->names($data->instruments);
		self::assertContains('Pipe Organ', $instrumentNames);
		self::assertContains('Harpsichord', $instrumentNames);
		self::assertNotContains('Jazz Ensemble', $instrumentNames);

		$customGroupNames = $this->names($data->customGroups);
		self::assertContains('Jazz Ensemble', $customGroupNames);
		self::assertNotContains('Pipe Organ', $customGroupNames);
		self::assertNotContains('Harpsichord', $customGroupNames);

		// Check song 1's resolved entities
		$song = array_values(array_filter($data->songs, static fn ($s) => $s->id === 1))[0];
		$songInstrumentNames = $this->names($song->instruments);
		self::assertContains('Pipe Organ', $songInstrumentNames);
		self::assertContains('Harpsichord', $songInstrumentNames);
		self::assertNotContains('Jazz Ensemble', $songInstrumentNames);

		$songGroupNames = $this->names($song->customGroups);
		self::assertContains('Jazz Ensemble', $songGroupNames);
		self::assertNotContains('Pipe Organ', $songGroupNames);
	}

	/**
	 * @param list<MobileSheetsComposer|MobileSheetsCollection|MobileSheetsArtist|MobileSheetsGenre|MobileSheetsKey|MobileSheetsCustomGroup|MobileSheetsYear|MobileSheetsSignature|MobileSheetsSourceType> $entities
	 * @return list<string>
	 */
	private function names(array $entities): array {
		return array_map(static fn ($entity) => $entity->name, $entities);
	}

	/**
	 * @param list<MobileSheetsBook> $books
	 * @return list<string>
	 */
	private function titles(array $books): array {
		return array_map(static fn (MobileSheetsBook $book) => $book->title, $books);
	}
}
