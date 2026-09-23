<?php

declare(strict_types=1);

namespace Controller;

use OCA\MobileSheetsManager\AppInfo\Application;
use OCA\MobileSheetsManager\Controller\ApiController;
use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\MobileSheetsCollection;
use OCA\MobileSheetsManager\Db\MobileSheetsFile;
use OCA\MobileSheetsManager\Db\MobileSheetsSong;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCA\MobileSheetsManager\Service\FileManager;
use OCA\MobileSheetsManager\Service\MobileSheetsHashCodesFile;
use OCA\MobileSheetsManager\Service\MobileSheetsPathResolver;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ApiTest extends TestCase {
	/** @var list<string> temporary hash codes files to remove after each test */
	private array $temporaryFiles = [];

	public function testIndex(): void {
		$controller = $this->createController();

		$this->assertEquals('Hello world!', $controller->index()->getData()['message']);
	}

	public function testDeleteSongFileWithoutLibraryIdIsBadRequest(): void {
		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_BAD_REQUEST,
			$controller->deleteSongFile(1, 2)->getStatus(),
		);
	}

	public function testUpdateSongWithoutLibraryIdIsBadRequest(): void {
		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_BAD_REQUEST,
			$controller->updateSong(1, null, 'Some title')->getStatus(),
		);
	}

	public function testDeleteSongFileResolvesLibraryById(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects($this->once())
			->method('findForUser')
			->with('admin', 7)
			->willReturn(null);

		$controller = $this->createController($libraryMapper);

		$this->assertEquals(
			Http::STATUS_NOT_FOUND,
			$controller->deleteSongFile(1, 2, 7)->getStatus(),
		);
	}

	/**
	 * The WebDAV path recorded for a newly attached file must come from FileManager.
	 * Building it by hand used to strip only the user id and produced
	 * "/remote.php/dav/files/admin/files/Noten/...".
	 */
	public function testAttachNextcloudFileStoresTheWebdavPathWithoutADuplicatedFilesSegment(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('getLocalFile')->willReturn('/tmp/mobilesheetsmanager-test-library.db');

		$databaseNode = $this->createMock(Node::class);
		$databaseNode->method('getStorage')->willReturn($storage);
		$databaseNode->method('getInternalPath')->willReturn('files/Noten/mobilesheets.db');

		// The file as it sits in the library folder after the copy below.
		$attachedFile = $this->createMock(Node::class);
		$attachedFile->method('getPath')->willReturn('/admin/files/Noten/T-Pen 2_QG.pdf');

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/admin/files/Noten');
		$targetFolder->method('get')->with('T-Pen 2_QG.pdf')->willReturn($attachedFile);

		$sourceNode = $this->createMock(Node::class);
		$sourceNode->method('getType')->willReturn('file');
		$sourceNode->method('getName')->willReturn('T-Pen 2_QG.pdf');
		// Node::copy() resolves against the filesystem root, so it needs the full
		// internal path rather than a path relative to the user folder.
		$sourceNode->expects($this->once())
			->method('copy')
			->with('/admin/files/Noten/T-Pen 2_QG.pdf');

		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('getPath')->willReturn('/admin/files');
		$userFolder->method('get')->willReturnCallback(
			static function (string $path) use ($databaseNode, $targetFolder, $sourceNode): Node {
				if ($path === 'Noten/mobilesheets.db') {
					return $databaseNode;
				}
				if ($path === 'Noten') {
					return $targetFolder;
				}
				if ($path === 'Music/T-Pen 2_QG.pdf') {
					return $sourceNode;
				}

				throw new NotFoundException($path);
			},
		);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')
			->with('admin', 7)
			->willReturn(['id' => 7, 'path' => '/Noten/mobilesheets.db']);

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->expects($this->once())
			->method('setSongFile')
			->with(
				'/tmp/mobilesheetsmanager-test-library.db',
				42,
				'/remote.php/dav/files/admin/Noten/T-Pen 2_QG.pdf',
			);
		$songMapper->method('findLibraryData')->willReturn(['songs' => []]);
		// Without a song fixture the regenerated paths cannot be resolved; the upload
		// stays where it is and the controller reports a warning.
		$songMapper->method('findSong')->willReturn(null);

		$fileManager = new FileManager(
			$libraryMapper,
			$songMapper,
			$rootFolder,
			new MobileSheetsPathResolver($rootFolder),
			$this->createMock(LoggerInterface::class),
		);

		$controller = $this->createController($libraryMapper, $songMapper, $rootFolder, $fileManager);

		$response = $controller->attachNextcloudFile(42, 7, '/Music/T-Pen 2_QG.pdf', 'copy');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals(['songs' => []], $response->getData());
	}

	/**
	 * Removing one file of a two-file song has to renumber the file that remains: the
	 * song drops back to a single file and therefore loses its numeric prefix again. The
	 * deleted file's hash code entry (and the rename of the remaining one) have to end up
	 * in the hash codes file, too.
	 */
	public function testDeleteSongFileRenumbersTheRemainingFiles(): void {
		$databasePath = '/tmp/mobilesheetsmanager-test-library.db';
		$hashFile = MobileSheetsHashCodesFile::pathForDatabase($databasePath);
		file_put_contents($hashFile, implode("\n", [
			'/remote.php/dav/files/admin/Noten/Jazz/001 Jazz - Take Five.pdf', '1', '2', '3',
			'/remote.php/dav/files/admin/Noten/Jazz/002 Jazz - Take Five.pdf', '561292453', '1789312275000', '839797',
		]));
		$this->temporaryFiles[] = $hashFile;

		$storage = $this->createMock(IStorage::class);
		$storage->method('getLocalFile')->willReturn($databasePath);

		$databaseNode = $this->createMock(Node::class);
		$databaseNode->method('getStorage')->willReturn($storage);
		$databaseNode->method('getInternalPath')->willReturn('files/Noten/mobilesheets.db');

		// The file the user deleted: it is gone from the database and from the filesystem.
		$deletedFile = $this->createMock(Node::class);
		$deletedFile->method('getType')->willReturn('file');
		$deletedFile->expects($this->once())->method('delete');

		// The file that remains; it is the only one left, so its name loses the prefix.
		$remainingFile = $this->createMock(Node::class);
		$remainingFile->expects($this->once())
			->method('move')
			->with('/admin/files/Noten/Jazz/Jazz - Take Five.pdf');

		$libraryFolder = $this->createMock(Folder::class);
		$libraryFolder->method('getPath')->willReturn('/admin/files/Noten');

		// Still holds the moved file, so no folder may be cleaned up.
		$jazzFolder = $this->createMock(Folder::class);
		$jazzFolder->method('getDirectoryListing')->willReturn([$remainingFile]);

		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('getPath')->willReturn('/admin/files');
		$userFolder->method('get')->willReturnCallback(
			static function (string $path) use ($databaseNode, $libraryFolder, $remainingFile, $jazzFolder): Node {
				return match ($path) {
					'Noten/mobilesheets.db' => $databaseNode,
					'Noten' => $libraryFolder,
					'Noten/Jazz' => $jazzFolder,
					'Noten/Jazz/001 Jazz - Take Five.pdf' => $remainingFile,
					default => throw new NotFoundException($path),
				};
			},
		);
		$userFolder->method('nodeExists')->willReturnCallback(
			static fn (string $path): bool => in_array(
				$path,
				['Noten', 'Noten/Jazz', 'Noten/Jazz/001 Jazz - Take Five.pdf'],
				true,
			),
		);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);
		$rootFolder->method('get')->willReturnCallback(
			static function (string $path) use ($deletedFile): Node {
				if ($path === '/admin/files/Noten/Jazz/002 Jazz - Take Five.pdf') {
					return $deletedFile;
				}

				throw new NotFoundException($path);
			},
		);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')
			->with('admin', 7)
			->willReturn(['id' => 7, 'path' => '/Noten/mobilesheets.db']);

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->expects($this->once())
			->method('removeSongFile')
			->with($databasePath, 42, 3)
			->willReturn('/remote.php/dav/files/admin/Noten/Jazz/002 Jazz - Take Five.pdf');
		// The song is read after the removal, so it lists the remaining file only.
		$songMapper->method('findSong')->willReturn(new MobileSheetsSong(
			42,
			'Take Five',
			[new MobileSheetsFile(11, '/remote.php/dav/files/admin/Noten/Jazz/001 Jazz - Take Five.pdf')],
			[],
			[],
			[new MobileSheetsCollection(1, 'Jazz')],
			[],
		));
		$songMapper->expects($this->once())
			->method('updateFile')
			->with($databasePath, 11, '/remote.php/dav/files/admin/Noten/Jazz/Jazz - Take Five.pdf');
		$songMapper->method('findLibraryData')->willReturn(['songs' => []]);

		$fileManager = new FileManager(
			$libraryMapper,
			$songMapper,
			$rootFolder,
			new MobileSheetsPathResolver($rootFolder),
			$this->createMock(LoggerInterface::class),
		);

		$controller = $this->createController($libraryMapper, $songMapper, $rootFolder, $fileManager);

		$response = $controller->deleteSongFile(42, 3, 7);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());

		$hashCodes = (string)file_get_contents($hashFile);
		$this->assertStringNotContainsString('002 Jazz - Take Five.pdf', $hashCodes, 'the removed file must not keep an entry');
		$this->assertStringNotContainsString('001 Jazz - Take Five.pdf', $hashCodes, 'the remaining file was renamed');
		$this->assertStringContainsString(
			"/remote.php/dav/files/admin/Noten/Jazz/Jazz - Take Five.pdf\n1\n2\n3",
			$hashCodes,
			'the renamed entry must keep its hash, modification time and size',
		);
	}

	/** A stored path does not have to point into this user's tree. */
	public function testDeleteSongFileIgnoresStoredFilesOfAnotherUser(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('getLocalFile')->willReturn('/tmp/mobilesheetsmanager-test-library.db');

		$databaseNode = $this->createMock(Node::class);
		$databaseNode->method('getStorage')->willReturn($storage);
		$databaseNode->method('getInternalPath')->willReturn('files/Noten/mobilesheets.db');

		$libraryFolder = $this->createMock(Folder::class);
		$libraryFolder->method('getPath')->willReturn('/admin/files/Noten');

		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('getPath')->willReturn('/admin/files');
		$userFolder->method('get')->willReturnCallback(
			static function (string $path) use ($databaseNode, $libraryFolder): Node {
				return match ($path) {
					'Noten/mobilesheets.db' => $databaseNode,
					'Noten' => $libraryFolder,
					default => throw new NotFoundException($path),
				};
			},
		);
		$userFolder->method('nodeExists')->willReturn(true);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);
		// Nothing may be touched: the stored file belongs to another user's folder.
		$rootFolder->expects($this->never())->method('get');

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')
			->with('admin', 7)
			->willReturn(['id' => 7, 'path' => '/Noten/mobilesheets.db']);

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->method('removeSongFile')
			->willReturn('/remote.php/dav/files/cwick/Noten/Jazz/Jazz - Take Five.pdf');
		$songMapper->method('findSong')->willReturn(null);
		$songMapper->method('findLibraryData')->willReturn(['songs' => []]);

		$fileManager = new FileManager(
			$libraryMapper,
			$songMapper,
			$rootFolder,
			new MobileSheetsPathResolver($rootFolder),
			$this->createMock(LoggerInterface::class),
		);

		$controller = $this->createController($libraryMapper, $songMapper, $rootFolder, $fileManager);

		$response = $controller->deleteSongFile(42, 3, 7);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	/** A corrupted path in the library must not make the endpoint delete a folder. */
	public function testDeleteSongFileRefusesToDeleteAFolder(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('getLocalFile')->willReturn('/tmp/mobilesheetsmanager-test-library.db');

		$databaseNode = $this->createMock(Node::class);
		$databaseNode->method('getStorage')->willReturn($storage);
		$databaseNode->method('getInternalPath')->willReturn('files/Noten/mobilesheets.db');

		// A folder behind a stored path, e.g. after someone edited the database by hand.
		$storedFolder = $this->createMock(Folder::class);
		$storedFolder->method('getType')->willReturn('dir');
		$storedFolder->expects($this->never())->method('delete');

		$libraryFolder = $this->createMock(Folder::class);
		$libraryFolder->method('getPath')->willReturn('/admin/files/Noten');

		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('getPath')->willReturn('/admin/files');
		$userFolder->method('get')->willReturnCallback(
			static function (string $path) use ($databaseNode, $libraryFolder): Node {
				return match ($path) {
					'Noten/mobilesheets.db' => $databaseNode,
					'Noten' => $libraryFolder,
					default => throw new NotFoundException($path),
				};
			},
		);
		$userFolder->method('nodeExists')->willReturn(true);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);
		$rootFolder->method('get')->willReturn($storedFolder);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')
			->with('admin', 7)
			->willReturn(['id' => 7, 'path' => '/Noten/mobilesheets.db']);

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->method('removeSongFile')->willReturn('/remote.php/dav/files/admin/Noten');
		$songMapper->method('findSong')->willReturn(null);
		$songMapper->method('findLibraryData')->willReturn(['songs' => []]);

		$fileManager = new FileManager(
			$libraryMapper,
			$songMapper,
			$rootFolder,
			new MobileSheetsPathResolver($rootFolder),
			$this->createMock(LoggerInterface::class),
		);

		$controller = $this->createController($libraryMapper, $songMapper, $rootFolder, $fileManager);

		$this->assertEquals(Http::STATUS_OK, $controller->deleteSongFile(42, 3, 7)->getStatus());
	}

	/**
	 * Deleting a library may only remove its entry: the database file and the files
	 * it references have to stay, so the library can be added again later.
	 */
	public function testDeleteLibraryRemovesOnlyTheEntryAndKeepsTheFiles(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects($this->once())
			->method('findForUser')
			->with('admin', 7)
			->willReturn(['id' => 7, 'path' => '/Noten/mobilesheets.db']);
		$libraryMapper->expects($this->once())
			->method('deleteForUser')
			->with('admin', 7)
			->willReturn(true);

		// Nothing in the user's files may be touched by removing a library.
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->never())->method('getUserFolder');

		$controller = $this->createController($libraryMapper, null, $rootFolder);

		$response = $controller->deleteLibrary(7);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals(['id' => 7, 'path' => '/Noten/mobilesheets.db'], $response->getData());
	}

	public function testDeleteLibraryWithUnknownIdIsNotFound(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects($this->once())
			->method('findForUser')
			->with('admin', 7)
			->willReturn(null);
		$libraryMapper->expects($this->never())->method('deleteForUser');

		$controller = $this->createController($libraryMapper);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $controller->deleteLibrary(7)->getStatus());
	}

	public function testCreateSongWithoutLibraryIdIsBadRequest(): void {
		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_BAD_REQUEST,
			$controller->createSong(null, 'Some title')->getStatus(),
		);
	}

	public function testCreateSongWithoutTitleIsBadRequest(): void {
		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_BAD_REQUEST,
			$controller->createSong(7, '   ')->getStatus(),
		);
	}

	/**
	 * The client has to know the id of the new song: creating it and adding a file to it
	 * afterwards are separate requests.
	 */
	public function testCreateSongReturnsTheNewSongIdWithTheLibraryData(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('getLocalFile')->willReturn('/tmp/mobilesheetsmanager-test-library.db');

		$databaseNode = $this->createMock(Node::class);
		$databaseNode->method('getStorage')->willReturn($storage);
		$databaseNode->method('getInternalPath')->willReturn('files/Noten/mobilesheets.db');

		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('get')->with('Noten/mobilesheets.db')->willReturn($databaseNode);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')
			->with('admin', 7)
			->willReturn(['id' => 7, 'path' => '/Noten/mobilesheets.db']);

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->expects($this->once())
			->method('createSong')
			->with('/tmp/mobilesheetsmanager-test-library.db', 'New Song', [10], [100], [1000], [200])
			->willReturn(42);
		$songMapper->method('findLibraryData')->willReturn(['songs' => [], 'composers' => []]);

		$controller = $this->createController($libraryMapper, $songMapper, $rootFolder);

		$response = $controller->createSong(7, 'New Song', [10], [100], [1000], [200]);

		$this->assertEquals(Http::STATUS_CREATED, $response->getStatus());
		$this->assertEquals(
			['createdSongId' => 42, 'songs' => [], 'composers' => []],
			$response->getData(),
		);
	}

	public function testCreateSongOfAnUnknownLibraryIsNotFound(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->with('admin', 7)->willReturn(null);

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->expects($this->never())->method('createSong');

		$controller = $this->createController($libraryMapper, $songMapper);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $controller->createSong(7, 'New Song')->getStatus());
	}

	protected function tearDown(): void {
		foreach ($this->temporaryFiles as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}
		$this->temporaryFiles = [];
	}

	private function createController(
		?LibraryMapper $libraryMapper = null,
		?SongMapper $songMapper = null,
		?IRootFolder $rootFolder = null,
		?FileManager $fileManager = null,
	): ApiController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return new ApiController(
			Application::APP_ID,
			$this->createMock(IRequest::class),
			$libraryMapper ?? $this->createMock(LibraryMapper::class),
			$songMapper ?? $this->createMock(SongMapper::class),
			$rootFolder ?? $this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
			$userSession,
			$fileManager ?? $this->createMock(FileManager::class),
		);
	}
}
