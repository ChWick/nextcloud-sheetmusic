<?php

declare(strict_types=1);

namespace Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Service\EntityService;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TestSupport\TestUtils;

final class EntityServiceTest extends TestCase {
	use TestUtils;

	public function testCreateEntityValidatesEmptyValueAndUnknownLibrary(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->with('admin', 99)->willReturn(null);
		$libraryService = $this->createLibraryServiceWithMapper($libraryMapper);

		$service = new EntityService(
			$libraryService,
			$this->createMock(LoggerInterface::class),
			$this->createMock(InternalEntityMapper::class),
			$this->createMock(InternalSongMapper::class),
			$libraryMapper,
		);

		$this->assertNull($service->createEntity($user, 1, 'composer', '  '));
		$this->assertNull($service->createEntity($user, 99, 'composer', 'Bach'));
	}

	public function testCreateEntitySupportsAllTypes(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->with('admin', 1)->willReturn(['id' => 1, 'path' => '/Noten']);
		$libraryService = $this->createLibraryServiceWithMapper($libraryMapper);

		$internalEntityMapper = $this->createMock(InternalEntityMapper::class);
		$internalSongMapper = $this->createMock(InternalSongMapper::class);

		$createdId = 10;
		$internalEntityMapper->method('insert')->willReturnCallback(function (InternalEntity $entity) use (&$createdId) {
			$entity->id = ++$createdId;
			return $entity;
		});

		$service = new EntityService(
			$libraryService,
			$this->createMock(LoggerInterface::class),
			$internalEntityMapper,
			$internalSongMapper,
			$libraryMapper,
		);

		$composer = $service->createEntity($user, 1, 'composer', 'Bach');
		$book = $service->createEntity($user, 1, 'book', 'Real Book');
		$collection = $service->createEntity($user, 1, 'collection', 'Jazz');
		$artist = $service->createEntity($user, 1, 'artist', 'Miles');
		$signature = $service->createEntity($user, 1, 'signature', '3/4');
		$sourceType = $service->createEntity($user, 1, 'sourcetype', 'Lead Sheet');
		$instrument = $service->createEntity($user, 1, 'instrument', 'Organ');

		self::assertSame(['id' => 11, 'name' => 'Bach'], $composer);
		self::assertSame(['id' => 12, 'title' => 'Real Book'], $book);
		self::assertSame(['id' => 13, 'name' => 'Jazz'], $collection);
		self::assertSame(['id' => 14, 'name' => 'Miles'], $artist);
		self::assertSame(['id' => 15, 'name' => '3/4'], $signature);
		self::assertSame(['id' => 16, 'name' => 'Lead Sheet', 'type' => 'Lead Sheet'], $sourceType);
		self::assertSame(['id' => 17, 'name' => 'Organ'], $instrument);
	}

	public function testDeleteEntityPassesTypeToMapper(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->with('admin', 1)->willReturn(['id' => 1, 'path' => '/Noten']);
		$libraryService = $this->createLibraryServiceWithMapper($libraryMapper);

		$internalEntityMapper = $this->createMock(InternalEntityMapper::class);
		$internalSongMapper = $this->createMock(InternalSongMapper::class);

		$internalEntityMapper->expects($this->once())
			->method('delete')
			->with(10)
			->willReturn(true);

		$libraryMapper->expects($this->once())
			->method('touch')
			->with(1);

		$internalSongMapper->expects($this->once())
			->method('findLibraryData')
			->with(1, $user)
			->willReturn(['songs' => []]);

		$service = new EntityService(
			$libraryService,
			$this->createMock(LoggerInterface::class),
			$internalEntityMapper,
			$internalSongMapper,
			$libraryMapper,
		);

		$result = $service->deleteEntity($user, 1, 'composers', 10);
		self::assertSame(['songs' => []], $result);
	}

	public function testMergeEntitiesReassignsSongsAndDeletesDuplicates(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryMapper = $this->createMock(LibraryMapper::class);
		$libraryMapper->method('findForUser')->with('admin', 1)->willReturn(['id' => 1, 'path' => '/Noten']);
		$libraryService = $this->createLibraryServiceWithMapper($libraryMapper);

		$internalEntityMapper = $this->createMock(InternalEntityMapper::class);
		$internalSongMapper = $this->createMock(InternalSongMapper::class);

		$internalEntityMapper->expects($this->once())
			->method('mergeEntities')
			->with(1, 'composer', 50, [5])
			->willReturn(1);

		$libraryMapper->expects($this->once())
			->method('touch')
			->with(1);

		$internalSongMapper->expects($this->once())
			->method('findLibraryData')
			->with(1, $user)
			->willReturn(['songs' => []]);

		$service = new EntityService(
			$libraryService,
			$this->createMock(LoggerInterface::class),
			$internalEntityMapper,
			$internalSongMapper,
			$libraryMapper,
		);

		$result = $service->mergeEntities($user, 1, 'composers', 50, [5]);
		self::assertNotNull($result);
		self::assertSame(1, $result['mergedCount']);
		self::assertSame(['songs' => []], $result['libraryData']);
	}
}
