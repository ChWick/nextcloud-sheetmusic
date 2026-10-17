<?php

declare(strict_types=1);

namespace Service;

use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Db\MobileSheetsSong;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\LibraryIntegrityService;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Service\SongFileService;
use OCA\SheetMusic\Service\SongService;
use OCP\Files\IRootFolder;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TestSupport\TestUtils;

final class SongServiceTest extends TestCase {
	use TestUtils;

	private function createSongService(
		?LibraryService $libraryService = null,
		?SongFileService $songFileService = null,
		?FileManager $fileManager = null,
		?LoggerInterface $logger = null,
		?InternalSongMapper $internalSongMapper = null,
		?InternalEntityMapper $internalEntityMapper = null,
		?InternalSongFileMapper $internalSongFileMapper = null,
		?LibraryMapper $libraryMapper = null,
		?IRootFolder $rootFolder = null,
		?LibraryIntegrityService $integrityService = null,
	): SongService {
		return new SongService(
			$libraryService ?? $this->createMock(LibraryService::class),
			$songFileService ?? $this->createMock(SongFileService::class),
			$fileManager ?? $this->createMock(FileManager::class),
			$logger ?? $this->createMock(LoggerInterface::class),
			$internalSongMapper ?? $this->createMock(InternalSongMapper::class),
			$internalEntityMapper ?? $this->createMock(InternalEntityMapper::class),
			$internalSongFileMapper ?? $this->createMock(InternalSongFileMapper::class),
			$libraryMapper ?? $this->createMock(LibraryMapper::class),
			$rootFolder ?? $this->createMock(IRootFolder::class),
			$integrityService,
		);
	}

	public function testGetSongsReturnsLibraryData(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->with('admin', 1)->willReturn(['id' => 1, 'path' => '/Noten']);

		$internalSongMapper = $this->createMock(InternalSongMapper::class);
		$internalSongMapper->method('findLibraryData')->with(1, $user)->willReturn(['songs' => []]);

		$service = $this->createSongService(
			internalSongMapper: $internalSongMapper,
			libraryMapper: $libraryMapper,
		);

		self::assertSame([], $service->getSongs($user, 1)['songs']);
	}

	public function testCreateSongReturnsCreatedIdAndData(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->with('admin', 1)->willReturn(['id' => 1, 'path' => '/Noten']);
		$libraryMapper->expects(self::once())->method('touch')->with(1);

		$createdSong = new InternalSong(
			id: 42,
			uuid: 'uuid-123',
			libraryId: 1,
			title: 'Take Five',
			opus: 'Op. 27 No. 2',
			catalogNumber: 'BWV 565',
			songNumber: '88',
		);

		$internalSongMapper = $this->createMock(InternalSongMapper::class);
		$internalSongMapper->expects(self::once())
			->method('insert')
			->willReturn($createdSong);

		$internalEntityMapper = $this->createMock(InternalEntityMapper::class);
		$internalEntityMapper->expects(self::exactly(11))->method('setSongEntitiesForType');

		$internalSongMapper->expects(self::once())
			->method('findLibraryData')
			->with(1, $user)
			->willReturn(['songs' => [$createdSong]]);

		$service = $this->createSongService(
			internalSongMapper: $internalSongMapper,
			internalEntityMapper: $internalEntityMapper,
			libraryMapper: $libraryMapper,
		);

		$res = $service->createSong(
			$user,
			1,
			'Take Five',
			opus: 'Op. 27 No. 2',
			catalogNumber: 'BWV 565',
			songNumber: '88',
		);

		self::assertSame(42, $res['createdSongId']);
		self::assertSame('Take Five', $res['songs'][0]->title);
		self::assertSame('Op. 27 No. 2', $res['songs'][0]->opus);
		self::assertSame('BWV 565', $res['songs'][0]->catalogNumber);
		self::assertSame('88', $res['songs'][0]->songNumber);
	}

	public function testUpdateSongSyncsMetadata(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$song = new InternalSong(
			id: 42,
			uuid: 'uuid-123',
			libraryId: 1,
			title: 'Take Five',
		);

		$internalSongMapper = $this->createMock(InternalSongMapper::class);
		$internalSongMapper->method('findById')->with(42)->willReturn($song);
		$internalSongMapper->expects(self::once())->method('update')->with($song);

		$internalEntityMapper = $this->createMock(InternalEntityMapper::class);
		$internalEntityMapper->expects(self::exactly(11))->method('setSongEntitiesForType');

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects(self::once())->method('touch')->with(1);

		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->expects(self::once())
			->method('syncSongFileMetadata')
			->with($user, '', 42, 1);

		$internalSongMapper->expects(self::once())
			->method('findLibraryData')
			->with(1, $user)
			->willReturn(['songs' => [$song]]);

		$service = $this->createSongService(
			songFileService: $songFileService,
			internalSongMapper: $internalSongMapper,
			internalEntityMapper: $internalEntityMapper,
			libraryMapper: $libraryMapper,
		);

		$result = $service->updateSong($user, 42, 1, 'Blue Bossa');
		self::assertSame('Blue Bossa', $result['songs'][0]->title);
		self::assertSame('Blue Bossa', $song->title);
	}

