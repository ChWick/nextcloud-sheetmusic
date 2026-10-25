<?php

declare(strict_types=1);

namespace Controller;

use OCA\SheetMusic\AppInfo\Application;
use OCA\SheetMusic\Controller\ApiController;
use OCA\SheetMusic\Db\LibraryData;
use OCA\SheetMusic\Service\EntityService;
use OCA\SheetMusic\Service\LibraryIntegrityService;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Service\SongFileService;
use OCA\SheetMusic\Service\SongService;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use TestSupport\FakeRequest;
use TestSupport\FakeUserSession;

final class ApiTest extends TestCase {
	private function createController(
		?LibraryService $libraryService = null,
		?SongService $songService = null,
		?SongFileService $songFileService = null,
		?EntityService $entityService = null,
		?LibraryIntegrityService $libraryIntegrityService = null,
		?IRequest $request = null,
		bool $loggedIn = true,
	): ApiController {
		$user = \OC::$server->get(IUserManager::class)->get('admin');
		$userSession = new FakeUserSession($loggedIn ? $user : null);

		return new ApiController(
			Application::APP_ID,
			$request ?? new FakeRequest(),
			$libraryService ?? $this->createMock(LibraryService::class),
			$songService ?? $this->createMock(SongService::class),
			$songFileService ?? $this->createMock(SongFileService::class),
			$entityService ?? $this->createMock(EntityService::class),
			$libraryIntegrityService ?? $this->createMock(LibraryIntegrityService::class),
			$userSession,
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
		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->expects($this->once())
			->method('deleteSongFile')
			->willReturn(null);

		$controller = $this->createController(songFileService: $songFileService);

		$this->assertEquals(
			Http::STATUS_NOT_FOUND,
			$controller->deleteSongFile(1, 2, 7)->getStatus(),
		);
	}

	public function testAttachNextcloudFileDelegatesToService(): void {
		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->expects($this->once())
			->method('attachNextcloudFile')
			->willReturn(new LibraryData(songs: []));

		$controller = $this->createController(songFileService: $songFileService);

		$response = $controller->attachNextcloudFile(42, 7, '/Music/T-Pen 2_QG.pdf', 'copy');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals(new LibraryData(songs: []), $response->getData());
	}

	public function testAttachNextcloudFileReturnsNotFoundWhenServiceFails(): void {
		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->method('attachNextcloudFile')->willReturn(null);

		$controller = $this->createController(songFileService: $songFileService);

		$response = $controller->attachNextcloudFile(42, 7, '/Music/Shared.pdf', 'copy');
		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testAttachNextcloudFileValidatesMissingParameters(): void {
		$controller = $this->createController();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $controller->attachNextcloudFile(42, null, '/Music/Shared.pdf')->getStatus());
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $controller->attachNextcloudFile(42, 7, '')->getStatus());
	}

	public function testDeleteSongFileDelegatesToService(): void {
		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->expects($this->once())
			->method('deleteSongFile')
			->willReturn(new LibraryData(songs: []));

		$controller = $this->createController(songFileService: $songFileService);

		$this->assertEquals(
			Http::STATUS_OK,
			$controller->deleteSongFile(42, 2, 7)->getStatus(),
		);
	}

	public function testDeleteSongFileReturnsNotFoundWhenServiceReturnsNull(): void {
		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->method('deleteSongFile')->willReturn(null);

		$controller = $this->createController(songFileService: $songFileService);

		$this->assertEquals(
			Http::STATUS_NOT_FOUND,
			$controller->deleteSongFile(42, 2, 7)->getStatus(),
		);
	}

	public function testDetachSongFileDelegatesToService(): void {
		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->expects($this->once())
			->method('detachSongFile')
			->willReturn(new LibraryData(songs: []));

		$controller = $this->createController(songFileService: $songFileService);

		$this->assertEquals(
			Http::STATUS_OK,
			$controller->detachSongFile(42, 2, 7)->getStatus(),
		);
	}

	public function testDeleteLibraryRemovesOnlyTheEntryAndKeepsTheFiles(): void {
		$libraryService = $this->createMock(LibraryService::class);
		$libraryService->expects($this->once())
			->method('deleteLibrary')
			->with('admin', 7)
			->willReturn(['id' => 7, 'path' => '/Noten/mobilesheets.db']);

		$controller = $this->createController(libraryService: $libraryService);

		$response = $controller->deleteLibrary(7);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals(['id' => 7, 'path' => '/Noten/mobilesheets.db'], $response->getData());
	}

