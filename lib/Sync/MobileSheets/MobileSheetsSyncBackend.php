<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalSublibrary;
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

	public function exportLibrary(int $libraryId, string $destinationPath, string $userId, ?InternalSublibrary $sublibrary = null): void {
		$this->exporter->exportToFile($libraryId, $destinationPath, $userId, $sublibrary);
	}

	public function importLibrary(int $libraryId, string $sourcePath, string $userId, ?InternalSublibrary $sublibrary = null): void {
		$this->importer->importFromFile($libraryId, $sourcePath, $userId, null, $sublibrary);
	}

	public function getVirtualFileProperties(
		InternalLibrary $library,
		string $filename,
		string $userId,
		?InternalSublibrary $sublibrary = null,
		?string $publicToken = null,
	): ?array {
		if (!$this->exporter->hasLibraryData($library, $sublibrary)) {
			return null;
		}

		if ($filename === self::VIRTUAL_DB_NAME) {
			return [
				'size' => $this->databaseSize($library, $sublibrary, $userId, $publicToken),
				'contentType' => self::DB_CONTENT_TYPE,
				'lastModified' => $library->updatedAt,
				'etag' => $this->etagFor($library, $filename, $sublibrary, $userId, $publicToken),
			];
		}

		if ($filename === self::VIRTUAL_HASH_NAME) {
			$hashcodes = $this->exporter->generateHashcodes($library, $sublibrary, $userId, $publicToken);
			return [
				'size' => strlen($hashcodes),
				'contentType' => self::HASH_CONTENT_TYPE,
				'lastModified' => $library->updatedAt,
				'etag' => $this->etagFor($library, $filename, $sublibrary, $userId, $publicToken),
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
		?InternalSublibrary $sublibrary = null,
		?string $userId = null,
		?string $publicToken = null,
	): bool {
		if ($filename === self::VIRTUAL_DB_NAME) {
			return $this->handleGetDatabase($request, $response, $library, $isHead, $sublibrary, $userId, $publicToken);
		}

		if ($filename === self::VIRTUAL_HASH_NAME) {
			return $this->handleGetHashcodes($request, $response, $library, $isHead, $sublibrary, $userId, $publicToken);
		}

		return true;
	}

	public function handlePut(
		RequestInterface $request,
		ResponseInterface $response,
		InternalLibrary $library,
		string $filename,
		?InternalSublibrary $sublibrary = null,
		?string $userId = null,
		bool $canWrite = true,
	): bool {
		if (!$canWrite) {
			$response->setStatus(403);
			$response->setHeader('Content-Type', 'text/plain; charset=utf-8');
			$response->setBody('Forbidden: This library is shared as read-only.');
			return false;
		}

		if ($filename === self::VIRTUAL_DB_NAME) {
			return $this->handlePutDatabase($request, $response, $library, $sublibrary, $userId);
		}

		if ($filename === self::VIRTUAL_HASH_NAME) {
			return $this->handlePutHashcodes($response, $library, $sublibrary, $userId);
		}

		return true;
	}

	private function handleGetDatabase(
		RequestInterface $request,
		ResponseInterface $response,
		InternalLibrary $library,
		bool $isHead,
		?InternalSublibrary $sublibrary = null,
		?string $userId = null,
		?string $publicToken = null,
	): bool {
		$etag = $this->etagFor($library, self::VIRTUAL_DB_NAME, $sublibrary, $userId, $publicToken);

		if ($this->checkNotModified($request, $response, $etag)) {
			return false;
		}

		try {
			$cachePath = $this->ensureDatabaseExport($library, $sublibrary, $userId, $publicToken);
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
		$this->setSuccessHeaders($response, self::DB_CONTENT_TYPE, $size, $etag, $library);

		if (!$isHead) {
			$response->setBody(fopen($cachePath, 'rb'));
		}

		return false;
	}

	private function handlePutDatabase(
		RequestInterface $request,
		ResponseInterface $response,
		InternalLibrary $library,
		?InternalSublibrary $sublibrary = null,
		?string $userId = null,
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
			$this->importer->importFromFile($library, $tempUpload, $userId, null, $sublibrary);
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
		$response->setHeader('ETag', $this->etagFor($updated, self::VIRTUAL_DB_NAME, $sublibrary, $userId));
		return false;
	}

	private function handleGetHashcodes(
		RequestInterface $request,
		ResponseInterface $response,
		InternalLibrary $library,
		bool $isHead,
		?InternalSublibrary $sublibrary = null,
		?string $userId = null,
		?string $publicToken = null,
	): bool {
		$etag = $this->etagFor($library, self::VIRTUAL_HASH_NAME, $sublibrary, $userId, $publicToken);

		if ($this->checkNotModified($request, $response, $etag)) {
			return false;
		}

		$hashcodes = $this->exporter->generateHashcodes($library, $sublibrary, $userId, $publicToken);
		$this->setSuccessHeaders($response, self::HASH_CONTENT_TYPE, strlen($hashcodes), $etag, $library);

		if (!$isHead) {
			$response->setBody($hashcodes);
		}

		return false;
	}

	private function checkNotModified(RequestInterface $request, ResponseInterface $response, string $etag): bool {
		if ($request->getHeader('If-None-Match') === $etag) {
			$response->setStatus(304);
			$response->setHeader('ETag', $etag);
			return true;
		}
		return false;
	}

	private function setSuccessHeaders(
		ResponseInterface $response,
		string $contentType,
		int $contentLength,
		string $etag,
		InternalLibrary $library,
	): void {
		$response->setStatus(200);
		$response->setHeader('Content-Type', $contentType);
		$response->setHeader('Content-Length', (string)$contentLength);
		$response->setHeader('ETag', $etag);
		$response->setHeader('Last-Modified', $this->httpDate($library));
	}

	private function handlePutHashcodes(
		ResponseInterface $response,
		InternalLibrary $library,
		?InternalSublibrary $sublibrary = null,
	): bool {
		$response->setStatus(204);
		$response->setHeader('ETag', $this->etagFor($library, self::VIRTUAL_HASH_NAME, $sublibrary));
		return false;
	}

	public function ensureDatabaseExport(
		InternalLibrary $library,
		?InternalSublibrary $sublibrary = null,
		?string $userId = null,
		?string $publicToken = null,
	): string {
		$owner = function_exists('posix_geteuid') ? (string)posix_geteuid() : get_current_user();
		$tempDir = sys_get_temp_dir() . '/sheetmusic_dav_cache_' . $owner;
		if (!is_dir($tempDir)) {
			@mkdir($tempDir, 0700, true);
		}
		$subSuffix = $sublibrary !== null ? '_sub_' . (int)$sublibrary->id : '';
		$userSuffix = '';
		if ($publicToken !== null) {
			$userSuffix = '_pub_' . $publicToken;
		} elseif ($userId !== null && $userId !== $library->userId) {
			$userSuffix = '_u_' . $userId;
		}
		$prefix = $tempDir . '/sm_' . (int)$library->id . $userSuffix . $subSuffix . '_';
		$timestamp = max($library->updatedAt, $sublibrary?->updatedAt ?? 0);
		$cachePath = $prefix . $timestamp . '.db';

		if (!file_exists($cachePath)) {
			$partPath = $cachePath . '.' . bin2hex(random_bytes(4)) . '.part';
			try {
				$this->exporter->exportToFile($library, $partPath, $userId, $sublibrary, $publicToken);
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

	public function databaseSize(
		InternalLibrary $library,
		?InternalSublibrary $sublibrary = null,
		?string $userId = null,
		?string $publicToken = null,
	): int {
		try {
			return (int)filesize($this->ensureDatabaseExport($library, $sublibrary, $userId, $publicToken));
		} catch (\Throwable $e) {
			$this->logger->warning('Could not determine size of virtual MobileSheets database', [
				'exception' => $e,
				'libraryId' => $library->id,
			]);
			return 0;
		}
	}

	public function etagFor(
		InternalLibrary $library,
		string $filename,
		?InternalSublibrary $sublibrary = null,
		?string $userId = null,
		?string $publicToken = null,
	): string {
		$prefix = $filename === self::VIRTUAL_DB_NAME ? 'sm-db' : 'sm-hash';
		$userPart = '';
		if ($publicToken !== null) {
			$userPart = '-pub-' . $publicToken;
		} elseif ($userId !== null && $userId !== $library->userId) {
			$userPart = '-u-' . $userId;
		}
		$ts = max($library->updatedAt, $sublibrary?->updatedAt ?? 0);
		if ($sublibrary !== null) {
			return sprintf('"%s-%d%s-%d-%d"', $prefix, $library->id, $userPart, $sublibrary->id, $ts);
		}
		return sprintf('"%s-%d%s-%d"', $prefix, $library->id, $userPart, $ts);
	}

	private function httpDate(InternalLibrary $library): string {
		return gmdate('D, d M Y H:i:s', $library->updatedAt) . ' GMT';
	}
}
