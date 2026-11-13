<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Manages import progress tracking for asynchronous or polled UI progress bars.
 */
class ImportProgressService {
	private ?ICache $cache = null;

	/** @var array<string, array<string, mixed>> In-memory fallback if cache is unavailable */
	private static array $memoryStore = [];

	public function __construct(?ICacheFactory $cacheFactory = null) {
		if ($cacheFactory !== null) {
			try {
				$this->cache = $cacheFactory->createDistributed('sheetmusic_progress');
			} catch (\Throwable) {
				$this->cache = null;
			}
		}
	}

	public function start(string $progressId, string $message = ''): void {
		$data = [
			'progressId' => $progressId,
			'status' => 'running',
			'stage' => 'starting',
			'current' => 0,
			'total' => 0,
			'percent' => 0,
			'message' => $message ?: 'Starting import...',
			'error' => null,
		];
		$this->save($progressId, $data);
	}

	public function update(
		string $progressId,
		string $stage,
		int $current,
		int $total,
		string $detail = '',
		bool $isMobileSheets = false,
	): void {
		$percent = 0;
		if ($total > 0) {
			if ($isMobileSheets) {
				if ($stage === 'songs') {
					$percent = (int)round(($current / $total) * 45);
				} elseif ($stage === 'files') {
					$percent = 45 + (int)round(($current / $total) * 45);
				} else {
					$percent = 90 + (int)round(($current / $total) * 9);
				}
			} elseif ($stage === 'fixing' || $stage === 'fix') {
				$percent = (int)round(($current / $total) * 90);
			} elseif ($stage === 'audit' || $stage === 'auditing') {
				$percent = 5 + (int)round(($current / $total) * 82);
			} elseif ($stage === 'entities') {
				$percent = 3;
			} elseif ($stage === 'orphans') {
				$percent = 90;
			} elseif ($stage === 'finalizing') {
				$percent = 95;
			} else {
				$percent = (int)round(($current / $total) * 99);
			}
		} elseif ($stage === 'entities') {
			$percent = 3;
		} elseif ($stage === 'orphans') {
			$percent = 90;
		} elseif ($stage === 'finalizing') {
			$percent = 95;
		}
		$percent = max(0, min(99, $percent));

		$message = match ($stage) {
			'songs' => $total > 0 ? "Importing song $current of $total" . ($detail !== '' ? ": $detail" : '') : 'Importing songs...',
			'files' => $total > 0 ? "Importing file $current of $total" . ($detail !== '' ? ": $detail" : '') : 'Importing files...',
			'reconcile' => $total > 0 ? "Importing file $current of $total" . ($detail !== '' ? ": $detail" : '') : 'Importing files...',
			'fixing', 'fix' => $total > 0 ? "Fixing issue $current of $total" . ($detail !== '' ? ": $detail" : '') : 'Fixing issues...',
			'audit', 'auditing' => $total > 0 ? "Auditing song $current of $total" . ($detail !== '' ? ": $detail" : '') : 'Auditing library songs...',
			'entities' => $detail ?: 'Checking database entities...',
			'orphans' => $detail ?: 'Scanning storage for orphan files...',
			'finalizing' => $detail ?: 'Finalizing...',
			default => $detail ?: 'Processing...',
		};

		$data = [
			'progressId' => $progressId,
			'status' => 'running',
			'stage' => $stage,
			'current' => $current,
			'total' => $total,
			'percent' => $percent,
			'message' => $message,
			'error' => null,
		];
		$this->save($progressId, $data);
	}

	public function complete(string $progressId, string $message = 'Import completed'): void {
		$data = [
			'progressId' => $progressId,
			'status' => 'completed',
			'stage' => 'completed',
			'current' => 100,
			'total' => 100,
			'percent' => 100,
			'message' => $message,
			'error' => null,
		];
		$this->save($progressId, $data);
	}

	public function fail(string $progressId, string $errorMessage): void {
		$data = [
			'progressId' => $progressId,
			'status' => 'error',
			'stage' => 'error',
			'current' => 0,
			'total' => 0,
			'percent' => 0,
			'message' => $errorMessage,
			'error' => $errorMessage,
		];
		$this->save($progressId, $data);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get(string $progressId): ?array {
		if ($this->cache !== null) {
			try {
				$data = $this->cache->get($progressId);
				if (is_array($data)) {
					return $data;
				}
			} catch (\Throwable) {
			}
		}
		return self::$memoryStore[$progressId] ?? null;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function save(string $progressId, array $data): void {
		self::$memoryStore[$progressId] = $data;
		if ($this->cache !== null) {
			try {
				$this->cache->set($progressId, $data, 300);
			} catch (\Throwable) {
			}
		}
	}
}
