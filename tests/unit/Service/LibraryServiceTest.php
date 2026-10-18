<?php

declare(strict_types=1);

namespace Service;

use OCA\SheetMusic\Service\LibraryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\RealMobileSheetsTestTrait;

final class LibraryServiceTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private LibraryService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$this->service = new LibraryService(
			$this->libraryMapper,
			$this->rootFolder,
			new NullLogger(),
			exporter: $this->exporter,
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testResolveLibraryPathReturnsPathWhenFound(): void {
		$lib = $this->createTestLibrary('/Noten/resolve_test.db', 'admin');

		self::assertSame('/Noten/resolve_test.db', $this->service->resolveLibraryPath('admin', (int)$lib->id));
	}

	public function testResolveLibraryPathReturnsNullWhenMissing(): void {
		self::assertNull($this->service->resolveLibraryPath('admin', 999999));
	}

	public function testGetDatabasePathResolvesFileLocation(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		if (!$userFolder->nodeExists('Noten')) {
			$this->createdUserNodes[] = $userFolder->newFolder('Noten');
		}
		$notenFolder = $userFolder->get('Noten');
		if (!$notenFolder->nodeExists('mobilesheets.db')) {
			$file = $notenFolder->newFile('mobilesheets.db');
			$file->putContent('fake-sqlite-content');
			$this->createdUserNodes[] = $file;
		}

		$lib = $this->createTestLibrary('/Noten/mobilesheets.db', 'admin');
		$path = $this->service->getDatabasePath('admin', (int)$lib->id);

		self::assertNotNull($path);
		self::assertFileExists($path);
	}

	public function testGetLibrariesReturnsUserLibraries(): void {
		$lib1 = $this->createTestLibrary('/Noten/lib1.db', 'admin');
		$lib2 = $this->createTestLibrary('/Noten/lib2.db', 'admin');

		$libs = $this->service->getLibraries('admin');
		$paths = array_column($libs, 'path');

		self::assertContains('/Noten/lib1.db', $paths);
		self::assertContains('/Noten/lib2.db', $paths);
	}

	public function testAddLibraryValidatesPath(): void {
		self::assertNull($this->service->addLibrary('admin', 'relative/path.db'));
		self::assertNull($this->service->addLibrary('admin', '/Noten/invalid.txt'));

		$result1 = $this->service->addLibrary('admin', '/Noten/add_test.db');
		self::assertSame(['path' => '/Noten/add_test.db'], $result1);

		$allLibs = $this->libraryMapper->findAllForUser('admin');
		$added = array_filter($allLibs, fn ($l) => $l->path === '/Noten/add_test.db');
		if (!empty($added)) {
			$this->createdLibraryIds[] = (int)reset($added)->id;
		}
	}

	public function testAddLibraryThrowsWhenDirectoryIsNotEmpty(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		$folderName = 'NotEmptyDir_' . bin2hex(random_bytes(4));
		$folder = $userFolder->newFolder($folderName);
		$this->createdUserNodes[] = $folder;

		$file = $folder->newFile('file.txt');
		$file->putContent('some content');
		$this->createdUserNodes[] = $file;

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('The directory must be empty');
		$this->service->addLibrary('admin', '/' . $folderName);
	}

	public function testAddLibrarySucceedsWhenDirectoryIsEmpty(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		$folderName = 'EmptyDir_' . bin2hex(random_bytes(4));
		$folder = $userFolder->newFolder($folderName);
		$this->createdUserNodes[] = $folder;

		$result = $this->service->addLibrary('admin', '/' . $folderName);
		self::assertSame(['path' => '/' . $folderName], $result);

		$allLibs = $this->libraryMapper->findAllForUser('admin');
		$added = array_filter($allLibs, fn ($l) => $l->path === '/' . $folderName);
		if (!empty($added)) {
			$this->createdLibraryIds[] = (int)reset($added)->id;
		}
	}

	public function testDeleteLibraryRemovesLibraryEntry(): void {
		$lib = $this->createTestLibrary('/Noten/to_delete.db', 'admin');

		$result = $this->service->deleteLibrary('admin', (int)$lib->id);
		self::assertNotNull($result);
		self::assertSame((int)$lib->id, $result['id']);
		self::assertSame('/Noten/to_delete.db', $result['path']);

		self::assertNull($this->libraryMapper->findForUser('admin', (int)$lib->id));
	}

	public function testIsAutoPathsEnabledReturnsMapperValue(): void {
		$lib1 = $this->createTestLibrary('/Noten/autopaths1.db', 'admin');
		$lib2 = $this->createTestLibrary('/Noten/autopaths2.db', 'admin');

		$this->libraryMapper->setSettingsForUser('admin', (int)$lib1->id, autoPaths: true);
		$this->libraryMapper->setSettingsForUser('admin', (int)$lib2->id, autoPaths: false);

		self::assertTrue($this->service->isAutoPathsEnabled('admin', (int)$lib1->id));
		self::assertFalse($this->service->isAutoPathsEnabled('admin', (int)$lib2->id));
	}

	public function testIsAutoPdfMetadataEnabledReturnsMapperValue(): void {
		$lib1 = $this->createTestLibrary('/Noten/autopdf1.db', 'admin');
		$lib2 = $this->createTestLibrary('/Noten/autopdf2.db', 'admin');

		$this->libraryMapper->setSettingsForUser('admin', (int)$lib1->id, autoPdfMetadata: true);
		$this->libraryMapper->setSettingsForUser('admin', (int)$lib2->id, autoPdfMetadata: false);

		self::assertTrue($this->service->isAutoPdfMetadataEnabled('admin', (int)$lib1->id));
		self::assertFalse($this->service->isAutoPdfMetadataEnabled('admin', (int)$lib2->id));
	}

	public function testUpdateSettingsUpdatesAndReturnsLibrary(): void {
		$lib = $this->createTestLibrary('/Noten/update_settings.db', 'admin');

		$result = $this->service->updateSettings('admin', (int)$lib->id, false, true);
		self::assertNotNull($result);
		self::assertFalse($result->autoPaths);
		self::assertTrue($result->autoPdfMetadata);
	}

	public function testUpdateSettingsReturnsNullWhenLibraryNotFound(): void {
		self::assertNull($this->service->updateSettings('admin', 999999, true));
	}
}
