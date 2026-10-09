<?php

declare(strict_types=1);

namespace Service;

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

			self::assertSame(['id' => 1, 'name' => 'Bach'], $composer);
			self::assertSame(['id' => 1, 'title' => 'Real Book'], $book);
			self::assertSame(['id' => 1, 'name' => 'Jazz'], $collection);
			self::assertSame(['id' => 1, 'name' => 'Miles'], $artist);
			self::assertSame(['id' => 1, 'name' => '3/4'], $signature);
			self::assertSame(['id' => 1, 'name' => 'Lead Sheet', 'type' => 'Lead Sheet'], $sourceType);
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
			$result = $service->deleteEntity($user, 1, 'composers', 10);
			self::assertSame([], $result['songs']);
			self::assertSame(0, (int)$fixture['connection']->query('SELECT COUNT(*) FROM Composer')->fetchColumn());
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
}
