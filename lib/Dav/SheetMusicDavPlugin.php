<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Dav;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalSublibrary;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Service\LibraryShareService;
use OCA\SheetMusic\Service\SublibraryService;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteExporter;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteImporter;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSyncBackend;
use OCA\SheetMusic\Sync\SyncClientInterface;
use OCA\SheetMusic\Sync\SyncClientRegistry;
use OCP\Files\IRootFolder;
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
	public const SONGS_DIR = 'Songs';
	public const SUBLIBRARIES_DIR = 'SubLibraries';
	public const SUBLIBRARIES_DIR_LEGACY = '.sublibraries';
	public const SUBLIBRARIES_DIR_PATTERN = '(?:\.sublibraries|SubLibraries)';

	private const OC_NS = 'http://owncloud.org/ns';

	/**
	 * Runs after the authentication plugin (priority 10) so that the user
	 * session is set up, but before Nextcloud resolves the (non-existing) node.
	 */
	private const BEFORE_METHOD_PRIORITY = 20;

	private ?Server $server = null;
	private SyncClientRegistry $registry;
	private SheetMusicPropfindBuilder $propfindBuilder;

	/** Raw body of a PROPFIND request on a library folder, kept for afterPropfind. */
	private ?string $propfindBody = null;

	public function __construct(
		private LibraryMapper $libraryMapper,
		private MobileSheetsSqliteExporter $exporter,
		private MobileSheetsSqliteImporter $importer,
		private LoggerInterface $logger,
		private IUserSession $userSession,
		?SyncClientRegistry $registry = null,
		private ?IRootFolder $rootFolder = null,
		private ?SublibraryService $sublibraryService = null,
		private ?LibraryShareService $libraryShareService = null,
		private ?SyncClientDetector $syncClientDetector = null,
	) {
		$this->syncClientDetector ??= new SyncClientDetector();
		$this->registry = $registry ?? new SyncClientRegistry();
		if ($this->registry->getClients() === []) {
			$this->registry->registerClient(new MobileSheetsSyncBackend(
				$this->exporter,
				$this->importer,
				$this->libraryMapper,
				$this->logger,
			));
		}
		$this->propfindBuilder = new SheetMusicPropfindBuilder(
			$this->registry,
			$this->logger,
			$this->sublibraryService,
			self::SUBLIBRARIES_DIR,
		);
	}

	#[\Override]
	public function getPluginName(): string {
		return 'sheetmusic-virtual-files';
	}

	#[\Override]
	public function initialize(Server $server): void {
		$this->server = $server;
		$this->propfindBuilder->setServer($server);
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
			if ($this->isSyncClient($request) && $this->isSublibraryContainerPath($path)) {
				return true;
			}

			$match = $this->matchVirtualPath($path);
			if ($match !== null && $this->isSyncClient($request)) {
				return true;
			}
			if ($match === null) {
				if ($method === 'PROPFIND') {
					if ($this->handleSublibraryFolderPropfind($request, $response, $path)) {
						$this->sendResponse($response);
						return false;
					}
					if ($this->handleSublibraryFilePropfind($request, $response, $path)) {
						$this->sendResponse($response);
						return false;
					}
					$this->rememberPropfindBody($request, $path);
				} elseif ($method === 'PUT') {
					if ($this->handleSublibraryFileUploadPut($request, $response, $path)) {
						$this->sendResponse($response);
						return false;
					}
					$this->ensureParentDirectoryExistsForPut($path);
				} elseif ($method === 'GET' || $method === 'HEAD') {
					if ($this->handleSublibraryFileDownloadGet($request, $response, $path, $method === 'HEAD')) {
						$this->sendResponse($response);
						return false;
					}
				}
				return true;
			}

			$client = $match['client'];
			$library = $match['library'];
			$filename = $match['filename'];
			$sublibrary = $match['sublibrary'] ?? null;
			$targetUserId = $match['userId'] ?? $library->userId;
			$publicToken = $match['publicToken'] ?? null;
			$canWrite = $match['canWrite'] ?? true;

			$handled = !match ($method) {
				'GET', 'HEAD' => $client->handleGet($request, $response, $library, $filename, $method === 'HEAD', $sublibrary, $targetUserId, $publicToken),
				'PUT' => $client->handlePut($request, $response, $library, $filename, $sublibrary, $targetUserId, $canWrite),
				'PROPFIND' => $this->handlePropfindFile($request, $response, $library, $path, $filename, $client, $sublibrary, $targetUserId, $publicToken, $canWrite),
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
		$context = $this->resolveDavContext($path);

		$isSongsFolder = false;
		try {
			$library = $this->findLibraryBySongsFolderPath($path);
			if ($library !== null) {
				$isSongsFolder = true;
			} else {
				$library = $this->findLibraryByFolderPath($path);
			}
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

		$targetUserId = $context['userId'] ?? $library->userId;
		$publicToken = $context['publicToken'] ?? null;
		$canWrite = $library->isShared ? ($library->canWrite ?? false) : true;
		if ($publicToken !== null) {
			$canWrite = false;
		}

		$isSyncClient = $this->isSyncClient($request);
		$includeSublibraries = !$isSongsFolder && !$isSyncClient;
		$includeVirtualFiles = $isSongsFolder && !$isSyncClient;

		if (!$isSongsFolder && $canWrite && $library->sublibraryId === null && $this->rootFolder !== null) {
			$this->ensureSongsFolder($library);
		}

		$this->logger->info(sprintf(
			'SheetMusicDavPlugin: afterPropfind injected into path=%s for library=%d isSyncClient=%s songsFolder=%s',
			$path,
			$library->id,
			$isSyncClient ? 'yes' : 'no',
			$isSongsFolder ? 'yes' : 'no'
		));

		$body = $response->getBody();
		if (is_callable($body)) {
			// Streamed multistatus (legacy endpoint): the listing is produced
			// lazily while the response is sent, so it can only be consumed once.
			$response->setBody(function () use ($body, $library, $path, $requestBody, $targetUserId, $publicToken, $canWrite, $includeSublibraries, $includeVirtualFiles): void {
				ob_start();
				try {
					$body();
				} finally {
					$xml = (string)ob_get_clean();
				}
				echo $this->propfindBuilder->injectVirtualEntries($xml, $library, $path, $requestBody, $targetUserId, $publicToken, $canWrite, $includeSublibraries, $includeVirtualFiles);
			});
			$response->removeHeader('Content-Length');
			return;
		}

		$xml = $response->getBodyAsString();
		$newBody = $this->propfindBuilder->injectVirtualEntries($xml, $library, $path, $requestBody, $targetUserId, $publicToken, $canWrite, $includeSublibraries, $includeVirtualFiles);
		if ($newBody !== $xml) {
			$response->setBody($newBody);
			$response->setHeader('Content-Length', (string)strlen($newBody));
		}
	}

	private function handlePropfindFile(
		RequestInterface $request,
		ResponseInterface $response,
		InternalLibrary $library,
		string $path,
		string $filename,
		?SyncClientInterface $client = null,
		?InternalSublibrary $sublibrary = null,
		?string $userId = null,
		?string $publicToken = null,
		bool $canWrite = true,
	): bool {
		$entry = $this->propfindBuilder->buildPropfindEntry($library, $path, $filename, $request->getBodyAsString(), $client, $sublibrary, $userId, $publicToken, $canWrite);
		if ($entry === null) {
			$response->setStatus(404);
			return false;
		}

		$xml = $this->propfindBuilder->generateMultiStatus([$entry]);
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
	 * Keep the body of a PROPFIND on a library folder so the injected entries
	 * can return the same properties as the real ones.
	 */
	private function rememberPropfindBody(RequestInterface $request, string $path): void {
		$this->propfindBody = null;
		if (
			$request->getHeader('Depth') === '0'
			|| ($this->findLibraryBySongsFolderPath($path) === null && $this->findLibraryByFolderPath($path) === null)
		) {
			return;
		}

		$body = $request->getBodyAsString();
		$request->setBody($body);
		$this->propfindBody = $body;
	}

	/**
	 * @return array{client: SyncClientInterface, library: InternalLibrary, filename: string, sublibrary: ?InternalSublibrary, userId: ?string, publicToken: ?string, canWrite: bool}|null
	 */
	public function matchVirtualPath(string $path): ?array {
		$path = trim($path, '/');
		$filename = basename($path);
		$client = $this->registry->getClientForVirtualFile($filename);
		if ($client === null) {
			return null;
		}

		$folder = dirname($path);
		$folder = ($folder === '.' ? '' : $folder);

		$context = $this->resolveDavContext($folder);

		$resolvedSub = $this->resolveSublibraryFromPath($folder);
		if ($resolvedSub !== null) {
			[$library, $sublibrary] = $resolvedSub;
			$canWrite = $library->isShared ? ($library->canWrite ?? false) : true;
			return [
				'client' => $client,
				'library' => $library,
				'filename' => $filename,
				'sublibrary' => $sublibrary,
				'userId' => $context['userId'] ?? $library->userId,
				'publicToken' => $context['publicToken'] ?? null,
				'canWrite' => $canWrite,
			];
		}

		$library = $this->findLibraryBySongsFolderPath($folder);
		if ($library === null) {
			return null;
		}

		$canWrite = $library->isShared ? ($library->canWrite ?? false) : true;
		if ($context !== null && $context['publicToken'] !== null) {
			$canWrite = false;
		}

		$sublibrary = null;
		if ($library->sublibraryId !== null && $this->sublibraryService !== null) {
			$sublibrary = $this->sublibraryService->getSublibrary($library->sublibraryId);
		}

		return [
			'client' => $client,
			'library' => $library,
			'filename' => $filename,
			'sublibrary' => $sublibrary,
			'userId' => $context['userId'] ?? $library->userId,
			'publicToken' => $context['publicToken'] ?? null,
			'canWrite' => $canWrite,
		];
	}

	/**
	 * @return array{0: InternalLibrary, 1: InternalSublibrary}|null
	 */
	private function resolveSublibraryFromPath(string $folder): ?array {
		$resolved = $this->resolveUserFolder($folder);
		if ($resolved === null) {
			return null;
		}
		[$userId, $relPath] = $resolved;
		if (!preg_match('#^(.+?)/' . self::SUBLIBRARIES_DIR_PATTERN . '/([^/]+)$#', $relPath, $m)) {
			return null;
		}
		$parentFolder = $m[1];
		$slug = $m[2];

		$library = $this->findLibraryForRelativePath($userId, $parentFolder);
		if ($library === null || $this->sublibraryService === null || $library->id === null) {
			return null;
		}

		$sublib = $this->sublibraryService->getSublibraryBySlug((int)$library->id, $slug);
		if ($sublib === null) {
			return null;
		}

		return [$library, $sublib];
	}

	private function handleSublibraryFolderPropfind(
		RequestInterface $request,
		ResponseInterface $response,
		string $path,
	): bool {
		if ($this->isSyncClient($request)) {
			return false;
		}

		$resolved = $this->resolveUserFolder($path);
		if ($resolved === null) {
			return false;
		}
		[$userId, $relPath] = $resolved;

		// Case 1: {LibraryFolder}/SubLibraries or {LibraryFolder}/.sublibraries
		if (preg_match('#^(.+?)/' . self::SUBLIBRARIES_DIR_PATTERN . '$#', $relPath, $m)) {
			$parentFolder = $m[1];
			$library = $this->findLibraryForRelativePath($userId, $parentFolder);
			if ($library === null || $this->sublibraryService === null || $library->id === null) {
				return false;
			}

			$depth = $request->getHeader('Depth') ?? '1';
			$entries = [];
			$entries[] = $this->propfindBuilder->buildDirectoryPropfindEntry($path, basename($path), $request->getBodyAsString(), $library->updatedAt);

			if ($depth !== '0') {
				foreach ($this->sublibraryService->getSublibraries((int)$library->id) as $sublib) {
					$entries[] = $this->propfindBuilder->buildDirectoryPropfindEntry(
						$path . '/' . $sublib->slug,
						$sublib->slug,
						$request->getBodyAsString(),
						$sublib->updatedAt,
					);
				}
			}

			$response->setStatus(207);
			$response->setHeader('Content-Type', 'application/xml; charset=utf-8');
			$response->setHeader('Vary', 'Brief,Prefer');
			$response->setBody($this->propfindBuilder->generateMultiStatus($entries));
			return true;
		}

		// Case 2: {LibraryFolder}/SubLibraries/{slug} or {LibraryFolder}/.sublibraries/{slug}
		$resolvedSub = $this->resolveSublibraryFromPath($path);
		if ($resolvedSub !== null) {
			[$library, $sublibrary] = $resolvedSub;

			$depth = $request->getHeader('Depth') ?? '1';
			$entries = [];
			$entries[] = $this->propfindBuilder->buildDirectoryPropfindEntry($path, $sublibrary->slug, $request->getBodyAsString(), $sublibrary->updatedAt);

			if ($depth !== '0') {
				foreach ($this->registry->getAllVirtualFileNames() as $filename) {
					$client = $this->registry->getClientForVirtualFile($filename);
					$entry = $this->propfindBuilder->buildPropfindEntry($library, $path . '/' . $filename, $filename, $request->getBodyAsString(), $client, $sublibrary);
					if ($entry !== null) {
						$entries[] = $entry;
					}
				}
			}

			$response->setStatus(207);
			$response->setHeader('Content-Type', 'application/xml; charset=utf-8');
			$response->setHeader('Vary', 'Brief,Prefer');
			$response->setBody($this->propfindBuilder->generateMultiStatus($entries));
			return true;
		}

		return false;
	}

	/**
	 * @return array{userId: string, library: InternalLibrary, parentFolder: string, slug: string, fileName: string, targetRelPath: string}|null
	 */
	private function resolveSublibraryFilePath(string $path): ?array {
		$resolved = $this->resolveUserFolder($path);
		if ($resolved === null) {
			return null;
		}
		[$userId, $relPath] = $resolved;

		if (!preg_match('#^(.+?)/' . self::SUBLIBRARIES_DIR_PATTERN . '/([^/]+)/(.+)$#', $relPath, $m)) {
			return null;
		}
		$parentFolder = $m[1];
		$slug = $m[2];
		$fileName = rawurldecode($m[3]);

		if ($this->registry->getClientForVirtualFile($fileName) !== null) {
			return null;
		}

		$library = $this->findLibraryForRelativePath($userId, $parentFolder);
		if ($library === null || $this->rootFolder === null) {
			return null;
		}

		return [
			'userId' => $userId,
			'library' => $library,
			'parentFolder' => $parentFolder,
			'slug' => $slug,
			'fileName' => $fileName,
			'targetRelPath' => $parentFolder . '/' . $fileName,
		];
	}

	/**
	 * Resolves routing info for a sub-library file accessed via:
	 * 1. Owner's virtual folder (SubLibraries/<slug>/...)
	 * 2. Public share token (/public.php/dav/files/<token>/...)
	 * 3. Logged-in user's shared folder (/remote.php/dav/files/<user>/<sharedFolder>/...)
	 *
	 * @return array{userId: string, library: InternalLibrary, parentFolder: string, slug: string, fileName: string, targetRelPath: string, canWrite: bool}|null
	 */
	private function resolveSublibraryInfo(string $path): ?array {
		$ownerInfo = $this->resolveSublibraryFilePath($path);
		if ($ownerInfo !== null) {
			return $ownerInfo + ['canWrite' => true];
		}

		$context = $this->resolveDavContext($path);
		if ($context === null || $this->libraryShareService === null || $this->rootFolder === null) {
			return null;
		}

		// Public share link
		if ($context['publicToken'] !== null) {
			$subInfo = $this->libraryShareService->findSublibraryByPublicToken($context['publicToken']);
			if ($subInfo === null) {
				return null;
			}
			$library = $subInfo['library'];
			$fileName = rawurldecode($context['relPath']);
			if ($this->registry->getClientForVirtualFile($fileName) !== null) {
				return null;
			}
			$parentLibId = $this->libraryShareService->getParentLibraryId((int)$library->id);
			$parentLib = $this->libraryMapper->findById($parentLibId);
			$ownerLib = $parentLib ?? $library;
			$libPath = trim($ownerLib->path, '/');
			if (str_ends_with(strtolower($libPath), '.db')) {
				$libPath = dirname($libPath);
				$libPath = ($libPath === '.' ? '' : $libPath);
			}
			$targetPath = $libPath === '' ? $fileName : $libPath . '/' . $fileName;

			return [
				'userId' => $ownerLib->userId,
				'library' => $ownerLib,
				'parentFolder' => $libPath,
				'slug' => $subInfo['sublibrary']->slug,
				'fileName' => $fileName,
				'targetRelPath' => $targetPath,
				'canWrite' => false,
			];
		}

		// Authenticated user with a shared sub-library folder
		if ($context['userId'] !== null) {
			$relPath = $context['relPath'];
			$folder = dirname($relPath);
			$folder = ($folder === '.' ? '' : $folder);
			$fileName = rawurldecode(basename($relPath));
			if ($folder === '' || $this->registry->getClientForVirtualFile($fileName) !== null) {
				return null;
			}

			$checkFolder = $folder;
			$subPath = $fileName;
			$sharedLib = null;
			while ($checkFolder !== '' && $checkFolder !== '.') {
				$candidate = $this->libraryShareService->findSharedLibraryByFolderPath($context['userId'], $checkFolder);
				if ($candidate !== null && $candidate->sublibraryId !== null) {
					$sharedLib = $candidate;
					break;
				}
				$subPath = basename($checkFolder) . '/' . $subPath;
				$checkFolder = dirname($checkFolder);
				$checkFolder = ($checkFolder === '.' ? '' : $checkFolder);
			}
			if ($sharedLib === null && $folder !== '') {
				$sharedLib = $this->libraryShareService->findSharedLibraryByFolderPath($context['userId'], $folder);
				$subPath = $fileName;
			}

			if ($sharedLib !== null && $sharedLib->sublibraryId !== null) {
				$parentLibId = $this->libraryShareService->getParentLibraryId((int)$sharedLib->id);
				$parentLib = $this->libraryMapper->findById($parentLibId);
				$ownerLib = $parentLib ?? $sharedLib;
				$ownerLibPath = trim($ownerLib->path, '/');
				if (str_ends_with(strtolower($ownerLibPath), '.db')) {
					$ownerLibPath = dirname($ownerLibPath);
					$ownerLibPath = ($ownerLibPath === '.' ? '' : $ownerLibPath);
				}
				$targetPath = $ownerLibPath === '' ? $subPath : $ownerLibPath . '/' . $subPath;

				return [
					'userId' => $ownerLib->userId,
					'library' => $ownerLib,
					'parentFolder' => $ownerLibPath,
					'slug' => $sharedLib->sublibraryName ?? '',
					'fileName' => $fileName,
					'targetRelPath' => $targetPath,
					'canWrite' => $sharedLib->canWrite ?? false,
				];
			}
		}

		return null;
	}

	/**
	 * @return array{0: array{userId: string, library: InternalLibrary, parentFolder: string, slug: string, fileName: string, targetRelPath: string}, 1: \OCP\Files\File}|null
	 */
	private function resolveSublibraryPhysicalFile(string $path): ?array {
		$info = $this->resolveSublibraryInfo($path);
		if ($info === null || $this->rootFolder === null) {
			return null;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($info['userId']);
			if ($userFolder->nodeExists($info['targetRelPath'])) {
				$node = $userFolder->get($info['targetRelPath']);
				if ($node instanceof \OCP\Files\File) {
					return [$info, $node];
				}
			}
		} catch (\Throwable) {
			// Fall through
		}

		return null;
	}

	private function handleSublibraryFileUploadPut(
		RequestInterface $request,
		ResponseInterface $response,
		string $path,
	): bool {
		$info = $this->resolveSublibraryInfo($path);
		if ($info === null || !$info['canWrite'] || $this->rootFolder === null) {
			return false;
		}
		if ($info === null || $this->rootFolder === null) {
			return false;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($info['userId']);
			$targetRelPath = $info['targetRelPath'];
			$targetDir = dirname($targetRelPath);

			$current = '';
			foreach (explode('/', $targetDir) as $segment) {
				if ($segment === '') {
					continue;
				}
				$current = $current === '' ? $segment : "$current/$segment";
				if (!$userFolder->nodeExists($current)) {
					$userFolder->newFolder($current);
				}
			}

			$body = $request->getBody();
			$content = is_resource($body) ? (string)stream_get_contents($body) : (string)$body;

			if ($userFolder->nodeExists($targetRelPath)) {
				$node = $userFolder->get($targetRelPath);
				if ($node instanceof \OCP\Files\File) {
					$node->putContent($content);
				}
			} else {
				$userFolder->newFile($targetRelPath, $content);
			}

			$response->setStatus(201);
			return true;
		} catch (\Throwable $e) {
			$this->logger->error('Failed to handle sublibrary file upload PUT: ' . $e->getMessage(), [
				'exception' => $e,
				'path' => $path,
			]);
			$response->setStatus(500);
			return true;
		}
	}

	private function handleSublibraryFileDownloadGet(
		RequestInterface $request,
		ResponseInterface $response,
		string $path,
		bool $isHead,
	): bool {
		$resolved = $this->resolveSublibraryPhysicalFile($path);
		if ($resolved === null) {
			return false;
		}
		[, $node] = $resolved;

		$size = $node->getSize();
		$mime = $node->getMimeType() ?: 'application/octet-stream';
		$etag = '"' . $node->getEtag() . '"';
		$lastModified = $node->getMTime();

		$ifNoneMatch = $request->getHeader('If-None-Match');
		if ($ifNoneMatch !== null && trim($ifNoneMatch) === $etag) {
			$response->setStatus(304);
			return true;
		}

		$response->setStatus(200);
		$response->setHeader('Content-Type', $mime);
		$response->setHeader('Content-Length', (string)$size);
		$response->setHeader('ETag', $etag);
		$response->setHeader('Last-Modified', gmdate('D, d M Y H:i:s GMT', $lastModified));
		if (!$isHead) {
			$response->setBody($node->fopen('rb'));
		}
		return true;
	}

	private function handleSublibraryFilePropfind(
		RequestInterface $request,
		ResponseInterface $response,
		string $path,
	): bool {
		$resolved = $this->resolveSublibraryPhysicalFile($path);
		if ($resolved === null) {
			return false;
		}
		[$info, $node] = $resolved;

		$size = $node->getSize();
		$contentType = $node->getMimeType() ?: 'application/octet-stream';
		$lastModified = $node->getMTime();
		$etag = '"' . $node->getEtag() . '"';

		$requestType = PropFind::ALLPROPS;
		$requested = [];
		$requestBody = $request->getBodyAsString();
		if ($requestBody !== '' && $this->server !== null) {
			$propFindXml = $this->server->xml->expect('{DAV:}propfind', $requestBody);
			if ($propFindXml instanceof PropFindRequest && !$propFindXml->allProp) {
				$requestType = PropFind::NORMAL;
				$requested = $propFindXml->properties;
			}
		}

		$propFind = new PropFind($path, $requested, 0, $requestType);
		$propFind->set('{DAV:}displayname', basename($info['fileName']));
		$propFind->set('{DAV:}getlastmodified', new GetLastModified($lastModified));
		$propFind->set('{DAV:}getcontentlength', $size);
		$propFind->set('{DAV:}getcontenttype', $contentType);
		$propFind->set('{DAV:}getetag', $etag);
		$propFind->set('{DAV:}resourcetype', new ResourceType());
		$propFind->set('{' . self::OC_NS . '}size', $size);
		$propFind->set('{' . self::OC_NS . '}permissions', 'RGDNVW');

		$entry = $propFind->getResultForMultiStatus();
		$entry['href'] = $path;

		$response->setStatus(207);
		$response->setHeader('Content-Type', 'application/xml; charset=utf-8');
		$response->setHeader('Vary', 'Brief,Prefer');
		$response->setBody($this->propfindBuilder->generateMultiStatus([$entry]));
		return true;
	}

	private function ensureParentDirectoryExistsForPut(string $path): void {
		if ($this->rootFolder === null) {
			return;
		}

		$resolved = $this->resolveUserFolder($path);
		if ($resolved === null) {
			return;
		}
		[$userId, $relPath] = $resolved;
		$parent = dirname($relPath);
		if ($parent === '.' || $parent === '') {
			return;
		}

		$library = $this->findLibraryForRelativePath($userId, $relPath);
		if ($library === null || ($library->isShared && !$library->canWrite)) {
			return;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$current = '';
			foreach (explode('/', $parent) as $segment) {
				if ($segment === '') {
					continue;
				}
				$current = $current === '' ? $segment : "$current/$segment";
				if (!$userFolder->nodeExists($current)) {
					$userFolder->newFolder($current);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('SheetMusicDavPlugin: Failed to ensure parent folder for PUT: ' . $e->getMessage(), [
				'exception' => $e,
				'path' => $path,
			]);
		}
	}

	private function findLibraryForRelativePath(string $userId, string $relPath): ?InternalLibrary {
		$cleanRel = trim($relPath, '/');
		foreach ($this->libraryMapper->findAllForUser($userId) as $lib) {
			$cleanLibFolder = $this->cleanLibraryFolder($lib);
			if ($cleanLibFolder !== '' && ($cleanRel === $cleanLibFolder || str_starts_with($cleanRel, $cleanLibFolder . '/'))) {
				return $lib;
			}
		}
		if ($this->libraryShareService !== null) {
			$accessible = $this->libraryShareService->getAccessibleLibraries($userId);
			foreach ($accessible as $lib) {
				if ($lib->isShared) {
					$cleanLibFolder = $this->cleanLibraryFolder($lib);
					if ($cleanLibFolder !== '' && ($cleanRel === $cleanLibFolder || str_starts_with($cleanRel, $cleanLibFolder . '/'))) {
						return $lib;
					}
				}
			}
		}
		return null;
	}

	private function cleanLibraryFolder(InternalLibrary $lib): string {
		$libPath = trim($lib->path, '/');
		if (str_ends_with(strtolower($libPath), '.db')) {
			$libFolder = dirname($libPath);
			return $libFolder === '.' ? '' : $libFolder;
		}
		return $libPath;
	}

	private function ensureSongsFolder(InternalLibrary $library): void {
		if ($this->rootFolder === null) {
			return;
		}
		try {
			$userFolder = $this->rootFolder->getUserFolder($library->userId);
			$cleanRel = $this->cleanLibraryFolder($library);
			$libFolder = $cleanRel === '' ? $userFolder : ($userFolder->nodeExists($cleanRel) ? $userFolder->get($cleanRel) : null);
			if ($libFolder instanceof Folder && !$libFolder->nodeExists(self::SONGS_DIR)) {
				$libFolder->newFolder(self::SONGS_DIR);
			}
		} catch (\Throwable) {
			// Best effort
		}
	}

	public function findLibraryBySongsFolderPath(string $path): ?InternalLibrary {
		$context = $this->resolveDavContext($path);
		if ($context === null) {
			return null;
		}

		$folderPath = $context['relPath'];
		$songsSuffix = '/' . self::SONGS_DIR;
		if ($folderPath === self::SONGS_DIR) {
			$parentRelPath = '';
		} elseif (str_ends_with($folderPath, $songsSuffix)) {
			$parentRelPath = substr($folderPath, 0, -strlen($songsSuffix));
		} else {
			return null;
		}

		if ($context['publicToken'] !== null) {
			$info = $this->libraryShareService?->findLibraryByPublicToken($context['publicToken']);
			return $info['library'] ?? null;
		}

		$userId = $context['userId'];
		if ($userId === null) {
			return null;
		}

		foreach ($this->libraryMapper->findAllForUser($userId) as $lib) {
			if ($this->cleanLibraryFolder($lib) === $parentRelPath) {
				return $lib;
			}
		}

		if ($this->libraryShareService !== null) {
			$shared = $this->libraryShareService->findSharedLibraryByFolderPath($userId, $parentRelPath);
			if ($shared !== null) {
				return $shared;
			}
		}

		return null;
	}

	public function findLibraryByFolderPath(string $path): ?InternalLibrary {
		$context = $this->resolveDavContext($path);
		if ($context === null) {
			return null;
		}

		if ($context['publicToken'] !== null) {
			$info = $this->libraryShareService?->findLibraryByPublicToken($context['publicToken']);
			return $info['library'] ?? null;
		}

		$userId = $context['userId'];
		if ($userId === null) {
			return null;
		}
		$folderPath = $context['relPath'];

		foreach ($this->libraryMapper->findAllForUser($userId) as $lib) {
			if ($this->cleanLibraryFolder($lib) === $folderPath) {
				return $lib;
			}
		}

		if ($this->libraryShareService !== null) {
			$shared = $this->libraryShareService->findSharedLibraryByFolderPath($userId, $folderPath);
			if ($shared !== null) {
				return $shared;
			}
		}

		return null;
	}

	/**
	 * @return array{userId: ?string, publicToken: ?string, relPath: string}|null
	 */
	private function resolveDavContext(string $path): ?array {
		$path = trim($path, '/');
		if (str_starts_with($path, 'remote.php/dav/')) {
			$path = substr($path, strlen('remote.php/dav/'));
		}
		if (str_starts_with($path, 'public.php/dav/')) {
			$path = substr($path, strlen('public.php/dav/'));
		}
		$sessionUser = $this->userSession->getUser()?->getUID();

		// Legacy public endpoint (/public.php/webdav): share is at root, token is the auth user
		if ($this->isLegacyPublicWebdav()) {
			$token = $this->resolvePublicWebdavToken();
			return $token === null ? null : [
				'userId' => null,
				'publicToken' => $token,
				'relPath' => $path,
			];
		}

		// Legacy endpoint (remote.php/webdav): paths are relative to the files
		// root of the authenticated user.
		if ($this->isLegacyWebdav()) {
			return $sessionUser === null ? null : [
				'userId' => $sessionUser,
				'publicToken' => null,
				'relPath' => $path,
			];
		}

		$parts = explode('/', $path);
		if (count($parts) < 2) {
			return null;
		}

		// Public DAV endpoint (/public.php/dav/files/<token>/...)
		if ($this->isPublicDav()) {
			if ($parts[0] === 'files' && $parts[1] !== '') {
				return [
					'userId' => null,
					'publicToken' => $parts[1],
					'relPath' => implode('/', array_slice($parts, 2)),
				];
			}
			return null;
		}

		if ($parts[0] === 'files' && $parts[1] !== '') {
			$userId = $parts[1];
			if ($sessionUser !== null && $sessionUser !== $userId) {
				return null;
			}
			return [
				'userId' => $userId,
				'publicToken' => null,
				'relPath' => implode('/', array_slice($parts, 2)),
			];
		}

		if ($parts[0] === 'public-files' && $parts[1] !== '') {
			return [
				'userId' => null,
				'publicToken' => $parts[1],
				'relPath' => implode('/', array_slice($parts, 2)),
			];
		}

		return null;
	}

	private function resolvePublicWebdavToken(): ?string {
		if (isset($_SERVER['PHP_AUTH_USER']) && $_SERVER['PHP_AUTH_USER'] !== '') {
			return $_SERVER['PHP_AUTH_USER'];
		}
		$auth = $this->server?->httpRequest->getHeader('Authorization');
		if ($auth && str_starts_with(strtolower($auth), 'basic ')) {
			$decoded = base64_decode(substr($auth, 6), true);
			if ($decoded && str_contains($decoded, ':')) {
				return explode(':', $decoded, 2)[0];
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
		$context = $this->resolveDavContext($path);
		if ($context === null || $context['userId'] === null) {
			return null;
		}
		return [$context['userId'], $context['relPath']];
	}

	private function isPublicDav(): bool {
		$baseUri = $this->server?->getBaseUri() ?? '';
		return str_contains($baseUri, 'public.php');
	}

	private function isLegacyWebdav(): bool {
		$baseUri = $this->server?->getBaseUri() ?? '';
		return !str_contains($baseUri, 'public.php') && str_ends_with(rtrim($baseUri, '/'), '/webdav');
	}

	private function isLegacyPublicWebdav(): bool {
		$baseUri = $this->server?->getBaseUri() ?? '';
		return str_contains($baseUri, 'public.php') && str_ends_with(rtrim($baseUri, '/'), '/webdav');
	}

	/**
	 * Check if the incoming WebDAV request originates from an official sync client
	 * (Nextcloud desktop client / mirall, Nextcloud Android, Nextcloud iOS).
	 */
	public function isSyncClient(RequestInterface $request): bool {
		return $this->syncClientDetector?->isSyncClient($request) ?? false;
	}

	/**
	 * Check if a path points to or inside the virtual sublibraries container directory.
	 */
	public function isSublibraryContainerPath(string $path): bool {
		return (bool)preg_match('#(?:^|/)' . self::SUBLIBRARIES_DIR_PATTERN . '(?:/|$)#i', trim($path, '/'));
	}
}
