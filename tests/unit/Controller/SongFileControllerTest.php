<?php

declare(strict_types=1);

namespace Controller;

use OCA\MobileSheetsManager\Controller\SongFileController;
use OCA\MobileSheetsManager\Service\SongFileService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class SongFileControllerTest extends TestCase {
	public function testImportSongMetadataHandlesUnauthenticatedAndInvalidFile(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = new SongFileController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$this->createMock(SongFileService::class),
			$userSession,
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->importSongMetadata()->getStatus());
	}

	public function testUploadSongFileValidatesLibraryId(): void {
		$controller = new SongFileController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$this->createMock(SongFileService::class),
			$this->createMock(IUserSession::class),
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->uploadSongFile(42, null)->getStatus());
	}

	public function testAttachNextcloudFileValidatesParameters(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn ($key, $default) => $default ?? '');

		$controller = new SongFileController(
			'mobilesheetsmanager',
			$request,
			$this->createMock(SongFileService::class),
			$userSession,
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->attachNextcloudFile(42, null, '/a.pdf')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->attachNextcloudFile(42, 1, '')->getStatus());
	}

	public function testDeleteSongFileDelegatesToService(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->expects($this->once())
			->method('deleteSongFile')
			->with($user, 1, 42, 2)
			->willReturn(['songs' => []]);

		$controller = new SongFileController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$songFileService,
			$userSession,
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->deleteSongFile(42, 2, null)->getStatus());

		$res = $controller->deleteSongFile(42, 2, 1);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(['songs' => []], $res->getData());
	}

	public function testDetachSongFileDelegatesToService(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->expects($this->once())
			->method('detachSongFile')
			->with($user, 1, 42, 2)
			->willReturn(['songs' => []]);

		$controller = new SongFileController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$songFileService,
			$userSession,
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->detachSongFile(42, 2, null)->getStatus());

		$response = $controller->detachSongFile(42, 2, 1);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['songs' => []], $response->getData());
	}
}
