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
			->with($user, 60, 207, 1);

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
}

