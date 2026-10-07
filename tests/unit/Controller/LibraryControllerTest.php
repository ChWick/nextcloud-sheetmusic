<?php

declare(strict_types=1);

namespace Controller;

use OCA\MobileSheetsManager\Controller\LibraryController;
use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use TestSupport\TestUtils;

final class LibraryControllerTest extends TestCase {
	use TestUtils;

	public function testGetLibrariesRejectsUnauthenticated(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = new LibraryController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$this->createLibraryService(),
			$userSession,
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->getLibraries()->getStatus());
	}

	public function testGetLibrariesReturnsLibraries(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects($this->once())
			->method('findAllForUser')
			->with('admin')
			->willReturn([['id' => 1, 'path' => '/Noten/mobilesheets.db']]);
		$libraryService = $this->createLibraryServiceWithMapper($libraryMapper);

		$controller = new LibraryController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$libraryService,
			$userSession,
		);

		$response = $controller->getLibraries();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['libraries' => [['id' => 1, 'path' => '/Noten/mobilesheets.db']]], $response->getData());
	}

	public function testAddLibraryHandlesBadRequestAndSuccess(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects($this->once())
			->method('addForUser')
			->with('admin', '/Noten/mobilesheets.db');
		$libraryService = $this->createLibraryServiceWithMapper($libraryMapper);

		$controller = new LibraryController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$libraryService,
			$userSession,
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->addLibrary('invalid/path.db')->getStatus());

		$okRes = $controller->addLibrary('/Noten/mobilesheets.db');
		$this->assertSame(Http::STATUS_CREATED, $okRes->getStatus());
		$this->assertSame(['path' => '/Noten/mobilesheets.db'], $okRes->getData());
	}

	public function testDeleteLibraryHandlesNotFoundAndSuccess(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects($this->once())
			->method('findForUser')
			->with('admin', 1)
			->willReturn(['id' => 1, 'path' => '/Noten/mobilesheets.db']);
		$libraryMapper->expects($this->once())
			->method('deleteForUser')
			->with('admin', 1)
			->willReturn(true);
		$libraryService = $this->createLibraryServiceWithMapper($libraryMapper);

		$controller = new LibraryController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$libraryService,
			$userSession,
		);

		$response = $controller->deleteLibrary(1);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 1, 'path' => '/Noten/mobilesheets.db'], $response->getData());
	}

	public function testGetIntegrityIncludesIssueDetails(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$libraryService = $this->createLibraryService();
		$integrityService = $this->createMock(\OCA\MobileSheetsManager\Service\LibraryIntegrityService::class);

		$issue = new \OCA\MobileSheetsManager\Service\IntegrityIssue(
			type: \OCA\MobileSheetsManager\Service\IntegrityIssue::TYPE_DUPLICATE_FILE_PATH,
			severity: \OCA\MobileSheetsManager\Service\IntegrityIssue::SEVERITY_ERROR,
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

		$integrityService->expects($this->once())
			->method('checkIntegrity')
			->with(1, 'admin')
			->willReturn([$issue]);

		$controller = new LibraryController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$libraryService,
			$userSession,
			$integrityService,
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
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = new LibraryController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$this->createLibraryService(),
			$userSession,
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->updateSettings(1, false)->getStatus());
	}

	public function testUpdateSettingsHandlesNotFound(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->with('admin', 99)->willReturn(null);
		$libraryService = $this->createLibraryServiceWithMapper($libraryMapper);

		$controller = new LibraryController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$libraryService,
			$userSession,
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->updateSettings(99, false)->getStatus());
	}

	public function testUpdateSettingsSuccess(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects($this->once())
			->method('setSettingsForUser')
			->with('admin', 1, false, true);
		$libraryMapper->expects($this->once())
			->method('findForUser')
			->with('admin', 1)
			->willReturn(['id' => 1, 'path' => '/Noten/mobilesheets.db', 'autoPaths' => false, 'autoPdfMetadata' => true]);
		$libraryService = $this->createLibraryServiceWithMapper($libraryMapper);

		$controller = new LibraryController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$libraryService,
			$userSession,
		);

		$response = $controller->updateSettings(1, false, true);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['library' => ['id' => 1, 'path' => '/Noten/mobilesheets.db', 'autoPaths' => false, 'autoPdfMetadata' => true]], $response->getData());
	}
}
