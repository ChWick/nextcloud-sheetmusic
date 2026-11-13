<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Dav;

use OCP\IRequest;
use Sabre\HTTP\RequestInterface;

/**
 * Detects whether an incoming WebDAV or HTTP request originates from an official
 * synchronization client (e.g., Nextcloud Desktop / mirall, Android, or iOS sync clients).
 */
class SyncClientDetector {
	/**
	 * Secondary fallback regex matching common sync client signatures in user agents.
	 */
	private const FALLBACK_SYNC_CLIENT_PATTERN = '/(?:mirall|csyncoC|Nextcloud-android|Nextcloud-iOS)/i';

	/**
	 * Check if the given WebDAV request originates from an official sync client.
	 */
	public function isSyncClient(RequestInterface $request): bool {
		$userAgent = $request->getHeader('User-Agent') ?? '';
		return $this->isSyncClientUserAgent($userAgent);
	}

	/**
	 * Check if a User-Agent string matches official Nextcloud sync clients.
	 */
	public function isSyncClientUserAgent(string $userAgent): bool {
		$trimmed = trim($userAgent);
		if ($trimmed === '') {
			return false;
		}

		return preg_match(IRequest::USER_AGENT_CLIENT_DESKTOP, $trimmed) === 1
			|| preg_match(IRequest::USER_AGENT_CLIENT_ANDROID, $trimmed) === 1
			|| preg_match(IRequest::USER_AGENT_CLIENT_IOS, $trimmed) === 1
			|| preg_match(self::FALLBACK_SYNC_CLIENT_PATTERN, $trimmed) === 1;
	}
}
