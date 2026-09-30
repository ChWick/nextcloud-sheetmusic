<?php

declare(strict_types=1);

namespace Controller;

use OCA\MobileSheetsManager\Controller\SongController;
use OCA\MobileSheetsManager\Service\SongService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class SongControllerTest extends TestCase {
	public function testGetSongsValidatesIdAndAuth(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = new SongController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$this->createMock(SongService::class),
			$userSession,
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->getSongs(null)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->getSongs('-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->getSongs('1')->getStatus());
	}

	public function testGetSongsReturnsData(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$songService = $this->createMock(SongService::class);
		$songService->expects($this->once())
			->method('getSongs')
			->with($user, 1)
			->willReturn(['songs' => []]);

		$controller = new SongController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$songService,
			$userSession,
		);

		$res = $controller->getSongs('1');
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(['songs' => []], $res->getData());
	}

	public function testCreateSongValidatesParameters(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(IUser::class));

		$controller = new SongController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$this->createMock(SongService::class),
			$userSession,
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->createSong(null, 'Title')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->createSong(1, '  ')->getStatus());
	}

	public function testDeleteSongValidatesParametersAndReturnsData(): void {
		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$songService = $this->createMock(SongService::class);
		$songService->expects($this->once())
			->method('deleteSong')
			->with($user, 42, 1, true)
			->willReturn(['songs' => []]);

		$controller = new SongController(
			'mobilesheetsmanager',
			$this->createMock(IRequest::class),
			$songService,
			$userSession,
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->deleteSong(42, null)->getStatus());

		$res = $controller->deleteSong(42, 1);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(['songs' => []], $res->getData());
	}
}

