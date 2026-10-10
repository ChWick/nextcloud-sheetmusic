<?php

declare(strict_types=1);

namespace Service;

use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Db\SongMapper;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\LibraryService;
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

	public function testCreateSongWithInternalDatabase(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createMock(LibraryService::class);
		$songMapper = $this->createSongMapper();
		$fileService = $this->createMock(SongFileService::class);
		$fileManager = $this->createMock(FileManager::class);
		$logger = $this->createMock(LoggerInterface::class);

		$internalSongMapper = $this->createMock(InternalSongMapper::class);
		$internalEntityMapper = $this->createMock(InternalEntityMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);

		$createdSong = new InternalSong(
			id: 42,
			uuid: 'test-uuid-1234',
			libraryId: 1,
			title: 'Across The Stars',
		);

		$internalSongMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (InternalSong $song): bool {
				return $song->title === 'Across The Stars'
					&& $song->libraryId === 1
					&& strlen($song->uuid) === 36
					&& $song->opus === 'Op. 27 No. 2'
					&& $song->catalogNumber === 'BWV 565'
					&& $song->songNumber === '88';
			}))
			->willReturn($createdSong);

		$internalEntityMapper->expects($this->exactly(11))
			->method('setSongEntitiesForType');

		$libraryMapper->expects($this->once())
			->method('touch')
			->with(1);

		$internalSongMapper->expects($this->once())
			->method('findLibraryData')
			->with(1, $user)
			->willReturn(['songs' => [$createdSong]]);

		$service = new SongService(
			$libraryService,
			$songMapper,
			$fileService,
			$fileManager,
			$logger,
			null,
			$internalSongMapper,
			$internalEntityMapper,
			null,
			$libraryMapper,
		);

		$result = $service->createSong(
			$user,
			1,
			'Across The Stars',
			composerIds: [10],
			opus: 'Op. 27 No. 2',
			catalogNumber: 'BWV 565',
			songNumber: '88',
		);

		self::assertNotNull($result);
		self::assertSame(42, $result['createdSongId']);
		self::assertSame('Across The Stars', $result['songs'][0]->title);
	}

	public function testUpdateSongWithInternalDatabase(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createMock(LibraryService::class);
		$songMapper = $this->createSongMapper();
		$fileService = $this->createMock(SongFileService::class);
		$fileManager = $this->createMock(FileManager::class);
		$logger = $this->createMock(LoggerInterface::class);

		$internalSongMapper = $this->createMock(InternalSongMapper::class);
		$internalEntityMapper = $this->createMock(InternalEntityMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);

		$existingSong = new InternalSong(
			id: 42,
			uuid: 'test-uuid-1234',
			libraryId: 1,
			title: 'Old Title',
		);

		$internalSongMapper->expects($this->once())
			->method('findById')
			->with(42)
			->willReturn($existingSong);

		$internalSongMapper->expects($this->once())
			->method('update')
			->with($this->callback(function (InternalSong $song): bool {
				return $song->id === 42 && $song->title === 'New Title';
			}));

		$internalEntityMapper->expects($this->exactly(11))
			->method('setSongEntitiesForType');

		$libraryMapper->expects($this->once())
			->method('touch')
			->with(1);

		$internalSongMapper->expects($this->once())
			->method('findLibraryData')
			->with(1, $user)
			->willReturn(['songs' => [$existingSong]]);

		$service = new SongService(
			$libraryService,
			$songMapper,
			$fileService,
			$fileManager,
			$logger,
			null,
			$internalSongMapper,
			$internalEntityMapper,
			null,
			$libraryMapper,
		);

		$result = $service->updateSong(
			$user,
			42,
			1,
			'New Title',
			composerIds: [10],
		);

		self::assertNotNull($result);
		self::assertSame('New Title', $existingSong->title);
	}

	public function testDeleteSongWithInternalDatabase(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createMock(LibraryService::class);
		$songMapper = $this->createSongMapper();
		$fileService = $this->createMock(SongFileService::class);
		$fileManager = $this->createMock(FileManager::class);
		$logger = $this->createMock(LoggerInterface::class);

		$internalSongMapper = $this->createMock(InternalSongMapper::class);
		$internalSongFileMapper = $this->createMock(InternalSongFileMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);

		$internalSongFileMapper->expects($this->once())
			->method('findAllForSong')
			->with(42)
			->willReturn([]);

		$internalSongMapper->expects($this->once())
			->method('delete')
			->with(42, false)
			->willReturn(true);

		$libraryMapper->expects($this->once())
			->method('touch')
			->with(1);

		$internalSongMapper->expects($this->once())
			->method('findLibraryData')
			->with(1, $user)
			->willReturn(['songs' => []]);

		$service = new SongService(
			$libraryService,
			$songMapper,
			$fileService,
			$fileManager,
			$logger,
			null,
			$internalSongMapper,
			null,
			$internalSongFileMapper,
			$libraryMapper,
		);

		$result = $service->deleteSong($user, 42, 1);
		self::assertSame(['songs' => []], $result);
	}
}
