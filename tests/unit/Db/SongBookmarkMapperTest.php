<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\SheetMusic\Db\SongBookmarkMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SongBookmarkMapperTest extends TestCase {
	private string $databasePath;
	private SongBookmarkMapper $bookmarkMapper;

	protected function setUp(): void {
		$databasePath = tempnam(sys_get_temp_dir(), 'sheetmusic-bookmark-test-');
		self::assertNotFalse($databasePath);
		$this->databasePath = $databasePath;

		$database = new \PDO('sqlite:' . $this->databasePath);
		$database->exec('CREATE TABLE Bookmarks(Id INTEGER PRIMARY KEY, SongId INTEGER, Name VARCHAR(255), PageNum INTEGER, ShowInLibrary INTEGER DEFAULT 0, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$database->exec("INSERT INTO Bookmarks (Id, SongId, Name, PageNum, ShowInLibrary) VALUES (1, 10, 'Intro', 1, 1)");

		$this->bookmarkMapper = new SongBookmarkMapper(new NullLogger());
	}

	protected function tearDown(): void {
		if (file_exists($this->databasePath)) {
			unlink($this->databasePath);
		}
	}

	public function testBookmarksOfSong(): void {
		$bookmarks = $this->bookmarkMapper->bookmarksOfSong($this->databasePath, 10);
		self::assertCount(1, $bookmarks);
		self::assertSame('Intro', $bookmarks[0]->name);
		self::assertSame(1, $bookmarks[0]->pageNum);
		self::assertSame(1, $bookmarks[0]->showInLibrary);
	}

	public function testUpdateBookmarks(): void {
		$newBookmarks = [
			['name' => 'Verse', 'pageNum' => 2, 'showInLibrary' => 0],
			['name' => 'Chorus', 'pageNum' => 4, 'showInLibrary' => 1],
			['name' => '  ', 'pageNum' => 5], // Empty name should be skipped
		];

		$this->bookmarkMapper->updateBookmarks($this->databasePath, 10, $newBookmarks);
		$bookmarks = $this->bookmarkMapper->bookmarksOfSong($this->databasePath, 10);

		self::assertCount(2, $bookmarks);
		self::assertSame('Verse', $bookmarks[0]->name);
		self::assertSame(2, $bookmarks[0]->pageNum);
		self::assertSame('Chorus', $bookmarks[1]->name);
		self::assertSame(4, $bookmarks[1]->pageNum);
	}
}
