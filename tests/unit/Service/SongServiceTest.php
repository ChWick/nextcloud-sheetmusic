<?php

declare(strict_types=1);

namespace Service;

use OCA\SheetMusic\Db\SongMapper;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\SongFileService;
use OCA\SheetMusic\Service\SongService;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TestSupport\TestUtils;

final class SongServiceTest extends TestCase {
	use TestUtils;

	public function testGetSongsReturnsLibraryData(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createLibraryServiceForDatabase('admin', 1, '/Noten/library.db', $fixture['path']);
		$songMapper = $this->createSongMapper();

		$service = new SongService(
			$libraryService,
			$songMapper,
			$this->createMock(SongFileService::class),
			$this->createMock(FileManager::class),
			$this->createMock(LoggerInterface::class),
		);

		try {
			self::assertSame([], $service->getSongs($user, 1)['songs']);
		} finally {
			unlink($fixture['path']);
		}
	}

	public function testCreateSongReturnsCreatedIdAndData(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createLibraryServiceForDatabase('admin', 1, '/Noten/library.db', $fixture['path']);
		$songMapper = $this->createSongMapper();

		$service = new SongService(
			$libraryService,
			$songMapper,
			$this->createMock(SongFileService::class),
			$this->createMock(FileManager::class),
			$this->createMock(LoggerInterface::class),
		);

		try {
			$res = $service->createSong(
				$user,
				1,
				'Take Five',
				opus: 'Op. 27 No. 2',
				catalogNumber: 'BWV 565',
				songNumber: '88',
			);
			self::assertSame(1, $res['createdSongId']);
			self::assertSame('Take Five', $res['songs'][0]->title);
			self::assertSame('Op. 27 No. 2', $res['songs'][0]->opus);
			self::assertSame('BWV 565', $res['songs'][0]->catalogNumber);
			self::assertSame('88', $res['songs'][0]->songNumber);
		} finally {
			unlink($fixture['path']);
		}
	}

	public function testUpdateSongSyncsMetadata(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$fixture['connection']->exec("INSERT INTO Songs (Id, Title) VALUES (42, 'Take Five')");
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createLibraryServiceForDatabase('admin', 1, '/Noten/library.db', $fixture['path']);
		$songMapper = $this->createSongMapper();

		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->expects($this->once())
			->method('syncSongFileMetadata')
			->with($user, $fixture['path'], 42);

		$service = new SongService(
			$libraryService,
			$songMapper,
			$songFileService,
			$this->createMock(FileManager::class),
			$this->createMock(LoggerInterface::class),
		);

		try {
			$result = $service->updateSong($user, 42, 1, 'Blue Bossa');
			self::assertSame('Blue Bossa', $result['songs'][0]->title);
			self::assertSame('Blue Bossa', $fixture['connection']->query('SELECT Title FROM Songs WHERE Id = 42')->fetchColumn());
		} finally {
			unlink($fixture['path']);
		}
	}

	public function testBatchUpdateSongsCallsBatchUpdateAndSyncsFiles(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$fixture['connection']->exec("INSERT INTO Songs (Id, Title) VALUES (42, 'Take Five'), (43, 'Blue Bossa')");
		$fixture['connection']->exec("INSERT INTO Composer (Id, Name) VALUES (10, 'Bach')");
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createLibraryServiceForDatabase('admin', 1, '/Noten/library.db', $fixture['path']);
		$songMapper = $this->createSongMapper();

		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->expects($this->exactly(2))->method('syncSongFileMetadata');

		$service = new SongService(
			$libraryService,
			$songMapper,
			$songFileService,
			$this->createMock(FileManager::class),
			$this->createMock(LoggerInterface::class),
		);

		try {
			$result = $service->batchUpdateSongs($user, 1, [42, 43], ['action' => 'add', 'ids' => [10]]);
			self::assertCount(2, $result['songs']);
			self::assertSame(2, (int)$fixture['connection']->query('SELECT COUNT(*) FROM ComposerSongs WHERE ComposerId = 10')->fetchColumn());
		} finally {
			unlink($fixture['path']);
		}
	}

	public function testResolveBothSongsPreservesSharedFileOnDetach(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$fixture['connection']->exec("INSERT INTO Songs (Id, Title) VALUES (60, 'Song A'), (61, 'Song B')");
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createLibraryServiceForDatabase('admin', 1, '/Noten/library.db', $fixture['path']);

		$songMapper = $this->createSongMapper();

		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->expects($this->once())
			->method('deleteSongFile')
			->with($user, 1, 60, 207);

		$fileManager = $this->createMock(FileManager::class);
		$targetFolder = $this->createMock(\OCP\Files\Folder::class);
		$fileManager->method('libraryContext')->willReturn([
			'databasePath' => $fixture['path'],
			'targetFolder' => $targetFolder,
		]);

		$integrityService = $this->createMock(\OCA\SheetMusic\Service\LibraryIntegrityService::class);
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

		self::assertCount(2, $res['songs']);
		self::assertSame('Song A', $fixture['connection']->query('SELECT Title FROM Songs WHERE Id = 60')->fetchColumn());
		unlink($fixture['path']);
	}

	public function testResolveBothSongsWithRealDatabaseIntegration(): void {
		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$databasePath = $fixture['path'];
		$database = $fixture['connection'];

		$database->exec("INSERT INTO Songs (Id, Title) VALUES (60, 'Nachspiel'), (61, 'Nachspiel')");
		$database->exec("INSERT INTO Files (Id, SongId, Path) VALUES (101, 60, '/remote.php/dav/files/admin/Noten/Nachspiel.pdf'), (102, 61, '/remote.php/dav/files/admin/Noten/Nachspiel.pdf')");

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createLibraryServiceForDatabase(
			'admin',
			1,
			'/Noten/library.db',
			$databasePath,
		);

		$logger = $this->createMock(LoggerInterface::class);
		$songMapper = new SongMapper($logger);

		$rootFolder = $this->createMock(\OCP\Files\IRootFolder::class);
		$userFolder = $this->createMock(\OCP\Files\IUserFolder::class);
		$userFolder->method('getOwner')->willReturn($user);
		$userFolder->method('getPath')->willReturn('/admin/files');
		$userFolder->method('get')->with('Noten')->willReturn($this->createMock(\OCP\Files\Folder::class));
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
