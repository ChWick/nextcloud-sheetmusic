<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Sync\SyncClientInterface;
use Psr\Log\LoggerInterface;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

class MobileSheetsSyncBackend implements SyncClientInterface {
	public const CLIENT_TYPE = 'mobilesheets';
	public const VIRTUAL_DB_NAME = 'mobilesheets.db';
	public const VIRTUAL_HASH_NAME = 'mobilesheets_hashcodes.txt';

	private const DB_CONTENT_TYPE = 'application/x-sqlite3';
	private const HASH_CONTENT_TYPE = 'text/plain; charset=utf-8';

	public function __construct(
		private MobileSheetsSqliteExporter $exporter,
		private MobileSheetsSqliteImporter $importer,
		private LibraryMapper $libraryMapper,
		private LoggerInterface $logger,
	) {
	}

	public function getClientType(): string {
		return self::CLIENT_TYPE;
	}

	public function getVirtualFileNames(): array {
		return [self::VIRTUAL_DB_NAME, self::VIRTUAL_HASH_NAME];
	}

	public function handlesVirtualFile(string $filename): bool {
		return $filename === self::VIRTUAL_DB_NAME || $filename === self::VIRTUAL_HASH_NAME;
	}

	public function exportLibrary(int $libraryId, string $destinationPath, string $userId): void {
		$this->exporter->exportToFile($libraryId, $destinationPath, $userId);
	}

	public function importLibrary(int $libraryId, string $sourcePath, string $userId): void {
		$this->importer->importFromFile($libraryId, $sourcePath, $userId);
	}

	public function getVirtualFileProperties(InternalLibrary $library, string $filename, string $userId): ?array {
		if ($filename === self::VIRTUAL_DB_NAME) {
			return [
				'size' => $this->databaseSize($library),
				'contentType' => self::DB_CONTENT_TYPE,
				'lastModified' => $library->updatedAt,
				'etag' => $this->etagFor($library, $filename),
			];
		}

		if ($filename === self::VIRTUAL_HASH_NAME) {
			$hashcodes = $this->exporter->generateHashcodes($library);
			return [
				'size' => strlen($hashcodes),
				'contentType' => self::HASH_CONTENT_TYPE,
				'lastModified' => $library->updatedAt,
				'etag' => $this->etagFor($library, $filename),
			];
		}

		return null;
	}

	public function handleGet(
		RequestInterface $request,
		ResponseInterface $response,
		InternalLibrary $library,
		string $filename,
		bool $isHead,
	): bool {
		if ($filename === self::VIRTUAL_DB_NAME) {
			return $this->handleGetDatabase($request, $response, $library, $isHead);
		}

		if ($filename === self::VIRTUAL_HASH_NAME) {
			return $this->handleGetHashcodes($request, $response, $library, $isHead);
		}

		return true;
	}

	public function handlePut(
		RequestInterface $request,
		ResponseInterface $response,
		InternalLibrary $library,
		string $filename,
	): bool {
		if ($filename === self::VIRTUAL_DB_NAME) {
			return $this->handlePutDatabase($request, $response, $library);
		}

		if ($filename === self::VIRTUAL_HASH_NAME) {
			return $this->handlePutHashcodes($response, $library);
		}

		return true;
	}

