<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\SheetMusic\Db\DuplicateEntityConflict;
use OCA\SheetMusic\Db\DuplicateSongEntityAssignment;
use OCA\SheetMusic\Db\LibraryEntityMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LibraryEntityMapperTest extends TestCase {
	private string $databasePath;
	private LibraryEntityMapper $entityMapper;

	protected function setUp(): void {
		$databasePath = tempnam(sys_get_temp_dir(), 'sheetmusic-entity-test-');
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

		$this->entityMapper = new LibraryEntityMapper(new NullLogger());
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

	public function testFindsAndRemovesDuplicateSongEntityAssignmentsAcrossEntityTables(): void {
		$relations = [
			'ComposerSongs' => 'ComposerId',
			'BookSongs' => 'BookId',
			'CollectionSong' => 'CollectionId',
			'ArtistsSongs' => 'ArtistId',
			'GenresSongs' => 'GenreId',
			'KeySongs' => 'KeyId',
			'CustomGroupSongs' => 'GroupId',
			'YearsSongs' => 'YearId',
			'SignatureSongs' => 'SignatureId',
			'SourceTypeSongs' => 'SourceTypeId',
		];
		$database = new \PDO('sqlite:' . $this->databasePath);
		foreach ($relations as $table => $entityColumn) {
			$insert = $database->prepare(sprintf('INSERT INTO "%s" ("%s", SongId) VALUES (:entityId, :songId)', $table, $entityColumn));
			$insert->execute([':entityId' => 5, ':songId' => 10]);
			$insert->execute([':entityId' => 5, ':songId' => 10]);
			$insert->execute([':entityId' => 6, ':songId' => 10]);
			$insert->execute([':entityId' => 5, ':songId' => 11]);
			$insert->execute([':entityId' => 5, ':songId' => 11]);
		}

		$duplicates = $this->entityMapper->findDuplicateSongEntityAssignments($this->databasePath);
		self::assertCount(count($relations) * 2, $duplicates);
		$songTenDuplicates = array_values(array_filter(
			$duplicates,
			static fn (DuplicateSongEntityAssignment $duplicate): bool => $duplicate->songId === 10,
		));
		self::assertCount(count($relations), $songTenDuplicates);
		self::assertSame(2, $songTenDuplicates[0]->count);

		self::assertSame(count($relations), $this->entityMapper->removeDuplicateSongEntityAssignments($this->databasePath, 10));

		$remaining = $this->entityMapper->findDuplicateSongEntityAssignments($this->databasePath);
		self::assertCount(count($relations), $remaining);
		self::assertSame(
			[11],
			array_values(array_unique(array_column($remaining, 'songId'))),
		);
		foreach ($relations as $table => $entityColumn) {
			$statement = $database->prepare(sprintf(
				'SELECT "%s", COUNT(*) AS AssignmentCount FROM "%s" WHERE SongId = 10 GROUP BY "%s" ORDER BY "%s"',
				$entityColumn,
				$table,
				$entityColumn,
				$entityColumn,
			));
			$statement->execute();
			self::assertSame(
				[['entityId' => 5, 'count' => 1], ['entityId' => 6, 'count' => 1]],
				array_map(
					static fn (array $row): array => ['entityId' => (int)$row[$entityColumn], 'count' => (int)$row['AssignmentCount']],
					$statement->fetchAll(\PDO::FETCH_ASSOC),
				),
				sprintf('Unexpected remaining assignments in %s', $table),
			);
		}
	}

	public function testFindsAndMergesDuplicateEntitiesAcrossAllSupportedTypes(): void {
		$entities = [
			'composers' => ['Composer', 'Name', 'ComposerSongs', 'ComposerId', 'Bach'],
			'books' => ['Books', 'Title', 'BookSongs', 'BookId', 'Sonatas'],
			'collections' => ['Collections', 'Name', 'CollectionSong', 'CollectionId', 'Jazz'],
			'artists' => ['Artists', 'Name', 'ArtistsSongs', 'ArtistId', 'Miles Davis'],
			'genres' => ['Genres', 'Type', 'GenresSongs', 'GenreId', 'Jazz'],
			'keys' => ['Keys', 'Name', 'KeySongs', 'KeyId', 'C'],
			'customgroups' => ['CustomGroup', 'Name', 'CustomGroupSongs', 'GroupId', 'Favorites'],
			'years' => ['Years', 'Year', 'YearsSongs', 'YearId', '1960'],
			'signatures' => ['Signature', 'Name', 'SignatureSongs', 'SignatureId', '4/4'],
			'sourcetypes' => ['SourceTypes', 'Type', 'SourceTypeSongs', 'SourceTypeId', 'Score'],
		];
		$database = new \PDO('sqlite:' . $this->databasePath);
		foreach ($entities as $entityType => [$entityTable, $labelColumn, $linkTable, $linkColumn, $label]) {
			$insertEntity = $database->prepare(sprintf(
				'INSERT INTO "%s" (Id, "%s") VALUES (:id, :name)',
				$entityTable,
				$labelColumn,
			));
			$insertEntity->execute([':id' => 2, ':name' => $label]);
			$insertEntity->execute([':id' => 9, ':name' => ' ' . mb_strtolower($label, 'UTF-8') . ' ']);
			$insertEntity->execute([':id' => 15, ':name' => $label . ' distinct']);

			$insertLink = $database->prepare(sprintf(
				'INSERT INTO "%s" ("%s", SongId) VALUES (:entityId, :songId)',
				$linkTable,
				$linkColumn,
			));
			$insertLink->execute([':entityId' => 2, ':songId' => 100]);
			$insertLink->execute([':entityId' => 9, ':songId' => 100]);
			$insertLink->execute([':entityId' => 9, ':songId' => 101]);
		}

		$duplicates = $this->entityMapper->findDuplicateEntities($this->databasePath);
		self::assertCount(count($entities), $duplicates);
		foreach ($duplicates as $duplicate) {
			self::assertSame(2, $duplicate->keepId);
			self::assertSame([9], $duplicate->duplicateIds);
		}

		foreach ($duplicates as $duplicate) {
			self::assertSame(
				1,
				$this->entityMapper->mergeDuplicateEntities(
					$this->databasePath,
					$duplicate->entityType,
					$duplicate->keepId,
					$duplicate->duplicateIds,
				),
				sprintf('Expected one duplicate entity to be removed for %s', $duplicate->entityType),
			);
		}

		self::assertSame([], $this->entityMapper->findDuplicateEntities($this->databasePath));
		foreach ($entities as $entityType => [$entityTable, $labelColumn, $linkTable, $linkColumn, $label]) {
			$entityCount = (int)$database->query(sprintf('SELECT COUNT(*) FROM "%s"', $entityTable))->fetchColumn();
			self::assertSame(2, $entityCount, sprintf('Expected one canonical %s entity and one distinct entity', $entityType));
			$distinctEntityId = (int)$database->query(sprintf(
				'SELECT Id FROM "%s" WHERE "%s" = %s',
				$entityTable,
				$labelColumn,
				$database->quote($label . ' distinct'),
			))->fetchColumn();
			self::assertSame(15, $distinctEntityId, sprintf('Expected distinct %s entity to be retained', $entityType));

			$statement = $database->prepare(sprintf(
				'SELECT SongId, "%s" AS EntityId, COUNT(*) AS AssignmentCount FROM "%s" '
				. 'GROUP BY SongId, "%s" ORDER BY SongId',
				$linkColumn,
				$linkTable,
				$linkColumn,
			));
			$statement->execute();
			self::assertSame(
				[
					['SongId' => 100, 'EntityId' => 2, 'AssignmentCount' => 1],
					['SongId' => 101, 'EntityId' => 2, 'AssignmentCount' => 1],
				],
				array_map(
					static fn (array $row): array => [
						'SongId' => (int)$row['SongId'],
						'EntityId' => (int)$row['EntityId'],
						'AssignmentCount' => (int)$row['AssignmentCount'],
					],
					$statement->fetchAll(\PDO::FETCH_ASSOC),
				),
				sprintf('Expected song assignments to be redirected to canonical %s', $entityType),
			);
		}
	}

	public function testFindsDuplicateKeysInSingularKeyTableUsingNameColumn(): void {
		$database = new \PDO('sqlite:' . $this->databasePath);
		$database->exec('DROP TABLE Keys');
		$database->exec('CREATE TABLE "Key"(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER, LastModified INTEGER)');
		$database->exec("INSERT INTO \"Key\" (Id, Name) VALUES (35, 'C'), (36, 'F'), (37, 'C'), (38, 'F')");

		self::assertEquals([
			new DuplicateEntityConflict(
				entityType: 'keys',
				name: 'C',
				keepId: 35,
				duplicateIds: [37],
			),
			new DuplicateEntityConflict(
				entityType: 'keys',
				name: 'F',
				keepId: 36,
				duplicateIds: [38],
			),
		], $this->entityMapper->findDuplicateEntities($this->databasePath));
	}

	public function testMergeDuplicateEntitiesAllowsKeepingHigherIdAndMergesSongLinks(): void {
		$database = new \PDO('sqlite:' . $this->databasePath);
		$database->exec("INSERT INTO Composer (Id, Name) VALUES (5, 'J. S. Bach'), (50, 'Johann Sebastian Bach')");
		$database->exec('INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (5, 10), (50, 10), (5, 11)');

		$affectedSongIds = $this->entityMapper->getSongIdsForEntity($this->databasePath, 'composers', 5);
		self::assertSame([10, 11], $affectedSongIds);

		$merged = $this->entityMapper->mergeDuplicateEntities($this->databasePath, 'composers', 50, [5]);
		self::assertSame(1, $merged);

		// Duplicate record (5) should be deleted
		$remCount = (int)$database->query('SELECT COUNT(*) FROM Composer WHERE Id = 5')->fetchColumn();
		self::assertSame(0, $remCount);

		// Canonical record (50) should remain
		$canonicalCount = (int)$database->query('SELECT COUNT(*) FROM Composer WHERE Id = 50')->fetchColumn();
		self::assertSame(1, $canonicalCount);

		// Songs 10 and 11 should be linked to ID 50, with no duplicates
		$statement = $database->prepare('SELECT SongId, COUNT(*) AS Cnt FROM ComposerSongs WHERE ComposerId = 50 GROUP BY SongId ORDER BY SongId');
		$statement->execute();
		$links = $statement->fetchAll(\PDO::FETCH_ASSOC);
		self::assertSame([
			['SongId' => 10, 'Cnt' => 1],
			['SongId' => 11, 'Cnt' => 1],
		], array_map(static fn (array $r) => ['SongId' => (int)$r['SongId'], 'Cnt' => (int)$r['Cnt']], $links));
	}

	public function testSchemaCacheDoesNotLeakBetweenLibraryDatabases(): void {
		$otherDatabasePath = tempnam(sys_get_temp_dir(), 'sheetmusic-entity-schema-test-');
		self::assertNotFalse($otherDatabasePath);
		try {
			$otherDatabase = new \PDO('sqlite:' . $otherDatabasePath);
			$otherDatabase->exec('CREATE TABLE "Key"(Id INTEGER PRIMARY KEY, Name VARCHAR(255))');
			$otherDatabase->exec("INSERT INTO \"Key\" (Id, Name) VALUES (35, 'C'), (37, 'C')");

			self::assertSame([], $this->entityMapper->findDuplicateEntities($this->databasePath));
			self::assertEquals([
				new DuplicateEntityConflict(
					entityType: 'keys',
					name: 'C',
					keepId: 35,
					duplicateIds: [37],
				),
			], $this->entityMapper->findDuplicateEntities($otherDatabasePath));
		} finally {
			unlink($otherDatabasePath);
		}
	}
}
