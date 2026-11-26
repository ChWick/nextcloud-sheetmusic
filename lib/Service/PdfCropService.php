<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\BackgroundJob\ComputePdfCropBoxesJob;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Computes, caches, and invalidates per-page normalized tight crop boxes for PDF files.
 *
 * Supports two complementary paths:
 *  1. Background job (`ComputePdfCropBoxesJob`) using `pdftoppm` or `gs` + PHP `gd` when available on the server.
 *  2. Client-side write-back (`saveCropBoxesForFile`) when pages are probed in the browser via PDF.js.
 */
class PdfCropService {
	public const FULL_PAGE_CROP = [
		'left' => 0.0,
		'top' => 0.0,
		'right' => 1.0,
		'bottom' => 1.0,
	];

	private const PAGES_PER_CHUNK = 20;
	private const CHUNK_TIMEOUT_SECONDS = 30;
	private const MAX_PAGES_TO_PROCESS = 2000;

	private ?string $detectedTool = null;
	private bool $toolChecked = false;

	public function __construct(
		private InternalSongFileMapper $songFileMapper,
		private IRootFolder $rootFolder,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Enqueue a Nextcloud background job to compute tight crop boxes for a single PDF file.
	 * Nextcloud's IJobList deduplicates identical class + argument pairs automatically.
	 */
	public function enqueueCropJob(string $userId, int $fileId): void {
		if ($userId === '' || $fileId <= 0) {
			return;
		}
		try {
			$this->jobList->add(ComputePdfCropBoxesJob::class, [
				'userId' => $userId,
				'fileId' => $fileId,
			]);
		} catch (\Throwable $e) {
			$this->logger->debug('Could not enqueue PDF crop job: ' . $e->getMessage(), [
				'userId' => $userId,
				'fileId' => $fileId,
			]);
		}
	}

	/**
	 * Validate and normalize a map of 1-indexed page numbers to normalized [0..1] crop boxes.
	 *
	 * @param array<mixed> $raw
	 * @return array<string, array{left: float, top: float, right: float, bottom: float}>
	 */
	public static function sanitizeCropBoxes(array $raw): array {
		$clean = [];
		foreach ($raw as $pageKey => $box) {
			if (!is_numeric($pageKey)) {
				continue;
			}
			$pageNum = (int)$pageKey;
			if ($pageNum < 1 || $pageNum > 5000 || !is_array($box)) {
				continue;
			}
			$left = isset($box['left']) && is_numeric($box['left']) ? (float)$box['left'] : null;
			$top = isset($box['top']) && is_numeric($box['top']) ? (float)$box['top'] : null;
			$right = isset($box['right']) && is_numeric($box['right']) ? (float)$box['right'] : null;
			$bottom = isset($box['bottom']) && is_numeric($box['bottom']) ? (float)$box['bottom'] : null;
			if ($left === null || $top === null || $right === null || $bottom === null) {
				continue;
			}
			$left = max(0.0, min(1.0, round($left, 4)));
			$top = max(0.0, min(1.0, round($top, 4)));
			$right = max(0.0, min(1.0, round($right, 4)));
			$bottom = max(0.0, min(1.0, round($bottom, 4)));
			if ($right <= $left || $bottom <= $top) {
				continue;
			}
			$clean[(string)$pageNum] = [
				'left' => $left,
				'top' => $top,
				'right' => $right,
				'bottom' => $bottom,
			];
		}
		return $clean;
	}

	/**
	 * Save (and optionally merge) client-computed crop boxes for a file accessible to the user.
	 *
	 * @param array<mixed> $rawBoxes
	 * @return ?array<string, array{left: float, top: float, right: float, bottom: float}>
	 */
	public function saveCropBoxesForFile(IUser $user, int $fileId, array $rawBoxes, bool $merge = true): ?array {
		if ($fileId <= 0) {
			return null;
		}
		$clean = self::sanitizeCropBoxes($rawBoxes);
		if ($clean === []) {
			return null;
		}

		// Verify user has access to this Nextcloud file
		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$nodes = $userFolder->getById($fileId);
			if ($nodes === [] || !($nodes[0] instanceof File)) {
				return null;
			}
		} catch (\Throwable) {
			return null;
		}

		$records = $this->songFileMapper->findByFileId($fileId, includeDeleted: false);
		if ($records === []) {
			return null;
		}

		$primary = $records[0];
		$merged = $merge ? ($primary->getCropBoxesMap() ?? []) : [];
		foreach ($clean as $pageKey => $box) {
			$merged[$pageKey] = $box;
		}

		$json = json_encode($merged);
		if (!is_string($json)) {
			return null;
		}

		$this->songFileMapper->updateCropBoxesByFileId($fileId, $json, $primary->fileHash);
		return $merged;
	}

