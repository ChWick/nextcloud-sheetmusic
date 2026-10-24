<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Dav;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteExporter;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteImporter;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSyncBackend;
use OCA\SheetMusic\Sync\SyncClientInterface;
use OCA\SheetMusic\Sync\SyncClientRegistry;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\Xml\Property\GetLastModified;
use Sabre\DAV\Xml\Property\ResourceType;
use Sabre\DAV\Xml\Request\PropFind as PropFindRequest;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Exposes the MobileSheets library files ("mobilesheets.db" and
 * "mobilesheets_hashcodes.txt") as virtual files inside every registered
 * library folder.
 *
 * MobileSheets syncs a library by looking for these two files in the library
 * folder. They are not stored in the user's file system: the database is
 * exported on the fly from this app's tables, uploads of it are imported back,
 * and the hash codes are generated from the known song files.
 *
 * Both the current endpoint (remote.php/dav/files/USER/...) and the legacy one
 * (remote.php/webdav/...) are supported.
 */
class SheetMusicDavPlugin extends ServerPlugin {
	public const VIRTUAL_DB_NAME = 'mobilesheets.db';
	public const VIRTUAL_HASH_NAME = 'mobilesheets_hashcodes.txt';

	private const OC_NS = 'http://owncloud.org/ns';

	/**
	 * Runs after the authentication plugin (priority 10) so that the user
	 * session is set up, but before Nextcloud resolves the (non-existing) node.
	 */
	private const BEFORE_METHOD_PRIORITY = 20;

	private ?Server $server = null;
	private SyncClientRegistry $registry;

	/** Raw body of a PROPFIND request on a library folder, kept for afterPropfind. */
	private ?string $propfindBody = null;

	public function __construct(
		private LibraryMapper $libraryMapper,
		private MobileSheetsSqliteExporter $exporter,
		private MobileSheetsSqliteImporter $importer,
		private LoggerInterface $logger,
		private IUserSession $userSession,
		?SyncClientRegistry $registry = null,
	) {
		$this->registry = $registry ?? new SyncClientRegistry();
		if ($this->registry->getClients() === []) {
			$this->registry->registerClient(new MobileSheetsSyncBackend(
				$this->exporter,
				$this->importer,
				$this->libraryMapper,
				$this->logger,
			));
		}
	}

	#[\Override]
	public function getPluginName(): string {
		return 'sheetmusic-virtual-files';
	}

	#[\Override]
	public function initialize(Server $server): void {
		$this->server = $server;
		$server->on('beforeMethod:*', [$this, 'beforeMethod'], self::BEFORE_METHOD_PRIORITY);
		$server->on('afterMethod:PROPFIND', [$this, 'afterPropfind'], 10);
	}

	/**
	 * Intercept WebDAV operations targeting virtual MobileSheets files.
	 */
	public function beforeMethod(RequestInterface $request, ResponseInterface $response): bool {
		$path = trim($request->getPath(), '/');
		$method = strtoupper($request->getMethod());

		$this->logger->info(sprintf(
			'SheetMusicDavPlugin: beforeMethod %s path=%s user=%s ua=%s',
			$method,
			$path,
			$this->userSession->getUser()?->getUID() ?? 'anonymous',
			$request->getHeader('User-Agent') ?? '',
		));

		try {
			$match = $this->matchVirtualPath($path);
			if ($match === null) {
				if ($method === 'PROPFIND') {
					$this->rememberPropfindBody($request, $path);
				}
				return true;
			}

			$client = $match['client'];
			$library = $match['library'];
			$filename = $match['filename'];

			$handled = !match ($method) {
				'GET', 'HEAD' => $client->handleGet($request, $response, $library, $filename, $method === 'HEAD'),
				'PUT' => $client->handlePut($request, $response, $library, $filename),
				'PROPFIND' => $this->handlePropfindFile($request, $response, $library, $path, $filename, $client),
				// The files cannot be removed; acknowledge so clients that delete
				// before re-uploading keep going.
				'DELETE' => $this->acknowledge($response),
				default => true,
			};
		} catch (\Throwable $e) {
			$this->logger->error('Error processing virtual WebDAV request: ' . $e->getMessage(), [
				'exception' => $e,
				'path' => $path,
				'method' => $method,
			]);
			$response->setStatus(500);
			$handled = true;
		}

		if (!$handled) {
			return true;
		}

		// Sabre does not send the response when a beforeMethod listener stops
		// the request, so it has to be sent here.
		$this->sendResponse($response);
		return false;
	}

