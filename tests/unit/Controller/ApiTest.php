<?php

declare(strict_types=1);

namespace Controller;

use OCA\MobileSheetsManager\AppInfo\Application;
use OCA\MobileSheetsManager\Controller\ApiController;
use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCA\MobileSheetsManager\Service\FileManager;
use OCP\AppFramework\Http;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ApiTest extends TestCase {
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
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects($this->once())
			->method('findForUser')
			->with('admin', 7)
			->willReturn(null);

		$controller = $this->createController($libraryMapper);

		$this->assertEquals(
			Http::STATUS_NOT_FOUND,
			$controller->deleteSongFile(1, 2, 7)->getStatus(),
		);
	}

	private function createController(?LibraryMapper $libraryMapper = null): ApiController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return new ApiController(
			Application::APP_ID,
			$this->createMock(IRequest::class),
			$libraryMapper ?? $this->createMock(LibraryMapper::class),
			$this->createMock(SongMapper::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
			$userSession,
			$this->createMock(FileManager::class),
		);
	}
}
