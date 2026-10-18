<?php

declare(strict_types=1);

namespace Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\LibraryData;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Service\SongFileService;
use OCA\SheetMusic\Service\SongService;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\RealMobileSheetsTestTrait;

final class SongServiceTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private int $libraryId;
	private IUser $user;
	private SongService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$library = $this->createTestLibrary('SongService Test Lib');
		$this->libraryId = (int)$library->id;

		$user = $this->userManager->get($library->userId);
		self::assertNotNull($user);
		$this->user = $user;
		$userFolder = $this->rootFolder->getUserFolder($library->userId);
		if (!$userFolder->nodeExists('SongService Test Lib')) {
			$this->createdUserNodes[] = $userFolder->newFolder('SongService Test Lib');
		}

		$logger = new NullLogger();
		$libraryService = new LibraryService($this->libraryMapper, $this->rootFolder, $logger, exporter: $this->exporter);
		$fileManager = new FileManager(
			$this->libraryMapper,
			$this->rootFolder,
			$this->pathResolver,
			$logger,
			$libraryService,
			$this->songFileMapper,
			$this->songMapper,
		);
		$songFileService = new SongFileService(
			$libraryService,
			$fileManager,
			$this->rootFolder,
			$logger,
			$this->songFileMapper,
			$this->songMapper,
			$this->libraryMapper,
		);
		$integrityService = $this->createLibraryIntegrityService($libraryService);

		$this->service = new SongService(
			$libraryService,
			$songFileService,
			$fileManager,
			$logger,
			$this->songMapper,
			$this->entityMapper,
			$this->songFileMapper,
			$this->libraryMapper,
			$this->rootFolder,
			$integrityService,
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testGetSongsReturnsLibraryData(): void {
		$this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's1-uuid',
			libraryId: $this->libraryId,
			title: 'Song One',
		));

		$data = $this->service->getSongs($this->user, $this->libraryId);
		self::assertNotNull($data);
		self::assertCount(1, $data->songs);
		self::assertSame('Song One', $data->songs[0]->title);
	}

	public function testCreateSongReturnsCreatedIdAndData(): void {
		$res = $this->service->createSong(
			$this->user,
			$this->libraryId,
			'Take Five',
			opus: 'Op. 27 No. 2',
			catalogNumber: 'BWV 565',
			songNumber: '88',
		);

		self::assertNotNull($res);
		$createdId = $res->createdSongId;
		self::assertNotNull($createdId);
		self::assertGreaterThan(0, $createdId);

		$savedSong = $this->songMapper->findById($createdId);
		self::assertNotNull($savedSong);
		self::assertSame('Take Five', $savedSong->title);
		self::assertSame('Op. 27 No. 2', $savedSong->opus);
		self::assertSame('BWV 565', $savedSong->catalogNumber);
		self::assertSame('88', $savedSong->songNumber);
	}

	public function testUpdateSongSyncsMetadata(): void {
		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'update-uuid',
			libraryId: $this->libraryId,
			title: 'Original Title',
		));

		$res = $this->service->updateSong(
			$this->user,
			(int)$song->id,
			$this->libraryId,
			'Updated Title',
			stars: 4,
			difficulty: 2,
		);

		self::assertNotNull($res);
		self::assertNotEmpty($res->songs);
		$reloaded = $this->songMapper->findById((int)$song->id);
		self::assertNotNull($reloaded);
		self::assertSame('Updated Title', $reloaded->title);
		self::assertSame(4, $reloaded->rating);
		self::assertSame(2, $reloaded->difficulty);
	}

	public function testBatchUpdateSongsCallsBatchUpdateAndSyncsFiles(): void {
		$s1 = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'b1-uuid',
			libraryId: $this->libraryId,
			title: 'Batch Song 1',
		));
		$s2 = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'b2-uuid',
			libraryId: $this->libraryId,
			title: 'Batch Song 2',
		));

		$composer = $this->entityMapper->insert(new InternalEntity(
			id: null,
			uuid: 'comp-uuid',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Gershwin',
		));

		$result = $this->service->batchUpdateSongs(
			$this->user,
			$this->libraryId,
			[(int)$s1->id, (int)$s2->id],
			composers: ['action' => 'add', 'ids' => [(int)$composer->id]],
		);

		self::assertNotNull($result);
		self::assertCount(2, $result->songs);

		$s1Composers = $this->entityMapper->findEntityIdsForSong((int)$s1->id, 'composer');
		$s2Composers = $this->entityMapper->findEntityIdsForSong((int)$s2->id, 'composer');
		self::assertSame([(int)$composer->id], $s1Composers);
		self::assertSame([(int)$composer->id], $s2Composers);
	}

	public function testResolveBothSongsPreservesSharedFileOnDetach(): void {
		$songA = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'u-60',
			libraryId: $this->libraryId,
			title: 'Old Song A',
		));
		$songB = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'u-61',
			libraryId: $this->libraryId,
			title: 'Song B',
		));

		$file = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'f-60',
			songId: (int)$songA->id,
			fileId: 207,
		));

		$res = $this->service->resolveBothSongs(
			$this->user,
			$this->libraryId,
			['id' => (int)$songA->id, 'title' => 'Song A', 'detachFileIds' => [(int)$file->id]],
			['id' => (int)$songB->id, 'title' => 'Song B Renamed', 'detachFileIds' => []],
		);

		self::assertInstanceOf(LibraryData::class, $res);
		self::assertCount(2, $res->songs);

		$reloadedA = $this->songMapper->findById((int)$songA->id);
		$reloadedB = $this->songMapper->findById((int)$songB->id);
		self::assertSame('Song A', $reloadedA->title);
		self::assertSame('Song B Renamed', $reloadedB->title);

		// File is detached (soft-deleted)
		$reloadedFile = $this->songFileMapper->findById((int)$file->id);
		self::assertNotNull($reloadedFile);
		self::assertNotNull($reloadedFile->deletedAt);
	}

	public function testDeleteSongWithInternalDatabase(): void {
		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'del-uuid',
			libraryId: $this->libraryId,
			title: 'Song To Delete',
		));

		$result = $this->service->deleteSong($this->user, (int)$song->id, $this->libraryId);
		self::assertNotNull($result);
		self::assertEmpty($result->songs);

		$reloaded = $this->songMapper->findById((int)$song->id);
		self::assertNotNull($reloaded);
		self::assertNotNull($reloaded->deletedAt);
	}

	public function testBatchDeleteSongsDeletesMultipleSongs(): void {
		$s1 = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'bdel-1',
			libraryId: $this->libraryId,
			title: 'Delete 1',
		));
		$s2 = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'bdel-2',
			libraryId: $this->libraryId,
			title: 'Delete 2',
		));

		$result = $this->service->batchDeleteSongs(
			$this->user,
			$this->libraryId,
			[(int)$s1->id, (int)$s2->id],
			deleteFiles: false,
		);

		self::assertNotNull($result);
		self::assertEmpty($result->songs);

		$reloaded1 = $this->songMapper->findById((int)$s1->id);
		$reloaded2 = $this->songMapper->findById((int)$s2->id);
		self::assertNotNull($reloaded1->deletedAt);
		self::assertNotNull($reloaded2->deletedAt);
	}

	public function testMergeSongMergesRecords(): void {
		$keep = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'm-keep',
			libraryId: $this->libraryId,
			title: 'Keep Song',
		));
		$drop = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'm-drop',
			libraryId: $this->libraryId,
			title: 'Drop Song',
		));

		$composer = $this->entityMapper->insert(new InternalEntity(
			id: null,
			uuid: 'c-drop',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Ravel',
		));
		$this->entityMapper->linkSongEntity((int)$drop->id, (int)$composer->id);

		$result = $this->service->mergeSong($this->user, $this->libraryId, (int)$keep->id, (int)$drop->id, []);
		self::assertNotNull($result);

		// Keep song now has the composer
		$keepComposers = $this->entityMapper->findEntityIdsForSong((int)$keep->id, 'composer');
		self::assertSame([(int)$composer->id], $keepComposers);

		// Dropped song is deleted
		$droppedSong = $this->songMapper->findById((int)$drop->id);
		self::assertNotNull($droppedSong->deletedAt);
	}
}
