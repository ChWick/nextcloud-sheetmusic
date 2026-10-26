<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Util;

use OCA\SheetMusic\Db\InternalSong;

/**
 * Utility for sorting songs and song-entity links according to MobileSheets sorting modes.
 *
 * Sort Modes:
 * 0 - Manual sequence (preserves link or order ID)
 * 1 - Title / Alphabetical (by sortTitle or title, case-insensitive natural order)
 * 2 - Date Created
 * 3 - Last Modified
 * 4 - Rating (Stars)
 * 5 - Difficulty
 */
final class SongSortingUtil {
	public const MODE_MANUAL = 0;
	public const MODE_TITLE = 1;
	public const MODE_DATE_CREATED = 2;
	public const MODE_LAST_MODIFIED = 3;
	public const MODE_RATING = 4;
	public const MODE_DIFFICULTY = 5;

	/**
	 * Compare two songs according to a given sort mode and ascending/descending order.
	 *
	 * @param InternalSong|null $songA
	 * @param InternalSong|null $songB
	 * @param int $sortBy Sort mode (0 to 5)
	 * @param int $ascending 1 for ascending, 0 for descending
	 * @param int|null $fallbackIdA Tie-breaker or manual sequence ID (e.g. link_id)
	 * @param int|null $fallbackIdB Tie-breaker or manual sequence ID (e.g. link_id)
	 */
	public static function compare(
		?InternalSong $songA,
		?InternalSong $songB,
		int $sortBy = self::MODE_TITLE,
		int $ascending = 1,
		?int $fallbackIdA = null,
		?int $fallbackIdB = null,
	): int {
		if ($songA === null || $songB === null) {
			$cmp = ($fallbackIdA ?? 0) <=> ($fallbackIdB ?? 0);
		} elseif ($sortBy === self::MODE_MANUAL) {
			$cmp = ($fallbackIdA ?? $songA->id ?? 0) <=> ($fallbackIdB ?? $songB->id ?? 0);
		} else {
			$cmp = match ($sortBy) {
				self::MODE_DATE_CREATED => $songA->createdAt <=> $songB->createdAt,
				self::MODE_LAST_MODIFIED => $songA->updatedAt <=> $songB->updatedAt,
				self::MODE_RATING => $songA->rating <=> $songB->rating,
				self::MODE_DIFFICULTY => $songA->difficulty <=> $songB->difficulty,
				default => self::compareTitles($songA, $songB),
			};

			if ($cmp === 0) {
				if ($sortBy !== self::MODE_TITLE) {
					$cmp = self::compareTitles($songA, $songB);
				}
				if ($cmp === 0 && ($fallbackIdA !== null || $fallbackIdB !== null)) {
					$cmp = ($fallbackIdA ?? 0) <=> ($fallbackIdB ?? 0);
				}
			}
		}

		return $ascending === 0 ? -$cmp : $cmp;
	}

	private static function compareTitles(InternalSong $songA, InternalSong $songB): int {
		return strnatcasecmp(self::getEffectiveTitle($songA), self::getEffectiveTitle($songB));
	}

	private static function getEffectiveTitle(InternalSong $song): string {
		return ($song->sortTitle !== null && $song->sortTitle !== '') ? $song->sortTitle : $song->title;
	}
}