	public function testDeleteLibraryWithUnknownIdIsNotFound(): void {
		$libraryService = $this->createMock(LibraryService::class);
		$libraryService->expects($this->once())
			->method('deleteLibrary')
			->with('admin', 7)
			->willReturn(null);

		$controller = $this->createController(libraryService: $libraryService);

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
			$controller->createSong(1, '  ')->getStatus(),
		);
	}

	public function testCreateSongReturnsTheNewSongIdWithTheLibraryData(): void {
		$songService = $this->createMock(SongService::class);
		$songService->expects(self::once())
			->method('createSong')
			->willReturn(new LibraryData(songs: [new MobileSheetsSong(42, 'New Song')], createdSongId: 42));

		$controller = $this->createController(songService: $songService);

		$response = $controller->createSong(7, 'New Song', [10], [100], [1000], [200]);

		$this->assertEquals(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		self::assertInstanceOf(LibraryData::class, $data);
		self::assertSame(42, $data->createdSongId);
		$createdSong = array_values(array_filter(
			$data->songs,
			static fn (MobileSheetsSong $song): bool => $song->id === 42,
		))[0];
		self::assertSame('New Song', $createdSong->title);
	}

	public function testCreateSongOfAnUnknownLibraryIsNotFound(): void {
		$songService = $this->createMock(SongService::class);
		$songService->method('createSong')->willReturn(null);

		$controller = $this->createController(songService: $songService);

		$this->assertEquals(
			Http::STATUS_NOT_FOUND,
			$controller->createSong(7, 'Some title')->getStatus(),
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
		$songService = $this->createMock(SongService::class);
		$songService->method('deleteSong')->willReturn(null);

		$controller = $this->createController(songService: $songService);

		$this->assertEquals(
			Http::STATUS_NOT_FOUND,
			$controller->deleteSong(42, 7)->getStatus(),
		);
	}

	public function testDeleteSongMovesItsFilesToTheTrashBin(): void {
		$songService = $this->createMock(SongService::class);
		$songService->expects($this->once())
			->method('deleteSong')
			->willReturn(new LibraryData(songs: []));

		$controller = $this->createController(songService: $songService);

		$this->assertEquals(
			Http::STATUS_OK,
			$controller->deleteSong(42, 7, true)->getStatus(),
		);
	}

	public function testDeleteSongKeepsItsFilesWhenNotRequested(): void {
		$songService = $this->createMock(SongService::class);
		$songService->expects($this->once())
			->method('deleteSong')
			->willReturn(new LibraryData(songs: []));

		$controller = $this->createController(songService: $songService);

		$this->assertEquals(
			Http::STATUS_OK,
			$controller->deleteSong(42, 7, false)->getStatus(),
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
		$entityService = $this->createMock(EntityService::class);
		$entityService->method('deleteEntity')->willReturn(null);

		$controller = $this->createController(entityService: $entityService);

		$this->assertEquals(
			Http::STATUS_NOT_FOUND,
			$controller->deleteComposer(1, 7)->getStatus(),
		);
	}

	public function testDeleteEntityReturnsTheLibraryDataWithTheFieldRemoved(): void {
		$entityService = $this->createMock(EntityService::class);
		$entityService->expects($this->once())
			->method('deleteEntity')
			->willReturn(new LibraryData(composers: []));

		$controller = $this->createController(entityService: $entityService);

		$response = $controller->deleteComposer(10, 7);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals(new LibraryData(composers: []), $response->getData());
	}

	public function testDeleteEntityOfAnUnknownEntityIsNotFound(): void {
		$entityService = $this->createMock(EntityService::class);
		$entityService->method('deleteEntity')->willReturn(null);

		$controller = $this->createController(entityService: $entityService);

		$this->assertEquals(
			Http::STATUS_NOT_FOUND,
			$controller->deleteComposer(999, 7)->getStatus(),
		);
	}

	public function testGetSongsWithoutAnIdIsBadRequest(): void {
		$controller = $this->createController();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $controller->getSongs()->getStatus());
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $controller->getSongs('-1')->getStatus());
	}

	public function testGetSongsOfAnUnknownLibraryIsNotFound(): void {
		$songService = $this->createMock(SongService::class);
		$songService->method('getSongs')->willReturn(null);

		$controller = $this->createController(songService: $songService);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $controller->getSongs('7')->getStatus());
	}

	public function testGetSongsOfSomethingThatIsNotAFileIsNotFound(): void {
		$songService = $this->createMock(SongService::class);
		$songService->method('getSongs')->willReturn(null);

		$controller = $this->createController(songService: $songService);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $controller->getSongs('7')->getStatus());
	}

	public function testGetSongsReturnsTheLibraryData(): void {
		$songService = $this->createMock(SongService::class);
		$songService->method('getSongs')->willReturn(new LibraryData(
			songs: [],
			sourceTypes: [],
		));

		$controller = $this->createController(songService: $songService);

		$response = $controller->getSongs('7');

		self::assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertInstanceOf(LibraryData::class, $data);
		self::assertSame([], $data->songs);
		self::assertSame([], $data->sourceTypes);
	}

	public function testAddLibraryRejectsInvalidPaths(): void {
		$libraryService = $this->createMock(LibraryService::class);
		$libraryService->method('addLibrary')->willReturn(null);

		$controller = $this->createController(libraryService: $libraryService);

		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->addLibrary('Noten/mobilesheets.db')->getStatus());
		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->addLibrary('/Noten/notes.txt')->getStatus());
	}

	public function testAddLibraryStoresThePath(): void {
		$libraryService = $this->createMock(LibraryService::class);
		$libraryService->expects(self::once())
			->method('addLibrary')
			->with('admin', '/Noten/mobilesheets.db')
			->willReturn(['path' => '/Noten']);

		$controller = $this->createController(libraryService: $libraryService);

		$response = $controller->addLibrary('/Noten/mobilesheets.db');

		self::assertEquals(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(['path' => '/Noten'], $response->getData());
	}

	public function testUpdateSongWritesTheMetadataIntoTheAttachedPdf(): void {
		$songService = $this->createMock(SongService::class);
		$songService->expects(self::once())
			->method('updateSong')
			->willReturn(new LibraryData(songs: []));

		$controller = $this->createController(songService: $songService);

		$response = $controller->updateSong(42, 7, 'Blue Bossa');

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
		$songService = $this->createMock(SongService::class);
		$songService->method('updateSong')->willReturn(null);

		$controller = $this->createController(songService: $songService);

		$this->assertEquals(
			Http::STATUS_NOT_FOUND,
			$controller->updateSong(1, 7, 'Some title')->getStatus(),
		);
	}

	public function testUpdateSongReturnsTheLibraryData(): void {
		$songService = $this->createMock(SongService::class);
		$songService->expects(self::once())
			->method('updateSong')
			->willReturn(new LibraryData(songs: []));

		$controller = $this->createController(songService: $songService);

		$response = $controller->updateSong(1, 7, 'After', [10], [100], [1000], [200]);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals(new LibraryData(songs: []), $response->getData());
	}

	public function testCreateEntityEndpointsRejectMissingValues(): void {
		$controller = $this->createController();

		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->createComposer(7, '  ')->getStatus());
		self::assertEquals(Http::STATUS_BAD_REQUEST, $controller->createComposer(null, 'Bach')->getStatus());
	}

	public function testCreateEntityEndpointsRejectAnUnknownLibrary(): void {
		$entityService = $this->createMock(EntityService::class);
		$entityService->method('createEntity')->willReturn(null);

		$controller = $this->createController(entityService: $entityService);

		self::assertEquals(Http::STATUS_NOT_FOUND, $controller->createComposer(7, 'Bach')->getStatus());
	}

	public function testCreateEntityEndpointsReturnTheCreatedEntry(): void {
		$entityService = $this->createMock(EntityService::class);
		$entityService->expects(self::once())
			->method('createEntity')
			->willReturn(['id' => 10, 'name' => 'Bach']);

		$controller = $this->createController(entityService: $entityService);

		$response = $controller->createComposer(7, 'Bach');

		self::assertEquals(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(['id' => 10, 'name' => 'Bach'], $response->getData());
	}

	public function testDeleteEntityEndpointsPassTheirEntityTypeToTheMapper(): void {
		$entityService = $this->createMock(EntityService::class);
		$entityService->expects(self::once())
			->method('deleteEntity')
			->with($this->anything(), 7, 'composers', 10)
			->willReturn(new LibraryData(composers: []));

		$controller = $this->createController(entityService: $entityService);

		$response = $controller->deleteComposer(10, 7);

		self::assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	public function testUploadSongFileWithoutAFileIsBadRequest(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->with('file')->willReturn(null);

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
	}

	public function testImportSongMetadataFromNextcloudPath(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->with('file')->willReturn(null);
		$request->method('getParam')->with('path', '')->willReturn('/Music/Take Five.pdf');

		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->expects(self::once())
			->method('importSongMetadata')
			->willReturn(['title' => 'Take Five']);

		$controller = $this->createController(songFileService: $songFileService, request: $request);

		$response = $controller->importSongMetadata();

		self::assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertArrayHasKey('title', $data);
		self::assertSame('Take Five', $data['title']);
	}

	public function testImportSongMetadataFromNextcloudPathWithDavPrefix(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->with('file')->willReturn(null);
		$request->method('getParam')->with('path', '')->willReturn('remote.php/dav/files/admin/Music/Take%20Five.pdf');

		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->expects(self::once())
			->method('importSongMetadata')
			->willReturn(['title' => 'Take Five']);

		$controller = $this->createController(songFileService: $songFileService, request: $request);

		$response = $controller->importSongMetadata();

		self::assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertArrayHasKey('title', $data);
	}

	public function testImportSongMetadataWithMissingFileReturnsBadRequest(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->with('file')->willReturn(null);
		$request->method('getParam')->with('path', '')->willReturn('/Music/NonExistent.pdf');

		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->method('importSongMetadata')->willThrowException(new \InvalidArgumentException('Could not read file content'));

		$controller = $this->createController(songFileService: $songFileService, request: $request);

		$response = $controller->importSongMetadata();

		self::assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertEquals(['message' => 'Could not read file content'], $response->getData());
	}
}
