<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

/**
 * One line of the log a library sync run produces: what happened to one file of
 * a song, or to the library as a whole.
 *
 * Runs like "sync file paths and metadata" are best-effort and report every file
 * they touch, so the user can follow along and see which files were moved, which
 * document properties were refreshed, which files were already up to date, and
 * which could not be updated. The entries are handed back to the client and shown
 * in the report dialog; {@see $level} grades each one so the user can filter the
 * log down to warnings and errors.
 *
 * Every entry carries a stable {@see $code} - the "custom error" the report maps
 * to a localized message and to the actions it offers - and {@see $fileId} holds
 * the Nextcloud node id of the file behind the entry when it can be resolved, so
 * the report can link straight to the file a warning or error is about.
 */
class MobileSheetsFileLogEntry {
	/** The file was processed as asked: moved, or already where it belongs. */
	public const LEVEL_INFO = 'info';

	/** Nothing was broken, but the file could not be updated (e.g. no naming rule). */
	public const LEVEL_WARNING = 'warning';

	/** The file could not be updated because of a real problem. */
	public const LEVEL_ERROR = 'error';

	/** Path phase: the file was moved to its naming-rule path. */
	public const CODE_PATH_MOVED = 'path-moved';

	/** Path phase: the file already sits at its naming-rule path. */
	public const CODE_PATH_CURRENT = 'path-current';

	/** Path phase: the song has no composer, artist or collection to name the file after. */
	public const CODE_NO_NAMING_RULE = 'no-naming-rule';

	/** Path phase: the stored path does not point into this user's files. */
	public const CODE_SOURCE_OUTSIDE_LIBRARY = 'source-outside-library';

	/** Path phase: the file the database records does not exist. */
	public const CODE_SOURCE_MISSING = 'source-missing';

	/** Path phase: the move happened but the database rejected it; the move was reverted. */
	public const CODE_PATH_PERSIST_FAILED = 'path-persist-failed';

	/** Path phase: anything else stopped the move. */
	public const CODE_PATH_FAILED = 'path-failed';

	/** Metadata phase: the document properties were written into the PDF. */
	public const CODE_METADATA_UPDATED = 'metadata-updated';

	/** Metadata phase: the PDF already carries exactly the song's document properties. */
	public const CODE_METADATA_CURRENT = 'metadata-current';

	/** Metadata phase: not a PDF, so there are no document properties to update. */
	public const CODE_NOT_PDF = 'not-a-pdf';

	/** Metadata phase: the file the database records does not exist. */
	public const CODE_METADATA_FILE_MISSING = 'metadata-file-missing';

	/** Metadata phase: the stored path points at something that is not a file. */
	public const CODE_METADATA_NOT_A_FILE = 'metadata-not-a-file';

	/** Metadata phase: the file exists but its content could not be read. */
	public const CODE_METADATA_UNREADABLE = 'metadata-unreadable';

	/** Metadata phase: the document properties could not be written. */
	public const CODE_METADATA_WRITE_FAILED = 'metadata-write-failed';

	/** Metadata phase: the PDF was written, but the database facts did not follow. */
	public const CODE_METADATA_FACTS_FAILED = 'metadata-facts-failed';

	/** Metadata phase: anything else stopped the update. */
	public const CODE_METADATA_FAILED = 'metadata-failed';

	/** Library scope: the MobileSheets hash codes file exists but could not be read. */
	public const CODE_HASHCODES_UNREADABLE = 'hashcodes-unreadable';

	/** Library scope: the refreshed MobileSheets hash codes file could not be saved. */
	public const CODE_HASHCODES_SAVE_FAILED = 'hashcodes-save-failed';

	/**
	 * The grade of every code the sync tool reports.
	 *
	 * The grade belongs to the code rather than to the place that raises it: a
	 * missing source file is an error whether the path phase or the metadata
	 * phase finds it, and a song without a naming rule stays a warning because
	 * nothing is broken. Unknown codes grade as errors, so a code this version
	 * does not know is never hidden.
	 */
	private const LEVEL_BY_CODE = [
		self::CODE_PATH_MOVED => self::LEVEL_INFO,
		self::CODE_PATH_CURRENT => self::LEVEL_INFO,
		self::CODE_NO_NAMING_RULE => self::LEVEL_WARNING,
		self::CODE_SOURCE_OUTSIDE_LIBRARY => self::LEVEL_ERROR,
		self::CODE_SOURCE_MISSING => self::LEVEL_ERROR,
		self::CODE_PATH_PERSIST_FAILED => self::LEVEL_ERROR,
		self::CODE_PATH_FAILED => self::LEVEL_ERROR,
		self::CODE_METADATA_UPDATED => self::LEVEL_INFO,
		self::CODE_METADATA_CURRENT => self::LEVEL_INFO,
		self::CODE_NOT_PDF => self::LEVEL_INFO,
		self::CODE_METADATA_FILE_MISSING => self::LEVEL_ERROR,
		self::CODE_METADATA_NOT_A_FILE => self::LEVEL_ERROR,
		self::CODE_METADATA_UNREADABLE => self::LEVEL_WARNING,
		self::CODE_METADATA_WRITE_FAILED => self::LEVEL_ERROR,
		self::CODE_METADATA_FACTS_FAILED => self::LEVEL_ERROR,
		self::CODE_METADATA_FAILED => self::LEVEL_ERROR,
		self::CODE_HASHCODES_UNREADABLE => self::LEVEL_WARNING,
		self::CODE_HASHCODES_SAVE_FAILED => self::LEVEL_WARNING,
	];

	/**
	 * The grade a report line with the given code gets.
	 *
	 * @param string $code one of the CODE_* constants, or '' for an entry that
	 *                     only carries a free-text message
	 */
	public static function levelForCode(string $code): string {
		return self::LEVEL_BY_CODE[$code] ?? self::LEVEL_ERROR;
	}

	public function __construct(
		public readonly int $songId,
		public readonly string $songTitle,
		public readonly string $filePath,
		public readonly string $message,
		public readonly string $level,
		/** Stable code of the outcome; '' when the entry only has a message. */
		public readonly string $code = '',
		/** Nextcloud node id of the file behind the entry, null when it cannot be resolved. */
		public readonly ?int $fileId = null,
	) {
	}
}
