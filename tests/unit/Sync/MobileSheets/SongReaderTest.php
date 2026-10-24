<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Tests\Unit\Db;

use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
use OCA\SheetMusic\Sync\MobileSheets\SongReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SongReaderTest extends TestCase {
	private string $databasePath;
	private SongReader $reader;

	protected function setUp(): void {
		parent::setUp();
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

		$database->exec("INSERT INTO CustomGroup (Id, Name) VALUES (300, 'Brass Quintet')");
		$database->exec('INSERT INTO CustomGroupSongs (GroupId, SongId) VALUES (300, 1)');

		$database->exec('INSERT INTO Years (Id, Year) VALUES (400, 1959)');
		$database->exec('INSERT INTO YearsSongs (YearId, SongId) VALUES (400, 1)');

		$database->exec("INSERT INTO Signature (Id, Name) VALUES (500, '4/4')");
		$database->exec('INSERT INTO SignatureSongs (SignatureId, SongId) VALUES (500, 1)');

		$database->exec("INSERT INTO SourceTypes (Id, Type) VALUES (600, 'Sheet')");
		$database->exec('INSERT INTO SourceTypeSongs (SourceTypeId, SongId) VALUES (600, 1)');

		$this->reader = new SongReader(new NullLogger());
	}

	public function testFindLibraryDataReadsEntitiesAndSongs(): void {
		$data = $this->reader->findLibraryData($this->databasePath);

		self::assertCount(2, $data->songs);
		self::assertCount(2, $data->composers);
		self::assertCount(1, $data->books);
		self::assertCount(1, $data->collections);
		self::assertCount(1, $data->artists);
		self::assertCount(1, $data->genres);
		self::assertCount(1, $data->keys);
		self::assertCount(1, $data->customGroups);
		self::assertCount(1, $data->years);
		self::assertCount(1, $data->signatures);
		self::assertCount(1, $data->sourceTypes);

		$zebra = $data->songs[1]; // ordered alphabetically by Title: Alpha, Zebra
		self::assertSame('Zebra', $zebra->title);
		self::assertCount(1, $zebra->files);
		self::assertSame('/remote.php/dav/files/admin/Noten/Zebra.pdf', $zebra->files[0]->path);
		self::assertCount(1, $zebra->bookmarks);
		self::assertSame('Chorus', $zebra->bookmarks[0]->name);
		self::assertCount(1, $zebra->audioFiles);
		self::assertSame('Audio Track', $zebra->audioFiles[0]->title);
		self::assertSame('Beethoven', $zebra->composers[0]->name);
	}

	public function testFindSongsReturnsAllSongs(): void {
		$songs = $this->reader->findSongs($this->databasePath);

		self::assertCount(2, $songs);
		$ids = array_map(fn (MobileSheetsSong $s) => $s->id, $songs);
		self::assertContains(1, $ids);
		self::assertContains(2, $ids);
	}

	public function testFindSongReturnsSingleSongById(): void {
		$song = $this->reader->findSong($this->databasePath, 1);

		self::assertNotNull($song);
		self::assertSame(1, $song->id);
		self::assertSame('Zebra', $song->title);
		self::assertSame(5, $song->stars);
		self::assertSame(3, $song->difficulty);
		self::assertSame(240, $song->duration);
		self::assertSame('jazz, fast', $song->keywords);
		self::assertCount(1, $song->composers);
		self::assertSame('Beethoven', $song->composers[0]->name);
	}

	public function testFindSongReturnsNullForNonexistentId(): void {
		$song = $this->reader->findSong($this->databasePath, 9999);

		self::assertNull($song);
	}
}
