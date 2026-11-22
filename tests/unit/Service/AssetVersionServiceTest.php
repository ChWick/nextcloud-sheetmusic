<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Tests\Unit\Service;

use OCA\SheetMusic\Service\AssetVersionService;
use PHPUnit\Framework\TestCase;

final class AssetVersionServiceTest extends TestCase {
	private string $tempDir;

	protected function setUp(): void {
		$this->tempDir = sys_get_temp_dir() . '/sheetmusic-asset-version-' . bin2hex(random_bytes(6));
		mkdir($this->tempDir . '/js', 0755, true);
		mkdir($this->tempDir . '/css', 0755, true);
		mkdir($this->tempDir . '/templates', 0755, true);
	}

	protected function tearDown(): void {
		$this->removeDirectory($this->tempDir);
	}

	public function testReturnsFallbackWhenNoTrackedFilesExist(): void {
		$service = new AssetVersionService($this->tempDir);
		self::assertSame('v1', $service->getVersion());
	}

	public function testComputesDeterministicHashThatChangesWhenJsOrHtmlChanges(): void {
		file_put_contents($this->tempDir . '/js/sheetmusic-main.mjs', 'console.log("v1");');
		file_put_contents($this->tempDir . '/templates/index.php', '<div id="sheetmusic"></div>');

		$v1a = (new AssetVersionService($this->tempDir))->getVersion();
		$v1b = (new AssetVersionService($this->tempDir))->getVersion();
		self::assertSame($v1a, $v1b);
		self::assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $v1a);

		// Updating JS changes the version string
		file_put_contents($this->tempDir . '/js/sheetmusic-main.mjs', 'console.log("v2");');
		$v2 = (new AssetVersionService($this->tempDir))->getVersion();
		self::assertNotSame($v1a, $v2);

		// Updating HTML template also changes the version string
		file_put_contents($this->tempDir . '/templates/index.php', '<div id="sheetmusic" class="updated"></div>');
		$v3 = (new AssetVersionService($this->tempDir))->getVersion();
		self::assertNotSame($v2, $v3);

		// Updating CSS entry point also changes the version string
		file_put_contents($this->tempDir . '/css/sheetmusic-main.css', "@import './main-new.chunk.css';");
		$v4 = (new AssetVersionService($this->tempDir))->getVersion();
		self::assertNotSame($v3, $v4);
	}

	public function testReadsAppVersionReleaseDateFromChangelogAndBuildDate(): void {
		mkdir($this->tempDir . '/appinfo', 0755, true);
		file_put_contents($this->tempDir . '/appinfo/info.xml', '<info><id>sheetmusic</id><version>1.2.3-rc1</version></info>');
		file_put_contents($this->tempDir . '/CHANGELOG.md', "# Changelog\n\n## [1.2.3-rc1] - 2026-11-20\n\n- Changes\n");
		file_put_contents($this->tempDir . '/js/sheetmusic-main.mjs', 'console.log("built");');

		$service = new AssetVersionService($this->tempDir);
		$meta = $service->getMetadata();

		self::assertSame('1.2.3-rc1', $meta['appVersion']);
		self::assertSame('2026-11-20', $meta['releaseDate']);
		self::assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $meta['assetVersion']);
		self::assertNotNull($meta['buildDate']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $meta['buildDate']);
	}

	public function testFallsBackToAssetMtimeWhenChangelogHasNoMatchingVersion(): void {
		mkdir($this->tempDir . '/appinfo', 0755, true);
		file_put_contents($this->tempDir . '/appinfo/info.xml', '<info><id>sheetmusic</id><version>9.9.9</version></info>');
		$jsFile = $this->tempDir . '/js/sheetmusic-main.mjs';
		file_put_contents($jsFile, 'console.log("built");');
		touch($jsFile, gmmktime(10, 0, 0, 11, 15, 2026));

		$service = new AssetVersionService($this->tempDir);
		self::assertSame('9.9.9', $service->getAppVersion());
		self::assertSame('2026-11-15', $service->getReleaseDate());
	}

	private function removeDirectory(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		foreach (scandir($dir) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $dir . '/' . $entry;
			if (is_dir($path)) {
				$this->removeDirectory($path);
			} else {
				@unlink($path);
			}
		}
		@rmdir($dir);
	}
}
