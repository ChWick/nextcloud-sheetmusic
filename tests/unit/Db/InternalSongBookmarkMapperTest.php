<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\SheetMusic\Db\InternalSong;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class InternalSongBookmarkMapperTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private int $libraryId;
	private InternalSong $song;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$library = $this->createTestLibrary('BookmarkMapper Test Lib');
		$this->libraryId = (int)$library->id;
		$this->song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'bm-test-song-uuid',
			libraryId: $this->libraryId,
			title: 'Sonata in C Major',
		));
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testSetBookmarksAndFindForSong(): void {
		$songId = (int)$this->song->id;

		self::assertEmpty($this->bookmarkMapper->findForSong($songId));

		$bookmarksData = [
			[
				'name' => 'I. Allegro',
				'pageNum' => 1,
				'endPage' => 4,
				'position' => 0,
			],
			[
				'name' => 'II. Andante',
				'pageNum' => 5,
				'endPage' => 8,
				'position' => 1,
			],
			[
				'name' => 'III. Rondo',
				'pageNum' => 9,
				'endPage' => 12,
				'position' => 2,
			],
		];

		$this->bookmarkMapper->setBookmarksForSong($songId, $bookmarksData);

		$bms = $this->bookmarkMapper->findForSong($songId);
		self::assertCount(3, $bms);
		self::assertSame('I. Allegro', $bms[0]->name);
		self::assertSame(1, $bms[0]->pageNum);
		self::assertSame(4, $bms[0]->endPage);
		self::assertSame(0, $bms[0]->position);

		self::assertSame('II. Andante', $bms[1]->name);
		self::assertSame(5, $bms[1]->pageNum);
		self::assertSame(8, $bms[1]->endPage);

		self::assertSame('III. Rondo', $bms[2]->name);
		self::assertSame(9, $bms[2]->pageNum);
		self::assertSame(12, $bms[2]->endPage);
	}

	public function testUpdateReplacesExistingBookmarks(): void {
		$songId = (int)$this->song->id;

		$this->bookmarkMapper->setBookmarksForSong($songId, [
			['name' => 'Old Movement', 'pageNum' => 1, 'endPage' => 2],
		]);
		self::assertCount(1, $this->bookmarkMapper->findForSong($songId));

		$this->bookmarkMapper->setBookmarksForSong($songId, [
			['name' => 'New Movement 1', 'pageNum' => 1, 'endPage' => 3],
			['name' => 'New Movement 2', 'pageNum' => 4, 'endPage' => 6],
		]);
		$bms = $this->bookmarkMapper->findForSong($songId);
		self::assertCount(2, $bms);
		self::assertSame('New Movement 1', $bms[0]->name);
		self::assertSame('New Movement 2', $bms[1]->name);
	}

	public function testFindForSongsAndFindAllForLibrary(): void {
		$song1Id = (int)$this->song->id;
		$song2 = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'bm-test-song-2-uuid',
			libraryId: $this->libraryId,
			title: 'Symphony No. 5',
		));
		$song2Id = (int)$song2->id;

		$this->bookmarkMapper->setBookmarksForSong($song1Id, [
			['name' => 'Song 1 Mvt 1', 'pageNum' => 1, 'endPage' => 5],
		]);
		$this->bookmarkMapper->setBookmarksForSong($song2Id, [
			['name' => 'Song 2 Mvt 1', 'pageNum' => 1, 'endPage' => 10],
			['name' => 'Song 2 Mvt 2', 'pageNum' => 11, 'endPage' => 20],
		]);

		$grouped = $this->bookmarkMapper->findForSongs([$song1Id, $song2Id]);
		self::assertCount(1, $grouped[$song1Id]);
		self::assertCount(2, $grouped[$song2Id]);

		$libBookmarks = $this->bookmarkMapper->findAllForLibrary($this->libraryId);
		self::assertArrayHasKey($song1Id, $libBookmarks);
		self::assertArrayHasKey($song2Id, $libBookmarks);
		self::assertCount(1, $libBookmarks[$song1Id]);
		self::assertCount(2, $libBookmarks[$song2Id]);
	}

	public function testDeleteForSong(): void {
		$songId = (int)$this->song->id;
		$this->bookmarkMapper->setBookmarksForSong($songId, [
			['name' => 'Movement to Delete', 'pageNum' => 1],
		]);
		self::assertCount(1, $this->bookmarkMapper->findForSong($songId));

		$this->bookmarkMapper->deleteForSong($songId);
		self::assertEmpty($this->bookmarkMapper->findForSong($songId));
	}

	public function testEmptyNamesAndSanitization(): void {
		$songId = (int)$this->song->id;
		$this->bookmarkMapper->setBookmarksForSong($songId, [
			['name' => '   ', 'pageNum' => 1],
			['name' => 'Valid Mvt', 'pageNum' => -5, 'endPage' => 2],
		]);

		$bms = $this->bookmarkMapper->findForSong($songId);
		self::assertCount(1, $bms);
		self::assertSame('Valid Mvt', $bms[0]->name);
		self::assertSame(1, $bms[0]->pageNum); // Clamped to 1
		self::assertSame(2, $bms[0]->endPage);
	}
}
