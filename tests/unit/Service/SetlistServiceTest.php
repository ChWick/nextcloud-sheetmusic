<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Db\InternalEntity;
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

	public function testUpdateItemBookmark(): void {
		$user = $this->userManager->get('admin');
		self::assertNotNull($user);
		$lib = $this->createTestLibrary('SetlistUpdateLib', 'admin');

		$setlist = $this->setlistService->createSetlist($user, (int)$lib->id, 'Update Test Setlist');
		self::assertNotNull($setlist);

		$song = $this->songMapper->insert(new InternalSong(
			uuid: InternalEntity::generateUuid(),
			libraryId: (int)$lib->id,
			title: 'Brandenburg Concerto',
		));

		$bm1 = $this->bookmarkMapper->insert(new InternalSongBookmark(
			id: null,
			songId: (int)$song->id,
			name: 'I. Allegro',
			pageNum: 1,
		));

		$bm2 = $this->bookmarkMapper->insert(new InternalSongBookmark(
			id: null,
			songId: (int)$song->id,
			name: 'II. Andante',
			pageNum: 5,
		));

		$otherSong = $this->songMapper->insert(new InternalSong(
			uuid: InternalEntity::generateUuid(),
			libraryId: (int)$lib->id,
			title: 'Other Piece',
		));
		$bmOther = $this->bookmarkMapper->insert(new InternalSongBookmark(
			id: null,
			songId: (int)$otherSong->id,
			name: 'Other Movement',
			pageNum: 1,
		));

		// 1. Add item with no bookmark (entire song)
		$item = $this->setlistService->addItem($user, (int)$lib->id, (int)$setlist->id, (int)$song->id);
		self::assertNotNull($item);
		self::assertNull($item->bookmarkId);

		// 2. Update to bookmark bm1
		$updated = $this->setlistService->updateItem($user, (int)$lib->id, (int)$setlist->id, (int)$item->id, (int)$bm1->id);
		self::assertNotNull($updated);
		self::assertSame((int)$bm1->id, $updated->bookmarkId);
		self::assertSame('I. Allegro', $updated->bookmarkName);

		// 3. Update to bookmark bm2
		$updated2 = $this->setlistService->updateItem($user, (int)$lib->id, (int)$setlist->id, (int)$item->id, (int)$bm2->id);
		self::assertNotNull($updated2);
		self::assertSame((int)$bm2->id, $updated2->bookmarkId);
		self::assertSame('II. Andante', $updated2->bookmarkName);

		// 4. Update back to null (entire song)
		$updated3 = $this->setlistService->updateItem($user, (int)$lib->id, (int)$setlist->id, (int)$item->id, null);
		self::assertNotNull($updated3);
		self::assertNull($updated3->bookmarkId);
		self::assertNull($updated3->bookmarkName);

		// 5. Attempt assigning bookmark from another song -> returns null (rejected)
		$invalid = $this->setlistService->updateItem($user, (int)$lib->id, (int)$setlist->id, (int)$item->id, (int)$bmOther->id);
		self::assertNull($invalid);
	}
}
