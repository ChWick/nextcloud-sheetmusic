<?php

declare(strict_types=1);

namespace Service;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCA\MobileSheetsManager\Service\LibraryService;
use OCA\MobileSheetsManager\Service\LibrarySyncService;
use OCA\MobileSheetsManager\Service\MobileSheetsFileLogEntry;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LibraryServiceTest extends TestCase {
	public function testResolveLibraryPathReturnsPathWhenFound(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects($this->once())
			->method('findForUser')
			->with('admin', 1)
			->willReturn(['id' => 1, 'path' => '/Noten/mobilesheets.db']);

		$service = new LibraryService(
			$libraryMapper,
			$this->createMock(SongMapper::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(LibrarySyncService::class),
		);

		$this->assertSame('/Noten/mobilesheets.db', $service->resolveLibraryPath('admin', 1));
	}

	public function testResolveLibraryPathReturnsNullWhenMissing(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->willReturn(null);

		$service = new LibraryService(
			$libraryMapper,
			$this->createMock(SongMapper::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(LibrarySyncService::class),
		);

		$this->assertNull($service->resolveLibraryPath('admin', 99));
	}

	public function testGetDatabasePathResolvesFileLocation(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->willReturn(['id' => 1, 'path' => '/Noten/mobilesheets.db']);

		$storage = $this->createMock(IStorage::class);
		$storage->method('getLocalFile')->with('files/Noten/mobilesheets.db')->willReturn('/tmp/mobilesheets.db');

		$node = $this->createMock(Node::class);
		$node->method('getStorage')->willReturn($storage);
		$node->method('getInternalPath')->willReturn('files/Noten/mobilesheets.db');

		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('get')->with('Noten/mobilesheets.db')->willReturn($node);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);

		$service = new LibraryService(
			$libraryMapper,
			$this->createMock(SongMapper::class),
			$rootFolder,
			$this->createMock(LoggerInterface::class),
			$this->createMock(LibrarySyncService::class),
		);

		$this->assertSame('/tmp/mobilesheets.db', $service->getDatabasePath('admin', 1));
	}

	public function testGetLibrariesReturnsUserLibraries(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects($this->once())
			->method('findAllForUser')
			->with('admin')
			->willReturn([['id' => 1, 'path' => '/Noten/mobilesheets.db']]);

		$service = new LibraryService(
			$libraryMapper,
			$this->createMock(SongMapper::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(LibrarySyncService::class),
		);

		$this->assertSame([['id' => 1, 'path' => '/Noten/mobilesheets.db']], $service->getLibraries('admin'));
	}

	public function testAddLibraryValidatesPath(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects($this->once())
			->method('addForUser')
			->with('admin', '/Noten/mobilesheets.db');

		$service = new LibraryService(
			$libraryMapper,
			$this->createMock(SongMapper::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(LibrarySyncService::class),
		);

		$this->assertNull($service->addLibrary('admin', 'relative/path.db'));
		$this->assertNull($service->addLibrary('admin', '/Noten/invalid.txt'));
		$this->assertSame(['path' => '/Noten/mobilesheets.db'], $service->addLibrary('admin', '/Noten/mobilesheets.db'));
	}

	public function testDeleteLibraryRemovesLibraryEntry(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->with('admin', 1)->willReturn(['id' => 1, 'path' => '/Noten/mobilesheets.db']);
		$libraryMapper->expects($this->once())->method('deleteForUser')->with('admin', 1);

		$service = new LibraryService(
			$libraryMapper,
			$this->createMock(SongMapper::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(LibrarySyncService::class),
		);

		$this->assertSame(['id' => 1, 'path' => '/Noten/mobilesheets.db'], $service->deleteLibrary('admin', 1));
	}

	public function testSyncLibraryDelegatesToLibrarySyncService(): void {
		$user = $this->createMock(IUser::class);
		$librarySync = $this->createMock(LibrarySyncService::class);
		$librarySync->expects($this->once())
			->method('syncLibrary')
			->with(1, $user)
			->willReturn([
				new MobileSheetsFileLogEntry(1, 'Take Five', '/a.pdf', 'Synced', MobileSheetsFileLogEntry::LEVEL_INFO, MobileSheetsFileLogEntry::CODE_PATH_MOVED, 10),
			]);

		$service = new LibraryService(
			$this->createMock(LibraryMapper::class),
			$this->createMock(SongMapper::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
			$librarySync,
		);

		$res = $service->syncLibrary(1, $user);
		$this->assertSame('File paths and metadata are up to date', $res['message']);
		$this->assertCount(1, $res['log']);
	}
}

