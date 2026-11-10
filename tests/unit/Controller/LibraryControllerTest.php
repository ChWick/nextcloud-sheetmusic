<?php

declare(strict_types=1);

namespace Controller;

use OCA\SheetMusic\Controller\LibraryController;
use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\SublibraryMapper;
use OCA\SheetMusic\Service\IntegrityIssue;
use OCA\SheetMusic\Service\LibraryIntegrityService;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Service\LibraryShareService;
use OCA\SheetMusic\Service\SublibraryService;
use OCP\AppFramework\Http;
use OCP\Constants;
use OCP\IUser;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\FakeRequest;
use TestSupport\FakeUserSession;
use TestSupport\RealMobileSheetsTestTrait;

final class LibraryControllerTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private IUser $user;
	private FakeUserSession $userSession;
	private FakeRequest $request;
	private LibraryService $libraryService;
	private LibraryShareService $shareService;
	private IShareManager $shareManager;
	private LibraryController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$this->user = $this->userManager->get('admin');

		$logger = new NullLogger();
		$this->libraryService = new LibraryService($this->libraryMapper, $this->rootFolder, $logger, exporter: $this->exporter);
		$this->userSession = new FakeUserSession($this->user);
		$this->request = new FakeRequest();

		$sublibraryService = new SublibraryService(
			new SublibraryMapper($this->dbConn),
			$this->entityMapper,
			$this->dbConn,
		);

		$this->shareManager = $this->createMock(IShareManager::class);
		$this->shareService = new LibraryShareService(
			$this->libraryMapper,
			$this->rootFolder,
			$this->shareManager,
			$this->userManager,
			null,
		);

		$this->controller = new LibraryController(
			'sheetmusic',
			$this->request,
			$this->libraryService,
			$this->userSession,
			$this->createLibraryIntegrityService($this->libraryService),
			null,
			$sublibraryService,
			$this->shareService,
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testGetLibrariesRejectsUnauthenticated(): void {
		$this->userSession->setUser(null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->getLibraries()->getStatus());
	}

	public function testGetLibrariesReturnsLibraries(): void {
		$library = $this->createTestLibrary('My Custom Library');

		$response = $this->controller->getLibraries();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('libraries', $data);
		$paths = array_column($data['libraries'], 'path');
		$this->assertContains($library->path, $paths);
	}

	public function testAddLibraryHandlesBadRequestAndSuccess(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->addLibrary('invalid/path.db')->getStatus());

		$userFolder = $this->rootFolder->getUserFolder('admin');
		if (!$userFolder->nodeExists('NewFolder')) {
			$this->createdUserNodes[] = $userFolder->newFolder('NewFolder');
		}

		$okRes = $this->controller->addLibrary('/NewFolder/mobilesheets.db');
		$this->assertSame(Http::STATUS_CREATED, $okRes->getStatus());
		$this->assertSame(['path' => '/NewFolder'], $okRes->getData());
	}

	public function testDeleteLibraryHandlesNotFoundAndSuccess(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->deleteLibrary(99999)->getStatus());

		$library = $this->createTestLibrary('Lib to Delete');
		$response = $this->controller->deleteLibrary((int)$library->id);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$found = $this->libraryMapper->findForUser('admin', (int)$library->id);
		$this->assertNull($found);
	}

	public function testGetIntegrityIncludesIssueDetails(): void {
		$issue = new IntegrityIssue(
			type: IntegrityIssue::TYPE_DUPLICATE_FILE_PATH,
			severity: IntegrityIssue::SEVERITY_ERROR,
			message: 'Multiple database entries point to the same file path',
			songId: 60,
			songTitle: 'Nachspiel',
			fileId: 207,
			path: 'Noten/Nachspiel.pdf',
			suggestedPath: null,
			fixable: false,
			details: [
				'songIds' => [60, 61],
				'exactDuplicate' => false,
			],
		);

		$fakeIntegrity = new class([$issue]) extends LibraryIntegrityService {
			public function __construct(
				private array $issues,
			) {
			}
			public function checkIntegrity(int $libraryId, ?string $userId = null, bool $autoMatchOrphans = true): array {
				return $this->issues;
			}
		};

		$controller = new LibraryController(
			'sheetmusic',
			$this->request,
			$this->libraryService,
			$this->userSession,
			$fakeIntegrity,
		);

		$response = $controller->getIntegrity(1);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('issues', $data);
		$this->assertCount(1, $data['issues']);
		$this->assertSame([
			'songIds' => [60, 61],
			'exactDuplicate' => false,
		], $data['issues'][0]['details']);
	}

	public function testGetIntegrityPassesAutoMatchOrphansOption(): void {
		$capturedOption = null;
		$fakeIntegrity = new class($capturedOption) extends LibraryIntegrityService {
			public ?bool $captured = null;
			public function __construct(
				?bool &$capturedOption,
			) {
			}
			public function checkIntegrity(int $libraryId, ?string $userId = null, bool $autoMatchOrphans = true): array {
				$this->captured = $autoMatchOrphans;
				return [];
			}
		};

		$controller = new LibraryController(
			'sheetmusic',
			$this->request,
			$this->libraryService,
			$this->userSession,
			$fakeIntegrity,
		);

		$controller->getIntegrity(1, autoMatchOrphans: '0');
		$this->assertFalse($fakeIntegrity->captured);

		$controller->getIntegrity(1, autoMatchOrphans: '1');
		$this->assertTrue($fakeIntegrity->captured);
	}

	public function testUpdateSettingsRejectsUnauthenticated(): void {
		$this->userSession->setUser(null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->updateSettings(1, false)->getStatus());
	}

	public function testUpdateSettingsHandlesNotFound(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->updateSettings(99999, false)->getStatus());
	}

	public function testUpdateSettingsSuccess(): void {
		$library = $this->createTestLibrary('Settings Lib');

		$response = $this->controller->updateSettings((int)$library->id, false, true);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('library', $data);
		$this->assertInstanceOf(InternalLibrary::class, $data['library']);
		$this->assertFalse($data['library']->autoPaths);
		$this->assertTrue($data['library']->autoPdfMetadata);

		$updated = $this->libraryMapper->findForUser('admin', (int)$library->id);
		$this->assertNotNull($updated);
		$this->assertFalse($updated->autoPaths);
		$this->assertTrue($updated->autoPdfMetadata);
	}

	public function testAddLibraryPassesImportPdfMetadata(): void {
		$folder = '/TestImportPdfMetadataFolder';
		$userFolder = $this->rootFolder->getUserFolder('admin');
		if (!$userFolder->nodeExists(ltrim($folder, '/'))) {
			$userFolder->newFolder(ltrim($folder, '/'));
		}

		$this->request->setParam('importPdfMetadata', false);
		$this->request->setParam('importExistingDb', false);

		$res = $this->controller->addLibrary($folder, false, null, false);
		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
		$this->assertSame(['path' => $folder], $res->getData());
	}

	public function testSublibraryEndpointsCrud(): void {
		$library = $this->createTestLibrary('Sublib CRUD Lib');
		$libId = (int)$library->id;

		// 1. Initial list should be empty
		$res = $this->controller->getSublibraries($libId);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(['sublibraries' => []], $res->getData());

		// 2. Create sublibrary
		$createRes = $this->controller->createSublibrary($libId, 'Organ Book', 'orgel', ['instrument' => ['Orgel']]);
		$this->assertSame(Http::STATUS_CREATED, $createRes->getStatus());
		$created = $createRes->getData();
		$this->assertSame('Organ Book', $created->name);
		$this->assertSame('orgel', $created->slug);
		$subId = (int)$created->id;

		// 3. Update sublibrary
		$updateRes = $this->controller->updateSublibrary($libId, $subId, 'Organ & Piano', 'orgel-piano', ['instrument' => ['Orgel', 'Klavier']]);
		$this->assertSame(Http::STATUS_OK, $updateRes->getStatus());
		$updated = $updateRes->getData();
		$this->assertSame('Organ & Piano', $updated->name);
		$this->assertSame('orgel-piano', $updated->slug);

		// 4. List sublibraries
		$listRes = $this->controller->getSublibraries($libId);
		$this->assertSame(Http::STATUS_OK, $listRes->getStatus());
		$this->assertCount(1, $listRes->getData()['sublibraries']);

		// 5. Delete sublibrary
		$delRes = $this->controller->deleteSublibrary($libId, $subId);
		$this->assertSame(Http::STATUS_OK, $delRes->getStatus());
		$this->assertSame(['success' => true], $delRes->getData());

		// 6. List should be empty again
		$listResAfter = $this->controller->getSublibraries($libId);
		$this->assertSame(['sublibraries' => []], $listResAfter->getData());
	}

	public function testShareEndpointsCrud(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		if (!$userFolder->nodeExists('ShareFolder')) {
			$this->createdUserNodes[] = $userFolder->newFolder('ShareFolder');
		}
		$library = $this->createTestLibrary('ShareFolder');
		$libId = (int)$library->id;

		$mockShare = $this->createMock(IShare::class);
		$mockShare->method('getId')->willReturn('55');
		$mockShare->method('getFullId')->willReturn('ocinternal:55');
		$mockShare->method('getShareType')->willReturn(IShare::TYPE_USER);
		$mockShare->method('getSharedWith')->willReturn('bob');
		$mockShare->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$mockShare->method('getToken')->willReturn(null);
		$mockShare->method('getSharedBy')->willReturn('admin');

		$newShare = $this->createMock(IShare::class);
		$this->shareManager->method('newShare')->willReturn($newShare);
		$this->shareManager->method('createShare')->willReturn($mockShare);
		$this->shareManager->method('getSharesBy')->willReturnCallback(fn ($by, $type) => $type === IShare::TYPE_USER ? [$mockShare] : []);
		$this->shareManager->method('getShareById')->willReturn($mockShare);
		$this->shareManager->method('updateShare')->willReturn($mockShare);

		// 1. Get shares
		$getRes = $this->controller->getShares($libId);
		$this->assertSame(Http::STATUS_OK, $getRes->getStatus());
		$this->assertCount(1, $getRes->getData()['shares']);
		$this->assertSame('55', $getRes->getData()['shares'][0]['id']);

		// 2. Create share
		$createRes = $this->controller->createShare($libId, 'bob', IShare::TYPE_USER, 'read');
		$this->assertSame(Http::STATUS_CREATED, $createRes->getStatus());
		$this->assertSame('bob', $createRes->getData()['shareWith']);

		// 3. Update share
		$updateRes = $this->controller->updateShare($libId, '55', 'write');
		$this->assertSame(Http::STATUS_OK, $updateRes->getStatus());

		// 4. Delete share
		$this->shareManager->expects(self::once())->method('deleteShare')->with($mockShare);
		$delRes = $this->controller->deleteShare($libId, '55');
		$this->assertSame(Http::STATUS_OK, $delRes->getStatus());
		$this->assertSame(['success' => true], $delRes->getData());

		// 5. Unauthenticated rejection
		$this->userSession->setUser(null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->getShares($libId)->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->createShare($libId, 'bob')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->updateShare($libId, '55', 'write')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->deleteShare($libId, '55')->getStatus());
	}
}
