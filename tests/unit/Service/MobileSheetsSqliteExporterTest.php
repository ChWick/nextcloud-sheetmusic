<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalMiscMapper;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongAttributeMapper;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Db\SongEntityLink;
use OCA\SheetMusic\Service\MobileSheetsPath;
use OCA\SheetMusic\Service\MobileSheetsPathResolver;
use OCA\SheetMusic\Service\MobileSheetsSqliteExporter;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\IUser;
use OCP\IUserManager;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MobileSheetsSqliteExporterTest extends TestCase {
	private string $tempDbPath;

	protected function setUp(): void {
		parent::setUp();
		$this->tempDbPath = tempnam(sys_get_temp_dir(), 'sm_export_') . '.db';
	}

	protected function tearDown(): void {
		if (file_exists($this->tempDbPath)) {
			@unlink($this->tempDbPath);
		}
		parent::tearDown();
	}

	public function testExportToFileCreatesCompleteMobileSheetsDatabase(): void {
		$songMapper = $this->createMock(InternalSongMapper::class);
		$entityMapper = $this->createMock(InternalEntityMapper::class);
		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$attributeMapper = $this->createMock(InternalSongAttributeMapper::class);
		$miscMapper = $this->createMock(InternalMiscMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$pathResolver = $this->createMock(MobileSheetsPathResolver::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$userManager = $this->createMock(IUserManager::class);
		$logger = new NullLogger();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userManager->method('get')->with('admin')->willReturn($user);

		$library = new InternalLibrary(
			id: 1,
			userId: 'admin',
			path: 'Noten',
		);

		// Songs
		$song = new InternalSong(
			id: 10,
			uuid: 'song-uuid-1',
			libraryId: 1,
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
		$songMapper->method('findAllForLibrary')->willReturn([$song]);

		// Dynamic attributes
		$attributeMapper->method('findAllForLibrary')->with(1)->willReturn([
			10 => [
				'custom' => 'Solo Piano',
				'custom2' => 'Adagio',
			],
		]);

		// Entities
		$composer = new InternalEntity(
			id: 20,
			uuid: 'ent-uuid-composer',
			libraryId: 1,
			type: 'composer',
			name: 'Ludwig van Beethoven',
			sortBy: 1,
			ascending: 1,
			clientId: 201,
			createdAt: 1000,
			updatedAt: 2000,
		);
		$instrument = new InternalEntity(
			id: 21,
			uuid: 'ent-uuid-instrument',
			libraryId: 1,
			type: 'instrument',
			name: 'Piano',
			sortBy: 1,
			ascending: 1,
			clientId: 202,
			createdAt: 1000,
			updatedAt: 2000,
		);
		$entityMapper->method('findAllForLibrary')->with(1, null, false)->willReturn([$composer, $instrument]);

		// Links
		$entityMapper->method('findAllSongEntityLinksForLibrary')->with(1)->willReturn([
			new SongEntityLink(linkId: 1, songId: 10, entityId: 20, type: 'composer'),
			new SongEntityLink(linkId: 2, songId: 10, entityId: 21, type: 'instrument'),
		]);

		// Files
		$file = new InternalSongFile(
			id: 30,
			uuid: 'file-uuid-1',
			songId: 10,
			fileId: 401,
			pageOrder: '1-5',
			pageCount: 5,
			fileSize: 102400,
			fileHash: 987654321,
			type: 1,
			clientId: 301,
			createdAt: 1000,
			updatedAt: 2000,
		);
		$songFileMapper->method('findAllForLibrary')->with(1, false)->willReturn([$file]);

		// File node resolution
		$node = $this->createMock(File::class);
		$node->method('getPath')->willReturn('/admin/files/Noten/Moonlight.pdf');

		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('getById')->with(401)->willReturn([$node]);
		$rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);

		$msPath = new MobileSheetsPath(
			'/admin/files/Noten/Moonlight.pdf',
			'/remote.php/dav/files/admin/Noten/Moonlight.pdf',
		);
		$pathResolver->method('fromInternal')->with('/admin/files/Noten/Moonlight.pdf', $user)->willReturn($msPath);

		// Misc schema & data
		$miscMapper->method('getMiscSchemas')->with(1, 'mobilesheets')->willReturn([
			'Setlists' => 'CREATE TABLE Setlists (Id INTEGER PRIMARY KEY, Name VARCHAR(255))',
		]);
		$miscMapper->method('getMiscDataForTable')->with(1, 'mobilesheets', 'Setlists', false)->willReturn([
			['Id' => 1, 'Name' => 'Autumn Recital'],
		]);

		$exporter = new MobileSheetsSqliteExporter(
			$songMapper,
			$entityMapper,
			$songFileMapper,
			$attributeMapper,
			$miscMapper,
			$libraryMapper,
			$pathResolver,
			$rootFolder,
			$userManager,
			$logger,
		);

		$exporter->exportToFile($library, $this->tempDbPath);

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
		self::assertSame('/remote.php/dav/files/admin/Noten/Moonlight.pdf', $fileRows[0]['Path']);
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
		$songMapper = $this->createMock(InternalSongMapper::class);
		$entityMapper = $this->createMock(InternalEntityMapper::class);
		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$attributeMapper = $this->createMock(InternalSongAttributeMapper::class);
		$miscMapper = $this->createMock(InternalMiscMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$pathResolver = $this->createMock(MobileSheetsPathResolver::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$userManager = $this->createMock(IUserManager::class);
		$logger = new NullLogger();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userManager->method('get')->with('admin')->willReturn($user);

		$library = new InternalLibrary(id: 1, userId: 'admin', path: 'Noten');

		$file1 = new InternalSongFile(
			id: 30,
			uuid: 'f-1',
			songId: 10,
			fileId: 401,
			fileSize: 50000,
			fileHash: 111111,
			updatedAt: 1000,
		);
		$file2 = new InternalSongFile(
			id: 31,
			uuid: 'f-2',
			songId: 10,
			fileId: 402,
			fileSize: 80000,
			fileHash: 222222,
			updatedAt: 2000,
		);
		$songFileMapper->method('findAllForLibrary')->with(1, false)->willReturn([$file1, $file2]);

		$node1 = $this->createMock(File::class);
		$node1->method('getPath')->willReturn('/admin/files/Noten/f1.pdf');
		$node2 = $this->createMock(File::class);
		$node2->method('getPath')->willReturn('/admin/files/Noten/f2.pdf');

		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('getById')->willReturnMap([
			[401, [$node1]],
			[402, [$node2]],
		]);
		$rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);

		$pathResolver->method('fromInternal')->willReturnMap([
			['/admin/files/Noten/f1.pdf', $user, new MobileSheetsPath('/admin/files/Noten/f1.pdf', '/remote.php/dav/files/admin/Noten/f1.pdf')],
			['/admin/files/Noten/f2.pdf', $user, new MobileSheetsPath('/admin/files/Noten/f2.pdf', '/remote.php/dav/files/admin/Noten/f2.pdf')],
		]);

		$exporter = new MobileSheetsSqliteExporter(
			$songMapper,
			$entityMapper,
			$songFileMapper,
			$attributeMapper,
			$miscMapper,
			$libraryMapper,
			$pathResolver,
			$rootFolder,
			$userManager,
			$logger,
		);

		$hashcodes = $exporter->generateHashcodes($library);

		$expected = implode("\n", [
			'/remote.php/dav/files/admin/Noten/f1.pdf',
			'111111',
			'1000000',
			'50000',
			'/remote.php/dav/files/admin/Noten/f2.pdf',
			'222222',
			'2000000',
			'80000',
		]);

		self::assertSame($expected, $hashcodes);
	}

	public function testMiscSchemaOverridesDefaultTableDefinitionAndFiltersExtraColumns(): void {
		$songMapper = $this->createMock(InternalSongMapper::class);
		$entityMapper = $this->createMock(InternalEntityMapper::class);
		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$attributeMapper = $this->createMock(InternalSongAttributeMapper::class);
		$miscMapper = $this->createMock(InternalMiscMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$pathResolver = $this->createMock(MobileSheetsPathResolver::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$userManager = $this->createMock(IUserManager::class);
		$logger = new NullLogger();

		$library = new InternalLibrary(id: 1, userId: 'admin', path: 'Noten');

		$songMapper->method('findAllForLibrary')->willReturn([]);
		$entityMapper->method('findAllForLibrary')->willReturn([]);
		$entityMapper->method('findAllSongEntityLinksForLibrary')->willReturn([]);
		$songFileMapper->method('findAllForLibrary')->willReturn([]);
		$attributeMapper->method('findAllForLibrary')->willReturn([]);

		// MobileSheets modern MetronomeSettings schema containing Sig1, Sig2 etc.
		$miscMapper->method('getMiscSchemas')->with(1, 'mobilesheets')->willReturn([
			'MetronomeSettings' => 'CREATE TABLE MetronomeSettings (Id INTEGER PRIMARY KEY, SongId INTEGER, Bpm INTEGER, Sig1 INTEGER, Sig2 INTEGER)',
		]);
		$miscMapper->method('getMiscDataForTable')->with(1, 'mobilesheets', 'MetronomeSettings', false)->willReturn([
			['Id' => 1, 'SongId' => 10, 'Bpm' => 120, 'Sig1' => 4, 'Sig2' => 4, 'NonExistentColumn' => 'ShouldBeIgnored'],
		]);

		$exporter = new MobileSheetsSqliteExporter(
			$songMapper,
			$entityMapper,
			$songFileMapper,
			$attributeMapper,
			$miscMapper,
			$libraryMapper,
			$pathResolver,
			$rootFolder,
			$userManager,
			$logger,
		);

		$exporter->exportToFile($library, $this->tempDbPath);

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

	public function testGenerateHashcodesIncludesMiscFilesAndExcludesDeletedSongs(): void {
		$songMapper = $this->createMock(InternalSongMapper::class);
		$entityMapper = $this->createMock(InternalEntityMapper::class);
		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$attributeMapper = $this->createMock(InternalSongAttributeMapper::class);
		$miscMapper = $this->createMock(InternalMiscMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$pathResolver = $this->createMock(MobileSheetsPathResolver::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$userManager = $this->createMock(IUserManager::class);
		$logger = new NullLogger();

		$library = new InternalLibrary(id: 1, userId: 'admin', path: '/Noten');
		$user = $this->createMock(IUser::class);
		$userManager->method('get')->with('admin')->willReturn($user);

		$songFileMapper->method('findAllForLibrary')->with(1, false)->willReturn([]);

		$activeSong = new InternalSong(id: 1, uuid: 's-1', libraryId: 1, title: 'Active', clientId: 10);
		$deletedSong = new InternalSong(id: 2, uuid: 's-2', libraryId: 1, title: 'Deleted', clientId: 20, deletedAt: 12345);
		$songMapper->method('findAllForLibrary')->with(1, true)->willReturn([$activeSong, $deletedSong]);

		$miscFiles = [
			['Id' => 1, 'SongId' => 10, 'Path' => '/remote.php/dav/files/admin/Noten/active.pdf', 'FileHash' => 9999, 'LastModified' => 1000000, 'FileSize' => 45000],
			['Id' => 2, 'SongId' => 20, 'Path' => '/remote.php/dav/files/admin/Noten/deleted.pdf', 'FileHash' => 8888, 'LastModified' => 1000000, 'FileSize' => 30000],
		];
		$miscMapper->method('getMiscDataForTable')->with(1, 'mobilesheets', 'Files', false)->willReturn($miscFiles);

		$exporter = new MobileSheetsSqliteExporter(
			$songMapper,
			$entityMapper,
			$songFileMapper,
			$attributeMapper,
			$miscMapper,
			$libraryMapper,
			$pathResolver,
			$rootFolder,
			$userManager,
			$logger,
		);

		$hashcodes = $exporter->generateHashcodes($library);

		$expected = implode("\n", [
			'/remote.php/dav/files/admin/Noten/active.pdf',
			'9999',
			'1000000',
			'45000',
		]);

		self::assertSame($expected, $hashcodes);
	}

	public function testExportToFilePurgesFilesWithNoConnectedSong(): void {
		$songMapper = $this->createMock(InternalSongMapper::class);
		$entityMapper = $this->createMock(InternalEntityMapper::class);
		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$attributeMapper = $this->createMock(InternalSongAttributeMapper::class);
		$miscMapper = $this->createMock(InternalMiscMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$pathResolver = $this->createMock(MobileSheetsPathResolver::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$userManager = $this->createMock(IUserManager::class);
		$logger = new NullLogger();

		$library = new InternalLibrary(id: 1, userId: 'admin', path: '/Noten');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userManager->method('get')->with('admin')->willReturn($user);

		$activeSong = new InternalSong(id: 1, uuid: 's-1', libraryId: 1, title: 'Active', clientId: 10);
		$songMapper->method('findAllForLibrary')->willReturn([$activeSong]);
		$songFileMapper->method('findAllForLibrary')->with(1, false)->willReturn([]);

		$miscMapper->method('getMiscSchemas')->with(1, 'mobilesheets')->willReturn([
			'Files' => 'CREATE TABLE Files (Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255), PageOrder VARCHAR(255), FileSize INTEGER, LastModified INTEGER, Source INTEGER, Type INTEGER, Password VARCHAR(255), SourceFilePageCount INTEGER, FileHash INTEGER, Width INTEGER, Height INTEGER);',
		]);

		$miscFiles = [
			['Id' => 1, 'SongId' => 10, 'Path' => 'active.pdf'],
			['Id' => 2, 'SongId' => 999, 'Path' => 'orphan.pdf'],
		];
		$miscMapper->method('getMiscDataForTable')->with(1, 'mobilesheets', 'Files', false)->willReturn($miscFiles);

		$exporter = new MobileSheetsSqliteExporter(
			$songMapper,
			$entityMapper,
			$songFileMapper,
			$attributeMapper,
			$miscMapper,
			$libraryMapper,
			$pathResolver,
			$rootFolder,
			$userManager,
			$logger,
		);

		$exporter->exportToFile($library, $this->tempDbPath);

		self::assertFileExists($this->tempDbPath);
		$db = new PDO('sqlite:' . $this->tempDbPath);
		$fileRows = $db->query('SELECT Id, SongId, Path FROM Files')->fetchAll(PDO::FETCH_ASSOC);

		// File 1 with SongId 10 exists, File 2 with orphan SongId 999 must have been purged
		self::assertCount(1, $fileRows);
		self::assertSame(1, (int)$fileRows[0]['Id']);
		self::assertSame(10, (int)$fileRows[0]['SongId']);
	}
}
