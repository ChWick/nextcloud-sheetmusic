<?php

declare(strict_types=1);

namespace Service;

use OCA\MobileSheetsManager\Db\SongMapper;
use OCA\MobileSheetsManager\Service\EntityService;
use OCA\MobileSheetsManager\Service\LibraryService;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class EntityServiceTest extends TestCase {
	public function testCreateEntityValidatesEmptyValueAndUnknownLibrary(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createMock(LibraryService::class);
		$libraryService->method('getDatabasePath')->with('admin', 99)->willReturn(null);

		$service = new EntityService(
			$libraryService,
			$this->createMock(SongMapper::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertNull($service->createEntity($user, 1, 'composer', '  '));
		$this->assertNull($service->createEntity($user, 99, 'composer', 'Bach'));
	}

	public function testCreateEntitySupportsAllTypes(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createMock(LibraryService::class);
		$libraryService->method('getDatabasePath')->with('admin', 1)->willReturn('/tmp/mobilesheets.db');

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->expects($this->once())->method('createComposer')->with('/tmp/mobilesheets.db', 'Bach')->willReturn(10);
		$songMapper->expects($this->once())->method('createBook')->with('/tmp/mobilesheets.db', 'Real Book')->willReturn(100);
		$songMapper->expects($this->once())->method('createCollection')->with('/tmp/mobilesheets.db', 'Jazz')->willReturn(1000);
		$songMapper->expects($this->once())->method('createArtist')->with('/tmp/mobilesheets.db', 'Miles')->willReturn(200);

		$service = new EntityService(
			$libraryService,
			$songMapper,
			$this->createMock(LoggerInterface::class),
		);

		$this->assertSame(['id' => 10, 'name' => 'Bach'], $service->createEntity($user, 1, 'composer', 'Bach'));
		$this->assertSame(['id' => 100, 'title' => 'Real Book'], $service->createEntity($user, 1, 'book', 'Real Book'));
		$this->assertSame(['id' => 1000, 'name' => 'Jazz'], $service->createEntity($user, 1, 'collection', 'Jazz'));
		$this->assertSame(['id' => 200, 'name' => 'Miles'], $service->createEntity($user, 1, 'artist', 'Miles'));
	}

	public function testDeleteEntityPassesTypeToMapper(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createMock(LibraryService::class);
		$libraryService->method('getDatabasePath')->with('admin', 1)->willReturn('/tmp/mobilesheets.db');

		$songMapper = $this->createMock(SongMapper::class);
		$songMapper->expects($this->once())
			->method('deleteEntity')
			->with('/tmp/mobilesheets.db', 'composers', 10)
			->willReturn(true);
		$songMapper->method('findLibraryData')->willReturn(['songs' => []]);

		$service = new EntityService(
			$libraryService,
			$songMapper,
			$this->createMock(LoggerInterface::class),
		);

		$this->assertSame(['songs' => []], $service->deleteEntity($user, 1, 'composers', 10));
	}
}

