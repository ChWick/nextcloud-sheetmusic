<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Service;

/**
 * Computes a deterministic short version hash from the app's built frontend
 * assets and entry template so browsers and the PWA Service Worker invalidate
 * stale app-shell caches automatically whenever JS, CSS, or HTML changes.
 */
class AssetVersionService {
	private const TRACKED_FILES = [
		'js/sheetmusic-main.mjs',
		'js/sheetmusic-pwa.mjs',
		'css/sheetmusic-main.css',
		'templates/index.php',
		'resources/pwa-sw.js',
	];

	private string $appRoot;
	private ?string $cachedVersion = null;

	public function __construct(?string $appRoot = null) {
		$this->appRoot = rtrim($appRoot ?? dirname(__DIR__, 2), '/');
	}

	public function getVersion(): string {
		if ($this->cachedVersion !== null) {
			return $this->cachedVersion;
		}

		$ctx = hash_init('sha256');
		$foundAny = false;
		foreach (self::TRACKED_FILES as $relPath) {
			$fullPath = $this->appRoot . '/' . $relPath;
			if (is_file($fullPath) && is_readable($fullPath)) {
				hash_update($ctx, $relPath . ':');
				hash_update_file($ctx, $fullPath);
				$foundAny = true;
			}
		}

		$this->cachedVersion = $foundAny ? substr(hash_final($ctx), 0, 12) : 'v1';
		return $this->cachedVersion;
	}
}
