<?php

declare(strict_types=1);

namespace Controller;

use OCA\SheetMusic\Controller\LibraryController;
use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Service\IntegrityIssue;
use OCA\SheetMusic\Service\LibraryIntegrityService;
use OCA\SheetMusic\Service\LibraryService;
use OCP\AppFramework\Http;
use OCP\IUser;
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
	private LibraryController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$this->user = $this->userManager->get('admin');

		$logger = new NullLogger();
		$this->libraryService = new LibraryService($this->libraryMapper, $this->rootFolder, $logger, exporter: $this->exporter);
		$this->userSession = new FakeUserSession($this->user);
		$this->request = new FakeRequest();

		$this->controller = new LibraryController(
			'sheetmusic',
			$this->request,
			$this->libraryService,
			$this->userSession,
			$this->createLibraryIntegrityService($this->libraryService),
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
		$this->assertSame(['path' => '/NewFolder/mobilesheets.db'], $okRes->getData());
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
			public function checkIntegrity(int $libraryId, ?string $userId = null): array {
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
}
