<?php

declare(strict_types=1);

namespace Controller;

use OCA\MobileSheetsManager\Controller\LibraryController;
use OCA\MobileSheetsManager\Service\LibraryService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class LibraryControllerTest extends TestCase {
	public function testGetLibrariesRejectsUnauthenticated(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = new LibraryController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$this->createMock(LibraryService::class),
			$userSession,
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->getLibraries()->getStatus());
	}

	public function testGetLibrariesReturnsLibraries(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$libraryService = $this->createMock(LibraryService::class);
		$libraryService->expects($this->once())
			->method('getLibraries')
			->with('admin')
			->willReturn([['id' => 1, 'path' => '/Noten/mobilesheets.db']]);

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

		$libraryService = $this->createMock(LibraryService::class);
		$libraryService->method('addLibrary')
			->willReturnCallback(fn (string $uid, string $path) => str_starts_with($path, '/') ? ['path' => $path] : null);

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

		$libraryService = $this->createMock(LibraryService::class);
		$libraryService->method('deleteLibrary')
			->with('admin', 1)
			->willReturn(['id' => 1, 'path' => '/Noten/mobilesheets.db']);

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
}

