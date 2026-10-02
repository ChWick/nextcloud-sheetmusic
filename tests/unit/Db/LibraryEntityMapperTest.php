<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\MobileSheetsManager\Db\LibraryEntityMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LibraryEntityMapperTest extends TestCase {
	private string $databasePath;
	private LibraryEntityMapper $entityMapper;

	protected function setUp(): void {
		$databasePath = tempnam(sys_get_temp_dir(), 'mobilesheetsmanager-entity-test-');
		self::assertNotFalse($databasePath);
		$this->databasePath = $databasePath;

		$database = new \PDO('sqlite:' . $this->databasePath);
		$database->exec('CREATE TABLE Composer(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec('CREATE TABLE ComposerSongs(Id INTEGER PRIMARY KEY, ComposerId INTEGER, SongId INTEGER)');
		$database->exec('CREATE TABLE Books(Id INTEGER PRIMARY KEY, Title VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec('CREATE TABLE BookSongs(Id INTEGER PRIMARY KEY, BookId INTEGER, SongId INTEGER)');
		$database->exec('CREATE TABLE Collections(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec('CREATE TABLE CollectionSong(Id INTEGER PRIMARY KEY, CollectionId INTEGER, SongId INTEGER)');
		$database->exec('CREATE TABLE Artists(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec('CREATE TABLE ArtistsSongs(Id INTEGER PRIMARY KEY, ArtistId INTEGER, SongId INTEGER)');
		$database->exec('CREATE TABLE Genres(Id INTEGER PRIMARY KEY, Type VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec('CREATE TABLE GenresSongs(Id INTEGER PRIMARY KEY, GenreId INTEGER, SongId INTEGER)');
		$database->exec('CREATE TABLE Keys(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec('CREATE TABLE KeySongs(Id INTEGER PRIMARY KEY, KeyId INTEGER, SongId INTEGER)');
		$database->exec('CREATE TABLE CustomGroup(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec('CREATE TABLE CustomGroupSongs(Id INTEGER PRIMARY KEY, GroupId INTEGER, SongId INTEGER)');
		$database->exec('CREATE TABLE Years(Id INTEGER PRIMARY KEY, Year INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec('CREATE TABLE YearsSongs(Id INTEGER PRIMARY KEY, YearId INTEGER, SongId INTEGER)');
		$database->exec('CREATE TABLE Signature(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec('CREATE TABLE SignatureSongs(Id INTEGER PRIMARY KEY, SignatureId INTEGER, SongId INTEGER)');
		$database->exec('CREATE TABLE SourceTypes(Id INTEGER PRIMARY KEY, Type VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec('CREATE TABLE SourceTypeSongs(Id INTEGER PRIMARY KEY, SourceTypeId INTEGER, SongId INTEGER)');

		$logger = $this->createMock(LoggerInterface::class);
		$this->entityMapper = new LibraryEntityMapper($logger);
	}

	protected function tearDown(): void {
		if (file_exists($this->databasePath)) {
			unlink($this->databasePath);
		}
	}

	public function testCreateAndDeleteEntities(): void {
		$composerId = $this->entityMapper->createComposer($this->databasePath, 'Mozart');
		self::assertGreaterThan(0, $composerId);

		$bookId = $this->entityMapper->createBook($this->databasePath, 'Sonatas');
		self::assertGreaterThan(0, $bookId);

		$collectionId = $this->entityMapper->createCollection($this->databasePath, 'Classics');
		self::assertGreaterThan(0, $collectionId);

		$artistId = $this->entityMapper->createArtist($this->databasePath, 'Orchestra');
		self::assertGreaterThan(0, $artistId);

		$genreId = $this->entityMapper->createGenre($this->databasePath, 'Classical');
		self::assertGreaterThan(0, $genreId);

		$keyId = $this->entityMapper->createKey($this->databasePath, 'G Major');
		self::assertGreaterThan(0, $keyId);

		$customGroupId = $this->entityMapper->createCustomGroup($this->databasePath, 'Group 1');
		self::assertGreaterThan(0, $customGroupId);

		$yearId = $this->entityMapper->createYear($this->databasePath, 1780);
		self::assertGreaterThan(0, $yearId);

		$signatureId = $this->entityMapper->createSignature($this->databasePath, '3/4');
		self::assertGreaterThan(0, $signatureId);

		$sourceTypeId = $this->entityMapper->createSourceType($this->databasePath, 'Score');
		self::assertGreaterThan(0, $sourceTypeId);

		// Delete entity
		$deleted = $this->entityMapper->deleteEntity($this->databasePath, 'composers', $composerId);
		self::assertTrue($deleted);

		$deleteNonExistent = $this->entityMapper->deleteEntity($this->databasePath, 'composers', 9999);
		self::assertFalse($deleteNonExistent);
	}
}
