<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace TestSupport;

use Sabre\HTTP\ResponseInterface;
use Sabre\HTTP\Sapi;

class RecordingSapi extends Sapi {
	/** @var list<ResponseInterface> */
	public static array $sent = [];

	public static function sendResponse(ResponseInterface $response): void {
		self::$sent[] = $response;
	}
}
