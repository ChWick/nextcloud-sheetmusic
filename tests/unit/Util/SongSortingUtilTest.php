<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Util;

use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Util\SongSortingUtil;
use PHPUnit\Framework\TestCase;

final class SongSortingUtilTest extends TestCase {
	private function makeSong(
		int $id,
		string $title,
		string $sortTitle = '',
		int $createdAt = 1000,
		int $updatedAt = 1000,
		int $rating = 0,
		int $difficulty = 0,
	): InternalSong {
		return new InternalSong(
			id: $id,
			uuid: 'test-uuid-' . $id,
			libraryId: 1,
			title: $title,
			sortTitle: $sortTitle,
			rating: $rating,
			difficulty: $difficulty,
			duration: 0,
			createdAt: $createdAt,
			updatedAt: $updatedAt,
		);
	}

	public function testCompareByTitle(): void {
		$songA = $this->makeSong(1, 'Alpha');
		$songB = $this->makeSong(2, 'Beta');

		self::assertLessThan(0, SongSortingUtil::compare($songA, $songB, SongSortingUtil::MODE_TITLE, 1));
		self::assertGreaterThan(0, SongSortingUtil::compare($songA, $songB, SongSortingUtil::MODE_TITLE, 0));
	}

	public function testCompareBySortTitle(): void {
		$songA = $this->makeSong(1, 'The Beatles', 'Beatles, The');
		$songB = $this->makeSong(2, 'Abba');

		self::assertGreaterThan(0, SongSortingUtil::compare($songA, $songB, SongSortingUtil::MODE_TITLE, 1));
	}

	public function testCompareByDateCreated(): void {
		$songA = $this->makeSong(1, 'Song 1', createdAt: 500);
		$songB = $this->makeSong(2, 'Song 2', createdAt: 1000);

		self::assertLessThan(0, SongSortingUtil::compare($songA, $songB, SongSortingUtil::MODE_DATE_CREATED, 1));
		self::assertGreaterThan(0, SongSortingUtil::compare($songA, $songB, SongSortingUtil::MODE_DATE_CREATED, 0));
	}

	public function testCompareByLastModified(): void {
		$songA = $this->makeSong(1, 'Song 1', updatedAt: 2000);
		$songB = $this->makeSong(2, 'Song 2', updatedAt: 1000);

		self::assertGreaterThan(0, SongSortingUtil::compare($songA, $songB, SongSortingUtil::MODE_LAST_MODIFIED, 1));
	}

	public function testCompareByRating(): void {
		$songA = $this->makeSong(1, 'Song 1', rating: 5);
		$songB = $this->makeSong(2, 'Song 2', rating: 3);

		self::assertGreaterThan(0, SongSortingUtil::compare($songA, $songB, SongSortingUtil::MODE_RATING, 1));
	}

	public function testCompareByDifficulty(): void {
		$songA = $this->makeSong(1, 'Song 1', difficulty: 2);
		$songB = $this->makeSong(2, 'Song 2', difficulty: 4);

		self::assertLessThan(0, SongSortingUtil::compare($songA, $songB, SongSortingUtil::MODE_DIFFICULTY, 1));
	}

	public function testCompareManualWithFallbackId(): void {
		$songA = $this->makeSong(1, 'Song 1');
		$songB = $this->makeSong(2, 'Song 2');

		// Manual mode preserves sequence using fallback IDs
		self::assertLessThan(0, SongSortingUtil::compare($songA, $songB, SongSortingUtil::MODE_MANUAL, 1, 10, 20));
		self::assertGreaterThan(0, SongSortingUtil::compare($songA, $songB, SongSortingUtil::MODE_MANUAL, 1, 20, 10));
		self::assertLessThan(0, SongSortingUtil::compare($songA, $songB, SongSortingUtil::MODE_MANUAL, 0, 20, 10));
	}

	public function testCompareNullSongHandling(): void {
		$songA = $this->makeSong(1, 'Song 1');

		self::assertLessThan(0, SongSortingUtil::compare($songA, null, SongSortingUtil::MODE_TITLE, 1, 1, 2));
		self::assertGreaterThan(0, SongSortingUtil::compare(null, $songA, SongSortingUtil::MODE_TITLE, 1, 2, 1));
	}
}
