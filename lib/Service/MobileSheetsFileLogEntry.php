<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Service;

/**
 * One line of the log a bulk run produces: what happened to one file of a song.
 *
 * Runs like "regenerate file paths" are best-effort and report every file they
 * touch, so the user can follow along and see which files were moved, which were
 * already in place, and which could not be updated. The entries are handed back
 * to the client and shown in a dialog; {@see $level} grades each one so the user
 * can filter the log down to warnings and errors.
 */
class MobileSheetsFileLogEntry {
	/** The file was processed as asked: moved, or already where it belongs. */
	public const LEVEL_INFO = 'info';

	/** Nothing was broken, but the file could not be updated (e.g. no naming rule). */
	public const LEVEL_WARNING = 'warning';

	/** The file could not be updated because of a real problem. */
	public const LEVEL_ERROR = 'error';

	public function __construct(
		public readonly int $songId,
		public readonly string $songTitle,
		public readonly string $filePath,
		public readonly string $message,
		public readonly string $level,
	) {
	}
}