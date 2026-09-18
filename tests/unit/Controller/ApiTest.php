<?php

declare(strict_types=1);

namespace Controller;

use OCA\MobileSheetsManager\AppInfo\Application;
use OCA\MobileSheetsManager\Controller\ApiController;
use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ApiTest extends TestCase {
	public function testIndex(): void {
		$request = $this->createMock(IRequest::class);
		$controller = new ApiController(
			Application::APP_ID,
			$request,
			$this->createMock(LibraryMapper::class),
			$this->createMock(SongMapper::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IUserSession::class),
		);

		$this->assertEquals('Hello world!', $controller->index()->getData()['message']);
	}
}
