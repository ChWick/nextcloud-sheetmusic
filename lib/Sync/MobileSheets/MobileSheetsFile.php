<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

class MobileSheetsFile {
	public function __construct(
		public readonly int $id,
		public readonly string $path,
		public readonly ?string $pageOrder = null,
		public readonly int $pageCount = 0,
		public readonly int $fileId = 0,
	) {
	}
}
