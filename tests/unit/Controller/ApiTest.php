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
use OCA\MobileSheetsManager\Service\MobileSheetsFileLogEntry;
use OCA\MobileSheetsManager\Service\MobileSheetsFileFacts;
use OCA\MobileSheetsManager\Service\MobileSheetsHashCodesFile;
use OCA\MobileSheetsManager\Service\MobileSheetsPath;
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
				$this->isInstanceOf(MobileSheetsFileFacts::class),
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
	 * A name that is already used in the library folder must not be overwritten when a
	 * file is copied in: two songs with the same title would otherwise share one PDF.
	 */
	public function testAttachNextcloudFileCopiesToAUniqueNameWhenTheNameIsTaken(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('getLocalFile')->willReturn('/tmp/mobilesheetsmanager-test-library.db');

		$databaseNode = $this->createMock(Node::class);
		$databaseNode->method('getStorage')->willReturn($storage);
		$databaseNode->method('getInternalPath')->willReturn('files/Noten/mobilesheets.db');

		$attachedFile = $this->createMock(Node::class);
		$attachedFile->method('getPath')->willReturn('/admin/files/Noten/Shared (2).pdf');

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/admin/files/Noten');
		$targetFolder->method('get')->with('Shared (2).pdf')->willReturn($attachedFile);

		$sourceNode = $this->createMock(Node::class);
		$sourceNode->method('getType')->willReturn('file');
		$sourceNode->method('getName')->willReturn('Shared.pdf');
		$sourceNode->expects($this->once())
			->method('copy')
			->with('/admin/files/Noten/Shared (2).pdf');

		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('getPath')->willReturn('/admin/files');
		$userFolder->method('get')->willReturnCallback(
			static function (string $path) use ($databaseNode, $targetFolder, $sourceNode): Node {
				return match ($path) {
					'Noten/mobilesheets.db' => $databaseNode,
					'Noten' => $targetFolder,
					'Music/Shared.pdf' => $sourceNode,
					default => throw new NotFoundException($path),
				};
			},
		);
		// The library folder already holds a file of that name, e.g. of another song.
		$userFolder->method('nodeExists')->willReturnCallback(
			static fn (string $path): bool => $path === 'Noten/Shared.pdf',
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
				'/remote.php/dav/files/admin/Noten/Shared (2).pdf',
				$this->isInstanceOf(MobileSheetsFileFacts::class),
			);
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

		$this->assertEquals(
			Http::STATUS_OK,
			$controller->attachNextcloudFile(42, 7, '/Music/Shared.pdf', 'copy')->getStatus(),
		);
	}

	/** Attaching a file the song already lists must not add a second entry for it. */
	public function testAttachNextcloudFileDoesNotListTheSameFileTwice(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('getLocalFile')->willReturn('/tmp/mobilesheetsmanager-test-library.db');

		$databaseNode = $this->createMock(Node::class);
		$databaseNode->method('getStorage')->willReturn($storage);
		$databaseNode->method('getInternalPath')->willReturn('files/Noten/mobilesheets.db');

		$libraryFile = $this->createMock(Node::class);
		$libraryFile->method('getPath')->willReturn('/admin/files/Noten/Shared.pdf');

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('getPath')->willReturn('/admin/files/Noten');
		$targetFolder->method('get')->with('Shared.pdf')->willReturn($libraryFile);

		// The library's own file is chosen as the source and already sits in place.
		$sourceNode = $this->createMock(Node::class);
		$sourceNode->method('getType')->willReturn('file');
		$sourceNode->method('getName')->willReturn('Shared.pdf');
		$sourceNode->expects($this->never())->method('copy');
		$sourceNode->expects($this->never())->method('move');

		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('getPath')->willReturn('/admin/files');
		$userFolder->method('get')->willReturnCallback(
			static function (string $path) use ($databaseNode, $targetFolder, $sourceNode): Node {
				return match ($path) {
					'Noten/mobilesheets.db' => $databaseNode,
					'Noten' => $targetFolder,
					'Noten/Shared.pdf' => $sourceNode,
					default => throw new NotFoundException($path),
				};
			},
		);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')
			->with('admin', 7)
			->willReturn(['id' => 7, 'path' => '/Noten/mobilesheets.db']);

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->method('findSong')->willReturn(new MobileSheetsSong(
			42,
			'Take Five',
			[new MobileSheetsFile(2, '/remote.php/dav/files/admin/Noten/Shared.pdf')],
		));
		$songMapper->expects($this->never())->method('setSongFile');
		$songMapper->method('findLibraryData')->willReturn(['songs' => []]);

		$fileManager = new FileManager(
			$libraryMapper,
			$songMapper,
			$rootFolder,
			new MobileSheetsPathResolver($rootFolder),
			$this->createMock(LoggerInterface::class),
		);

		$controller = $this->createController($libraryMapper, $songMapper, $rootFolder, $fileManager);

		$this->assertEquals(
			Http::STATUS_OK,
			$controller->attachNextcloudFile(42, 7, '/Noten/Shared.pdf', 'copy')->getStatus(),
		);
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
			->with($databasePath, 11, '/remote.php/dav/files/admin/Noten/Jazz/Jazz - Take Five.pdf', $this->isInstanceOf(MobileSheetsFileFacts::class));
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

	public function testDeleteSongWithoutLibraryIdIsBadRequest(): void {
		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_BAD_REQUEST,
			$controller->deleteSong(1)->getStatus(),
		);
	}

	public function testDeleteSongOfAnUnknownSongIsNotFound(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')
			->with('admin', 7)
			->willReturn(['id' => 7, 'path' => '/Noten/mobilesheets.db']);

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->method('findSong')
			->with('/tmp/mobilesheetsmanager-test-library.db', 42)
			->willReturn(null);
		$songMapper->expects($this->never())->method('deleteSong');

		$controller = $this->createController(
			$libraryMapper,
			$songMapper,
			$this->createLibraryRootFolder(),
		);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $controller->deleteSong(42, 7)->getStatus());
	}

	/**
	 * Deleting a song has to move its files into the trash bin: the database rows are the
	 * only thing that knows which files belong to the song, so they are read first.
	 */
	public function testDeleteSongMovesItsFilesToTheTrashBin(): void {
		$storedPath = '/remote.php/dav/files/admin/Noten/Alpha.pdf';
		$fileNode = $this->createMock(Node::class);
		$fileNode->method('getType')->willReturn('file');
		$fileNode->expects($this->once())->method('delete');

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->method('findSong')->willReturn(new MobileSheetsSong(
			42,
			'Alpha',
			[new MobileSheetsFile(2, $storedPath)],
		));
		$songMapper->expects($this->once())
			->method('deleteSong')
			->with('/tmp/mobilesheetsmanager-test-library.db', 42)
			->willReturn([$storedPath]);
		$songMapper->method('findLibraryData')->willReturn(['songs' => []]);

		$fileManager = $this->createMock(FileManager::class);
		$fileManager->expects($this->once())
			->method('pathOfStoredFile')
			->with($storedPath)
			->willReturn(new MobileSheetsPath('/admin/files/Noten/Alpha.pdf', $storedPath));

		$rootFolder = $this->createLibraryRootFolder();
		$rootFolder->method('get')
			->with('/admin/files/Noten/Alpha.pdf')
			->willReturn($fileNode);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')
			->with('admin', 7)
			->willReturn(['id' => 7, 'path' => '/Noten/mobilesheets.db']);

		$controller = $this->createController($libraryMapper, $songMapper, $rootFolder, $fileManager);

		$response = $controller->deleteSong(42, 7);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals(['songs' => []], $response->getData());
	}

	public function testDeleteEntityWithoutLibraryIdIsBadRequest(): void {
		$controller = $this->createController();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $controller->deleteComposer(10)->getStatus());
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $controller->deleteBook(100)->getStatus());
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $controller->deleteCollection(1000)->getStatus());
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $controller->deleteArtist(200)->getStatus());
	}

	public function testDeleteEntityResolvesTheLibraryById(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects($this->once())
			->method('findForUser')
			->with('admin', 7)
			->willReturn(null);

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->expects($this->never())->method('deleteEntity');

		$controller = $this->createController($libraryMapper, $songMapper, $this->createLibraryRootFolder());

		$this->assertEquals(Http::STATUS_NOT_FOUND, $controller->deleteComposer(10, 7)->getStatus());
	}

	/**
	 * Deleting an entity returns the refreshed library data: the songs keep existing
	 * and only lose the deleted entry in their field, which the client has to show.
	 */
	public function testDeleteEntityReturnsTheLibraryDataWithTheFieldRemoved(): void {
		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->expects($this->once())
			->method('deleteEntity')
			->with('/tmp/mobilesheetsmanager-test-library.db', 'composers', 10)
			->willReturn(true);
		$songMapper->method('findLibraryData')->willReturn(['songs' => [], 'composers' => []]);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')
			->with('admin', 7)
			->willReturn(['id' => 7, 'path' => '/Noten/mobilesheets.db']);

		$controller = $this->createController($libraryMapper, $songMapper, $this->createLibraryRootFolder());

		$response = $controller->deleteComposer(10, 7);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals(['songs' => [], 'composers' => []], $response->getData());
	}

	public function testDeleteEntityOfAnUnknownEntityIsNotFound(): void {
		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->method('deleteEntity')->willReturn(false);
		$songMapper->expects($this->never())->method('findLibraryData');

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')
			->with('admin', 7)
			->willReturn(['id' => 7, 'path' => '/Noten/mobilesheets.db']);

		$controller = $this->createController($libraryMapper, $songMapper, $this->createLibraryRootFolder());

		$this->assertEquals(Http::STATUS_NOT_FOUND, $controller->deleteArtist(200, 7)->getStatus());
	}

	public function testGetSongsWithoutAnIdIsBadRequest(): void {
		$controller = $this->createController();

		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->getSongs(null)->getStatus());
		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->getSongs('-3')->getStatus());
	}

	public function testGetSongsOfAnUnknownLibraryIsNotFound(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->with('admin', 7)->willReturn(null);

		$controller = $this->createController($libraryMapper);

		self::assertEquals(Http::STATUS_NOT_FOUND, $controller->getSongs('7')->getStatus());
	}

	public function testGetSongsOfSomethingThatIsNotAFileIsNotFound(): void {
		$node = $this->createMock(Node::class);
		$node->method('getType')->willReturn('dir');

		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('get')->with('Noten/mobilesheets.db')->willReturn($node);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->willReturn(['id' => 7, 'path' => '/Noten/mobilesheets.db']);

		$controller = $this->createController($libraryMapper, null, $rootFolder);

		self::assertEquals(Http::STATUS_NOT_FOUND, $controller->getSongs('7')->getStatus());
	}

	public function testGetSongsReturnsTheLibraryData(): void {
		$libraryData = ['songs' => [], 'composers' => [], 'books' => [], 'collections' => [], 'artists' => []];

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->with('admin', 7)->willReturn(['id' => 7, 'path' => '/Noten/mobilesheets.db']);

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->expects(self::once())
			->method('findLibraryData')
			->with('/tmp/mobilesheetsmanager-test-library.db')
			->willReturn($libraryData);

		$controller = $this->createController($libraryMapper, $songMapper, $this->createLibraryRootFolder());

		$response = $controller->getSongs('7');

		self::assertEquals(Http::STATUS_OK, $response->getStatus());
		self::assertEquals($libraryData, $response->getData());
	}

	public function testAddLibraryRejectsInvalidPaths(): void {
		$controller = $this->createController();

		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->addLibrary('Noten/mobilesheets.db')->getStatus());
		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->addLibrary('/Noten/notes.txt')->getStatus());
	}

	public function testAddLibraryStoresThePath(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects(self::once())
			->method('addForUser')
			->with('admin', '/Noten/mobilesheets.db');

		$controller = $this->createController($libraryMapper);

		$response = $controller->addLibrary('/Noten/mobilesheets.db');

		self::assertEquals(Http::STATUS_CREATED, $response->getStatus());
		self::assertEquals(['path' => '/Noten/mobilesheets.db'], $response->getData());
	}

	public function testUpdateSongWithoutATitleIsBadRequest(): void {
		$controller = $this->createController();

		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->updateSong(1, 7, '')->getStatus());
		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->updateSong(1, 7, null)->getStatus());
	}

	public function testUpdateSongOfAnUnknownLibraryIsNotFound(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->with('admin', 7)->willReturn(null);

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->expects(self::never())->method('updateSong');

		$controller = $this->createController($libraryMapper, $songMapper, $this->createLibraryRootFolder());

		self::assertEquals(Http::STATUS_NOT_FOUND, $controller->updateSong(1, 7, 'Alpha')->getStatus());
	}

	public function testUpdateSongReturnsTheLibraryData(): void {
		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->expects(self::once())
			->method('updateSong')
			->with('/tmp/mobilesheetsmanager-test-library.db', 1, 'Alpha', [10], [100], [1000], [200]);
		$songMapper->method('findLibraryData')->willReturn(['songs' => []]);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->with('admin', 7)->willReturn(['id' => 7, 'path' => '/Noten/mobilesheets.db']);

		$controller = $this->createController($libraryMapper, $songMapper, $this->createLibraryRootFolder());

		$response = $controller->updateSong(1, 7, 'Alpha', [10], [100], [1000], [200]);

		self::assertEquals(Http::STATUS_OK, $response->getStatus());
		self::assertEquals(['songs' => []], $response->getData());
	}

	public function testCreateEntityEndpointsRejectMissingValues(): void {
		$controller = $this->createController();

		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->createComposer(null, 'Bach')->getStatus());
		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->createComposer(7, '   ')->getStatus());
		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->createBook(null, 'Real Book')->getStatus());
		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->createBook(7, null)->getStatus());
		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->createCollection(null, 'Jazz')->getStatus());
		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->createCollection(7, '')->getStatus());
		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->createArtist(null, 'Coltrane')->getStatus());
		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->createArtist(7, '   ')->getStatus());
	}

	public function testCreateEntityEndpointsRejectAnUnknownLibrary(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->willReturn(null);

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->expects(self::never())->method('createComposer');
		$songMapper->expects(self::never())->method('createBook');
		$songMapper->expects(self::never())->method('createCollection');
		$songMapper->expects(self::never())->method('createArtist');

		$controller = $this->createController($libraryMapper, $songMapper, $this->createLibraryRootFolder());

		self::assertEquals(Http::STATUS_NOT_FOUND, $controller->createComposer(7, 'Bach')->getStatus());
		self::assertEquals(Http::STATUS_NOT_FOUND, $controller->createBook(7, 'Real Book')->getStatus());
		self::assertEquals(Http::STATUS_NOT_FOUND, $controller->createCollection(7, 'Jazz')->getStatus());
		self::assertEquals(Http::STATUS_NOT_FOUND, $controller->createArtist(7, 'Coltrane')->getStatus());
	}

	public function testCreateEntityEndpointsReturnTheCreatedEntry(): void {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->willReturn(['id' => 7, 'path' => '/Noten/mobilesheets.db']);

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->method('createComposer')->with('/tmp/mobilesheetsmanager-test-library.db', 'Bach')->willReturn(10);
		$songMapper->method('createBook')->with('/tmp/mobilesheetsmanager-test-library.db', 'Real Book')->willReturn(100);
		$songMapper->method('createCollection')->with('/tmp/mobilesheetsmanager-test-library.db', 'Jazz')->willReturn(1000);
		$songMapper->method('createArtist')->with('/tmp/mobilesheetsmanager-test-library.db', 'Coltrane')->willReturn(200);

		$controller = $this->createController($libraryMapper, $songMapper, $this->createLibraryRootFolder());

		$composer = $controller->createComposer(7, 'Bach');
		$book = $controller->createBook(7, 'Real Book');
		$collection = $controller->createCollection(7, 'Jazz');
		$artist = $controller->createArtist(7, 'Coltrane');

		self::assertEquals(Http::STATUS_CREATED, $composer->getStatus());
		self::assertEquals(['id' => 10, 'name' => 'Bach'], $composer->getData());
		self::assertEquals(Http::STATUS_CREATED, $book->getStatus());
		self::assertEquals(['id' => 100, 'title' => 'Real Book'], $book->getData());
		self::assertEquals(Http::STATUS_CREATED, $collection->getStatus());
		self::assertEquals(['id' => 1000, 'name' => 'Jazz'], $collection->getData());
		self::assertEquals(Http::STATUS_CREATED, $artist->getStatus());
		self::assertEquals(['id' => 200, 'name' => 'Coltrane'], $artist->getData());
	}

	/** Every delete endpoint hands its own entity type to the shared mapper method. */
	public function testDeleteEntityEndpointsPassTheirEntityTypeToTheMapper(): void {
		$calls = [];
		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->method('deleteEntity')->willReturnCallback(
			function (string $databasePath, string $entityType, int $entityId) use (&$calls): bool {
				$calls[] = [$entityType, $entityId];

				return true;
			},
		);
		$songMapper->method('findLibraryData')->willReturn(['songs' => []]);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->willReturn(['id' => 7, 'path' => '/Noten/mobilesheets.db']);

		$controller = $this->createController($libraryMapper, $songMapper, $this->createLibraryRootFolder());

		$controller->deleteComposer(10, 7);
		$controller->deleteBook(100, 7);
		$controller->deleteCollection(1000, 7);
		$controller->deleteArtist(200, 7);

		self::assertSame(
			[
				['composers', 10],
				['books', 100],
				['collections', 1000],
				['artists', 200],
			],
			$calls,
		);
	}

	public function testUploadSongFileWithoutAFileIsBadRequest(): void {
		unset($_FILES['file']);

		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->with('file')->willReturn(null);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->willReturn(['id' => 7, 'path' => '/Noten/mobilesheets.db']);

		$controller = $this->createController($libraryMapper, null, $this->createLibraryRootFolder(), null, $request);

		$response = $controller->uploadSongFile(1, 7);

		self::assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertEquals(['message' => 'No file uploaded'], $response->getData());
	}

	public function testEveryEndpointRejectsALoggedOutUser(): void {
		$controller = $this->createController(loggedIn: false);

		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->getLibraries()->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->addLibrary('/Noten/mobilesheets.db')->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->deleteLibrary(1)->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->getSongs('1')->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->createSong(7, 'Title')->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->updateSong(1, 7, 'Title')->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->deleteSong(1, 7)->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->uploadSongFile(1, 7)->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->attachNextcloudFile(1, 7, '/Noten/file.pdf')->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->deleteSongFile(1, 2, 7)->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->createComposer(7, 'Bach')->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->createBook(7, 'Real Book')->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->createCollection(7, 'Jazz')->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->createArtist(7, 'Coltrane')->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->deleteComposer(1, 7)->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->deleteBook(1, 7)->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->deleteCollection(1, 7)->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->deleteArtist(1, 7)->getStatus());
	}

	/** Root folder whose user folder resolves the library database. */
	private function createLibraryRootFolder(): IRootFolder {
		$storage = $this->createMock(IStorage::class);
		$storage->method('getLocalFile')->willReturn('/tmp/mobilesheetsmanager-test-library.db');

		$databaseNode = $this->createMock(Node::class);
		$databaseNode->method('getStorage')->willReturn($storage);
		$databaseNode->method('getInternalPath')->willReturn('files/Noten/mobilesheets.db');
		$databaseNode->method('getType')->willReturn('file');

		$userFolder = $this->createMock(IUserFolder::class);
		$userFolder->method('get')->with('Noten/mobilesheets.db')->willReturn($databaseNode);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);

		return $rootFolder;
	}

	protected function tearDown(): void {
		foreach ($this->temporaryFiles as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}
		$this->temporaryFiles = [];
	}

	/**
	 * Per-file failures are not fatal: the whole log - one line per file touched -
	 * is sent back to the client so the user can follow the run.
	 */
	public function testRegenerateFilePathsReportsTheLog(): void {
		$fileManager = $this->createMock(FileManager::class);
		$fileManager->method('regenerateFilePaths')->willReturn([
			new MobileSheetsFileLogEntry(1, 'Take Five', '/remote.php/dav/files/admin/Noten/a.pdf', 'Moved to "Noten/Jazz/Take Five.pdf"', MobileSheetsFileLogEntry::LEVEL_INFO),
			new MobileSheetsFileLogEntry(2, 'Broken', '/remote.php/dav/files/admin/Noten/b.pdf', 'No composer, artist, or collection', MobileSheetsFileLogEntry::LEVEL_WARNING),
		]);

		$controller = $this->createController(fileManager: $fileManager);

		$response = $controller->regenerateFilePaths(1);

		self::assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertCount(2, $data['log']);
		self::assertSame(MobileSheetsFileLogEntry::LEVEL_INFO, $data['log'][0]->level);
		self::assertSame('Take Five', $data['log'][0]->songTitle);
		self::assertSame(MobileSheetsFileLogEntry::LEVEL_WARNING, $data['log'][1]->level);
		self::assertSame('No composer, artist, or collection', $data['log'][1]->message);
		self::assertStringContainsString('could not be updated', $data['message']);
	}

	/** A clean run reports no failures and the success message. */
	public function testRegenerateFilePathsReportsSuccessWhenNothingFails(): void {
		$fileManager = $this->createMock(FileManager::class);
		$fileManager->method('regenerateFilePaths')->willReturn([
			new MobileSheetsFileLogEntry(1, 'Take Five', '/remote.php/dav/files/admin/Noten/a.pdf', 'Moved to "Noten/Jazz/Take Five.pdf"', MobileSheetsFileLogEntry::LEVEL_INFO),
		]);

		$controller = $this->createController(fileManager: $fileManager);

		$response = $controller->regenerateFilePaths(1);

		self::assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertCount(1, $data['log']);
		self::assertSame(MobileSheetsFileLogEntry::LEVEL_INFO, $data['log'][0]->level);
		self::assertSame('File paths regenerated successfully', $data['message']);
	}

	private function createController(
		?LibraryMapper $libraryMapper = null,
		?SongMapper $songMapper = null,
		?IRootFolder $rootFolder = null,
		?FileManager $fileManager = null,
		?IRequest $request = null,
		bool $loggedIn = true,
	): ApiController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($loggedIn ? $user : null);

		return new ApiController(
			Application::APP_ID,
			$request ?? $this->createMock(IRequest::class),
			$libraryMapper ?? $this->createMock(LibraryMapper::class),
			$songMapper ?? $this->createMock(SongMapper::class),
			$rootFolder ?? $this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
			$userSession,
			$fileManager ?? $this->createMock(FileManager::class),
		);
	}
}
