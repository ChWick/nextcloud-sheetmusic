<?php

declare(strict_types=1);

namespace Controller;

use OCA\SheetMusic\Controller\SongFileController;
use OCA\SheetMusic\Service\SongFileService;
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
			'sheetmusic',
			$this->createMock(IRequest::class),
			$this->createMock(SongFileService::class),
			$userSession,
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->importSongMetadata()->getStatus());
	}

	public function testUploadSongFileUsesLibraryIdFromLibraryScopedRoute(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$tmpFile = tempnam(sys_get_temp_dir(), 'song-upload-controller-test-');
		self::assertNotFalse($tmpFile);
		$fileInfo = ['tmp_name' => $tmpFile, 'name' => 'score.pdf'];
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->with('file')->willReturn($fileInfo);

		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->expects(self::once())
			->method('uploadSongFile')
			->with($user, 7, 42, $fileInfo)
			->willReturn(['songs' => []]);

		try {
			$controller = new SongFileController('sheetmusic', $request, $songFileService, $userSession);
			$response = $controller->uploadSongFile(42, 7);

			self::assertSame(Http::STATUS_OK, $response->getStatus());
			self::assertSame(['songs' => []], $response->getData());
		} finally {
			unlink($tmpFile);
		}
	}

	public function testUploadSongFileReportsPhpUploadSizeLimit(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $key === 'libraryId' ? '7' : $default,
		);
		$request->method('getUploadedFile')->with('file')->willReturn([
			'tmp_name' => '',
			'error' => UPLOAD_ERR_INI_SIZE,
		]);

		$controller = new SongFileController(
			'sheetmusic',
			$request,
			$this->createMock(SongFileService::class),
			$userSession,
		);
		$response = $controller->uploadSongFile(42, 7);

		self::assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());
		self::assertStringContainsString('upload_max_filesize', $response->getData()['message']);
	}

	public function testUploadSongFileDetectsPhpPostMaxSizeWhenFilesAreMissing(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $key === 'libraryId' ? '7' : $default,
		);
		$request->method('getUploadedFile')->with('file')->willReturn(null);

		$previousContentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
		$_SERVER['CONTENT_LENGTH'] = '999999999999';
		try {
			$controller = new SongFileController(
				'sheetmusic',
				$request,
				$this->createMock(SongFileService::class),
				$userSession,
			);
			$response = $controller->uploadSongFile(42, 7);

			self::assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());
			self::assertStringContainsString('post_max_size', $response->getData()['message']);
		} finally {
			if ($previousContentLength === null) {
				unset($_SERVER['CONTENT_LENGTH']);
			} else {
				$_SERVER['CONTENT_LENGTH'] = $previousContentLength;
			}
		}
	}

	public function testAttachNextcloudFileValidatesParameters(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn ($key, $default) => $default ?? '');

		$controller = new SongFileController(
			'sheetmusic',
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
			'sheetmusic',
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
			'sheetmusic',
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
