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
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$databasePath = $fixture['path'];
		$database = $fixture['connection'];

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
