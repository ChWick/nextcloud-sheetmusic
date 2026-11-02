<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalSetlistItem;
use OCA\SheetMusic\Db\InternalSetlistItemMapper;
use OCA\SheetMusic\Db\InternalSongBookmark;
use OCA\SheetMusic\Db\InternalSong;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class InternalSetlistItemMapperTest extends TestCase {
	use RealMobileSheetsTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testInsertAndFindForSetlist(): void {
		$lib = $this->createTestLibrary();
		$setlist = $this->entityMapper->insert(new InternalEntity(
			uuid: InternalEntity::generateUuid(),
			libraryId: (int)$lib->id,
			type: 'setlist',
			name: 'My Gig Setlist',
			sortBy: 0,
			ascending: 1,
		));

		$song1 = $this->songMapper->insert(new InternalSong(
			uuid: InternalEntity::generateUuid(),
			libraryId: (int)$lib->id,
			title: 'Song A',
		));
		$song2 = $this->songMapper->insert(new InternalSong(
			uuid: InternalEntity::generateUuid(),
			libraryId: (int)$lib->id,
			title: 'Song B',
		));

		$bm = $this->bookmarkMapper->insert(new InternalSongBookmark(
			id: null,
			songId: (int)$song2->id,
			name: 'Movement 1',
			pageNum: 1,
			endPage: 3,
			position: 0,
		));

		$item1 = $this->setlistItemMapper->insert(new InternalSetlistItem(
			id: null,
			setlistId: (int)$setlist->id,
			songId: (int)$song1->id,
			bookmarkId: null,
			position: 0,
		));

		$item2 = $this->setlistItemMapper->insert(new InternalSetlistItem(
			id: null,
			setlistId: (int)$setlist->id,
			songId: (int)$song2->id,
			bookmarkId: (int)$bm->id,
			position: 1,
		));

		self::assertNotNull($item1->id);
		self::assertNotNull($item2->id);

		$items = $this->setlistItemMapper->findForSetlist((int)$setlist->id);
		self::assertCount(2, $items);
		self::assertSame((int)$song1->id, $items[0]->songId);
		self::assertNull($items[0]->bookmarkId);
		self::assertSame('Song A', $items[0]->songTitle);

		self::assertSame((int)$song2->id, $items[1]->songId);
		self::assertSame((int)$bm->id, $items[1]->bookmarkId);
		self::assertSame('Movement 1', $items[1]->bookmarkName);
		self::assertSame(1, $items[1]->pageNum);
		self::assertSame(3, $items[1]->endPage);

		$counts = $this->setlistItemMapper->getItemCountForSetlists([(int)$setlist->id]);
		self::assertSame(2, $counts[(int)$setlist->id]);
	}

	public function testReorder(): void {
		$lib = $this->createTestLibrary();
		$setlist = $this->entityMapper->insert(new InternalEntity(
			uuid: InternalEntity::generateUuid(),
			libraryId: (int)$lib->id,
			type: 'setlist',
			name: 'Reorder Test',
			sortBy: 0,
			ascending: 1,
		));

		$song1 = $this->songMapper->insert(new InternalSong(
			uuid: InternalEntity::generateUuid(),
			libraryId: (int)$lib->id,
			title: 'First',
		));
		$song2 = $this->songMapper->insert(new InternalSong(
			uuid: InternalEntity::generateUuid(),
			libraryId: (int)$lib->id,
			title: 'Second',
		));

		$item1 = $this->setlistItemMapper->insert(new InternalSetlistItem(
			id: null,
			setlistId: (int)$setlist->id,
			songId: (int)$song1->id,
			position: 0,
		));
		$item2 = $this->setlistItemMapper->insert(new InternalSetlistItem(
			id: null,
			setlistId: (int)$setlist->id,
			songId: (int)$song2->id,
			position: 1,
		));

		// Swap order: item2 first, item1 second
		$this->setlistItemMapper->reorder((int)$setlist->id, [(int)$item2->id, (int)$item1->id]);

		$items = $this->setlistItemMapper->findForSetlist((int)$setlist->id);
		self::assertSame((int)$item2->id, $items[0]->id);
		self::assertSame(0, $items[0]->position);
		self::assertSame((int)$item1->id, $items[1]->id);
		self::assertSame(1, $items[1]->position);
	}

	public function testDeleteAndNullifyBookmark(): void {
		$lib = $this->createTestLibrary();
		$setlist = $this->entityMapper->insert(new InternalEntity(
			uuid: InternalEntity::generateUuid(),
			libraryId: (int)$lib->id,
			type: 'setlist',
			name: 'Delete Test',
			sortBy: 0,
			ascending: 1,
		));

		$song = $this->songMapper->insert(new InternalSong(
			uuid: InternalEntity::generateUuid(),
			libraryId: (int)$lib->id,
			title: 'Deletable',
		));
		$bm = $this->bookmarkMapper->insert(new InternalSongBookmark(
			id: null,
			songId: (int)$song->id,
			name: 'BM',
			pageNum: 2,
		));

		$item = $this->setlistItemMapper->insert(new InternalSetlistItem(
			id: null,
			setlistId: (int)$setlist->id,
			songId: (int)$song->id,
			bookmarkId: (int)$bm->id,
			position: 0,
		));

		$this->setlistItemMapper->nullifyBookmark((int)$bm->id);
		$loaded = $this->setlistItemMapper->findById((int)$item->id);
		self::assertNotNull($loaded);
		self::assertNull($loaded->bookmarkId);

		$this->setlistItemMapper->delete((int)$item->id);
		self::assertNull($this->setlistItemMapper->findById((int)$item->id));
	}
}
