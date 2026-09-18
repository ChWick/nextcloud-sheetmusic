<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

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

		$database->exec("INSERT INTO Songs (Id, Title, SortTitle) VALUES (1, 'Zebra', 'Zebra'), (2, 'Alpha', 'Alpha')");
		$database->exec("INSERT INTO Files (SongId, Path) VALUES (1, '/remote.php/dav/files/admin/Noten/Zebra.pdf'), (2, '/remote.php/dav/files/admin/Noten/Alpha.pdf')");

		$database->exec("INSERT INTO Composer (Id, Name) VALUES (10, 'Beethoven'), (20, 'Bach')");
		$database->exec("INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (10, 1), (20, 2)");

		$database->exec("INSERT INTO Books (Id, Title) VALUES (100, 'Real Book 1')");
		$database->exec("INSERT INTO BookSongs (BookId, SongId) VALUES (100, 2)");

		$database->exec("INSERT INTO Collections (Id, Name) VALUES (1000, 'Jazz Standards')");
		$database->exec("INSERT INTO CollectionSong (CollectionId, SongId) VALUES (1000, 1), (1000, 2)");
	}

	protected function tearDown(): void {
		unlink($this->databasePath);
	}

	public function testFindSongsReturnsSortedSongsWithAssociations(): void {
		$this->assertSame([
			[
				'id' => 2,
				'title' => 'Alpha',
				'filePath' => '/remote.php/dav/files/admin/Noten/Alpha.pdf',
				'composerIds' => [20],
				'bookIds' => [100],
				'collectionIds' => [1000],
			],
			[
				'id' => 1,
				'title' => 'Zebra',
				'filePath' => '/remote.php/dav/files/admin/Noten/Zebra.pdf',
				'composerIds' => [10],
				'bookIds' => [],
				'collectionIds' => [1000],
			],
		], (new SongMapper())->findSongs($this->databasePath));
	}

	public function testFindLibraryDataReturnsAllEntities(): void {
		$data = (new SongMapper())->findLibraryData($this->databasePath);

		$this->assertCount(2, $data['songs']);
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
}