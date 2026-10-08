<?php

declare(strict_types=1);

namespace TestSupport;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCA\MobileSheetsManager\Service\LibraryService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use Psr\Log\LoggerInterface;

trait TestUtils {
	protected function createSongMapper(?LoggerInterface $logger = null): SongMapper {
		return new SongMapper($logger ?? $this->createMock(LoggerInterface::class));
	}

	protected function createLibraryService(): LibraryService {
		return $this->createLibraryServiceWithMapper(
			$this->createMock(LibraryMapper::class),
		);
	}

	protected function createLibraryServiceWithMapper(
		LibraryMapper $libraryMapper,
		?IRootFolder $rootFolder = null,
	): LibraryService {
		return new LibraryService(
			$libraryMapper,
			$this->createSongMapper(),
			$rootFolder ?? $this->createMock(IRootFolder::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	protected function createLibraryServiceForDatabase(
		string $userId,
		int $libraryId,
		string $libraryPath,
		string $databasePath,
	): LibraryService {
		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')
			->with($userId, $libraryId)
			->willReturn(['id' => $libraryId, 'path' => $libraryPath]);

		$storage = $this->createMock(IStorage::class);
		$storage->method('getLocalFile')->willReturn($databasePath);

		$databaseNode = $this->createMock(Node::class);
		$databaseNode->method('getStorage')->willReturn($storage);
		$databaseNode->method('getInternalPath')->willReturn('files/' . ltrim($libraryPath, '/'));

		$userFolder = $this->createMock(\OCP\Files\IUserFolder::class);
		$userFolder->method('get')->with(ltrim($libraryPath, '/'))->willReturn($databaseNode);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with($userId)->willReturn($userFolder);

		return $this->createLibraryServiceWithMapper($libraryMapper, $rootFolder);
	}
}
