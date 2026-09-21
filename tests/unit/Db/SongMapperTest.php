<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\MobileSheetsManager\Db\MobileSheetsFile;
use OCA\MobileSheetsManager\Db\MobileSheetsSong;
use OCA\MobileSheetsManager\Db\SongMapper;
use PHPUnit\Framework\TestCase;

final class SongMapperTest extends TestCase {
	private string $databasePath;

	protected function setUp(): void {
		$databasePath = tempnam(sys_get_temp_dir(), 'mobilesheetsmanager-');
		self::assertNotFalse($databasePath);
		$this->databasePath = $databasePath;
		$database = new \PDO('sqlite:' . $this->databasePath);
		$database->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255))');
		$database->exec('CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255))');
		$database->exec('CREATE TABLE Collections(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec('CREATE TABLE CollectionSong(Id INTEGER PRIMARY KEY, CollectionId INTEGER, SongId INTEGER)');
		$database->exec('CREATE TABLE Composer(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec('CREATE TABLE ComposerSongs(Id INTEGER PRIMARY KEY, ComposerId INTEGER, SongId INTEGER)');
		$database->exec('CREATE TABLE BookSongs(Id INTEGER PRIMARY KEY, BookId INTEGER, SongId INTEGER)');
		$database->exec('CREATE TABLE Books(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec('CREATE TABLE Artists(Id INTEGER PRIMARY KEY, Name VARCHAR(255))');
		$database->exec('CREATE TABLE ArtistsSongs(Id INTEGER PRIMARY KEY, ArtistId INTEGER, SongId INTEGER)');
		$database->exec('CREATE TABLE Genres(Id INTEGER PRIMARY KEY, Type VARCHAR(255))');
		$database->exec('CREATE TABLE GenresSongs(Id INTEGER PRIMARY KEY, GenreId INTEGER, SongId INTEGER)');

		$database->exec("INSERT INTO Songs (Id, Title, SortTitle) VALUES (1, 'Zebra', 'Zebra'), (2, 'Alpha', 'Alpha')");
		$database->exec("INSERT INTO Files (Id, SongId, Path) VALUES (1, 1, '/remote.php/dav/files/admin/Noten/Zebra.pdf'), (2, 2, '/remote.php/dav/files/admin/Noten/Alpha.pdf')");

		$database->exec("INSERT INTO Composer (Id, Name) VALUES (10, 'Beethoven'), (20, 'Bach')");
		$database->exec("INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (10, 1), (20, 2)");

		$database->exec("INSERT INTO Books (Id, Title) VALUES (100, 'Real Book 1')");
		$database->exec("INSERT INTO BookSongs (BookId, SongId) VALUES (100, 2)");

		$database->exec("INSERT INTO Collections (Id, Name) VALUES (1000, 'Jazz Standards')");
		$database->exec("INSERT INTO CollectionSong (CollectionId, SongId) VALUES (1000, 1), (1000, 2)");

		$database->exec("INSERT INTO Artists (Id, Name) VALUES (200, 'Coltrane')");
		$database->exec("INSERT INTO ArtistsSongs (ArtistId, SongId) VALUES (200, 1)");
	}

	protected function tearDown(): void {
		unlink($this->databasePath);
	}

	public function testFindSongsReturnsSongsWithResolvedNamesAndIds(): void {
		$songs = (new SongMapper())->findSongs($this->databasePath);

		$this->assertCount(2, $songs);
		$this->assertContainsOnlyInstancesOf(MobileSheetsSong::class, $songs);

		$byId = [];
		foreach ($songs as $song) {
			$byId[$song->id] = $song;
		}

		$zebra = $byId[1];
		$this->assertSame('Zebra', $zebra->title);
		$this->assertSame('Beethoven', $zebra->composerName);
		$this->assertSame('Coltrane', $zebra->artistName);
		$this->assertNull($zebra->bookName);
		$this->assertSame('Jazz Standards', $zebra->collectionName);
		$this->assertCount(1, $zebra->files);
		$this->assertContainsOnlyInstancesOf(MobileSheetsFile::class, $zebra->files);
		$this->assertSame(1, $zebra->files[0]->id);
		$this->assertSame('/remote.php/dav/files/admin/Noten/Zebra.pdf', $zebra->files[0]->path);
		$this->assertSame([10], $zebra->composerIds);
		$this->assertSame([200], $zebra->artistIds);
		$this->assertSame([], $zebra->bookIds);
		$this->assertSame([1000], $zebra->collectionIds);

		$alpha = $byId[2];
		$this->assertSame('Alpha', $alpha->title);
		$this->assertSame('Bach', $alpha->composerName);
		$this->assertNull($alpha->artistName);
		$this->assertSame('Real Book 1', $alpha->bookName);
		$this->assertSame('Jazz Standards', $alpha->collectionName);
		$this->assertSame([20], $alpha->composerIds);
		$this->assertSame([], $alpha->artistIds);
		$this->assertSame([100], $alpha->bookIds);
		$this->assertSame([1000], $alpha->collectionIds);
	}

	public function testFindLibraryDataReturnsAllEntities(): void {
		$data = (new SongMapper())->findLibraryData($this->databasePath);

		$this->assertCount(2, $data['songs']);
		$this->assertContainsOnlyInstancesOf(MobileSheetsSong::class, $data['songs']);
		$this->assertSame([
			['id' => 20, 'name' => 'Bach'],
			['id' => 10, 'name' => 'Beethoven'],
		], $data['composers']);
		$this->assertSame([
			['id' => 100, 'title' => 'Real Book 1'],
		], $data['books']);
		$this->assertSame([
			['id' => 1000, 'name' => 'Jazz Standards'],
		], $data['collections']);
	}

	public function testUpdateSongAndAssociations(): void {
		$mapper = new SongMapper();
		$mapper->updateSong($this->databasePath, 2, 'Alpha (Updated)', [10], [100], [1000]);

		$data = $mapper->findLibraryData($this->databasePath);
		$updatedSong = array_values(array_filter($data['songs'], fn(MobileSheetsSong $s) => $s->id === 2))[0];

		$this->assertSame('Alpha (Updated)', $updatedSong->title);
		$this->assertSame([10], $updatedSong->composerIds);
		$this->assertSame([100], $updatedSong->bookIds);
		$this->assertSame([1000], $updatedSong->collectionIds);
	}

	public function testSetSongFile(): void {
		$mapper = new SongMapper();
		$mapper->setSongFile($this->databasePath, 2, '/remote.php/dav/files/admin/Noten/AlphaNew.pdf');

		$data = $mapper->findLibraryData($this->databasePath);
		$updatedSong = array_values(array_filter($data['songs'], fn(MobileSheetsSong $s) => $s->id === 2))[0];

		$this->assertCount(2, $updatedSong->files);
		$this->assertContainsOnlyInstancesOf(MobileSheetsFile::class, $updatedSong->files);
	}

	public function testRemoveSongFile(): void {
		$mapper = new SongMapper();
		$deletedPath = $mapper->removeSongFile($this->databasePath, 2, 2);

		$this->assertSame('/remote.php/dav/files/admin/Noten/Alpha.pdf', $deletedPath);

		$data = $mapper->findLibraryData($this->databasePath);
		$updatedSong = array_values(array_filter($data['songs'], fn(MobileSheetsSong $s) => $s->id === 2))[0];

		$this->assertCount(0, $updatedSong->files);
	}

	public function testCreateEntities(): void {
		$mapper = new SongMapper();
		$composerId = $mapper->createComposer($this->databasePath, 'Mozart');
		$bookId = $mapper->createBook($this->databasePath, 'Real Book 2');
		$collectionId = $mapper->createCollection($this->databasePath, 'Classical');

		$this->assertGreaterThan(0, $composerId);
		$this->assertGreaterThan(0, $bookId);
		$this->assertGreaterThan(0, $collectionId);

		$data = $mapper->findLibraryData($this->databasePath);
		$this->assertCount(3, $data['composers']);
		$this->assertCount(2, $data['books']);
		$this->assertCount(2, $data['collections']);
	}
}