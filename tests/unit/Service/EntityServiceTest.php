<?php

declare(strict_types=1);

namespace Service;

use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\LibraryData;
use OCA\SheetMusic\Service\EntityService;
use OCA\SheetMusic\Service\LibraryService;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\RealMobileSheetsTestTrait;

final class EntityServiceTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private int $libraryId;
	private IUser $user;
	private EntityService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$library = $this->createTestLibrary('EntityService Test Lib');
		$this->libraryId = (int)$library->id;

		$this->user = $this->userManager->get($library->userId);

		$libraryService = new LibraryService(
			$this->libraryMapper,
			$this->rootFolder,
			new NullLogger(),
		);

		$this->service = new EntityService(
			$libraryService,
			new NullLogger(),
			$this->entityMapper,
			$this->songMapper,
			$this->libraryMapper,
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testCreateEntityValidatesEmptyValueAndUnknownLibrary(): void {
		self::assertNull($this->service->createEntity($this->user, $this->libraryId, 'composer', '  '));
		self::assertNull($this->service->createEntity($this->user, 999999, 'composer', 'Bach'));
	}

	public function testCreateEntitySupportsAllTypes(): void {
		$composer = $this->service->createEntity($this->user, $this->libraryId, 'composer', 'Bach');
		$book = $this->service->createEntity($this->user, $this->libraryId, 'book', 'Real Book');
		$collection = $this->service->createEntity($this->user, $this->libraryId, 'collection', 'Jazz');
		$artist = $this->service->createEntity($this->user, $this->libraryId, 'artist', 'Miles');
		$signature = $this->service->createEntity($this->user, $this->libraryId, 'signature', '3/4');
		$sourceType = $this->service->createEntity($this->user, $this->libraryId, 'sourcetype', 'Lead Sheet');
		$instrument = $this->service->createEntity($this->user, $this->libraryId, 'instrument', 'Organ');
		$arranger = $this->service->createEntity($this->user, $this->libraryId, 'arranger', 'Quincy Jones');
		$year = $this->service->createEntity($this->user, $this->libraryId, 'year', '1959');

		self::assertNotNull($composer);
		self::assertSame('Bach', $composer['name']);
		self::assertGreaterThan(0, $composer['id']);

		self::assertNotNull($book);
		self::assertSame('Real Book', $book['title']);

		self::assertNotNull($collection);
		self::assertSame('Jazz', $collection['name']);

		self::assertNotNull($artist);
		self::assertSame('Miles', $artist['name']);

		self::assertNotNull($signature);
		self::assertSame('3/4', $signature['name']);

		self::assertNotNull($sourceType);
		self::assertSame('Lead Sheet', $sourceType['type']);

		self::assertNotNull($instrument);
		self::assertSame('Organ', $instrument['name']);

		self::assertNotNull($arranger);
		self::assertSame('Quincy Jones', $arranger['name']);

		self::assertNotNull($year);
		self::assertSame(1959, $year['year']);

		$composers = $this->entityMapper->findAllForLibrary($this->libraryId, 'composer');
		self::assertCount(1, $composers);
		$books = $this->entityMapper->findAllForLibrary($this->libraryId, 'book');
		self::assertCount(1, $books);
		$collections = $this->entityMapper->findAllForLibrary($this->libraryId, 'collection');
		self::assertCount(1, $collections);
		$artists = $this->entityMapper->findAllForLibrary($this->libraryId, 'artist');
		self::assertCount(1, $artists);
	}

	public function testDeleteEntityRemovesFromDbAndReturnsLibraryData(): void {
		$composer = $this->service->createEntity($this->user, $this->libraryId, 'composer', 'Chopin');
		self::assertNotNull($composer);
		$composerId = $composer['id'];

		$result = $this->service->deleteEntity($this->user, $this->libraryId, 'composers', $composerId);
		self::assertInstanceOf(LibraryData::class, $result);
		self::assertIsArray($result->songs);

		// Entity should be soft-deleted in the database
		$deleted = $this->entityMapper->findById($composerId);
		self::assertNotNull($deleted);
		self::assertNotNull($deleted->deletedAt);
		self::assertEmpty($this->entityMapper->findAllForLibrary($this->libraryId, 'composer'));

		// Deleting non-existent returns null
		self::assertNull($this->service->deleteEntity($this->user, $this->libraryId, 'composers', 999999));
	}

	public function testMergeEntitiesReassignsSongsAndDeletesDuplicates(): void {
		$keep = $this->service->createEntity($this->user, $this->libraryId, 'composer', 'Johann Sebastian Bach');
		$duplicate = $this->service->createEntity($this->user, $this->libraryId, 'composer', 'J.S. Bach');
		self::assertNotNull($keep);
		self::assertNotNull($duplicate);

		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-bach',
			libraryId: $this->libraryId,
			title: 'Toccata and Fugue',
		));

		$this->entityMapper->linkSongEntity((int)$song->id, (int)$duplicate['id']);

		// Verify initial link
		$linkedBefore = $this->entityMapper->findEntityIdsForSong((int)$song->id, 'composer');
		self::assertSame([$duplicate['id']], $linkedBefore);

		// Merge duplicate into keep
		$result = $this->service->mergeEntities($this->user, $this->libraryId, 'composers', $keep['id'], [$duplicate['id']]);
		self::assertNotNull($result);
		self::assertSame(1, $result['mergedCount']);
		self::assertInstanceOf(LibraryData::class, $result['libraryData']);
		self::assertIsArray($result['libraryData']->songs);

		// Song is now linked to keep
		$linkedAfter = $this->entityMapper->findEntityIdsForSong((int)$song->id, 'composer');
		self::assertSame([$keep['id']], $linkedAfter);

		// Duplicate entity was soft-deleted
		$deletedDuplicate = $this->entityMapper->findById($duplicate['id']);
		self::assertNotNull($deletedDuplicate);
		self::assertNotNull($deletedDuplicate->deletedAt);
	}

	public function testUpdateEntityRenamesAndRejectsDuplicateName(): void {
		$first = $this->service->createEntity($this->user, $this->libraryId, 'composers', 'J.S. Bach');
		$second = $this->service->createEntity($this->user, $this->libraryId, 'composers', 'Mozart');
		self::assertNotNull($first);
		self::assertNotNull($second);

		// Renaming to an existing entity name returns an error
		$conflict = $this->service->updateEntity($this->user, $this->libraryId, 'composers', (int)$first['id'], 'Mozart');
		self::assertIsArray($conflict);
		self::assertArrayHasKey('error', $conflict);

		// Renaming to a unique name updates the entity and returns LibraryData
		$updated = $this->service->updateEntity($this->user, $this->libraryId, 'composers', (int)$first['id'], 'Johann Sebastian Bach');
		self::assertInstanceOf(LibraryData::class, $updated);
		$reloaded = $this->entityMapper->findById((int)$first['id']);
		self::assertNotNull($reloaded);
		self::assertSame('Johann Sebastian Bach', $reloaded->name);
	}
}
