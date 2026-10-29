<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use JsonSerializable;

/**
 * Represents a single issue or status check item in the library integrity report.
 */
class IntegrityIssue implements JsonSerializable {
	public const TYPE_MISSING_FILE = 'missing_file';
	public const TYPE_ORPHAN_FILE = 'orphan_file';
	public const TYPE_DUPLICATE_FILE_PATH = 'duplicate_file_path';
	public const TYPE_DUPLICATE_ENTITY_ASSIGNMENT = 'duplicate_entity_assignment';
	public const TYPE_DUPLICATE_ENTITY = 'duplicate_entity';
	public const TYPE_INVALID_NAMING_RULE = 'invalid_naming_rule';
	public const TYPE_IDENTICAL_SONG = 'identical_song';
	public const TYPE_PDF_METADATA_MISMATCH = 'pdf_metadata_mismatch';
	public const TYPE_PDF_METADATA_UNREADABLE = 'pdf_metadata_unreadable';
	public const TYPE_ORPHAN_ENTITY_ASSIGNMENT = 'orphan_entity_assignment';
	public const TYPE_ORPHAN_SONG_FILE = 'orphan_song_file';
	public const TYPE_SONG_WITHOUT_FILES = 'song_without_files';
	public const TYPE_DUPLICATE_CLIENT_ID = 'duplicate_client_id';
	public const TYPE_ORPHAN_SONG_ATTRIBUTE = 'orphan_song_attribute';
	public const TYPE_ORPHAN_MISC_DATA = 'orphan_misc_data';

	public const SEVERITY_ERROR = 'error';
	public const SEVERITY_WARNING = 'warning';
	public const SEVERITY_INFO = 'info';

	public function __construct(
		public readonly string $type,
		public readonly string $severity,
		public readonly string $message,
		public readonly ?int $songId = null,
		public readonly ?string $songTitle = null,
		public readonly ?int $fileId = null,
		public readonly ?string $path = null,
		public readonly ?string $suggestedPath = null,
		public readonly bool $fixable = false,
		public readonly ?array $details = null,
	) {
	}

	/**
	 * @return array{
	 *     type: string,
	 *     severity: string,
	 *     message: string,
	 *     songId: ?int,
	 *     songTitle: ?string,
	 *     fileId: ?int,
	 *     path: ?string,
	 *     suggestedPath: ?string,
	 *     fixable: bool,
	 *     details: ?array
	 * }
	 */
	public function jsonSerialize(): array {
		return [
			'type' => $this->type,
			'severity' => $this->severity,
			'message' => $this->message,
			'songId' => $this->songId,
			'songTitle' => $this->songTitle,
			'fileId' => $this->fileId,
			'path' => $this->path,
			'suggestedPath' => $this->suggestedPath,
			'fixable' => $this->fixable,
			'details' => $this->details,
		];
	}
}
