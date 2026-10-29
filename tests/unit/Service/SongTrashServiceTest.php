<?php

declare(strict_types=1);

namespace Service;

use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongHistoryMapper;
use OCA\SheetMusic\Service\History\SongSnapshotHelper;
use OCA\SheetMusic\Service\SongTrashService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\RealMobileSheetsTestTrait;

final class SongTrashServiceTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private int $libraryId;
	private SongTrashService $trashService;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$library = $this->createTestLibrary('TrashService Test Lib');
		$this->libraryId = (int)$library->id;

		$historyMapper = new InternalSongHistoryMapper($this->dbConn);
		$snapshotHelper = new SongSnapshotHelper(
			$this->entityMapper,
			$this->songFileMapper,
			$this->attributeMapper,
		);

		$this->trashService = new SongTrashService(
			$this->songMapper,
			$this->entityMapper,
			$this->songFileMapper,
			$this->libraryMapper,
			$this->dbConn,
			new NullLogger(),
			$this->rootFolder,
			$historyMapper,
			$snapshotHelper,
		);
	}

	protected function tearDown(): void {
		$this->cleanupLibraryData($this->libraryId);
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testGetDeletedSongsAndRestore(): void {
		$song = $this->songMapper->insert(new InternalSong(
			uuid: 'trash-song-1',
			libraryId: $this->libraryId,
			title: 'Song to be trashed',
			deletedAt: time(),
		));

		$deletedList = $this->trashService->getDeletedSongs($this->libraryId);
		self::assertNotEmpty($deletedList);
		self::assertSame((int)$song->id, (int)$deletedList[0]['id']);

		$restored = $this->trashService->restoreDeletedSong((int)$song->id, 'admin');
		self::assertNull($restored->deletedAt);

		$deletedAfter = $this->trashService->getDeletedSongs($this->libraryId);
		self::assertEmpty($deletedAfter);
	}
}
