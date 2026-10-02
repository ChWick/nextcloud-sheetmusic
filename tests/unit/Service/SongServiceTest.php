<?php

declare(strict_types=1);

namespace Service;

use OCA\MobileSheetsManager\Db\SongMapper;
use OCA\MobileSheetsManager\Service\FileManager;
use OCA\MobileSheetsManager\Service\LibraryService;
use OCA\MobileSheetsManager\Service\SongFileService;
use OCA\MobileSheetsManager\Service\SongService;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SongServiceTest extends TestCase {
	public function testGetSongsReturnsLibraryData(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createMock(LibraryService::class);
		$libraryService->method('getDatabasePath')->with('admin', 1)->willReturn('/tmp/mobilesheets.db');

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->expects($this->once())
			->method('findLibraryData')
			->with('/tmp/mobilesheets.db')
			->willReturn(['songs' => []]);

		$service = new SongService(
			$libraryService,
			$songMapper,
			$this->createMock(SongFileService::class),
			$this->createMock(FileManager::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertSame(['songs' => []], $service->getSongs($user, 1));
	}

	public function testCreateSongReturnsCreatedIdAndData(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createMock(LibraryService::class);
		$libraryService->method('getDatabasePath')->with('admin', 1)->willReturn('/tmp/mobilesheets.db');

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->expects($this->once())
			->method('createSong')
			->with('/tmp/mobilesheets.db', 'Take Five', [10], [], [], [])
			->willReturn(42);
		$songMapper->method('findLibraryData')->willReturn(['songs' => []]);

		$service = new SongService(
			$libraryService,
			$songMapper,
			$this->createMock(SongFileService::class),
			$this->createMock(FileManager::class),
			$this->createMock(LoggerInterface::class),
		);

		$res = $service->createSong($user, 1, 'Take Five', [10]);
		$this->assertSame(['createdSongId' => 42, 'songs' => []], $res);
	}

	public function testUpdateSongSyncsMetadata(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createMock(LibraryService::class);
		$libraryService->method('getDatabasePath')->with('admin', 1)->willReturn('/tmp/mobilesheets.db');

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->expects($this->once())
			->method('updateSong')
			->with('/tmp/mobilesheets.db', 42, 'Blue Bossa', [], [], [], []);
		$songMapper->method('findLibraryData')->willReturn(['songs' => []]);

		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->expects($this->once())
			->method('syncSongFileMetadata')
			->with($user, '/tmp/mobilesheets.db', 42);

		$service = new SongService(
			$libraryService,
			$songMapper,
			$songFileService,
			$this->createMock(FileManager::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertSame(['songs' => []], $service->updateSong($user, 42, 1, 'Blue Bossa'));
	}

	public function testBatchUpdateSongsCallsBatchUpdateAndSyncsFiles(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createMock(LibraryService::class);
		$libraryService->method('getDatabasePath')->with('admin', 1)->willReturn('/tmp/mobilesheets.db');

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->expects($this->once())
			->method('batchUpdateSongs')
			->with('/tmp/mobilesheets.db', [42, 43], [10], null, null, null);
		$songMapper->method('findLibraryData')->willReturn(['songs' => []]);

		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->expects($this->exactly(2))->method('syncSongFileMetadata');

		$service = new SongService(
			$libraryService,
			$songMapper,
			$songFileService,
			$this->createMock(FileManager::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertSame(['songs' => []], $service->batchUpdateSongs($user, 1, [42, 43], [10]));
	}

	public function testResolveBothSongsPreservesSharedFileOnDetach(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createMock(LibraryService::class);
		$libraryService->method('getDatabasePath')->with('admin', 1)->willReturn('/tmp/mobilesheets.db');

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->method('findLibraryData')->willReturn(['songs' => []]);

		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->expects($this->once())
			->method('deleteSongFile')
			->with($user, 1, 60, 207);

		$fileManager = $this->createMock(FileManager::class);
		$targetFolder = $this->createMock(\OCP\Files\Folder::class);
		$fileManager->method('libraryContext')->willReturn([
			'databasePath' => '/tmp/mobilesheets.db',
			'targetFolder' => $targetFolder,
		]);

		$integrityService = $this->createMock(\OCA\MobileSheetsManager\Service\LibraryIntegrityService::class);
		$integrityService->method('checkIntegrity')->willReturn([]);

		$service = new SongService(
			$libraryService,
			$songMapper,
			$songFileService,
			$fileManager,
			$this->createMock(LoggerInterface::class),
			$integrityService,
		);

		$res = $service->resolveBothSongs(
			$user,
			1,
			['id' => 60, 'title' => 'Song A', 'detachFileIds' => [207]],
			['id' => 61, 'title' => 'Song B', 'detachFileIds' => []],
		);

		$this->assertSame(['songs' => []], $res);
	}

	public function testResolveBothSongsWithRealDatabaseIntegration(): void {
		$databasePath = tempnam(sys_get_temp_dir(), 'msm-song-service-test-');
		self::assertNotFalse($databasePath);

		$database = new \PDO('sqlite:' . $databasePath);
		$database->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), Stars INTEGER DEFAULT 0, Difficulty INTEGER DEFAULT 0, Duration INTEGER DEFAULT 0, Keywords VARCHAR(255) DEFAULT \'\', Custom VARCHAR(255) DEFAULT \'\', Custom2 VARCHAR(255) DEFAULT \'\', SongId INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec('CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255), PageOrder VARCHAR(255), FileSize INTEGER, LastModified INTEGER, Source INTEGER, Type INTEGER, Password VARCHAR(255) DEFAULT \'\', SourceFilePageCount INTEGER, FileHash INTEGER, Width INTEGER, Height INTEGER)');
		$database->exec('CREATE TABLE Collections(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec('CREATE TABLE CollectionSong(Id INTEGER PRIMARY KEY, CollectionId INTEGER, SongId INTEGER)');
		$database->exec('CREATE TABLE Composer(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec('CREATE TABLE ComposerSongs(Id INTEGER PRIMARY KEY, ComposerId INTEGER, SongId INTEGER)');
		$database->exec('CREATE TABLE BookSongs(Id INTEGER PRIMARY KEY, BookId INTEGER, SongId INTEGER)');
		$database->exec('CREATE TABLE Books(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
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

		$database->exec("INSERT INTO Songs (Id, Title) VALUES (60, 'Nachspiel'), (61, 'Nachspiel')");
		$database->exec("INSERT INTO Files (Id, SongId, Path) VALUES (101, 60, '/remote.php/dav/files/admin/Noten/Nachspiel.pdf'), (102, 61, '/remote.php/dav/files/admin/Noten/Nachspiel.pdf')");

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createMock(LibraryService::class);
		$libraryService->method('getDatabasePath')->with('admin', 1)->willReturn($databasePath);
		$libraryService->method('getLibraryAndDatabasePath')->with('admin', 1)->willReturn(['Noten', $databasePath]);

		$logger = $this->createMock(LoggerInterface::class);
		$songMapper = new SongMapper($logger);

		$rootFolder = $this->createMock(\OCP\Files\IRootFolder::class);
		$userFolder = $this->createMock(\OCP\Files\IUserFolder::class);
		$userFolder->method('getOwner')->willReturn($user);
		$userFolder->method('getPath')->willReturn('/admin/files');
		$rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);

		$fileManager = $this->createMock(FileManager::class);
		$targetFolder = $this->createMock(\OCP\Files\Folder::class);
		$fileManager->method('libraryContext')->willReturn([
			'databasePath' => $databasePath,
			'targetFolder' => $targetFolder,
		]);

		$songFileService = new SongFileService($libraryService, $songMapper, $fileManager, $rootFolder, $logger);

		$service = new SongService(
			$libraryService,
			$songMapper,
			$songFileService,
			$fileManager,
			$logger,
			null,
		);

		// Execute resolveBothSongs with detaching file 101 from Song 60
		$res = $service->resolveBothSongs(
			$user,
			1,
			['id' => 60, 'title' => 'Nachspiel', 'detachFileIds' => [101]],
			['id' => 61, 'title' => 'Nachspiel', 'detachFileIds' => []],
		);

		self::assertIsArray($res);
		// Verify file 101 was successfully removed from database for Song 60
		$stmt = $database->query('SELECT COUNT(*) FROM Files WHERE SongId = 60');
		self::assertSame(0, (int)$stmt->fetchColumn());

		// Verify file 102 remains attached to Song 61
		$stmt = $database->query('SELECT COUNT(*) FROM Files WHERE SongId = 61');
		self::assertSame(1, (int)$stmt->fetchColumn());

		if (file_exists($databasePath)) {
			unlink($databasePath);
		}
	}
}

