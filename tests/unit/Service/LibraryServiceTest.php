<?php

declare(strict_types=1);

namespace Service;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Service\LibraryService;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TestSupport\TestUtils;

final class LibraryServiceTest extends TestCase {
	use TestUtils;

	public function testResolveLibraryPathReturnsPathWhenFound(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects($this->once())
			->method('findForUser')
			->with('admin', 1)
			->willReturn(['id' => 1, 'path' => '/Noten/mobilesheets.db']);

		$service = new LibraryService(
			$libraryMapper,
			$this->createSongMapper(),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertSame('/Noten/mobilesheets.db', $service->resolveLibraryPath('admin', 1));
	}

	public function testResolveLibraryPathReturnsNullWhenMissing(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->willReturn(null);

		$service = new LibraryService(
			$libraryMapper,
			$this->createSongMapper(),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
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
			$this->createSongMapper(),
			$rootFolder,
			$this->createMock(LoggerInterface::class),
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
			$this->createSongMapper(),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
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
			$this->createSongMapper(),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
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
			$this->createSongMapper(),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertSame(['id' => 1, 'path' => '/Noten/mobilesheets.db'], $service->deleteLibrary('admin', 1));
	}

	public function testIsAutoPathsEnabledReturnsMapperValue(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects($this->exactly(2))
			->method('findForUser')
			->willReturnMap([
				['admin', 1, ['id' => 1, 'path' => '/Noten/mobilesheets.db', 'auto_paths' => 1]],
				['admin', 2, ['id' => 2, 'path' => '/Noten/mobilesheets.db', 'auto_paths' => 0]],
			]);

		$service = new LibraryService(
			$libraryMapper,
			$this->createSongMapper(),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertTrue($service->isAutoPathsEnabled('admin', 1));
		$this->assertFalse($service->isAutoPathsEnabled('admin', 2));
	}

	public function testIsAutoPdfMetadataEnabledReturnsMapperValue(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects($this->exactly(2))
			->method('findForUser')
			->willReturnMap([
				['admin', 1, ['id' => 1, 'path' => '/Noten/mobilesheets.db', 'auto_pdf_metadata' => 1]],
				['admin', 2, ['id' => 2, 'path' => '/Noten/mobilesheets.db', 'auto_pdf_metadata' => 0]],
			]);

		$service = new LibraryService(
			$libraryMapper,
			$this->createSongMapper(),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertTrue($service->isAutoPdfMetadataEnabled('admin', 1));
		$this->assertFalse($service->isAutoPdfMetadataEnabled('admin', 2));
	}

	public function testUpdateSettingsUpdatesAndReturnsLibrary(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects($this->once())
			->method('setSettingsForUser')
			->with('admin', 1, false, true);
		$libraryMapper->expects($this->once())
			->method('findForUser')
			->with('admin', 1)
			->willReturn(['id' => 1, 'path' => '/Noten/mobilesheets.db', 'autoPaths' => false, 'autoPdfMetadata' => true]);

		$service = new LibraryService(
			$libraryMapper,
			$this->createSongMapper(),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
		);

		$result = $service->updateSettings('admin', 1, false, true);
		$this->assertNotNull($result);
		$this->assertFalse($result['autoPaths']);
		$this->assertTrue($result['autoPdfMetadata']);
	}

	public function testUpdateSettingsReturnsNullWhenLibraryNotFound(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects($this->once())
			->method('setSettingsForUser')
			->with('admin', 99, true, null);
		$libraryMapper->expects($this->once())
			->method('findForUser')
			->with('admin', 99)
			->willReturn(null);

		$service = new LibraryService(
			$libraryMapper,
			$this->createSongMapper(),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertNull($service->updateSettings('admin', 99, true));
	}

}
