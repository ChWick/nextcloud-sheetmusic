<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Dav;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Service\MobileSheetsSqliteExporter;
use OCA\SheetMusic\Service\MobileSheetsSqliteImporter;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\Xml\Property\GetLastModified;
use Sabre\DAV\Xml\Property\ResourceType;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

class SheetMusicDavPlugin extends ServerPlugin {
	public const VIRTUAL_DB_NAME = 'mobilesheets.db';
	public const VIRTUAL_HASH_NAME = 'mobilesheets_hashcodes.txt';

	private ?Server $server = null;

	public function __construct(
		private LibraryMapper $libraryMapper,
		private MobileSheetsSqliteExporter $exporter,
		private MobileSheetsSqliteImporter $importer,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function initialize(Server $server): void {
		$this->server = $server;
		$server->on('beforeMethod:*', [$this, 'beforeMethod'], 10);
		$server->on('afterMethod:PROPFIND', [$this, 'afterPropfind'], 10);
	}

	/**
	 * Intercept WebDAV operations targeting virtual MobileSheets files.
	 */
	public function beforeMethod(RequestInterface $request, ResponseInterface $response): bool {
		$path = trim($request->getPath(), '/');
		$match = $this->matchVirtualPath($path);
		if ($match === null) {
			return true;
		}

		$library = $match['library'];
		$filename = $match['filename'];
		$method = strtoupper($request->getMethod());

		try {
			if ($filename === self::VIRTUAL_DB_NAME) {
				return match ($method) {
					'GET', 'HEAD' => $this->handleGetDatabase($request, $response, $library, $method === 'HEAD'),
					'PUT' => $this->handlePutDatabase($request, $response, $library),
					'PROPFIND' => $this->handlePropfindFile($request, $response, $library, $filename, 'application/x-sqlite3'),
					default => true,
				};
			}

			if ($filename === self::VIRTUAL_HASH_NAME) {
				return match ($method) {
					'GET', 'HEAD' => $this->handleGetHashcodes($request, $response, $library, $method === 'HEAD'),
					'PUT' => $this->handlePutHashcodes($request, $response, $library),
					'PROPFIND' => $this->handlePropfindFile($request, $response, $library, $filename, 'text/plain; charset=utf-8'),
					default => true,
				};
			}
		} catch (\Throwable $e) {
			$this->logger->error('Error processing virtual WebDAV request: ' . $e->getMessage(), [
				'exception' => $e,
				'path' => $path,
				'method' => $method,
			]);
			$response->setStatus(500);
			return false;
		}

		return true;
	}

	/**
	 * Inject virtual files into PROPFIND directory listings of registered libraries.
	 */
	public function afterPropfind(RequestInterface $request, ResponseInterface $response): void {
		$depth = $request->getHeader('Depth');
		if ($depth === '0' || $response->getStatus() !== 207) {
			return;
		}

		$path = trim($request->getPath(), '/');
		$library = $this->findLibraryByFolderPath($path);
		if ($library === null || $library->id === null) {
			return;
		}

		$body = (string)$response->getBody();
		if ($body === '' || !str_contains($body, '</d:multistatus>')) {
			return;
		}

		// Check if already injected
		if (str_contains($body, self::VIRTUAL_DB_NAME)) {
			return;
		}

		$baseUri = rtrim($this->server?->getBaseUri() ?? '', '/') . '/';
		$folderUri = $baseUri . $path;

		$dbHref = $folderUri . '/' . self::VIRTUAL_DB_NAME;
		$hashHref = $folderUri . '/' . self::VIRTUAL_HASH_NAME;

		$dbEtag = '"sm-db-' . $library->id . '-' . $library->updatedAt . '"';
		$hashEtag = '"sm-hash-' . $library->id . '-' . $library->updatedAt . '"';
		$lastMod = gmdate('D, d M Y H:i:s GMT', $library->updatedAt ?: time());

		$injectedResponses = sprintf(
			'
  <d:response>
    <d:href>%s</d:href>
    <d:propstat>
      <d:prop>
        <d:getlastmodified>%s</d:getlastmodified>
        <d:getcontentlength>0</d:getcontentlength>
        <d:getcontenttype>application/x-sqlite3</d:getcontenttype>
        <d:getetag>%s</d:getetag>
        <d:resourcetype/>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>
  <d:response>
    <d:href>%s</d:href>
    <d:propstat>
      <d:prop>
        <d:getlastmodified>%s</d:getlastmodified>
        <d:getcontentlength>0</d:getcontentlength>
        <d:getcontenttype>text/plain; charset=utf-8</d:getcontenttype>
        <d:getetag>%s</d:getetag>
        <d:resourcetype/>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>',
			htmlspecialchars($dbHref, ENT_XML1),
			$lastMod,
			htmlspecialchars($dbEtag, ENT_XML1),
			htmlspecialchars($hashHref, ENT_XML1),
			$lastMod,
			htmlspecialchars($hashEtag, ENT_XML1),
		);

		$newBody = str_replace('</d:multistatus>', $injectedResponses . "\n</d:multistatus>", $body);
		$response->setBody($newBody);
		$response->setHeader('Content-Length', (string)strlen($newBody));
	}

	private function handleGetDatabase(
		RequestInterface $request,
		ResponseInterface $response,
		InternalLibrary $library,
		bool $isHead,
	): bool {
		$etag = '"sm-db-' . $library->id . '-' . $library->updatedAt . '"';

		if ($request->getHeader('If-None-Match') === $etag) {
			$response->setStatus(304);
			return false;
		}

		$tempDir = sys_get_temp_dir() . '/sheetmusic_cache';
		if (!is_dir($tempDir)) {
			mkdir($tempDir, 0777, true);
		}
		$cachePath = $tempDir . '/sm_' . $library->id . '_' . $library->updatedAt . '.db';

		if (!file_exists($cachePath)) {
			$this->exporter->exportToFile($library, $cachePath);
		}

		$size = (int)filesize($cachePath);
		$response->setStatus(200);
		$response->setHeader('Content-Type', 'application/x-sqlite3');
		$response->setHeader('Content-Length', (string)$size);
		$response->setHeader('ETag', $etag);
		$response->setHeader('Last-Modified', gmdate('D, d M Y H:i:s GMT', $library->updatedAt ?: time()));

		if (!$isHead) {
			$stream = fopen($cachePath, 'rb');
			$response->setBody($stream);
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

		$response->setStatus(204);
		$response->setHeader('ETag', '"sm-db-' . $library->id . '-' . time() . '"');
		return false;
	}

	private function handleGetHashcodes(
		RequestInterface $request,
		ResponseInterface $response,
		InternalLibrary $library,
		bool $isHead,
	): bool {
		$etag = '"sm-hash-' . $library->id . '-' . $library->updatedAt . '"';

		if ($request->getHeader('If-None-Match') === $etag) {
			$response->setStatus(304);
			return false;
		}

		$hashcodes = $this->exporter->generateHashcodes($library);

		$response->setStatus(200);
		$response->setHeader('Content-Type', 'text/plain; charset=utf-8');
		$response->setHeader('Content-Length', (string)strlen($hashcodes));
		$response->setHeader('ETag', $etag);
		$response->setHeader('Last-Modified', gmdate('D, d M Y H:i:s GMT', $library->updatedAt ?: time()));

		if (!$isHead) {
			$response->setBody($hashcodes);
		}

		return false;
	}

	private function handlePutHashcodes(
		RequestInterface $request,
		ResponseInterface $response,
		InternalLibrary $library,
	): bool {
		// Acknowledge hashcodes upload
		$response->setStatus(204);
		return false;
	}

	private function handlePropfindFile(
		RequestInterface $request,
		ResponseInterface $response,
		InternalLibrary $library,
		string $filename,
		string $contentType,
	): bool {
		$response->setStatus(207);
		$response->setHeader('Content-Type', 'application/xml; charset=utf-8');

		$etag = '"sm-' . ($filename === self::VIRTUAL_DB_NAME ? 'db' : 'hash') . '-' . $library->id . '-' . $library->updatedAt . '"';

		$properties = [
			'href' => $request->getPath(),
			200 => [
				'{DAV:}getlastmodified' => new GetLastModified($library->updatedAt ?: time()),
				'{DAV:}getcontentlength' => 0,
				'{DAV:}getcontenttype' => $contentType,
				'{DAV:}getetag' => $etag,
				'{DAV:}resourcetype' => new ResourceType(null),
			],
		];

		$xml = $this->server?->generateMultiStatus([$properties]);
		if ($xml !== null) {
			$response->setBody($xml);
		}

		return false;
	}

	/**
	 * @return array{library: InternalLibrary, filename: string}|null
	 */
	public function matchVirtualPath(string $path): ?array {
		$parts = explode('/', trim($path, '/'));
		if (count($parts) < 3 || $parts[0] !== 'files') {
			return null;
		}

		$userId = $parts[1];
		$filename = end($parts);
		if ($filename !== self::VIRTUAL_DB_NAME && $filename !== self::VIRTUAL_HASH_NAME) {
			return null;
		}

		// Relative folder path
		$folderParts = array_slice($parts, 2, -1);
		$folderPath = implode('/', $folderParts);

		$libraries = $this->libraryMapper->findAllForUser($userId);
		foreach ($libraries as $libData) {
			$cleanLibPath = trim($libData['path'], '/');
			if ($cleanLibPath === $folderPath || $cleanLibPath === '/' . $folderPath) {
				$model = $this->libraryMapper->findModelById($libData['id']);
				if ($model !== null) {
					return ['library' => $model, 'filename' => $filename];
				}
			}
		}

		return null;
	}

	public function findLibraryByFolderPath(string $path): ?InternalLibrary {
		$parts = explode('/', trim($path, '/'));
		if (count($parts) < 2 || $parts[0] !== 'files') {
			return null;
		}

		$userId = $parts[1];
		$folderParts = array_slice($parts, 2);
		$folderPath = implode('/', $folderParts);

		$libraries = $this->libraryMapper->findAllForUser($userId);
		foreach ($libraries as $libData) {
			$cleanLibPath = trim($libData['path'], '/');
			if ($cleanLibPath === $folderPath || $cleanLibPath === '/' . $folderPath) {
				return $this->libraryMapper->findModelById($libData['id']);
			}
		}

		return null;
	}
}
