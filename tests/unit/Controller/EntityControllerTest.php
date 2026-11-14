<?php

declare(strict_types=1);

namespace Controller;

use OCA\SheetMusic\Controller\EntityController;
use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Service\EntityService;
use OCA\SheetMusic\Service\LibraryService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\FakeUserSession;
use TestSupport\RealMobileSheetsTestTrait;

final class EntityControllerTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private int $libraryId;
	private IUser $user;
	private FakeUserSession $userSession;
	private EntityService $entityService;
	private EntityController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$library = $this->createTestLibrary('EntityController Test Lib');
		$this->libraryId = (int)$library->id;
		$this->user = $this->userManager->get($library->userId);

		$logger = new NullLogger();
		$libraryService = new LibraryService($this->libraryMapper, $this->rootFolder, $logger, exporter: $this->exporter);
		$this->entityService = new EntityService(
			$libraryService,
			$logger,
			$this->entityMapper,
			$this->songMapper,
			$this->libraryMapper,
		);

		$this->userSession = new FakeUserSession($this->user);
		$request = \OC::$server->get(IRequest::class);

		$this->controller = new EntityController(
			'sheetmusic',
			$request,
			$this->entityService,
			$this->userSession,
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testCreateEndpointsValidateParams(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->createComposer(null, 'Bach')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->createComposer($this->libraryId, '  ')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->createBook($this->libraryId, null)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->createCollection(null, 'Jazz')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->createArtist($this->libraryId, '')->getStatus());
	}

	public function testCreateComposer(): void {
		$res = $this->controller->createComposer($this->libraryId, 'Bach');
		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
		$data = $res->getData();
		$this->assertArrayHasKey('id', $data);
		$this->assertSame('Bach', $data['name']);

		$composers = $this->entityMapper->findAllForLibrary($this->libraryId, 'composer');
		$this->assertCount(1, $composers);
		$this->assertSame('Bach', $composers[0]->name);
	}

	public function testCreateAndDeleteArranger(): void {
		$res = $this->controller->createArranger($this->libraryId, 'Quincy Jones');
		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
		$data = $res->getData();
		$this->assertArrayHasKey('id', $data);
		$this->assertSame('Quincy Jones', $data['name']);

		$arrangers = $this->entityMapper->findAllForLibrary($this->libraryId, 'arranger');
		$this->assertCount(1, $arrangers);
		$this->assertSame('Quincy Jones', $arrangers[0]->name);

		$delRes = $this->controller->deleteArranger($arrangers[0]->id, $this->libraryId);
		$this->assertSame(Http::STATUS_OK, $delRes->getStatus());

		$this->assertEmpty($this->entityMapper->findAllForLibrary($this->libraryId, 'arranger'));
	}

	public function testDeleteEndpointsValidateParamsAndDeletes(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->deleteComposer(10, null)->getStatus());

		$composer = $this->entityMapper->insert(new InternalEntity(
			uuid: 'composer-del-uuid',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Beethoven',
		));

		$res = $this->controller->deleteComposer($composer->id, $this->libraryId);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());

		$composers = $this->entityMapper->findAllForLibrary($this->libraryId, 'composer');
		$this->assertEmpty($composers);
	}

	public function testMergeEntitiesValidatesParams(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->mergeEntities(null, null, null, null)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->mergeEntities($this->libraryId, 'composers', 5, [5])->getStatus()); // duplicateIds == keepId
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->mergeEntities($this->libraryId, '', 5, [6])->getStatus());
	}

	public function testMergeEntities(): void {
		$c1 = $this->entityMapper->insert(new InternalEntity(
			uuid: 'merge-c1',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Mozart',
		));
		$c2 = $this->entityMapper->insert(new InternalEntity(
			uuid: 'merge-c2',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'W. A. Mozart',
		));

		$res = $this->controller->mergeEntities($this->libraryId, 'composers', $c1->id, [$c2->id]);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$data = $res->getData();
		$this->assertSame(1, $data['mergedCount']);

		$composers = $this->entityMapper->findAllForLibrary($this->libraryId, 'composer');
		$this->assertCount(1, $composers);
		$this->assertSame('Mozart', $composers[0]->name);
	}

	public function testUpdateEntityValidatesParamsHandlesNotFoundConflictAndRenames(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->updateEntity(0, $this->libraryId, 'collections', 'Jazz')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->updateEntity(1, null, 'collections', 'Jazz')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->updateEntity(1, $this->libraryId, '', 'Jazz')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->updateEntity(1, $this->libraryId, 'collections', '  ')->getStatus());

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->updateEntity(999999, $this->libraryId, 'collections', 'Jazz')->getStatus());

		$col1 = $this->entityMapper->insert(new InternalEntity(
			uuid: 'upd-col-1',
			libraryId: $this->libraryId,
			type: 'collection',
			name: 'Baroque',
		));
		$col2 = $this->entityMapper->insert(new InternalEntity(
			uuid: 'upd-col-2',
			libraryId: $this->libraryId,
			type: 'collection',
			name: 'Classical',
		));

		// Duplicate name -> 409 Conflict
		$conflictRes = $this->controller->updateEntity($col1->id, $this->libraryId, 'collections', 'Classical');
		$this->assertSame(Http::STATUS_CONFLICT, $conflictRes->getStatus());
		$this->assertArrayHasKey('error', $conflictRes->getData());

		// Rename collection -> 200 OK
		$okRes = $this->controller->updateEntity($col1->id, $this->libraryId, 'collections', 'Early Baroque');
		$this->assertSame(Http::STATUS_OK, $okRes->getStatus());
		$reloaded = $this->entityMapper->findById($col1->id);
		$this->assertSame('Early Baroque', $reloaded?->name);

		// Rename book via $title param -> 200 OK
		$book = $this->entityMapper->insert(new InternalEntity(
			uuid: 'upd-book-1',
			libraryId: $this->libraryId,
			type: 'book',
			name: 'Old Real Book',
		));
		$bookRes = $this->controller->updateEntity($book->id, $this->libraryId, 'books', null, 'New Real Book');
		$this->assertSame(Http::STATUS_OK, $bookRes->getStatus());
		$this->assertSame('New Real Book', $this->entityMapper->findById($book->id)?->name);
	}
}
