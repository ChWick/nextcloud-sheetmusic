<?php

declare(strict_types=1);

namespace Service;

use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongHistory;
use OCA\SheetMusic\Service\History\SongSnapshotHelper;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class SongSnapshotHelperTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private int $libraryId;
	private SongSnapshotHelper $snapshotHelper;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$library = $this->createTestLibrary('SnapshotHelper Test Lib');
		$this->libraryId = (int)$library->id;

		$this->snapshotHelper = new SongSnapshotHelper(
			$this->entityMapper,
			$this->songFileMapper,
			$this->attributeMapper,
		);
	}

	protected function tearDown(): void {
		$this->cleanupLibraryData($this->libraryId);
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testBuildSnapshotContainsAllPublicProperties(): void {
		$song = $this->songMapper->insert(new InternalSong(
			uuid: 'snap-song-1',
			libraryId: $this->libraryId,
			title: 'Snapshot Song',
			rating: 4,
			difficulty: 2,
			duration: 180,
		));

		$snap = $this->snapshotHelper->buildSnapshot($song);
		self::assertSame('Snapshot Song', $snap['title']);
		self::assertSame(4, $snap['rating']);
		self::assertSame(2, $snap['difficulty']);
		self::assertSame(180, $snap['duration']);
		self::assertArrayHasKey('entities', $snap);
		self::assertArrayHasKey('files', $snap);
		self::assertArrayNotHasKey('updatedAt', $snap);
	}

	public function testAreSnapshotsEquivalent(): void {
		$a = [
			'title' => 'Song A',
			'rating' => 5,
			'entities' => ['composer' => [['name' => 'Bach']]],
			'files' => [],
		];
		$b = [
			'title' => 'Song A',
			'rating' => 5,
			'entities' => ['composer' => [['name' => 'Bach']]],
			'files' => [],
		];
		$c = [
			'title' => 'Song B',
			'rating' => 5,
			'entities' => ['composer' => [['name' => 'Bach']]],
			'files' => [],
		];

		self::assertTrue($this->snapshotHelper->areSnapshotsEquivalent($a, $b));
		self::assertFalse($this->snapshotHelper->areSnapshotsEquivalent($a, $c));
	}

	public function testGenerateSummary(): void {
		self::assertSame('Song created', $this->snapshotHelper->generateSummary(InternalSongHistory::ACTION_CREATED, null, []));
		self::assertSame('Song deleted', $this->snapshotHelper->generateSummary(InternalSongHistory::ACTION_DELETED, null, []));

		$old = ['title' => 'Old Title', 'rating' => 2];
		$new = ['title' => 'New Title', 'rating' => 5];
		$summary = $this->snapshotHelper->generateSummary(InternalSongHistory::ACTION_UPDATED, $old, $new);
		self::assertStringContainsString('Title: "Old Title" → "New Title"', $summary);
		self::assertStringContainsString('Rating: 5★', $summary);
	}

	public function testRevertSongProperties(): void {
		$song = new InternalSong(
			uuid: 'test-uuid',
			libraryId: $this->libraryId,
			title: 'Changed Title',
			rating: 1,
			difficulty: 5,
		);

		$snap = [
			'title' => 'Original Title',
			'rating' => 4,
			'difficulty' => 2,
		];

		$this->snapshotHelper->revertSongProperties($song, $snap);

		self::assertSame('Original Title', $song->title);
		self::assertSame(4, $song->rating);
		self::assertSame(2, $song->difficulty);
	}
}
