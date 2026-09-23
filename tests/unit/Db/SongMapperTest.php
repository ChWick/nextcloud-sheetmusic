<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\MobileSheetsManager\Db\MobileSheetsArtist;
use OCA\MobileSheetsManager\Db\MobileSheetsBook;
use OCA\MobileSheetsManager\Db\MobileSheetsCollection;
use OCA\MobileSheetsManager\Db\MobileSheetsComposer;
use OCA\MobileSheetsManager\Db\MobileSheetsFile;
use OCA\MobileSheetsManager\Db\MobileSheetsSong;
use OCA\MobileSheetsManager\Db\SongMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

	private function mapper(): SongMapper {
		return new SongMapper($this->createMock(LoggerInterface::class));
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
		$this->assertSame([], $zebra->books);
		$this->assertSame(['Jazz Standards'], $this->names($zebra->collections));
		$this->assertContainsOnlyInstancesOf(MobileSheetsComposer::class, $zebra->composers);
		$this->assertContainsOnlyInstancesOf(MobileSheetsArtist::class, $zebra->artists);
		$this->assertContainsOnlyInstancesOf(MobileSheetsCollection::class, $zebra->collections);
		$this->assertCount(1, $zebra->files);
		$this->assertContainsOnlyInstancesOf(MobileSheetsFile::class, $zebra->files);
		$this->assertSame(1, $zebra->files[0]->id);
		$this->assertSame('/remote.php/dav/files/admin/Noten/Zebra.pdf', $zebra->files[0]->path);
		$this->assertSame(10, $zebra->composers[0]->id);
		$this->assertSame(200, $zebra->artists[0]->id);
		$this->assertSame(1000, $zebra->collections[0]->id);

		$alpha = $byId[2];
		$this->assertSame('Alpha', $alpha->title);
		$this->assertSame(['Bach'], $this->names($alpha->composers));
		$this->assertSame([], $alpha->artists);
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

		$this->assertCount(2, $data['songs']);
		$this->assertContainsOnlyInstancesOf(MobileSheetsSong::class, $data['songs']);
		$this->assertContainsOnlyInstancesOf(MobileSheetsComposer::class, $data['composers']);
		$this->assertContainsOnlyInstancesOf(MobileSheetsBook::class, $data['books']);
		$this->assertContainsOnlyInstancesOf(MobileSheetsCollection::class, $data['collections']);
		$this->assertContainsOnlyInstancesOf(MobileSheetsArtist::class, $data['artists']);
		$this->assertSame(['Bach', 'Beethoven'], $this->names($data['composers']));
		$this->assertSame(['Real Book 1'], $this->titles($data['books']));
		$this->assertSame(['Jazz Standards'], $this->names($data['collections']));
		$this->assertSame(['Coltrane'], $this->names($data['artists']));

		// Songs are ordered by title (Alpha, Zebra) and carry nested entities.
		$alpha = $data['songs'][0];
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
		$updatedSong = array_values(array_filter($data['songs'], fn(MobileSheetsSong $s) => $s->id === 2))[0];

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
		$created = array_values(array_filter($data['songs'], fn(MobileSheetsSong $s) => $s->id === $songId))[0];

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
		$created = array_values(array_filter($data['songs'], fn(MobileSheetsSong $s) => $s->id === $songId))[0];

		$this->assertSame('Bare Song', $created->title);
		$this->assertSame([], $created->composers);
		$this->assertSame([], $created->books);
		$this->assertSame([], $created->collections);
		$this->assertSame([], $created->artists);
	}

	public function testSetSongFile(): void {
		$mapper = $this->mapper();
		$mapper->setSongFile($this->databasePath, 2, '/remote.php/dav/files/admin/Noten/AlphaNew.pdf');

		$data = $mapper->findLibraryData($this->databasePath);
		$updatedSong = array_values(array_filter($data['songs'], fn(MobileSheetsSong $s) => $s->id === 2))[0];

		$this->assertCount(2, $updatedSong->files);
		$this->assertContainsOnlyInstancesOf(MobileSheetsFile::class, $updatedSong->files);
	}

	public function testRemoveSongFile(): void {
		$mapper = $this->mapper();
		$deletedPath = $mapper->removeSongFile($this->databasePath, 2, 2);

		$this->assertSame('/remote.php/dav/files/admin/Noten/Alpha.pdf', $deletedPath);

		$data = $mapper->findLibraryData($this->databasePath);
		$updatedSong = array_values(array_filter($data['songs'], fn(MobileSheetsSong $s) => $s->id === 2))[0];

		$this->assertCount(0, $updatedSong->files);
	}

	public function testCreateEntities(): void {
		$mapper = $this->mapper();
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

	/**
	 * @param list<MobileSheetsComposer|MobileSheetsCollection|MobileSheetsArtist> $entities
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