	/**
	 * Background job entry point: compute and store tight crop boxes for one PDF file.
	 */
	public function processFileForJob(string $userId, int $fileId): bool {
		if ($userId === '' || $fileId <= 0) {
			return false;
		}

		$records = $this->songFileMapper->findByFileId($fileId, includeDeleted: false);
		if ($records === []) {
			return false;
		}

		$primary = $records[0];
		if ($primary->type !== InternalSongFile::FILE_TYPE_PDF) {
			return false;
		}

		// Skip if we already have fresh crop boxes covering all pages
		$existing = $primary->getCropBoxesMap();
		if ($existing !== null && $primary->pageCount > 0 && count($existing) >= $primary->pageCount) {
			return true;
		}

		if ($this->detectRasterTool() === null || !function_exists('imagecreatefrompng')) {
			return false;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$nodes = $userFolder->getById($fileId);
			if ($nodes === [] || !($nodes[0] instanceof File)) {
				return false;
			}
			$fileNode = $nodes[0];
		} catch (\Throwable $e) {
			$this->logger->debug('Skipping PDF crop job; file not accessible: ' . $e->getMessage(), [
				'userId' => $userId,
				'fileId' => $fileId,
			]);
			return false;
		}

		$computed = $this->computeCropBoxesForPdfFile(
			$fileNode,
			$primary->pageCount > 0 ? $primary->pageCount : null,
		);
		if ($computed === null || $computed === []) {
			return false;
		}

		$json = json_encode($computed);
		if (!is_string($json)) {
			return false;
		}

		$this->songFileMapper->updateCropBoxesByFileId($fileId, $json, $primary->fileHash);
		return true;
	}

	/**
	 * Stream a Nextcloud file node to a local temporary path without loading the whole file into PHP RAM.
	 */
	public static function streamNodeToTempFile(File $fileNode, string $destPath): bool {
		$src = null;
		$dst = null;
		try {
			$src = $fileNode->fopen('rb');
			if (is_resource($src)) {
				$dst = @fopen($destPath, 'wb');
				if (!is_resource($dst)) {
					return false;
				}
				$bytes = stream_copy_to_stream($src, $dst);
				return $bytes !== false && $bytes > 0;
			}
		} catch (\Throwable) {
			// Fallback to getContent() below if stream wrapper is unsupported
		} finally {
			if (is_resource($src)) {
				@fclose($src);
			}
			if (is_resource($dst)) {
				@fclose($dst);
			}
		}

		$content = $fileNode->getContent();
		return $content !== '' && @file_put_contents($destPath, $content) !== false;
	}

	/**
	 * Rasterize a PDF file at low DPI (36 DPI) in bounded page chunks using `pdftoppm` or `gs`,
	 * scanning and immediately deleting each temporary PNG to keep memory and /tmp usage small.
	 * Returns null when no server-side PDF rasterizer is installed.
	 *
	 * @return ?array<string, array{left: float, top: float, right: float, bottom: float}>
	 */
	public function computeCropBoxesForPdfFile(File $fileNode, ?int $expectedPageCount = null): ?array {
		$tool = $this->detectRasterTool();
		if ($tool === null || !function_exists('imagecreatefrompng')) {
			return null;
		}

		$tmpDir = sys_get_temp_dir() . '/sheetmusic-crop-' . bin2hex(random_bytes(8));
		if (!@mkdir($tmpDir, 0700, true)) {
			return null;
		}

		try {
			$pdfPath = $tmpDir . '/input.pdf';
			if (!self::streamNodeToTempFile($fileNode, $pdfPath)) {
				return null;
			}

			$maxPage = $expectedPageCount !== null && $expectedPageCount > 0
				? min($expectedPageCount, self::MAX_PAGES_TO_PROCESS)
				: self::MAX_PAGES_TO_PROCESS;

			$boxes = [];
			for ($firstPage = 1; $firstPage <= $maxPage; $firstPage += self::PAGES_PER_CHUNK) {
				$lastPage = min($maxPage, $firstPage + self::PAGES_PER_CHUNK - 1);
				$chunkPrefix = $tmpDir . '/chunk-' . $firstPage;

				$args = $tool === 'pdftoppm'
					? ['pdftoppm', '-png', '-r', '36', '-f', (string)$firstPage, '-l', (string)$lastPage, $pdfPath, $chunkPrefix]
					: [
						'gs',
						'-q',
						'-dNOPAUSE',
						'-dBATCH',
						'-dSAFER',
						'-sDEVICE=pnggray',
						'-r36',
						'-dFirstPage=' . $firstPage,
						'-dLastPage=' . $lastPage,
						'-sOutputFile=' . $chunkPrefix . '-%04d.png',
						$pdfPath,
					];

				$exitCode = $this->runCommandWithTimeout($args, self::CHUNK_TIMEOUT_SECONDS);
				$pngFiles = glob($chunkPrefix . '-*.png');
				if (!is_array($pngFiles) || $pngFiles === []) {
					if ($firstPage === 1 && $exitCode !== 0) {
						return null;
					}
					break;
				}

				natsort($pngFiles);
				$chunkCount = 0;
				foreach ($pngFiles as $pngPath) {
					$pageNum = $firstPage + $chunkCount;
					$img = @imagecreatefrompng($pngPath);
					if ($img instanceof \GdImage) {
						$boxes[(string)$pageNum] = self::detectInkBoundsFromGdImage($img);
						unset($img);
					} else {
						$boxes[(string)$pageNum] = self::FULL_PAGE_CROP;
					}
					@unlink($pngPath);
					$chunkCount++;
				}

				// Stop early if this chunk hit EOF of the PDF
				if ($chunkCount < ($lastPage - $firstPage + 1)) {
					break;
				}
			}

			return $boxes !== [] ? $boxes : null;
		} catch (\Throwable $e) {
			$this->logger->debug('Failed computing PDF crop boxes on server: ' . $e->getMessage());
			return null;
		} finally {
			$files = @glob($tmpDir . '/*');
			if (is_array($files)) {
				foreach ($files as $f) {
					@unlink($f);
				}
			}
			@rmdir($tmpDir);
		}
	}

