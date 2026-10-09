<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCP\Files\File;
use OCP\Files\Node;

/**
 * The metadata MobileSheets keeps next to a file's path in the Files table.
 *
 * FileHash, FileSize and LastModified mirror the "hash, modified at, size"
 * triple of the library's hash codes file, so they describe the file's content
 * rather than its location. They are refreshed whenever the file is created or
 * changed, so the database never keeps a stale or empty timestamp.
 */
class MobileSheetsFileFacts {
	public function __construct(
		public readonly int $fileHash,
		public readonly int $fileSize,
		public readonly int $lastModified,
		public readonly int $sourceFilePageCount,
		public readonly string $pageOrder,
	) {
	}

	/**
	 * Derive the facts from the file as it sits in Nextcloud.
	 *
	 * LastModified is the file's modification time in milliseconds - the unit
	 * MobileSheets uses (the hash codes file stores the same value) - so it is
	 * refreshed to the file's real modification time on every change.
	 */
	public static function fromNode(Node $file): self {
		$content = $file instanceof File ? $file->getContent() : '';
		$content = is_string($content) ? $content : '';
		$pageCount = MobileSheetsPdf::pageCount($content);

		return new self(
			fileHash: MobileSheetsPdf::contentHash($content),
			fileSize: strlen($content),
			lastModified: (int)$file->getMTime() * 1000,
			sourceFilePageCount: $pageCount,
			pageOrder: MobileSheetsPdf::pageOrder($pageCount),
		);
	}
}
