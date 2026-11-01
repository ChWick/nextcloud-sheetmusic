<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFile;
use OCP\Files\File;
use PDO;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class MobileSheetsSqliteExporterTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private string $tempDbPath;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$this->tempDbPath = tempnam(sys_get_temp_dir(), 'sm_export_') . '.db';
	}

	protected function tearDown(): void {
		if (file_exists($this->tempDbPath)) {
			@unlink($this->tempDbPath);
		}
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testExportToFileCreatesCompleteMobileSheetsDatabase(): void {
		$library = $this->createTestLibrary('/NotenCompleteExport', 'admin');

		$song = new InternalSong(
			id: null,
			uuid: 'song-uuid-1',
			libraryId: (int)$library->id,
			title: 'Moonlight Sonata',
			sortTitle: 'Moonlight Sonata',
			rating: 5,
			difficulty: 3,
			duration: 360,
			keywords: 'piano, classical',
			notes: 'Play with expression',
			opus: 'Op. 27 No. 2',
			catalogNumber: 'WoO 47',
			songNumber: '14',
			clientId: 101,
			createdAt: 1000,
			updatedAt: 2000,
		);
		$createdSong = $this->songMapper->insert($song);

		$this->attributeMapper->setAttribute((int)$createdSong->id, 'custom', 'Solo Piano');
		$this->attributeMapper->setAttribute((int)$createdSong->id, 'custom2', 'Adagio');

		$composer = $this->entityMapper->insert(new InternalEntity(
			id: null,
			uuid: 'ent-uuid-composer',
			libraryId: (int)$library->id,
			type: 'composer',
			name: 'Ludwig van Beethoven',
			sortBy: 1,
			ascending: 1,
			clientId: 201,
			createdAt: 1000,
			updatedAt: 2000,
		));

		$instrument = $this->entityMapper->insert(new InternalEntity(
			id: null,
			uuid: 'ent-uuid-instrument',
			libraryId: (int)$library->id,
			type: 'instrument',
			name: 'Piano',
			sortBy: 1,
			ascending: 1,
			clientId: 202,
			createdAt: 1000,
			updatedAt: 2000,
		));

		$this->entityMapper->linkSongEntity((int)$createdSong->id, (int)$composer->id);
		$this->entityMapper->linkSongEntity((int)$createdSong->id, (int)$instrument->id);

		$file = $this->attachTestFileToSong($createdSong, 'Moonlight.pdf', 301);
		$file->pageOrder = '1-5';
		$file->fileHash = 987654321;
		$file->pageCount = 5;
		$this->songFileMapper->update($file);

		$this->miscMapper->saveMiscSchema((int)$library->id, 'mobilesheets', 'Setlists', 'CREATE TABLE Setlists (Id INTEGER PRIMARY KEY, Name VARCHAR(255))');
		$this->miscMapper->saveMiscData((int)$library->id, 'mobilesheets', 'Setlists', '1', [
			'Id' => 1,
			'Name' => 'Autumn Recital',
		]);

		$this->exporter->exportToFile($library, $this->tempDbPath);

		self::assertFileExists($this->tempDbPath);

		$db = new PDO('sqlite:' . $this->tempDbPath);

		// Verify Songs
		$songRows = $db->query('SELECT * FROM Songs')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(1, $songRows);
		self::assertSame(101, (int)$songRows[0]['Id']);
		self::assertSame('Moonlight Sonata', $songRows[0]['Title']);
		self::assertSame(5, (int)$songRows[0]['Stars']);
		self::assertSame(3, (int)$songRows[0]['Difficulty']);
		self::assertSame(360, (int)$songRows[0]['Duration']);
		self::assertSame('Solo Piano', $songRows[0]['Custom']);
		self::assertSame('Adagio', $songRows[0]['Custom2']);
		self::assertSame(2000000, (int)$songRows[0]['LastModified']);

		// Verify SongNotes
		$noteRows = $db->query('SELECT * FROM SongNotes')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(1, $noteRows);
		self::assertSame(101, (int)$noteRows[0]['SongId']);
		self::assertSame('Play with expression', $noteRows[0]['Notes'] ?? $noteRows[0]['Note']);

		// Verify Composer
		$composerRows = $db->query('SELECT * FROM Composer')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(1, $composerRows);
		self::assertSame(201, (int)$composerRows[0]['Id']);
		self::assertSame('Ludwig van Beethoven', $composerRows[0]['Name']);

		// Verify CustomGroup (for instrument 'Piano')
		$customRows = $db->query('SELECT * FROM CustomGroup')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(1, $customRows);
		self::assertSame(202, (int)$customRows[0]['Id']);
		self::assertSame('Piano', $customRows[0]['Name']);

		// Verify Links
		$compLinkRows = $db->query('SELECT * FROM ComposerSongs')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(1, $compLinkRows);
		self::assertSame(201, (int)$compLinkRows[0]['ComposerId']);
		self::assertSame(101, (int)$compLinkRows[0]['SongId']);

		$customLinkRows = $db->query('SELECT * FROM CustomGroupSongs')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(1, $customLinkRows);
		self::assertSame(202, (int)$customLinkRows[0]['GroupId']);
		self::assertSame(101, (int)$customLinkRows[0]['SongId']);

		// Verify Files
		$fileRows = $db->query('SELECT * FROM Files')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(1, $fileRows);
		self::assertSame(301, (int)$fileRows[0]['Id']);
		self::assertSame(101, (int)$fileRows[0]['SongId']);
		self::assertStringContainsString('Moonlight.pdf', $fileRows[0]['Path']);
		self::assertSame('1-5', $fileRows[0]['PageOrder']);
		self::assertSame(987654321, (int)$fileRows[0]['FileHash']);

		// Verify _nc_sync_metadata
		$metaRows = $db->query('SELECT * FROM _nc_sync_metadata ORDER BY entity_type ASC')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(4, $metaRows); // entity:composer, entity:instrument, file, song

		$songMeta = array_values(array_filter($metaRows, fn ($r) => $r['entity_type'] === 'song'))[0];
		self::assertSame('song-uuid-1', $songMeta['uuid']);
		self::assertSame(101, (int)$songMeta['client_id']);
		$extra = json_decode($songMeta['extra_json'], true);
		self::assertSame('Op. 27 No. 2', $extra['opus']);
		self::assertSame('WoO 47', $extra['catalog_number']);
		self::assertSame('14', $extra['song_number']);

		// Verify Misc table Setlists
		$setlistRows = $db->query('SELECT * FROM Setlists')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(1, $setlistRows);
		self::assertSame(1, (int)$setlistRows[0]['Id']);
		self::assertSame('Autumn Recital', $setlistRows[0]['Name']);
	}

	public function testGenerateHashcodes(): void {
		$library = $this->createTestLibrary('/HashcodesRealLib', 'admin');

		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'song-hc',
			libraryId: (int)$library->id,
			title: 'Song',
			clientId: 10,
		));

		$f1 = $this->attachTestFileToSong($song, 'f1.pdf', 30);
		$f1->fileHash = 111111;
		$f1->fileSize = 50000;
		$f1->updatedAt = 1000;
		$this->songFileMapper->update($f1);

		$f2 = $this->attachTestFileToSong($song, 'f2.pdf', 31);
		$f2->fileHash = 222222;
		$f2->fileSize = 80000;
		$f2->updatedAt = 2000;
		$this->songFileMapper->update($f2);

		$hashcodes = $this->exporter->generateHashcodes($library);
		$lines = explode("\n", trim($hashcodes));

		self::assertCount(8, $lines);
		self::assertStringContainsString('f1.pdf', $lines[0]);
		self::assertSame('111111', $lines[1]);
		self::assertSame('1000000', $lines[2]);
		self::assertSame('50000', $lines[3]);
		self::assertStringContainsString('f2.pdf', $lines[4]);
		self::assertSame('222222', $lines[5]);
		self::assertSame('2000000', $lines[6]);
		self::assertSame('80000', $lines[7]);
	}

	public function testMiscSchemaOverridesDefaultTableDefinitionAndFiltersExtraColumns(): void {
		$library = $this->createTestLibrary('/MiscSchemaRealLib', 'admin');

		// MobileSheets modern MetronomeSettings schema containing Sig1, Sig2 etc.
		$this->miscMapper->saveMiscSchema((int)$library->id, 'mobilesheets', 'MetronomeSettings', 'CREATE TABLE MetronomeSettings (Id INTEGER PRIMARY KEY, SongId INTEGER, Bpm INTEGER, Sig1 INTEGER, Sig2 INTEGER)');
		$this->miscMapper->saveMiscData((int)$library->id, 'mobilesheets', 'MetronomeSettings', '1', [
			'Id' => 1, 'SongId' => 10, 'Bpm' => 120, 'Sig1' => 4, 'Sig2' => 4, 'NonExistentColumn' => 'ShouldBeIgnored',
		]);

		$this->exporter->exportToFile($library, $this->tempDbPath);

		self::assertFileExists($this->tempDbPath);

		$db = new PDO('sqlite:' . $this->tempDbPath);
		$rows = $db->query('SELECT * FROM MetronomeSettings')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(1, $rows);
		self::assertSame(1, (int)$rows[0]['Id']);
		self::assertSame(10, (int)$rows[0]['SongId']);
		self::assertSame(120, (int)$rows[0]['Bpm']);
		self::assertSame(4, (int)$rows[0]['Sig1']);
		self::assertSame(4, (int)$rows[0]['Sig2']);
	}

	public function testGenerateHashcodesFromSongFilesAndExcludesDeletedSongs(): void {
		$library = $this->createTestLibrary('/ExcludeDeletedHashLib', 'admin');

		$activeSong = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-active',
			libraryId: (int)$library->id,
			title: 'Active',
			clientId: 10,
		));

		$deletedSong = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-deleted',
			libraryId: (int)$library->id,
			title: 'Deleted',
			clientId: 20,
			deletedAt: 12345,
		));

		$activeFile = $this->attachTestFileToSong($activeSong, 'active.pdf', 1);
		$activeFile->fileHash = 9999;
		$activeFile->fileSize = 45000;
		$activeFile->updatedAt = 1000;
		$this->songFileMapper->update($activeFile);

		$deletedFile = $this->attachTestFileToSong($deletedSong, 'deleted.pdf', 2);
		$deletedFile->fileHash = 8888;
		$deletedFile->fileSize = 30000;
		$deletedFile->updatedAt = 1000;
		$this->songFileMapper->update($deletedFile);

		$hashcodes = $this->exporter->generateHashcodes($library);
		$lines = explode("\n", trim($hashcodes));

		self::assertCount(4, $lines);
		self::assertStringContainsString('active.pdf', $lines[0]);
		self::assertSame('9999', $lines[1]);
		self::assertSame('1000000', $lines[2]);
		self::assertSame('45000', $lines[3]);
	}

	public function testExportToFilePurgesFilesWithNoConnectedSong(): void {
		$library = $this->createTestLibrary('/PurgeOrphanLib', 'admin');

		$activeSong = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-act',
			libraryId: (int)$library->id,
			title: 'Active',
			clientId: 10,
		));

		$activeFile = $this->attachTestFileToSong($activeSong, 'active.pdf', 1);
		$activeFile->fileHash = 9999;
		$activeFile->fileSize = 45000;
		$activeFile->updatedAt = 1000;
		$this->songFileMapper->update($activeFile);

		$orphanFile = new InternalSongFile(
			id: null,
			uuid: 'f-orphan',
			songId: 999999,
			fileId: $activeFile->fileId,
			clientId: 2,
			pageOrder: '1',
			fileSize: 30000,
			fileHash: 8888,
			updatedAt: 1000,
		);
		$this->songFileMapper->insert($orphanFile);

		$this->exporter->exportToFile($library, $this->tempDbPath);

		self::assertFileExists($this->tempDbPath);
		$db = new PDO('sqlite:' . $this->tempDbPath);
		$fileRows = $db->query('SELECT Id, SongId, Path FROM Files')->fetchAll(PDO::FETCH_ASSOC);

		// File 1 with SongId 10 exists, File 2 with orphan SongId 999999 must have been skipped
		self::assertCount(1, $fileRows);
		self::assertSame(1, (int)$fileRows[0]['Id']);
		self::assertSame(10, (int)$fileRows[0]['SongId']);
	}

	public function testExportToFileDoesNotAddSongFilesMissingOnDisk(): void {
		$library = $this->createTestLibrary('/MissingDiskLib', 'admin');

		$activeSong = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-active-nodisk',
			libraryId: (int)$library->id,
			title: 'Active',
			clientId: 10,
		));

		// Missing file on disk (fileId 9999999 does not exist in Nextcloud filecache)
		$missingFile = new InternalSongFile(
			id: null,
			uuid: 'f-missing',
			songId: (int)$activeSong->id,
			fileId: 9999999,
			clientId: 1,
			pageOrder: '1',
			type: 1,
		);
		$this->songFileMapper->insert($missingFile);

		$this->exporter->exportToFile($library, $this->tempDbPath);

		self::assertFileExists($this->tempDbPath);
		$db = new PDO('sqlite:' . $this->tempDbPath);
		$fileRows = $db->query('SELECT Id, SongId, Path FROM Files')->fetchAll(PDO::FETCH_ASSOC);

		// The missing file must NOT have been added to the Files table
		self::assertCount(0, $fileRows);

		// The song whose only file was missing on disk must ALSO be excluded from Songs
		$songRows = $db->query('SELECT * FROM Songs')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(0, $songRows);
	}

	public function testExportToFileExcludesSongsWithoutFiles(): void {
		$library = $this->createTestLibrary('/ExcludesNoFileLib', 'admin');

		$songWithFile = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-hasfile',
			libraryId: (int)$library->id,
			title: 'Has File',
			clientId: 10,
		));
		$this->attachTestFileToSong($songWithFile, 'song1.pdf', 100);

		$songWithoutFile = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-nofile',
			libraryId: (int)$library->id,
			title: 'No File',
			clientId: 20,
		));

		$this->exporter->exportToFile($library, $this->tempDbPath);

		self::assertFileExists($this->tempDbPath);
		$db = new PDO('sqlite:' . $this->tempDbPath);
		$songRows = $db->query('SELECT Id, Title FROM Songs')->fetchAll(PDO::FETCH_ASSOC);

		// Only the song with a valid file should be exported
		self::assertCount(1, $songRows);
		self::assertSame(10, (int)$songRows[0]['Id']);
		self::assertSame('Has File', $songRows[0]['Title']);
	}

	public function testExportMovementsAsChildSongsAndBookmarksOptionB(): void {
		$library = $this->createTestLibrary('/MovementsRealLib', 'admin');

		$song = new InternalSong(
			id: null,
			uuid: 'sonata-uuid-1',
			libraryId: (int)$library->id,
			title: 'Moonlight Sonata',
			clientId: 10,
			createdAt: 2000,
			updatedAt: 2000,
		);
		$createdSong = $this->songMapper->insert($song);
		$this->attachTestFileToSong($createdSong, 'Sonata.pdf', 100);

		$this->bookmarkMapper->setBookmarksForSong((int)$createdSong->id, [
			[
				'name' => 'I. Adagio sostenuto',
				'pageNum' => 1,
				'endPage' => 4,
				'position' => 0,
			],
			[
				'name' => 'II. Allegretto',
				'pageNum' => 5,
				'endPage' => 7,
				'position' => 1,
			],
			[
				'name' => 'III. Presto agitato',
				'pageNum' => 8,
				'endPage' => 15,
				'position' => 2,
			],
		]);

		$this->exporter->exportToFile($library, $this->tempDbPath);

		self::assertFileExists($this->tempDbPath);
		$db = new PDO('sqlite:' . $this->tempDbPath);

		// 1. Master song + 3 child movement songs exported into Songs table
		$songRows = $db->query('SELECT Id, Title, SortTitle FROM Songs ORDER BY Id ASC')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(4, $songRows);
		self::assertSame('Moonlight Sonata', $songRows[0]['Title']);
		self::assertSame('Moonlight Sonata - I. Adagio sostenuto', $songRows[1]['Title']);
		self::assertSame('Moonlight Sonata - II. Allegretto', $songRows[2]['Title']);
		self::assertSame('Moonlight Sonata - III. Presto agitato', $songRows[3]['Title']);

		// 2. Bookmarks exported on master song
		$bmRows = $db->query('SELECT SongId, Name, PageNum FROM Bookmarks ORDER BY PageNum ASC')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(3, $bmRows);
		self::assertSame(10, (int)$bmRows[0]['SongId']);
		self::assertSame('I. Adagio sostenuto', $bmRows[0]['Name']);
		self::assertSame(1, (int)$bmRows[0]['PageNum']);

		// 3. Child files pointing to parent PDF with pageOrder
		$fileRows = $db->query('SELECT SongId, PageOrder FROM Files ORDER BY Id ASC')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(4, $fileRows);
		self::assertSame('1-4', $fileRows[1]['PageOrder']);
		self::assertSame('5-7', $fileRows[2]['PageOrder']);
		self::assertSame('8-15', $fileRows[3]['PageOrder']);

		// 4. _nc_sync_metadata tracks child movements
		$metaRows = $db->query("SELECT entity_type, client_id, extra_json FROM _nc_sync_metadata WHERE entity_type = 'song_movement'")->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(3, $metaRows);
	}

	public function testExportMovementsWithExistingCollectionsAvoidsIdCollision(): void {
		$library = $this->createTestLibrary('/CollisionLib', 'admin');

		// Existing user collection with clientId = 2
		$existingCollection = new InternalEntity(
			id: null,
			uuid: 'coll-uuid-existing',
			libraryId: (int)$library->id,
			name: 'Existing Collection',
			type: 'collection',
			clientId: 2,
			createdAt: 2000,
			updatedAt: 2000,
		);
		$this->entityMapper->insert($existingCollection);

		$song = new InternalSong(
			id: null,
			uuid: 'sonata-uuid-123',
			libraryId: (int)$library->id,
			title: 'Moonlight Sonata',
			clientId: 1,
			createdAt: 2000,
			updatedAt: 2000,
		);
		$createdSong = $this->songMapper->insert($song);
		$this->attachTestFileToSong($createdSong, 'Sonata.pdf', 1);

		$this->bookmarkMapper->setBookmarksForSong((int)$createdSong->id, [
			[
				'name' => 'I. Adagio sostenuto',
				'pageNum' => 1,
				'endPage' => 4,
				'position' => 0,
			],
		]);

		$this->exporter->exportToFile($library, $this->tempDbPath);

		self::assertFileExists($this->tempDbPath);
		$db = new PDO('sqlite:' . $this->tempDbPath);

		// Both the existing collection and the new movement collection exist with distinct IDs
		$collRows = $db->query('SELECT Id, Name FROM Collections ORDER BY Id ASC')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(2, $collRows);
		self::assertSame(2, (int)$collRows[0]['Id']);
		self::assertSame('Existing Collection', $collRows[0]['Name']);
		self::assertSame(3, (int)$collRows[1]['Id']);
		self::assertSame('Moonlight Sonata', $collRows[1]['Name']);
	}
}
