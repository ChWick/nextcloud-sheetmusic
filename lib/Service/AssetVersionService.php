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
		'css/sheetmusic-pwa.css',
		'templates/index.php',
		'resources/pwa-sw.js',
		'appinfo/info.xml',
	];

	private const BUILD_ASSET_FILES = [
		'js/sheetmusic-main.mjs',
		'js/sheetmusic-pwa.mjs',
		'css/sheetmusic-main.css',
		'css/sheetmusic-pwa.css',
	];

	private string $appRoot;
	private ?string $cachedVersion = null;
	private ?string $cachedAppVersion = null;
	private bool $releaseDateResolved = false;
	private ?string $cachedReleaseDate = null;
	private bool $buildDateResolved = false;
	private ?string $cachedBuildDate = null;
	private bool $latestBuildMtimeResolved = false;
	private ?int $cachedLatestBuildMtime = null;

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

	public function getAppVersion(): string {
		if ($this->cachedAppVersion !== null) {
			return $this->cachedAppVersion;
		}

		$infoPath = $this->appRoot . '/appinfo/info.xml';
		if (is_file($infoPath) && is_readable($infoPath)) {
			$raw = file_get_contents($infoPath);
			if ($raw !== false && preg_match('/<version>\s*([^<\s]+)\s*<\/version>/', $raw, $matches) === 1) {
				$this->cachedAppVersion = $matches[1];
				return $this->cachedAppVersion;
			}
		}

		$this->cachedAppVersion = 'dev';
		return $this->cachedAppVersion;
	}

	public function getReleaseDate(): ?string {
		if ($this->releaseDateResolved) {
			return $this->cachedReleaseDate;
		}
		$this->releaseDateResolved = true;

		$appVersion = $this->getAppVersion();
		$changelogPath = $this->appRoot . '/CHANGELOG.md';
		if ($appVersion !== 'dev' && is_file($changelogPath) && is_readable($changelogPath)) {
			$raw = file_get_contents($changelogPath);
			if ($raw !== false) {
				$pattern = '/^##\s+\[?' . preg_quote($appVersion, '/') . '\]?\s*-\s*(\d{4}-\d{2}-\d{2})\b/m';
				if (preg_match($pattern, $raw, $matches) === 1) {
					$this->cachedReleaseDate = $matches[1];
					return $this->cachedReleaseDate;
				}
			}
		}

		$maxMtime = $this->getLatestBuildAssetMtime();
		$this->cachedReleaseDate = $maxMtime !== null ? gmdate('Y-m-d', $maxMtime) : null;
		return $this->cachedReleaseDate;
	}

	public function getBuildDate(): ?string {
		if ($this->buildDateResolved) {
			return $this->cachedBuildDate;
		}
		$this->buildDateResolved = true;

		$maxMtime = $this->getLatestBuildAssetMtime();
		$this->cachedBuildDate = $maxMtime !== null ? gmdate('Y-m-d\TH:i:s\Z', $maxMtime) : null;
		return $this->cachedBuildDate;
	}

	/**
	 * @return array{appVersion: string, assetVersion: string, releaseDate: ?string, buildDate: ?string}
	 */
	public function getMetadata(): array {
		return [
			'appVersion' => $this->getAppVersion(),
			'assetVersion' => $this->getVersion(),
			'releaseDate' => $this->getReleaseDate(),
			'buildDate' => $this->getBuildDate(),
		];
	}

	private function getLatestBuildAssetMtime(): ?int {
		if ($this->latestBuildMtimeResolved) {
			return $this->cachedLatestBuildMtime;
		}
		$this->latestBuildMtimeResolved = true;

		$maxMtime = null;
		foreach (self::BUILD_ASSET_FILES as $relPath) {
			$fullPath = $this->appRoot . '/' . $relPath;
			if (is_file($fullPath)) {
				$mtime = @filemtime($fullPath);
				if (is_int($mtime) && ($maxMtime === null || $mtime > $maxMtime)) {
					$maxMtime = $mtime;
				}
			}
		}
		$this->cachedLatestBuildMtime = $maxMtime;
		return $this->cachedLatestBuildMtime;
	}
}