	/**
	 * Execute a command with an argv array (no shell) and enforce a hard wall-clock timeout.
	 *
	 * @param list<string> $args
	 */
	private function runCommandWithTimeout(array $args, int $timeoutSeconds): int {
		if (!function_exists('proc_open')) {
			return -1;
		}
		$descriptors = [
			0 => ['file', '/dev/null', 'r'],
			1 => ['file', '/dev/null', 'w'],
			2 => ['file', '/dev/null', 'w'],
		];
		$pipes = [];
		$proc = @proc_open($args, $descriptors, $pipes);
		if (!is_resource($proc)) {
			return -1;
		}

		$deadline = microtime(true) + max(1, $timeoutSeconds);
		while (true) {
			$status = proc_get_status($proc);
			if (!$status['running']) {
				$exitCode = (int)$status['exitcode'];
				proc_close($proc);
				return $exitCode;
			}
			if (microtime(true) >= $deadline) {
				@proc_terminate($proc, 9);
				proc_close($proc);
				$this->logger->warning('Timed out while rasterizing PDF chunk for tight cropping', [
					'tool' => $args[0] ?? 'unknown',
					'timeoutSeconds' => $timeoutSeconds,
				]);
				return -1;
			}
			usleep(50000);
		}
	}

	/**
	 * Detect tight normalized [0..1] ink bounds from a GD image, matching frontend pdfCrop.ts.
	 *
	 * @return array{left: float, top: float, right: float, bottom: float}
	 */
	public static function detectInkBoundsFromGdImage(
		\GdImage $img,
		int $whiteThreshold = 242,
		float $marginRatio = 0.015,
		int $edgeIgnorePx = 1,
	): array {
		$width = imagesx($img);
		$height = imagesy($img);
		if ($width <= 4 || $height <= 4) {
			return self::FULL_PAGE_CROP;
		}

		$edgeIgnore = min($edgeIgnorePx, (int)floor(min($width, $height) / 10));
		$minX = $width;
		$minY = $height;
		$maxX = -1;
		$maxY = -1;

		for ($y = $edgeIgnore; $y < $height - $edgeIgnore; $y++) {
			for ($x = $edgeIgnore; $x < $width - $edgeIgnore; $x++) {
				$rgb = imagecolorat($img, $x, $y);
				if ($rgb === false) {
					continue;
				}
				$colors = imagecolorsforindex($img, $rgb);
				// GD alpha is 0 (opaque) to 127 (transparent)
				if ($colors['alpha'] > 118) {
					continue;
				}
				if (
					$colors['red'] < $whiteThreshold
					|| $colors['green'] < $whiteThreshold
					|| $colors['blue'] < $whiteThreshold
				) {
					if ($x < $minX) {
						$minX = $x;
					}
					if ($x > $maxX) {
						$maxX = $x;
					}
					if ($y < $minY) {
						$minY = $y;
					}
					if ($y > $maxY) {
						$maxY = $y;
					}
				}
			}
		}

		if ($maxX < $minX || $maxY < $minY) {
			return self::FULL_PAGE_CROP;
		}

		$rawLeft = $minX / $width;
		$rawTop = $minY / $height;
		$rawRight = ($maxX + 1) / $width;
		$rawBottom = ($maxY + 1) / $height;

		$left = max(0.0, round($rawLeft - $marginRatio, 4));
		$top = max(0.0, round($rawTop - $marginRatio, 4));
		$right = min(1.0, round($rawRight + $marginRatio, 4));
		$bottom = min(1.0, round($rawBottom + $marginRatio, 4));

		$cropW = $right - $left;
		$cropH = $bottom - $top;
		if ($cropW < 0.1 || $cropH < 0.1 || ($cropW > 0.96 && $cropH > 0.96)) {
			return self::FULL_PAGE_CROP;
		}

		return [
			'left' => $left,
			'top' => $top,
			'right' => $right,
			'bottom' => $bottom,
		];
	}

	private function detectRasterTool(): ?string {
		if ($this->toolChecked) {
			return $this->detectedTool;
		}
		$this->toolChecked = true;
		if (!function_exists('exec')) {
			return null;
		}
		foreach (['pdftoppm', 'gs'] as $candidate) {
			$out = [];
			$code = 1;
			@exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null', $out, $code);
			if ($code === 0 && !empty($out)) {
				$this->detectedTool = $candidate;
				return $candidate;
			}
		}
		return null;
	}
}
