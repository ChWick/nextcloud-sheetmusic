<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Dav;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalSublibrary;
use OCA\SheetMusic\Service\SublibraryService;
use OCA\SheetMusic\Sync\SyncClientInterface;
use OCA\SheetMusic\Sync\SyncClientRegistry;
use Psr\Log\LoggerInterface;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\Xml\Property\GetLastModified;
use Sabre\DAV\Xml\Property\ResourceType;
use Sabre\DAV\Xml\Request\PropFind as PropFindRequest;

/**
 * Builds and injects WebDAV PROPFIND multistatus entries for virtual library files
 * and virtual sublibrary directories.
 */
final class SheetMusicPropfindBuilder {
	private const OC_NS = 'http://owncloud.org/ns';

	private ?Server $server = null;

	public function __construct(
		private SyncClientRegistry $registry,
		private LoggerInterface $logger,
		private ?SublibraryService $sublibraryService = null,
		private string $sublibrariesDir = 'SubLibraries',
	) {
	}

	public function setServer(?Server $server): void {
		$this->server = $server;
	}

	/**
	 * Append the virtual file entries to a PROPFIND response body, keeping
	 * every real entry that was already present.
	 */
	public function injectVirtualEntries(
		string $xml,
		InternalLibrary $library,
		string $path,
		?string $requestBody,
		?string $userId = null,
		?string $publicToken = null,
		bool $canWrite = true,
		bool $includeSublibraries = true,
		bool $includeVirtualFiles = true,
	): string {
		if ($xml === '') {
			return $xml;
		}

		try {
			$document = new \DOMDocument('1.0', 'UTF-8');
			if (!@$document->loadXML($xml) || $document->documentElement === null) {
				return $xml;
			}
			$document->encoding = 'UTF-8';

			$existing = $this->listedFilenames($document);
			$sublibrary = null;
			if ($library->sublibraryId !== null && $this->sublibraryService !== null) {
				$sublibrary = $this->sublibraryService->getSublibrary($library->sublibraryId);
			}

			$entries = [];
			if ($includeVirtualFiles) {
				foreach ($this->registry->getAllVirtualFileNames() as $filename) {
					// A real file of that name takes precedence over the virtual one.
					if (in_array($filename, $existing, true)) {
						continue;
					}
					$client = $this->registry->getClientForVirtualFile($filename);
					$entry = $this->buildPropfindEntry($library, $path . '/' . $filename, $filename, $requestBody, $client, $sublibrary, $userId, $publicToken, $canWrite);
					if ($entry !== null) {
						$entries[] = $entry;
					}
				}
			}

			if ($includeSublibraries && $this->sublibraryService !== null && $library->id !== null && $library->sublibraryId === null) {
				$sublibs = $this->sublibraryService->getSublibraries((int)$library->id);
				if (!empty($sublibs) && !in_array($this->sublibrariesDir, $existing, true)) {
					$entries[] = $this->buildDirectoryPropfindEntry(
						$path . '/' . $this->sublibrariesDir,
						$this->sublibrariesDir,
						$requestBody,
						$library->updatedAt,
					);
				}
			}

			if ($entries === []) {
				return $xml;
			}

			$virtual = new \DOMDocument('1.0', 'UTF-8');
			if (!$virtual->loadXML($this->generateMultiStatus($entries)) || $virtual->documentElement === null) {
				return $xml;
			}
			$virtual->encoding = 'UTF-8';

			foreach ($virtual->documentElement->childNodes as $node) {
				if ($node instanceof \DOMElement && $node->localName === 'response') {
					$document->documentElement->appendChild($document->importNode($node, true));
				}
			}

			return (string)$document->saveXML();
		} catch (\Throwable $e) {
			$this->logger->error('Failed to add virtual MobileSheets files to PROPFIND response: ' . $e->getMessage(), [
				'exception' => $e,
				'path' => $path,
			]);
			return $xml;
		}
	}