	public function testBatchUpdateSongsCallsBatchUpdateAndSyncsFiles(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$song1 = new MobileSheetsSong(id: 42, title: 'Take Five');
		$song2 = new MobileSheetsSong(id: 43, title: 'Blue Bossa');

		$internalSongMapper = $this->createMock(InternalSongMapper::class);
		$internalSongMapper->method('findSong')->willReturnMap([
			[42, $user, $song1],
			[43, $user, $song2],
		]);

		$internalEntityMapper = $this->createMock(InternalEntityMapper::class);
		$internalEntityMapper->expects(self::exactly(2))
			->method('setSongEntitiesForType');

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects(self::once())->method('touch')->with(1);

		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->expects(self::exactly(2))->method('syncSongFileMetadata');

		$internalSongMapper->expects(self::once())
			->method('findLibraryData')
			->with(1, $user)
			->willReturn(['songs' => [$song1, $song2]]);

		$service = $this->createSongService(
			songFileService: $songFileService,
			internalSongMapper: $internalSongMapper,
			internalEntityMapper: $internalEntityMapper,
			libraryMapper: $libraryMapper,
		);

		$result = $service->batchUpdateSongs($user, 1, [42, 43], composers: ['action' => 'add', 'ids' => [10]]);
		self::assertCount(2, $result['songs']);
	}

	public function testResolveBothSongsPreservesSharedFileOnDetach(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$songFileService = $this->createMock(SongFileService::class);
		$songFileService->expects(self::once())
			->method('deleteSongFile')
			->with($user, 1, 60, 207);

		$songA = new InternalSong(id: 60, uuid: 'u-60', libraryId: 1, title: 'Old Song A');
		$songB = new InternalSong(id: 61, uuid: 'u-61', libraryId: 1, title: 'Song B');

		$internalSongMapper = $this->createMock(InternalSongMapper::class);
		$internalSongMapper->method('findById')->willReturnMap([
			[60, $songA],
			[61, $songB],
		]);
		$internalSongMapper->method('findLibraryData')->willReturn(['songs' => [$songA, $songB]]);

		$integrityService = $this->createMock(LibraryIntegrityService::class);
		$integrityService->method('checkIntegrity')->willReturn([]);

		$service = $this->createSongService(
			songFileService: $songFileService,
			internalSongMapper: $internalSongMapper,
			integrityService: $integrityService,
		);

		$res = $service->resolveBothSongs(
			$user,
			1,
			['id' => 60, 'title' => 'Song A', 'detachFileIds' => [207]],
			['id' => 61, 'title' => 'Song B', 'detachFileIds' => []],
		);

		self::assertCount(2, $res['songs']);
		self::assertSame('Song A', $songA->title);
	}

	public function testDeleteSongWithInternalDatabase(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$internalSongMapper = $this->createMock(InternalSongMapper::class);
		$internalSongFileMapper = $this->createMock(InternalSongFileMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);

		$internalSongFileMapper->expects(self::once())
			->method('findAllForSong')
			->with(42)
			->willReturn([]);

		$internalSongMapper->expects(self::once())
			->method('delete')
			->with(42, false)
			->willReturn(true);

		$libraryMapper->expects(self::once())
			->method('touch')
			->with(1);

		$internalSongMapper->expects(self::once())
			->method('findLibraryData')
			->with(1, $user)
			->willReturn(['songs' => []]);

		$service = $this->createSongService(
			internalSongMapper: $internalSongMapper,
			internalSongFileMapper: $internalSongFileMapper,
			libraryMapper: $libraryMapper,
		);

		$result = $service->deleteSong($user, 42, 1);
		self::assertSame(['songs' => []], $result);
	}

	public function testBatchDeleteSongsDeletesMultipleSongs(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$internalSongMapper = $this->createMock(InternalSongMapper::class);
		$internalSongMapper->expects(self::exactly(2))
			->method('delete')
			->willReturnCallback(function (int $id) {
				self::assertContains($id, [42, 43]);
				return true;
			});

		$internalSongFileMapper = $this->createMock(InternalSongFileMapper::class);
		$internalSongFileMapper->expects(self::exactly(2))
			->method('deleteAllForSong')
			->willReturnCallback(function (int $id) {
				self::assertContains($id, [42, 43]);
				return 1;
			});

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects(self::once())
			->method('touch')
			->with(1);

		$internalSongMapper->expects(self::once())
			->method('findLibraryData')
			->with(1, $user)
			->willReturn(['songs' => []]);

		$service = $this->createSongService(
			internalSongMapper: $internalSongMapper,
			internalSongFileMapper: $internalSongFileMapper,
			libraryMapper: $libraryMapper,
		);

		$result = $service->batchDeleteSongs($user, 1, [42, 43], deleteFiles: false);
		self::assertSame(['songs' => []], $result);
	}

	public function testMergeSongMergesRecords(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$internalSongMapper = $this->createMock(InternalSongMapper::class);
		$internalSongMapper->expects(self::once())
			->method('mergeSongs')
			->with(1, 42, 43);

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->expects(self::once())
			->method('touch')
			->with(1);

		$internalSongMapper->expects(self::once())
			->method('findLibraryData')
			->with(1, $user)
			->willReturn(['songs' => []]);

		$service = $this->createSongService(
			internalSongMapper: $internalSongMapper,
			libraryMapper: $libraryMapper,
		);

		$result = $service->mergeSong($user, 1, 42, 43, []);
		self::assertSame(['songs' => []], $result);
	}
}
