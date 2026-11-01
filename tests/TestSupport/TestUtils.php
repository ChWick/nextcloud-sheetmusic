<?php

declare(strict_types=1);

namespace TestSupport;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Sync\MobileSheets\SongMapper;
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
		if (property_exists($this, 'libraryMapper') && isset($this->libraryMapper) && property_exists($this, 'rootFolder') && isset($this->rootFolder)) {
			return new LibraryService(
				$this->libraryMapper,
				$this->rootFolder,
				new NullLogger(),
			);
		}
		return $this->createLibraryServiceWithMapper(
			$this->createMock(LibraryMapper::class),
		);
	}

	public static function minimalPdf(
		string $trailerExtra = '',
		string $infoBody = '/Title (Take Five) /Creator (Unit test)',
	): string {
		$objects = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Count 1 /Kids [3 0 R] >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. '4 0 obj << ' . $infoBody . " >> endobj\n";

		$xrefOffset = strlen("%PDF-1.4\n" . $objects);

		return "%PDF-1.4\n"
			. $objects
			. "xref\n0 5\n0000000000 65535 f \n"
			. 'trailer << /Size 5 /Root 1 0 R /Info 4 0 R' . $trailerExtra . " >>\n"
			. "startxref\n{$xrefOffset}\n%%EOF\n";
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
