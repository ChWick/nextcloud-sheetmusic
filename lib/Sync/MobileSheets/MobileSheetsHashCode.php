<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

/**
 * One entry of a MobileSheets hash codes file.
 *
 * The file stores four lines per file:
 *
 *   /remote.php/dav/files/uid/.../song.pdf   <- path, as stored in the database
 *   561292453                                <- content hash (signed 32-bit integer)
 *   1789312275000                            <- modification time in milliseconds
 *   839797                                   <- size in bytes
 *
 * The three values are kept verbatim as strings so the file can be rewritten
 * byte for byte; they are metadata of the file's content and therefore stay
 * unchanged when the file is moved or renamed.
 */
class MobileSheetsHashCode {
	public function __construct(
		public readonly string $path,
		public readonly string $hashCode,
		public readonly string $modifiedAt,
		public readonly string $size,
	) {
	}

	public function withPath(string $path): self {
		return new self($path, $this->hashCode, $this->modifiedAt, $this->size);
	}
}
