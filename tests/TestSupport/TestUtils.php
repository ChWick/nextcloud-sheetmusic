<?php

declare(strict_types=1);

namespace TestSupport;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCA\MobileSheetsManager\Service\LibraryService;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

trait TestUtils {
	protected function createSongMapper(?LoggerInterface $logger = null): SongMapper {
		return new SongMapper($logger ?? $this->createMock(LoggerInterface::class));
	}

	protected function createLibraryService(): LibraryService {
		$logger = $this->createMock(LoggerInterface::class);

		return new LibraryService(
			new LibraryMapper($this->createMock(\OCP\IDBConnection::class)),
			$this->createSongMapper(),
			$this->createMock(IRootFolder::class),
			$logger,
		);
	}
}
