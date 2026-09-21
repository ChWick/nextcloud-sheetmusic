<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Service;

/**
 * One MobileSheets file location expressed in both representations.
 *
 *  - internal: Nextcloud node path,           e.g. "/admin/files/Noten/sub/file.pdf"
 *  - webdav:   path as stored in the database, e.g. "/remote.php/dav/files/admin/Noten/sub/file.pdf"
 *
 * All filesystem work must use $internal; $webdav exists purely to read from and
 * write back to the MobileSheets database.
 */
class MobileSheetsPath {
	public function __construct(
		public readonly string $internal,
		public readonly string $webdav,
	) {
	}
}