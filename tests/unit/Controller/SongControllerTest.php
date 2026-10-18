<?php

declare(strict_types=1);

namespace Controller;

use OCA\SheetMusic\Controller\SongController;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\LibraryData;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Service\SongFileService;
use OCA\SheetMusic\Service\SongService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\FakeUserSession;
use TestSupport\RealMobileSheetsTestTrait;

final class SongControllerTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private int $libraryId;
	private IUser $user;
	private FakeUserSession $userSession;
	private SongService $songService;
	private SongController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$library = $this->createTestLibrary('SongController Test Lib');
		$this->libraryId = (int)$library->id;
		$this->user = $this->userManager->get($library->userId);

		$logger = new NullLogger();
		$libraryService = new LibraryService($this->libraryMapper, $this->rootFolder, $logger, exporter: $this->exporter);
		$fileManager = new FileManager(
			$this->libraryMapper,
			$this->rootFolder,
			$this->pathResolver,
			$logger,
			$libraryService,
			$this->songFileMapper,
			$this->songMapper,
		);
		$songFileService = new SongFileService(
			$libraryService,
			$fileManager,
			$this->rootFolder,
			$logger,
			$this->songFileMapper,
			$this->songMapper,
			$this->libraryMapper,
		);
		$integrityService = $this->createLibraryIntegrityService($libraryService);
		$this->songService = new SongService(
			$libraryService,
			$songFileService,
			$fileManager,
			$logger,
			$this->songMapper,
			$this->entityMapper,
			$this->songFileMapper,
			$this->libraryMapper,
			$this->rootFolder,
			$integrityService,
		);

		$this->userSession = new FakeUserSession($this->user);
		$request = \OC::$server->get(IRequest::class);

		$this->controller = new SongController(
			'sheetmusic',
			$request,
			$this->songService,
			$this->userSession,
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testGetSongsValidatesIdAndAuth(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->getSongs(null)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->getSongs('-1')->getStatus());

		$this->userSession->setUser(null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->getSongs((string)$this->libraryId)->getStatus());
	}

	public function testGetSongsReturnsData(): void {
		$this->songMapper->insert(new InternalSong(
			uuid: 'uuid-autumn',
			libraryId: $this->libraryId,
			title: 'Autumn Leaves',
		));

		$res = $this->controller->getSongs((string)$this->libraryId);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$data = $res->getData();
		$this->assertInstanceOf(LibraryData::class, $data);
		$this->assertCount(1, $data->songs);
		$this->assertSame('Autumn Leaves', $data->songs[0]->title);
	}

	public function testCreateSongValidatesParametersAndCreatesSong(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->createSong(null, 'Title')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->createSong($this->libraryId, '  ')->getStatus());

		$this->userSession->setUser(null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->createSong($this->libraryId, 'Title')->getStatus());
		$this->userSession->setUser($this->user);

		$res = $this->controller->createSong($this->libraryId, 'Blue in Green');
		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
		$data = $res->getData();
		$this->assertInstanceOf(LibraryData::class, $data);
		$this->assertNotNull($data->createdSongId);
		$this->assertSame('Blue in Green', $data->songs[0]->title);
	}

	public function testDeleteSongValidatesParametersAndDeletesSong(): void {
		$song = $this->songMapper->insert(new InternalSong(
			uuid: 'uuid-to-delete',
			libraryId: $this->libraryId,
			title: 'Song to Delete',
		));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->deleteSong($song->id, null)->getStatus());

		$res = $this->controller->deleteSong($song->id, $this->libraryId);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());

		$remaining = $this->songMapper->findAllForLibrary($this->libraryId);
		$this->assertEmpty($remaining);
	}

	public function testBatchDeleteSongs(): void {
		$song1 = $this->songMapper->insert(new InternalSong(
			uuid: 'uuid-batch-1',
			libraryId: $this->libraryId,
			title: 'Batch 1',
		));
		$song2 = $this->songMapper->insert(new InternalSong(
			uuid: 'uuid-batch-2',
			libraryId: $this->libraryId,
			title: 'Batch 2',
		));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->batchDeleteSongs(null, [$song1->id, $song2->id])->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->batchDeleteSongs($this->libraryId, [])->getStatus());

		$res = $this->controller->batchDeleteSongs($this->libraryId, [$song1->id, $song2->id]);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());

		$remaining = $this->songMapper->findAllForLibrary($this->libraryId);
		$this->assertEmpty($remaining);
	}
}