	private function handleGetDatabase(
		RequestInterface $request,
		ResponseInterface $response,
		InternalLibrary $library,
		bool $isHead,
	): bool {
		$etag = $this->etagFor($library, self::VIRTUAL_DB_NAME);

		if ($request->getHeader('If-None-Match') === $etag) {
			$response->setStatus(304);
			$response->setHeader('ETag', $etag);
			return false;
		}

		try {
			$cachePath = $this->ensureDatabaseExport($library);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to export MobileSheets database for WebDAV: ' . $e->getMessage(), [
				'exception' => $e,
				'libraryId' => $library->id,
			]);
			$response->setStatus(500);
			return false;
		}

		$size = (int)filesize($cachePath);
		$this->logger->info(sprintf('MobileSheetsSyncBackend: handleGetDatabase library=%d size=%d isHead=%d path=%s', $library->id, $size, $isHead ? 1 : 0, $cachePath));
		$response->setStatus(200);
		$response->setHeader('Content-Type', self::DB_CONTENT_TYPE);
		$response->setHeader('Content-Length', (string)$size);
		$response->setHeader('ETag', $etag);
		$response->setHeader('Last-Modified', $this->httpDate($library));

		if (!$isHead) {
			$response->setBody(fopen($cachePath, 'rb'));
		}

		return false;
	}

	private function handlePutDatabase(
		RequestInterface $request,
		ResponseInterface $response,
		InternalLibrary $library,
	): bool {
		$body = $request->getBody();
		$tempUpload = tempnam(sys_get_temp_dir(), 'sm_upload_') . '.db';

		if (is_resource($body)) {
			$dest = fopen($tempUpload, 'wb');
			stream_copy_to_stream($body, $dest);
			fclose($dest);
		} else {
			file_put_contents($tempUpload, (string)$body);
		}

		try {
			$this->importer->importFromFile($library, $tempUpload);
		} finally {
			if (file_exists($tempUpload)) {
				@unlink($tempUpload);
			}
		}

		if ($library->id !== null) {
			$this->libraryMapper->touch($library->id);
		}
		$updated = $library->id !== null ? $this->libraryMapper->findModelById($library->id) : null;
		$updated ??= $library;

		$response->setStatus(204);
		$response->setHeader('ETag', $this->etagFor($updated, self::VIRTUAL_DB_NAME));
		return false;
	}

	private function handleGetHashcodes(
		RequestInterface $request,
		ResponseInterface $response,
		InternalLibrary $library,
		bool $isHead,
	): bool {
		$etag = $this->etagFor($library, self::VIRTUAL_HASH_NAME);

		if ($request->getHeader('If-None-Match') === $etag) {
			$response->setStatus(304);
			$response->setHeader('ETag', $etag);
			return false;
		}

		$hashcodes = $this->exporter->generateHashcodes($library);

		$response->setStatus(200);
		$response->setHeader('Content-Type', self::HASH_CONTENT_TYPE);
		$response->setHeader('Content-Length', (string)strlen($hashcodes));
		$response->setHeader('ETag', $etag);
		$response->setHeader('Last-Modified', $this->httpDate($library));

		if (!$isHead) {
			$response->setBody($hashcodes);
		}

		return false;
	}

	private function handlePutHashcodes(
		ResponseInterface $response,
		InternalLibrary $library,
	): bool {
		$response->setStatus(204);
		$response->setHeader('ETag', $this->etagFor($library, self::VIRTUAL_HASH_NAME));
		return false;
	}

	public function ensureDatabaseExport(InternalLibrary $library): string {
		$owner = function_exists('posix_geteuid') ? (string)posix_geteuid() : get_current_user();
		$tempDir = sys_get_temp_dir() . '/sheetmusic_dav_cache_' . $owner;
		if (!is_dir($tempDir)) {
			@mkdir($tempDir, 0700, true);
		}
		$prefix = $tempDir . '/sm_' . (int)$library->id . '_';
		$cachePath = $prefix . $library->updatedAt . '.db';

		if (!file_exists($cachePath)) {
			$partPath = $cachePath . '.' . bin2hex(random_bytes(4)) . '.part';
			try {
				$this->exporter->exportToFile($library, $partPath);
				rename($partPath, $cachePath);
			} finally {
				if (file_exists($partPath)) {
					@unlink($partPath);
				}
			}

			// Drop exports of older states of this library.
			foreach (glob($prefix . '*.db') ?: [] as $stale) {
				if ($stale !== $cachePath) {
					@unlink($stale);
				}
			}
		}

		return $cachePath;
	}

	public function databaseSize(InternalLibrary $library): int {
		try {
			return (int)filesize($this->ensureDatabaseExport($library));
		} catch (\Throwable $e) {
			$this->logger->warning('Could not determine size of virtual MobileSheets database', [
				'exception' => $e,
				'libraryId' => $library->id,
			]);
			return 0;
		}
	}

	public function etagFor(InternalLibrary $library, string $filename): string {
		$prefix = $filename === self::VIRTUAL_DB_NAME ? 'sm-db' : 'sm-hash';
		return sprintf('"%s-%d-%d"', $prefix, $library->id, $library->updatedAt);
	}

	private function httpDate(InternalLibrary $library): string {
		return gmdate('D, d M Y H:i:s', $library->updatedAt) . ' GMT';
	}
}

