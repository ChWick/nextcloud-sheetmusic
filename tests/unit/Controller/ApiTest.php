<?php

declare(strict_types=1);

namespace Controller;

use OCA\SheetMusic\AppInfo\Application;
use OCA\SheetMusic\Controller\ApiController;
use OCA\SheetMusic\Db\LibraryData;
use OCA\SheetMusic\Service\EntityService;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\LibraryIntegrityService;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Service\SetlistService;
use OCA\SheetMusic\Service\SongFileService;
use OCA\SheetMusic\Service\SongService;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\FakeRequest;
use TestSupport\FakeUserSession;
use TestSupport\RealMobileSheetsTestTrait;
use TestSupport\TestUtils;

final class ApiTest extends TestCase {
	use RealMobileSheetsTestTrait;
	use TestUtils;

	private IUser $user;
	private FakeUserSession $userSession;
	private LibraryService $libraryService;
	private FileManager $fileManager;
	private LibraryIntegrityService $libraryIntegrityService;
	private EntityService $entityService;
	private SongFileService $songFileService;
	private SongService $songService;
	private SetlistService $setlistService;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$this->user = $this->userManager->get('admin');
		$this->userSession = new FakeUserSession($this->user);

		$logger = new NullLogger();
		$this->libraryService = new LibraryService($this->libraryMapper, $this->rootFolder, $logger, exporter: $this->exporter);
		$this->fileManager = new FileManager(
			$this->libraryMapper,
			$this->rootFolder,
			$this->pathResolver,
			$logger,
			$this->libraryService,
			$this->songFileMapper,
			$this->songMapper,
		);
		$this->libraryIntegrityService = $this->createLibraryIntegrityService($this->libraryService);
		$this->entityService = new EntityService($this->libraryService, $logger, $this->entityMapper, $this->songMapper, $this->libraryMapper);
		$this->songFileService = new SongFileService(
			$this->libraryService,
			$this->fileManager,
			$this->rootFolder,
			$logger,
			$this->songFileMapper,
			$this->songMapper,
			$this->libraryMapper,
		);
		$this->songService = new SongService(
			$this->libraryService,
			$this->songFileService,
			$this->fileManager,
			$logger,
			$this->songMapper,
			$this->entityMapper,
			$this->songFileMapper,
			$this->libraryMapper,
			$this->rootFolder,
			$this->libraryIntegrityService,
			null,
			$this->bookmarkMapper,
		);
		$this->setlistService = new SetlistService(
			$this->libraryMapper,
			$this->entityMapper,
			$this->setlistItemMapper,
			$this->songMapper,
			$this->bookmarkMapper,
			$logger,
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	private function createController(
		?IRequest $request = null,
		bool $loggedIn = true,
	): ApiController {
		return new ApiController(
			Application::APP_ID,
			$request ?? new FakeRequest(),
			$this->libraryService,
			$this->songService,
			$this->songFileService,
			$this->entityService,
			$this->libraryIntegrityService,
			$loggedIn ? $this->userSession : new FakeUserSession(null),
			null,
			$this->setlistService,
		);
	}

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
		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_NOT_FOUND,
			$controller->deleteSongFile(1, 2, 9999)->getStatus(),
		);
	}

	public function testAttachNextcloudFileDelegatesToService(): void {
		$library = $this->createTestLibrary('Noten/mobilesheets.db');
		$song = $this->insertTestSong($library->id, 'Test Song');
		$userFolder = $this->rootFolder->getUserFolder('admin');
		if (!$userFolder->nodeExists('Noten')) {
			$userFolder->newFolder('Noten');
		}
		if (!$userFolder->nodeExists('Music')) {
			$userFolder->newFolder('Music');
		}
		/** @var Folder $musicFolder */
		$musicFolder = $userFolder->get('Music');
		if ($musicFolder->nodeExists('T-Pen 2_QG.pdf')) {
			$musicFolder->get('T-Pen 2_QG.pdf')->delete();
		}
		$pdf = $musicFolder->newFile('T-Pen 2_QG.pdf');
		$pdf->putContent(self::minimalPdf());

		$controller = $this->createController();

		$response = $controller->attachNextcloudFile($song->id, $library->id, '/Music/T-Pen 2_QG.pdf', 'copy');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertInstanceOf(LibraryData::class, $response->getData());
	}

	public function testAttachNextcloudFileReturnsNotFoundWhenServiceFails(): void {
		$controller = $this->createController();

		$response = $controller->attachNextcloudFile(42, 9999, '/Music/Shared.pdf', 'copy');
		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testAttachNextcloudFileValidatesMissingParameters(): void {
		$controller = $this->createController();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $controller->attachNextcloudFile(42, null, '/Music/Shared.pdf')->getStatus());
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $controller->attachNextcloudFile(42, 7, '')->getStatus());
	}

	public function testDeleteSongFileDelegatesToService(): void {
		$library = $this->createTestLibrary('Noten/mobilesheets.db');
		$song = $this->insertTestSong($library->id, 'Test Song');
		$file = $this->attachTestFileToSong($song);

		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_OK,
			$controller->deleteSongFile($song->id, $file->id, $library->id)->getStatus(),
		);
	}

	public function testDeleteSongFileReturnsNotFoundWhenServiceReturnsNull(): void {
		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_NOT_FOUND,
			$controller->deleteSongFile(42, 2, 9999)->getStatus(),
		);
	}

	public function testDetachSongFileDelegatesToService(): void {
		$library = $this->createTestLibrary('Noten/mobilesheets.db');
		$song = $this->insertTestSong($library->id, 'Test Song');
		$file = $this->attachTestFileToSong($song);

		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_OK,
			$controller->detachSongFile($song->id, $file->id, $library->id)->getStatus(),
		);
	}

	public function testDeleteLibraryRemovesOnlyTheEntryAndKeepsTheFiles(): void {
		$library = $this->createTestLibrary('Noten/mobilesheets.db');
		$controller = $this->createController();

		$response = $controller->deleteLibrary($library->id);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals(['id' => $library->id, 'path' => $library->path], $response->getData());
		$this->assertNull($this->libraryMapper->findById($library->id));
	}

	public function testDeleteLibraryWithUnknownIdIsNotFound(): void {
		$controller = $this->createController();

		$this->assertEquals(Http::STATUS_NOT_FOUND, $controller->deleteLibrary(9999)->getStatus());
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
			$controller->createSong(1, '  ')->getStatus(),
		);
	}

	public function testCreateSongReturnsTheNewSongIdWithTheLibraryData(): void {
		$library = $this->createTestLibrary('Noten/mobilesheets.db');
		$controller = $this->createController();

		$response = $controller->createSong($library->id, 'New Song');

		$this->assertEquals(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		self::assertInstanceOf(LibraryData::class, $data);
		self::assertNotNull($data->createdSongId);
		$createdSong = array_values(array_filter(
			$data->songs,
			static fn (MobileSheetsSong $song): bool => $song->id === $data->createdSongId,
		))[0];
		self::assertSame('New Song', $createdSong->title);
	}

	public function testCreateSongOfAnUnknownLibraryIsNotFound(): void {
		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_NOT_FOUND,
			$controller->createSong(9999, 'Some title')->getStatus(),
		);
	}

	public function testDeleteSongWithoutLibraryIdIsBadRequest(): void {
		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_BAD_REQUEST,
			$controller->deleteSong(1, null)->getStatus(),
		);
	}

	public function testDeleteSongOfAnUnknownSongIsNotFound(): void {
		$library = $this->createTestLibrary('Noten/mobilesheets.db');
		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_NOT_FOUND,
			$controller->deleteSong(9999, $library->id)->getStatus(),
		);
	}

	public function testDeleteSongMovesItsFilesToTheTrashBin(): void {
		$library = $this->createTestLibrary('Noten/mobilesheets.db');
		$song = $this->insertTestSong($library->id, 'Song To Trash');
		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_OK,
			$controller->deleteSong($song->id, $library->id, true)->getStatus(),
		);
	}

	public function testDeleteSongKeepsItsFilesWhenNotRequested(): void {
		$library = $this->createTestLibrary('Noten/mobilesheets.db');
		$song = $this->insertTestSong($library->id, 'Song To Retain Files');
		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_OK,
			$controller->deleteSong($song->id, $library->id, false)->getStatus(),
		);
	}

	public function testDeleteEntityWithoutLibraryIdIsBadRequest(): void {
		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_BAD_REQUEST,
			$controller->deleteComposer(1, null)->getStatus(),
		);
	}

	public function testDeleteEntityResolvesTheLibraryById(): void {
		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_NOT_FOUND,
			$controller->deleteComposer(1, 9999)->getStatus(),
		);
	}

	public function testDeleteEntityReturnsTheLibraryDataWithTheFieldRemoved(): void {
		$library = $this->createTestLibrary('Noten/mobilesheets.db');
		$composer = $this->insertTestEntity($library->id, 'composers', 'Bach');
		$controller = $this->createController();

		$response = $controller->deleteComposer($composer->id, $library->id);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertInstanceOf(LibraryData::class, $response->getData());
	}

	public function testDeleteEntityOfAnUnknownEntityIsNotFound(): void {
		$library = $this->createTestLibrary('Noten/mobilesheets.db');
		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_NOT_FOUND,
			$controller->deleteComposer(999, $library->id)->getStatus(),
		);
	}

	public function testGetSongsWithoutAnIdIsBadRequest(): void {
		$controller = $this->createController();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $controller->getSongs()->getStatus());
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $controller->getSongs('-1')->getStatus());
	}

	public function testGetSongsOfAnUnknownLibraryIsNotFound(): void {
		$controller = $this->createController();

		$this->assertEquals(Http::STATUS_NOT_FOUND, $controller->getSongs('9999')->getStatus());
	}

	public function testGetSongsOfSomethingThatIsNotAFileIsNotFound(): void {
		$controller = $this->createController();

		$this->assertEquals(Http::STATUS_NOT_FOUND, $controller->getSongs('9999')->getStatus());
	}

	public function testGetSongsReturnsTheLibraryData(): void {
		$library = $this->createTestLibrary('Noten/mobilesheets.db');
		$controller = $this->createController();

		$response = $controller->getSongs((string)$library->id);

		self::assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertInstanceOf(LibraryData::class, $data);
		self::assertSame([], $data->songs);
	}

	public function testAddLibraryRejectsInvalidPaths(): void {
		$controller = $this->createController();

		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->addLibrary('Noten/mobilesheets.db')->getStatus());
		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->addLibrary('/Noten/notes.txt')->getStatus());
	}

	public function testAddLibraryStoresThePath(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		$folderName = 'NewApiFolder_' . bin2hex(random_bytes(3));
		if (!$userFolder->nodeExists($folderName)) {
			$this->createdUserNodes[] = $userFolder->newFolder($folderName);
		}

		$controller = $this->createController();

		$response = $controller->addLibrary('/' . $folderName . '/mobilesheets.db');

		self::assertEquals(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('/' . $folderName, $response->getData()['path']);
	}

	public function testUpdateSongWritesTheMetadataIntoTheAttachedPdf(): void {
		$library = $this->createTestLibrary('Noten/mobilesheets.db');
		$song = $this->insertTestSong($library->id, 'Blue Bossa');
		$controller = $this->createController();

		$response = $controller->updateSong($song->id, $library->id, 'Blue Bossa');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateSongWithoutATitleIsBadRequest(): void {
		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_BAD_REQUEST,
			$controller->updateSong(1, 7, '  ')->getStatus(),
		);
	}

	public function testUpdateSongOfAnUnknownLibraryIsNotFound(): void {
		$controller = $this->createController();

		$this->assertEquals(
			Http::STATUS_NOT_FOUND,
			$controller->updateSong(1, 9999, 'Some title')->getStatus(),
		);
	}

	public function testUpdateSongReturnsTheLibraryData(): void {
		$library = $this->createTestLibrary('Noten/mobilesheets.db');
		$song = $this->insertTestSong($library->id, 'Before');
		$controller = $this->createController();

		$response = $controller->updateSong($song->id, $library->id, 'After');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertInstanceOf(LibraryData::class, $response->getData());
	}

	public function testCreateEntityEndpointsRejectMissingValues(): void {
		$controller = $this->createController();

		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->createComposer(7, '  ')->getStatus());
		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->createComposer(null, 'Bach')->getStatus());
	}

	public function testCreateEntityEndpointsRejectAnUnknownLibrary(): void {
		$controller = $this->createController();

		self::assertEquals(Http::STATUS_NOT_FOUND, $controller->createComposer(9999, 'Bach')->getStatus());
	}

	public function testCreateEntityEndpointsReturnTheCreatedEntry(): void {
		$library = $this->createTestLibrary('Noten/mobilesheets.db');
		$controller = $this->createController();

		$response = $controller->createComposer($library->id, 'Bach');

		self::assertEquals(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('Bach', $response->getData()['name']);
	}

	public function testDeleteEntityEndpointsPassTheirEntityTypeToTheMapper(): void {
		$library = $this->createTestLibrary('Noten/mobilesheets.db');
		$composer = $this->insertTestEntity($library->id, 'composers', 'Bach');
		$controller = $this->createController();

		$response = $controller->deleteComposer($composer->id, $library->id);

		self::assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	public function testUploadSongFileWithoutAFileIsBadRequest(): void {
		$request = new FakeRequest();

		$controller = $this->createController(request: $request);

		$this->assertEquals(
			Http::STATUS_BAD_REQUEST,
			$controller->uploadSongFile(1, 7)->getStatus(),
		);
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
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->createSignature(7, '4/4')->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->deleteComposer(1, 7)->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->deleteBook(1, 7)->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->deleteCollection(1, 7)->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->deleteArtist(1, 7)->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->deleteSignature(1, 7)->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->importSongMetadata()->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->getSongHistory(1)->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->revertSongHistory(1, 1)->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->getDeletedSongs(1)->getStatus());
		self::assertEquals(Http::STATUS_UNAUTHORIZED, $controller->restoreDeletedSong(1)->getStatus());
	}

	public function testImportSongMetadataFromNextcloudPath(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		if (!$userFolder->nodeExists('Noten')) {
			$userFolder->newFolder('Noten');
		}
		if (!$userFolder->nodeExists('Music')) {
			$userFolder->newFolder('Music');
		}
		/** @var Folder $musicFolder */
		$musicFolder = $userFolder->get('Music');
		if ($musicFolder->nodeExists('Take Five.pdf')) {
			$musicFolder->get('Take Five.pdf')->delete();
		}
		$pdf = $musicFolder->newFile('Take Five.pdf');
		$pdf->putContent(self::minimalPdf());

		$request = new FakeRequest(params: ['path' => '/Music/Take Five.pdf']);

		$controller = $this->createController(request: $request);

		$response = $controller->importSongMetadata();

		self::assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertArrayHasKey('title', $data);
		self::assertSame('Take Five', $data['title']);
	}

	public function testImportSongMetadataFromNextcloudPathWithDavPrefix(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		if (!$userFolder->nodeExists('Noten')) {
			$userFolder->newFolder('Noten');
		}
		if (!$userFolder->nodeExists('Music')) {
			$userFolder->newFolder('Music');
		}
		/** @var Folder $musicFolder */
		$musicFolder = $userFolder->get('Music');
		if ($musicFolder->nodeExists('Take Five.pdf')) {
			$musicFolder->get('Take Five.pdf')->delete();
		}
		$pdf = $musicFolder->newFile('Take Five.pdf');
		$pdf->putContent(self::minimalPdf());

		$request = new FakeRequest(params: ['path' => 'remote.php/dav/files/admin/Music/Take%20Five.pdf']);

		$controller = $this->createController(request: $request);

		$response = $controller->importSongMetadata();

		self::assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertArrayHasKey('title', $data);
	}

	public function testImportSongMetadataWithMissingFileReturnsBadRequest(): void {
		$request = new FakeRequest(params: ['path' => '/Music/NonExistent.pdf']);

		$controller = $this->createController(request: $request);

		$response = $controller->importSongMetadata();

		self::assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertEquals(['message' => 'Could not read file content'], $response->getData());
	}

	public function testSetlistsApiEndpoints(): void {
		$lib = $this->createTestLibrary('ApiSetlistLib', 'admin');

		$controller = $this->createController();

		// 1. Create setlist
		$createReq = new FakeRequest(params: ['libraryId' => (int)$lib->id, 'name' => 'Live Set']);
		$controller = $this->createController(request: $createReq);
		$createRes = $controller->createSetlist();
		self::assertEquals(Http::STATUS_CREATED, $createRes->getStatus());
		$setlistData = $createRes->getData();
		self::assertSame('Live Set', $setlistData['name']);
		$setlistId = (int)$setlistData['id'];

		// 2. List setlists
		$listRes = $controller->getSetlists((int)$lib->id);
		self::assertEquals(Http::STATUS_OK, $listRes->getStatus());
		self::assertCount(1, $listRes->getData());

		// 3. Create song and add to setlist
		$song = $this->songMapper->insert(new \OCA\SheetMusic\Db\InternalSong(
			uuid: \OCA\SheetMusic\Db\InternalEntity::generateUuid(),
			libraryId: (int)$lib->id,
			title: 'Song 1',
		));

		$addItemReq = new FakeRequest(params: ['libraryId' => (int)$lib->id, 'songId' => (int)$song->id]);
		$controller = $this->createController(request: $addItemReq);
		$addItemRes = $controller->addSetlistItem($setlistId);
		self::assertEquals(Http::STATUS_CREATED, $addItemRes->getStatus());
		$itemData = $addItemRes->getData();
		$itemId = (int)$itemData->id;

		// 4. Get setlist details
		$getRes = $controller->getSetlist((int)$lib->id, $setlistId);
		self::assertEquals(Http::STATUS_OK, $getRes->getStatus());
		self::assertCount(1, $getRes->getData()['items']);

		// 4b. Create bookmark and update item inline
		$bm = $this->bookmarkMapper->insert(new \OCA\SheetMusic\Db\InternalSongBookmark(
			id: null,
			songId: (int)$song->id,
			name: 'Movement I',
			pageNum: 1,
		));
		$updateItemReq = new FakeRequest(params: ['libraryId' => (int)$lib->id, 'bookmarkId' => (int)$bm->id]);
		$controller = $this->createController(request: $updateItemReq);
		$updateItemRes = $controller->updateSetlistItem($setlistId, $itemId);
		self::assertEquals(Http::STATUS_OK, $updateItemRes->getStatus());
		self::assertSame((int)$bm->id, $updateItemRes->getData()->bookmarkId);

		// 5. Remove item
		$removeReq = new FakeRequest(params: ['libraryId' => (int)$lib->id]);
		$controller = $this->createController(request: $removeReq);
		$removeRes = $controller->removeSetlistItem($setlistId, $itemId);
		self::assertEquals(Http::STATUS_OK, $removeRes->getStatus());

		// 6. Delete setlist
		$delReq = new FakeRequest(params: ['libraryId' => (int)$lib->id]);
		$controller = $this->createController(request: $delReq);
		$delRes = $controller->deleteSetlist($setlistId);
		self::assertEquals(Http::STATUS_OK, $delRes->getStatus());
	}
}
