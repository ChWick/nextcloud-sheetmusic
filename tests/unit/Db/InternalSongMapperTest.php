<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongMapper;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class InternalSongMapperTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private InternalSongMapper $mapper;
	private int $libraryId;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$this->mapper = $this->songMapper;
		$library = $this->createTestLibrary();
		$this->libraryId = (int)$library->id;
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testFindByIdReturnsSong(): void {
		$song = new InternalSong(
			id: null,
			uuid: 'uuid-123',
			libraryId: $this->libraryId,
			title: 'Moonlight Sonata',
			sortTitle: 'Moonlight Sonata',
			rating: 5,
			difficulty: 3,
			duration: 360,
			keywords: 'piano, beethoven',
			notes: 'Movement 1',
			opus: 'Op. 27 No. 2',
			catalogNumber: 'WoO 47',
			songNumber: '14',
			clientId: 42,
		);
		$created = $this->mapper->insert($song);

		$found = $this->mapper->findById((int)$created->id);
		self::assertNotNull($found);
		self::assertSame((int)$created->id, $found->id);
		self::assertSame('uuid-123', $found->uuid);
		self::assertSame($this->libraryId, $found->libraryId);
		self::assertSame('Moonlight Sonata', $found->title);
		self::assertSame('Op. 27 No. 2', $found->opus);
		self::assertSame('WoO 47', $found->catalogNumber);
		self::assertSame('14', $found->songNumber);
		self::assertSame(42, $found->clientId);
		self::assertSame(5, $found->rating);
	}

	public function testFindByIdReturnsNullWhenNotFound(): void {
		self::assertNull($this->mapper->findById(999999));
	}

	public function testFindAllForLibraryReturnsSongs(): void {
		$this->mapper->insert(new InternalSong(
			id: null,
			uuid: 'uuid-1',
			libraryId: $this->libraryId,
			title: 'Song A',
		));
		$this->mapper->insert(new InternalSong(
			id: null,
			uuid: 'uuid-2',
			libraryId: $this->libraryId,
			title: 'Song B',
		));

		$songs = $this->mapper->findAllForLibrary($this->libraryId);
		self::assertCount(2, $songs);
		self::assertSame('Song A', $songs[0]->title);
		self::assertSame('Song B', $songs[1]->title);
	}

	public function testInsertSetsIdAndTimestamps(): void {
		$song = new InternalSong(
			id: null,
			uuid: 'new-uuid',
			libraryId: $this->libraryId,
			title: 'New Piece',
			opus: 'Op. 1',
		);
		$saved = $this->mapper->insert($song);

		self::assertNotNull($saved->id);
		self::assertGreaterThan(0, $saved->id);
		self::assertGreaterThan(0, $saved->createdAt);
		self::assertGreaterThan(0, $saved->updatedAt);
		self::assertNull($saved->rating);
		self::assertNull($saved->difficulty);
		self::assertNull($saved->duration);

		$found = $this->mapper->findById((int)$saved->id);
		self::assertNotNull($found);
		self::assertNull($found->rating);
		self::assertNull($found->difficulty);
		self::assertNull($found->duration);
	}

	public function testDeleteAndRestore(): void {
		$song = $this->mapper->insert(new InternalSong(
			id: null,
			uuid: 'del-uuid',
			libraryId: $this->libraryId,
			title: 'To Delete',
		));

		self::assertTrue($this->mapper->delete((int)$song->id));
		$deleted = $this->mapper->findById((int)$song->id);
		self::assertNotNull($deleted);
		self::assertNotNull($deleted->deletedAt);
		self::assertEmpty($this->mapper->findAllForLibrary($this->libraryId));

		self::assertTrue($this->mapper->delete((int)$song->id, true));
		self::assertNull($this->mapper->findById((int)$song->id));
	}

	public function testFindDuplicateClientIds(): void {
		$s1 = $this->mapper->insert(new InternalSong(
			id: null,
			uuid: 's1',
			libraryId: $this->libraryId,
			title: 'Song A',
			clientId: 50,
		));
		$s2 = $this->mapper->insert(new InternalSong(
			id: null,
			uuid: 's2',
			libraryId: $this->libraryId,
			title: 'Song B',
			clientId: 50,
		));
		$this->mapper->insert(new InternalSong(
			id: null,
			uuid: 's3',
			libraryId: $this->libraryId,
			title: 'Song C',
			clientId: 60,
		));

		$conflicts = $this->mapper->findDuplicateClientIds($this->libraryId);
		self::assertCount(1, $conflicts);
		self::assertSame(50, $conflicts[0]->clientId);
		self::assertSame([(int)$s1->id, (int)$s2->id], $conflicts[0]->songIds);
	}

	public function testFixDuplicateClientIds(): void {
		$this->mapper->insert(new InternalSong(
			id: null,
			uuid: 's1',
			libraryId: $this->libraryId,
			title: 'Song A',
			clientId: 50,
		));
		$this->mapper->insert(new InternalSong(
			id: null,
			uuid: 's2',
			libraryId: $this->libraryId,
			title: 'Song B',
			clientId: 50,
		));

		$fixed = $this->mapper->fixDuplicateClientIds($this->libraryId);
		self::assertSame(1, $fixed);

		$remainingConflicts = $this->mapper->findDuplicateClientIds($this->libraryId);
		self::assertEmpty($remainingConflicts);
	}

	public function testFindSongsWithoutFiles(): void {
		$emptySong = $this->mapper->insert(new InternalSong(
			id: null,
			uuid: 'u99',
			libraryId: $this->libraryId,
			title: 'Empty Song',
		));
		$songWithFile = $this->mapper->insert(new InternalSong(
			id: null,
			uuid: 'u100',
			libraryId: $this->libraryId,
			title: 'Song With File',
		));

		$this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'file-1',
			songId: (int)$songWithFile->id,
			fileId: 101,
		));

		$songs = $this->mapper->findSongsWithoutFiles($this->libraryId);
		self::assertCount(1, $songs);
		self::assertSame((int)$emptySong->id, $songs[0]->id);
		self::assertSame('Empty Song', $songs[0]->title);
	}
}