	/**
	 * Build one multistatus entry for a virtual file.
	 *
	 * @return array<int|string, mixed>|null
	 */
	public function buildPropfindEntry(
		InternalLibrary $library,
		string $path,
		string $filename,
		?string $requestBody,
		?SyncClientInterface $client = null,
		?InternalSublibrary $sublibrary = null,
		?string $userId = null,
		?string $publicToken = null,
		bool $canWrite = true,
	): ?array {
		$client ??= $this->registry->getClientForVirtualFile($filename);
		$targetUserId = $userId ?? $library->userId ?? '';
		$props = $client?->getVirtualFileProperties($library, $filename, $targetUserId, $sublibrary, $publicToken);
		if ($props === null) {
			return null;
		}

		[$requestType, $requested] = $this->parsePropFindRequest($requestBody);

		$size = $props['size'] ?? 0;
		$contentType = $props['contentType'] ?? 'application/octet-stream';
		$lastModified = $props['lastModified'] ?? max($library->updatedAt ?: time(), $sublibrary?->updatedAt ?? 0);
		$etag = $props['etag'] ?? ($sublibrary !== null
			? sprintf('"sm-%d-%d-%d"', $library->id, $sublibrary->id, $lastModified)
			: sprintf('"sm-%d-%d"', $library->id, $library->updatedAt));

		$propFind = new PropFind($path, $requested, 0, $requestType);
		$propFind->set('{DAV:}displayname', $filename);
		$propFind->set('{DAV:}getlastmodified', new GetLastModified($lastModified));
		$propFind->set('{DAV:}getcontentlength', $size);
		$propFind->set('{DAV:}getcontenttype', $contentType);
		$propFind->set('{DAV:}getetag', $etag);
		$propFind->set('{DAV:}resourcetype', new ResourceType());
		$propFind->set('{' . self::OC_NS . '}size', $size);
		$propFind->set('{' . self::OC_NS . '}permissions', $canWrite ? 'RGDNVW' : 'RG');

		$entry = $propFind->getResultForMultiStatus();
		$entry['href'] = $path;
		return $entry;
	}

	/**
	 * Build a multistatus entry for a virtual directory/collection.
	 *
	 * @return array<int|string, mixed>
	 */
	public function buildDirectoryPropfindEntry(
		string $path,
		string $displayname,
		?string $requestBody,
		int $lastModified = 0,
	): array {
		[$requestType, $requested] = $this->parsePropFindRequest($requestBody, ignoreErrors: true);

		$propFind = new PropFind($path, $requested, 0, $requestType);
		$propFind->set('{DAV:}displayname', $displayname);
		$propFind->set('{DAV:}getlastmodified', new GetLastModified($lastModified ?: time()));
		$propFind->set('{DAV:}resourcetype', new ResourceType(['{DAV:}collection']));
		$propFind->set('{' . self::OC_NS . '}permissions', 'RGDNVW');

		$entry = $propFind->getResultForMultiStatus();
		$entry['href'] = $path;
		return $entry;
	}

	/**
	 * @param list<array<int|string, mixed>> $entries
	 */
	public function generateMultiStatus(array $entries): string {
		if ($this->server === null) {
			throw new \RuntimeException('Plugin is not attached to a server');
		}

		$prefer = $this->server->getHTTPPrefer();
		// Nextcloud streams multistatus responses on some endpoints; the
		// entries built here are always needed as a string.
		$streaming = Server::$streamMultiStatus;
		Server::$streamMultiStatus = false;
		try {
			return (string)$this->server->generateMultiStatus($entries, $prefer['return'] === 'minimal');
		} finally {
			Server::$streamMultiStatus = $streaming;
		}
	}

	/**
	 * Names of the entries of a multistatus listing.
	 *
	 * @return list<string>
	 */
	public function listedFilenames(\DOMDocument $document): array {
		$names = [];
		foreach ($document->getElementsByTagNameNS('DAV:', 'href') as $href) {
			$names[] = rawurldecode(basename(rtrim(trim($href->textContent), '/')));
		}
		return $names;
	}

	/**
	 * @return array{0: int, 1: list<string>}
	 */
	private function parsePropFindRequest(?string $requestBody, bool $ignoreErrors = false): array {
		$requestType = PropFind::ALLPROPS;
		$requested = [];
		if ($requestBody !== null && trim($requestBody) !== '' && $this->server !== null) {
			try {
				$propFindXml = $this->server->xml->expect('{DAV:}propfind', $requestBody);
				if ($propFindXml instanceof PropFindRequest && !$propFindXml->allProp) {
					$requestType = PropFind::NORMAL;
					$requested = $propFindXml->properties;
				}
			} catch (\Throwable $e) {
				if (!$ignoreErrors) {
					throw $e;
				}
			}
		}
		return [$requestType, $requested];
	}
}
