<?php

declare(strict_types=1);

namespace TestSupport;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Sync\MobileSheets\SongMapper;
use OCA\SheetMusic\Service\LibraryService;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

trait TestUtils {
	protected function createSongMapper(?LoggerInterface $logger = null): SongMapper {
		return new SongMapper($logger ?? new NullLogger());
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
			$rootFolder ?? $this->createMock(IRootFolder::class),
			new NullLogger(),
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
			->willReturn(new InternalLibrary(
				id: $libraryId,
				userId: $userId,
				path: $libraryPath,
			));

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
