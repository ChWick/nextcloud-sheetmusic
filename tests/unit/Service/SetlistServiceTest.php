<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalSetlistItemMapper;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongBookmark;
use OCA\SheetMusic\Service\SetlistService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\RealMobileSheetsTestTrait;

final class SetlistServiceTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private SetlistService $setlistService;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$this->setlistService = new SetlistService(
			$this->libraryMapper,
			$this->entityMapper,
			$this->setlistItemMapper,
			$this->songMapper,
			$this->bookmarkMapper,
			new NullLogger(),
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testCreateGetUpdateDeleteSetlist(): void {
		$user = $this->userManager->get('admin');
		self::assertNotNull($user);
		$lib = $this->createTestLibrary('SetlistLib', 'admin');

		// 1. Create setlist
		$setlist = $this->setlistService->createSetlist($user, (int)$lib->id, 'Gig Setlist 1');
		self::assertNotNull($setlist);
		self::assertSame('Gig Setlist 1', $setlist->name);
		self::assertSame('setlist', $setlist->type);
		self::assertSame(0, $setlist->sortBy); // Manual sorting default

		// 2. List setlists
		$list = $this->setlistService->getSetlists($user, (int)$lib->id);
		self::assertNotNull($list);
		self::assertCount(1, $list);
		self::assertSame('Gig Setlist 1', $list[0]['name']);
		self::assertSame(0, $list[0]['itemCount']);

		// 3. Update setlist name & sort
		$updated = $this->setlistService->updateSetlist($user, (int)$lib->id, (int)$setlist->id, 'Gig Setlist 2026', sortBy: 1, ascending: 0);
		self::assertNotNull($updated);
		self::assertSame('Gig Setlist 2026', $updated->name);
		self::assertSame(1, $updated->sortBy);
		self::assertSame(0, $updated->ascending);

		// 4. Delete setlist
		$deleted = $this->setlistService->deleteSetlist($user, (int)$lib->id, (int)$setlist->id);
		self::assertTrue($deleted);
		$listAfter = $this->setlistService->getSetlists($user, (int)$lib->id);
		self::assertSame([], $listAfter);
	}

	public function testAddItemsWithAndWithoutBookmarkAndReorder(): void {
		$user = $this->userManager->get('admin');
		self::assertNotNull($user);
		$lib = $this->createTestLibrary('SetlistItemsLib', 'admin');

		$setlist = $this->setlistService->createSetlist($user, (int)$lib->id, 'Concert Program');
		self::assertNotNull($setlist);

		$song1 = $this->songMapper->insert(new InternalSong(
			uuid: InternalEntity::generateUuid(),
			libraryId: (int)$lib->id,
			title: 'Song Full',
			duration: 240,
		));
		$song2 = $this->songMapper->insert(new InternalSong(
			uuid: InternalEntity::generateUuid(),
			libraryId: (int)$lib->id,
			title: 'Symphony No. 5',
			duration: 600,
		));

		$bm = $this->bookmarkMapper->insert(new InternalSongBookmark(
			id: null,
			songId: (int)$song2->id,
			name: 'Allegro con brio',
			pageNum: 1,
			endPage: 12,
		));

		// Add whole song
		$item1 = $this->setlistService->addItem($user, (int)$lib->id, (int)$setlist->id, (int)$song1->id);
		self::assertNotNull($item1);
		self::assertSame((int)$song1->id, $item1->songId);
		self::assertNull($item1->bookmarkId);
		self::assertSame(0, $item1->position);

		// Add movement/bookmark only
		$item2 = $this->setlistService->addItem($user, (int)$lib->id, (int)$setlist->id, (int)$song2->id, bookmarkId: (int)$bm->id);
		self::assertNotNull($item2);
		self::assertSame((int)$song2->id, $item2->songId);
		self::assertSame((int)$bm->id, $item2->bookmarkId);
		self::assertSame(1, $item2->position);

		// Fetch full setlist
		$details = $this->setlistService->getSetlist($user, (int)$lib->id, (int)$setlist->id);
		self::assertNotNull($details);
		self::assertCount(2, $details['items']);
		self::assertSame('Song Full', $details['items'][0]->songTitle);
		self::assertNull($details['items'][0]->bookmarkName);
		self::assertSame('Symphony No. 5', $details['items'][1]->songTitle);
		self::assertSame('Allegro con brio', $details['items'][1]->bookmarkName);
		self::assertSame(1, $details['items'][1]->pageNum);

		// Reorder: item2 first, item1 second
		$reordered = $this->setlistService->reorderItems($user, (int)$lib->id, (int)$setlist->id, [(int)$item2->id, (int)$item1->id]);
		self::assertTrue($reordered);

		$detailsAfter = $this->setlistService->getSetlist($user, (int)$lib->id, (int)$setlist->id);
		self::assertSame((int)$item2->id, $detailsAfter['items'][0]->id);
		self::assertSame(0, $detailsAfter['items'][0]->position);
		self::assertSame((int)$item1->id, $detailsAfter['items'][1]->id);
		self::assertSame(1, $detailsAfter['items'][1]->position);

		// Remove item
		$removed = $this->setlistService->removeItem($user, (int)$lib->id, (int)$setlist->id, (int)$item1->id);
		self::assertTrue($removed);

		$detailsFinal = $this->setlistService->getSetlist($user, (int)$lib->id, (int)$setlist->id);
		self::assertCount(1, $detailsFinal['items']);
	}
}
