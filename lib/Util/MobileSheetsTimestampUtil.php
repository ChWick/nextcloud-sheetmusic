<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Util;

/**
 * Utility for converting and extracting timestamps between MobileSheets (milliseconds)
 * and Nextcloud internal domain representations (UNIX seconds).
 */
final class MobileSheetsTimestampUtil {
	/**
	 * Convert millisecond timestamp to UNIX seconds (rounded).
	 */
	public static function msToSeconds(int|float|null $ms): int {
		if ($ms === null || $ms <= 0) {
			return 0;
		}
		return (int)round($ms / 1000);
	}

	/**
	 * Convert UNIX seconds to millisecond timestamp.
	 */
	public static function secondsToMs(int|float|null $seconds): int {
		if ($seconds === null || $seconds <= 0) {
			return 0;
		}
		return (int)round($seconds * 1000);
	}

	/**
	 * Extract LastModified in seconds from an SQLite row.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function extractLastModifiedSec(array $row): int {
		$ms = $row['LastModified'] ?? $row['lastmodified'] ?? null;
		return self::msToSeconds(is_numeric($ms) ? (int)$ms : null);
	}

	/**
	 * Extract DateCreated / CreationDate in seconds from an SQLite row.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function extractDateCreatedSec(array $row): int {
		$ms = $row['DateCreated'] ?? $row['CreationDate'] ?? $row['datecreated'] ?? $row['creationdate'] ?? null;
		return self::msToSeconds(is_numeric($ms) ? (int)$ms : null);
	}
}
