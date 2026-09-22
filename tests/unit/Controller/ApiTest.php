<?php

declare(strict_types=1);

namespace Controller;

use OCA\MobileSheetsManager\AppInfo\Application;
use OCA\MobileSheetsManager\Controller\ApiController;
use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCA\MobileSheetsManager\Service\FileManager;
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