	private function sendResponse(ResponseInterface $response): void {
		if ($this->server === null) {
			return;
		}
		$this->server->sapi->sendResponse($response);
		$this->server->emit('afterResponse', [$this->server->httpRequest, $response]);
	}

	/**
	 * Inject virtual files into PROPFIND directory listings of registered libraries.
	 */
	public function afterPropfind(RequestInterface $request, ResponseInterface $response): void {
		$requestBody = $this->propfindBody;
		$this->propfindBody = null;

		$depth = $request->getHeader('Depth');
		if ($depth === '0' || $response->getStatus() !== 207) {
			return;
		}

		$path = trim($request->getPath(), '/');

		try {
			$library = $this->findLibraryByFolderPath($path);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to resolve library for PROPFIND: ' . $e->getMessage(), [
				'exception' => $e,
				'path' => $path,
			]);
			return;
		}
		if ($library === null || $library->id === null) {
			return;
		}

		$this->logger->info(sprintf('SheetMusicDavPlugin: afterPropfind injected into path=%s for library=%d', $path, $library->id));

		$body = $response->getBody();
		if (is_callable($body)) {
			// Streamed multistatus (legacy endpoint): the listing is produced
			// lazily while the response is sent, so it can only be consumed once.
			$response->setBody(function () use ($body, $library, $path, $requestBody): void {
				ob_start();
				try {
					$body();
				} finally {
					$xml = (string)ob_get_clean();
				}
				echo $this->injectVirtualEntries($xml, $library, $path, $requestBody);
			});
			$response->removeHeader('Content-Length');
			return;
		}

		$xml = $response->getBodyAsString();
		$newBody = $this->injectVirtualEntries($xml, $library, $path, $requestBody);
		if ($newBody !== $xml) {
			$response->setBody($newBody);
			$response->setHeader('Content-Length', (string)strlen($newBody));
		}
	}

	/**
	 * Add multistatus entries for the virtual files to a folder listing.
	 *
	 * @return string the listing with the entries, or unchanged on failure
	 */
	private function injectVirtualEntries(string $xml, InternalLibrary $library, string $path, ?string $requestBody): string {
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
			$entries = [];
			foreach ($this->registry->getAllVirtualFileNames() as $filename) {
				// A real file of that name takes precedence over the virtual one.
				if (in_array($filename, $existing, true)) {
					continue;
				}
				$client = $this->registry->getClientForVirtualFile($filename);
				$entries[] = $this->buildPropfindEntry($library, $path . '/' . $filename, $filename, $requestBody, $client);
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

	private function handlePropfindFile(
		RequestInterface $request,
		ResponseInterface $response,
		InternalLibrary $library,
		string $path,
		string $filename,
		?SyncClientInterface $client = null,
	): bool {
		$entry = $this->buildPropfindEntry($library, $path, $filename, $request->getBodyAsString(), $client);

		$xml = $this->generateMultiStatus([$entry]);
		$response->setStatus(207);
		$response->setHeader('Content-Type', 'application/xml; charset=utf-8');
		$response->setHeader('Vary', 'Brief,Prefer');
		$response->setBody($xml);

		return false;
	}

	private function acknowledge(ResponseInterface $response): bool {
		$response->setStatus(204);
		return false;
	}

	/**
	 * Build a multistatus entry for a virtual file, honouring the properties
	 * requested in the PROPFIND body.
	 *
	 * @return array<int|string, mixed>
	 */
	private function buildPropfindEntry(
		InternalLibrary $library,
		string $path,
		string $filename,
		?string $requestBody,
		?SyncClientInterface $client = null,
	): array {
		$client ??= $this->registry->getClientForVirtualFile($filename);
		$props = $client?->getVirtualFileProperties($library, $filename, $library->userId ?? '');

		$requestType = PropFind::ALLPROPS;
		$requested = [];
		if ($requestBody !== null && trim($requestBody) !== '' && $this->server !== null) {
			$propFindXml = $this->server->xml->expect('{DAV:}propfind', $requestBody);
			if ($propFindXml instanceof PropFindRequest && !$propFindXml->allProp) {
				$requestType = PropFind::NORMAL;
				$requested = $propFindXml->properties;
			}
		}

		$size = $props['size'] ?? 0;
		$contentType = $props['contentType'] ?? 'application/octet-stream';
		$lastModified = $props['lastModified'] ?? ($library->updatedAt ?: time());
		$etag = $props['etag'] ?? sprintf('"sm-%d-%d"', $library->id, $library->updatedAt);

		$propFind = new PropFind($path, $requested, 0, $requestType);
		$propFind->set('{DAV:}displayname', $filename);
		$propFind->set('{DAV:}getlastmodified', new GetLastModified($lastModified));
		$propFind->set('{DAV:}getcontentlength', $size);
		$propFind->set('{DAV:}getcontenttype', $contentType);
		$propFind->set('{DAV:}getetag', $etag);
		$propFind->set('{DAV:}resourcetype', new ResourceType());
		$propFind->set('{' . self::OC_NS . '}size', $size);
		$propFind->set('{' . self::OC_NS . '}permissions', 'RGDNVW');

		$entry = $propFind->getResultForMultiStatus();
		$entry['href'] = $path;
		return $entry;
	}

	/**
	 * @param list<array<int|string, mixed>> $entries
	 */
	private function generateMultiStatus(array $entries): string {
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
	private function listedFilenames(\DOMDocument $document): array {
		$names = [];
		foreach ($document->getElementsByTagNameNS('DAV:', 'href') as $href) {
			$names[] = rawurldecode(basename(rtrim(trim($href->textContent), '/')));
		}
		return $names;
	}

	/**
	 * Keep the body of a PROPFIND on a library folder so the injected entries
	 * can return the same properties as the real ones.
	 */
	private function rememberPropfindBody(RequestInterface $request, string $path): void {
		$this->propfindBody = null;
		if ($request->getHeader('Depth') === '0' || $this->findLibraryByFolderPath($path) === null) {
			return;
		}

		$body = $request->getBodyAsString();
		$request->setBody($body);
		$this->propfindBody = $body;
	}

	/**
	 * @return array{client: SyncClientInterface, library: InternalLibrary, filename: string}|null
	 */
	public function matchVirtualPath(string $path): ?array {
		$path = trim($path, '/');
		$filename = basename($path);
		$client = $this->registry->getClientForVirtualFile($filename);
		if ($client === null) {
			return null;
		}

		$folder = dirname($path);
		$library = $this->findLibraryByFolderPath($folder === '.' ? '' : $folder);
		if ($library === null) {
			return null;
		}

		return ['client' => $client, 'library' => $library, 'filename' => $filename];
	}

	public function findLibraryByFolderPath(string $path): ?InternalLibrary {
		$resolved = $this->resolveUserFolder($path);
		if ($resolved === null) {
			return null;
		}
		[$userId, $folderPath] = $resolved;

		foreach ($this->libraryMapper->findAllForUser($userId) as $lib) {
			$libPath = trim($lib->path, '/');
			// Libraries may be registered with the path to the database file itself
			// (e.g. "/tmp/SheetMusic/mobilesheets.db"). The enclosing folder is what
			// MobileSheets queries over WebDAV.
			if (str_ends_with(strtolower($libPath), '.db')) {
				$libFolder = dirname($libPath);
				$cleanLibFolder = $libFolder === '.' ? '' : $libFolder;
			} else {
				$cleanLibFolder = $libPath;
			}

			if ($cleanLibFolder === $folderPath) {
				return $lib;
			}
		}

		return null;
	}

	/**
	 * Split a request path into the owning user and the folder relative to the
	 * user's files root.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function resolveUserFolder(string $path): ?array {
		$path = trim($path, '/');
		$sessionUser = $this->userSession->getUser()?->getUID();

		// Legacy endpoint (remote.php/webdav): paths are relative to the files
		// root of the authenticated user.
		if ($this->isLegacyWebdav()) {
			return $sessionUser === null ? null : [$sessionUser, $path];
		}

		$parts = explode('/', $path);
		if (count($parts) < 2 || $parts[0] !== 'files' || $parts[1] === '') {
			return null;
		}

		$userId = $parts[1];
		// Never expose a library to anybody but its owner.
		if ($sessionUser !== $userId) {
			return null;
		}

		return [$userId, implode('/', array_slice($parts, 2))];
	}

	private function isLegacyWebdav(): bool {
		$baseUri = $this->server?->getBaseUri() ?? '';
		return str_ends_with(rtrim($baseUri, '/'), '/webdav');
	}
}
