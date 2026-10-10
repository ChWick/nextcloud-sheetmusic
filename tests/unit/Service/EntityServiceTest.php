<?php

declare(strict_types=1);

namespace Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Service\EntityService;
use OCA\SheetMusic\Service\LibraryService;
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
			$this->createSongMapper(),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertNull($service->createEntity($user, 1, 'composer', '  '));
		$this->assertNull($service->createEntity($user, 99, 'composer', 'Bach'));
	}

	public function testCreateEntitySupportsAllTypes(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$databasePath = $fixture['path'];
		$libraryService = $this->createLibraryServiceForDatabase(
			'admin',
			1,
			'/Noten/library.db',
			$databasePath,
		);
		$songMapper = $this->createSongMapper();

		$service = new EntityService(
			$libraryService,
			$songMapper,
			$this->createMock(LoggerInterface::class),
		);

		try {
			$composer = $service->createEntity($user, 1, 'composer', 'Bach');
			$book = $service->createEntity($user, 1, 'book', 'Real Book');
			$collection = $service->createEntity($user, 1, 'collection', 'Jazz');
			$artist = $service->createEntity($user, 1, 'artist', 'Miles');
			$signature = $service->createEntity($user, 1, 'signature', '3/4');
			$sourceType = $service->createEntity($user, 1, 'sourcetype', 'Lead Sheet');
			$instrument = $service->createEntity($user, 1, 'instrument', 'Organ');

			self::assertSame(['id' => 1, 'name' => 'Bach'], $composer);
			self::assertSame(['id' => 1, 'title' => 'Real Book'], $book);
			self::assertSame(['id' => 1, 'name' => 'Jazz'], $collection);
			self::assertSame(['id' => 1, 'name' => 'Miles'], $artist);
			self::assertSame(['id' => 1, 'name' => '3/4'], $signature);
			self::assertSame(['id' => 1, 'name' => 'Lead Sheet', 'type' => 'Lead Sheet'], $sourceType);
			self::assertSame(['id' => 1, 'name' => 'Organ'], $instrument);
		} finally {
			unlink($databasePath);
		}
	}

	public function testDeleteEntityPassesTypeToMapper(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$databasePath = $fixture['path'];
		$fixture['connection']->exec("INSERT INTO Composer (Id, Name) VALUES (10, 'Bach')");
		$libraryService = $this->createLibraryServiceForDatabase(
			'admin',
			1,
			'/Noten/library.db',
			$databasePath,
		);

		$songMapper = $this->createSongMapper();

		$service = new EntityService(
			$libraryService,
			$songMapper,
			$this->createMock(LoggerInterface::class),
		);

		try {
			$fixture['connection']->exec("INSERT INTO CustomGroup (Id, Name) VALUES (20, 'Piano')");
			$result = $service->deleteEntity($user, 1, 'composers', 10);
			self::assertSame([], $result['songs']);
			self::assertSame(0, (int)$fixture['connection']->query('SELECT COUNT(*) FROM Composer')->fetchColumn());

			$service->deleteEntity($user, 1, 'instruments', 20);
			self::assertSame(0, (int)$fixture['connection']->query('SELECT COUNT(*) FROM CustomGroup WHERE Id = 20')->fetchColumn());
		} finally {
			unlink($databasePath);
		}
	}

	public function testMergeEntitiesReassignsSongsAndDeletesDuplicates(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$fixture = \TestSupport\MobileSheetsTestDatabase::create();
		$databasePath = $fixture['path'];
		$fixture['connection']->exec("INSERT INTO Composer (Id, Name) VALUES (5, 'J. S. Bach'), (50, 'Johann Sebastian Bach')");
		$fixture['connection']->exec("INSERT INTO Songs (Id, Title) VALUES (1, 'Air')");
		$fixture['connection']->exec('INSERT INTO ComposerSongs (ComposerId, SongId) VALUES (5, 1)');

		$libraryService = $this->createLibraryServiceForDatabase(
			'admin',
			1,
			'/Noten/library.db',
			$databasePath,
		);

		$songMapper = $this->createSongMapper();

		$service = new EntityService(
			$libraryService,
			$songMapper,
			$this->createMock(LoggerInterface::class),
		);

		try {
			$result = $service->mergeEntities($user, 1, 'composers', 50, [5]);
			self::assertNotNull($result);
			self::assertSame(1, $result['mergedCount']);
			self::assertArrayHasKey('libraryData', $result);

			self::assertSame(0, (int)$fixture['connection']->query('SELECT COUNT(*) FROM Composer WHERE Id = 5')->fetchColumn());
			self::assertSame(1, (int)$fixture['connection']->query('SELECT COUNT(*) FROM Composer WHERE Id = 50')->fetchColumn());

			$composerIds = $fixture['connection']->query('SELECT ComposerId FROM ComposerSongs WHERE SongId = 1')->fetchAll(\PDO::FETCH_COLUMN);
			self::assertSame([50], array_map('intval', $composerIds));
		} finally {
			unlink($databasePath);
		}
	}

	public function testCreateEntityWithInternalMapper(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createMock(LibraryService::class);
		$songMapper = $this->createSongMapper();
		$logger = $this->createMock(LoggerInterface::class);
		$internalEntityMapper = $this->createMock(InternalEntityMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);

		$createdEntity = new InternalEntity(
			id: 7,
			uuid: 'entity-uuid-1',
			libraryId: 1,
			type: 'composer',
			name: 'Chopin',
		);

		$internalEntityMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (InternalEntity $entity): bool {
				return $entity->name === 'Chopin'
					&& $entity->type === 'composer'
					&& $entity->libraryId === 1
					&& strlen($entity->uuid) === 36;
			}))
			->willReturn($createdEntity);

		$libraryMapper->expects($this->once())
			->method('touch')
			->with(1);

		$service = new EntityService(
			$libraryService,
			$songMapper,
			$logger,
			null,
			$internalEntityMapper,
			null,
			$libraryMapper,
		);

		$result = $service->createEntity($user, 1, 'composer', 'Chopin');
		self::assertSame([
			'id' => 7,
			'name' => 'Chopin',
		], $result);
	}

	public function testDeleteEntityWithInternalMapper(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createMock(LibraryService::class);
		$songMapper = $this->createSongMapper();
		$logger = $this->createMock(LoggerInterface::class);
		$internalEntityMapper = $this->createMock(InternalEntityMapper::class);
		$internalSongMapper = $this->createMock(InternalSongMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);

		$internalEntityMapper->expects($this->once())
			->method('delete')
			->with(7)
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
			$songMapper,
			$logger,
			null,
			$internalEntityMapper,
			$internalSongMapper,
			$libraryMapper,
		);

		$result = $service->deleteEntity($user, 1, 'composers', 7);
		self::assertSame(['songs' => []], $result);
	}

	public function testMergeEntitiesWithInternalMapper(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$libraryService = $this->createMock(LibraryService::class);
		$songMapper = $this->createSongMapper();
		$logger = $this->createMock(LoggerInterface::class);
		$internalEntityMapper = $this->createMock(InternalEntityMapper::class);
		$internalSongMapper = $this->createMock(InternalSongMapper::class);
		$libraryMapper = $this->createMock(LibraryMapper::class);

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
			$songMapper,
			$logger,
			null,
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
