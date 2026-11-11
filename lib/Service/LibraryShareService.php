<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalSublibrary;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Db\SublibraryMapper;
use OCA\SheetMusic\Exception\SecurityException;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

class LibraryShareService {
	public const ACCESS_NONE = 'none';
	public const ACCESS_READ = 'read';
	public const ACCESS_WRITE = 'write';
	public const ACCESS_ADMIN = 'admin';

	public const SUBLIBRARY_ID_OFFSET = 1_000_000;

	public function __construct(
		private LibraryMapper $libraryMapper,
		private IRootFolder $rootFolder,
		private ?IShareManager $shareManager = null,
		private ?IUserManager $userManager = null,
		private ?IURLGenerator $urlGenerator = null,
		private ?LoggerInterface $logger = null,
		private ?SublibraryMapper $sublibraryMapper = null,
	) {
	}

	public function ensureSublibraryFolderId(InternalSublibrary $sublib, ?InternalLibrary $library = null): ?int {
		if ($sublib->folderId !== null && $sublib->folderId > 0) {
			return $sublib->folderId;
		}
		if ($sublib->id === null) {
			return null;
		}

		$lib = $library ?? $this->libraryMapper->findById($sublib->libraryId);
		if ($lib === null) {
			return null;
		}

		try {
			$libFolder = $this->getLibraryFolderNode($lib);
			if ($libFolder === null) {
				return null;
			}

			if (!$libFolder->nodeExists('_SubLibraries_')) {
				$libFolder->newFolder('_SubLibraries_');
			}
			$subDirNode = $libFolder->get('_SubLibraries_');
			if (!($subDirNode instanceof Folder)) {
				return null;
			}

			if (!$subDirNode->nodeExists($sublib->slug)) {
				$subDirNode->newFolder($sublib->slug);
			}
			$folderNode = $subDirNode->get($sublib->slug);
			if ($folderNode instanceof Folder) {
				$folderId = $folderNode->getId();
				$sublib->folderId = $folderId;
				$this->sublibraryMapper?->updateFolderId($sublib->id, $folderId);
				return $folderId;
			}
		} catch (\Throwable $e) {
			$this->logger?->warning('Failed to ensure sublibrary folder: ' . $e->getMessage(), [
				'exception' => $e,
				'sublibraryId' => $sublib->id,
			]);
		}

		return null;
	}

	public function getSublibraryFolderNode(InternalSublibrary $sublib, ?InternalLibrary $library = null): ?Folder {
		$lib = $library ?? $this->libraryMapper->findById($sublib->libraryId);
		if ($lib === null) {
			return null;
		}

		$folderId = $this->ensureSublibraryFolderId($sublib, $lib);
		if ($folderId === null) {
			return null;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($lib->userId);
			$nodes = $userFolder->getById($folderId);
			if (!empty($nodes) && $nodes[0] instanceof Folder) {
				return $nodes[0];
			}
		} catch (\Throwable) {
			// Ignore
		}

		return null;
	}

	public function ensureLibraryFolderId(InternalLibrary $library): ?int {
		if ($library->folderId !== null && $library->folderId > 0) {
			return $library->folderId;
		}
		if ($library->id === null) {
			return null;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($library->userId);
			$libPath = trim($library->path, '/');
			if (str_ends_with(strtolower($libPath), '.db')) {
				$libFolder = dirname($libPath);
				$libPath = ($libFolder === '.' ? '' : $libFolder);
			}

			$node = null;
			if ($libPath === '') {
				$node = $userFolder;
			} elseif ($userFolder->nodeExists($libPath)) {
				$node = $userFolder->get($libPath);
			}

			if ($node !== null) {
				$folderNode = $node instanceof Folder ? $node : $node->getParent();
				if ($folderNode !== null) {
					$folderId = $folderNode->getId();
					$library->folderId = $folderId;
					$this->libraryMapper->updateFolderId($library->id, $folderId);
					return $folderId;
				}
			}
		} catch (\Throwable $e) {
			$this->logger?->warning('Failed to resolve folderId for library: ' . $e->getMessage(), [
				'exception' => $e,
				'libraryId' => $library->id,
			]);
		}

		return null;
	}

	public function getAccess(string $userId, int|InternalLibrary $library): string {
		if (is_int($library) && $library >= self::SUBLIBRARY_ID_OFFSET) {
			$sublibId = $library - self::SUBLIBRARY_ID_OFFSET;
			$sublib = $this->sublibraryMapper?->findById($sublibId);
			if ($sublib === null) {
				return self::ACCESS_NONE;
			}
			$parentLib = $this->libraryMapper->findById($sublib->libraryId);
			if ($parentLib === null) {
				return self::ACCESS_NONE;
			}
			if ($parentLib->userId === $userId) {
				return self::ACCESS_ADMIN;
			}
			$folderId = $this->ensureSublibraryFolderId($sublib, $parentLib);
			if ($folderId === null) {
				return self::ACCESS_NONE;
			}
			try {
				$userFolder = $this->rootFolder->getUserFolder($userId);
				$nodes = $userFolder->getById($folderId);
				if (empty($nodes) || !$nodes[0]->isReadable()) {
					return self::ACCESS_NONE;
				}
				$node = $nodes[0];
				if ($node->isUpdateable() && $node->isCreatable()) {
					return self::ACCESS_WRITE;
				}
				return self::ACCESS_READ;
			} catch (\Throwable) {
				return self::ACCESS_NONE;
			}
		}

		if ($library instanceof InternalLibrary && $library->sublibraryId !== null) {
			if ($library->userId === $userId) {
				return self::ACCESS_ADMIN;
			}
			$folderId = $library->folderId;
			if ($folderId === null && $this->sublibraryMapper !== null) {
				$sub = $this->sublibraryMapper->findById($library->sublibraryId);
				$folderId = $sub ? $this->ensureSublibraryFolderId($sub, $library) : null;
			}
			if ($folderId === null) {
				return self::ACCESS_NONE;
			}
			try {
				$userFolder = $this->rootFolder->getUserFolder($userId);
				$nodes = $userFolder->getById($folderId);
				if (empty($nodes) || !$nodes[0]->isReadable()) {
					return self::ACCESS_NONE;
				}
				$node = $nodes[0];
				if ($node->isUpdateable() && $node->isCreatable()) {
					return self::ACCESS_WRITE;
				}
				return self::ACCESS_READ;
			} catch (\Throwable) {
				return self::ACCESS_NONE;
			}
		}

		$lib = is_int($library) ? $this->libraryMapper->findById($library) : $library;
		if ($lib === null || $lib->id === null) {
			return self::ACCESS_NONE;
		}

		// Owner has full admin access
		if ($lib->userId === $userId) {
			return self::ACCESS_ADMIN;
		}

		$folderId = $this->ensureLibraryFolderId($lib);
		if ($folderId === null) {
			return self::ACCESS_NONE;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$nodes = $userFolder->getById($folderId);
			if (empty($nodes)) {
				return self::ACCESS_NONE;
			}
			$node = $nodes[0];
			if (!$node->isReadable()) {
				return self::ACCESS_NONE;
			}
			if ($node->isUpdateable() && $node->isCreatable()) {
				return self::ACCESS_WRITE;
			}
			return self::ACCESS_READ;
		} catch (\Throwable) {
			return self::ACCESS_NONE;
		}
	}

	public function canRead(string $userId, int|InternalLibrary $library): bool {
		$access = $this->getAccess($userId, $library);
		return $access !== self::ACCESS_NONE;
	}

	public function canWrite(string $userId, int|InternalLibrary $library): bool {
		$access = $this->getAccess($userId, $library);
		return $access === self::ACCESS_WRITE || $access === self::ACCESS_ADMIN;
	}

	public function isOwner(string $userId, int|InternalLibrary $library): bool {
		$access = $this->getAccess($userId, $library);
		return $access === self::ACCESS_ADMIN;
	}

	public function getParentLibraryId(int $libraryId): int {
		if ($libraryId >= self::SUBLIBRARY_ID_OFFSET && $this->sublibraryMapper !== null) {
			$sublib = $this->sublibraryMapper->findById($libraryId - self::SUBLIBRARY_ID_OFFSET);
			if ($sublib !== null) {
				return (int)$sublib->libraryId;
			}
		}
		return $libraryId;
	}

	private function buildSublibraryLibrary(
		InternalLibrary $parentLib,
		InternalSublibrary $sub,
		string $path,
		int $folderId,
		bool $isShared,
		string $ownerId,
		string $permission,
		bool $canWrite,
	): InternalLibrary {
		$shared = clone $parentLib;
		$shared->id = self::SUBLIBRARY_ID_OFFSET + (int)$sub->id;
		$shared->path = $path;
		$shared->sublibraryId = $sub->id;
		$shared->sublibraryName = $sub->name;
		$shared->folderId = $folderId;
		$shared->isShared = $isShared;
		$shared->ownerId = $ownerId;
		$shared->ownerDisplayName = $this->getDisplayName($ownerId);
		$shared->permission = $permission;
		$shared->canWrite = $canWrite;
		return $shared;
	}

	public function getAccessibleLibrary(string $userId, int $libraryId): ?InternalLibrary {
		if ($libraryId >= self::SUBLIBRARY_ID_OFFSET) {
			$sublibId = $libraryId - self::SUBLIBRARY_ID_OFFSET;
			$sublib = $this->sublibraryMapper?->findById($sublibId);
			if ($sublib === null) {
				return null;
			}
			$lib = $this->libraryMapper->findById($sublib->libraryId);
			if ($lib === null) {
				return null;
			}

			if ($lib->userId === $userId) {
				$this->ensureSublibraryFolderId($sublib, $lib);
				return $this->buildSublibraryLibrary(
					$lib,
					$sublib,
					$lib->path . ' / ' . $sublib->name,
					(int)$sublib->folderId,
					false,
					$userId,
					self::ACCESS_ADMIN,
					true,
				);
			}

			$folderId = $this->ensureSublibraryFolderId($sublib, $lib);
			if ($folderId === null) {
				return null;
			}

			try {
				$userFolder = $this->rootFolder->getUserFolder($userId);
				$nodes = $userFolder->getById($folderId);
				if (empty($nodes) || !$nodes[0]->isReadable()) {
					return null;
				}
				$node = $nodes[0];
				$canWrite = $node->isUpdateable() && $node->isCreatable();
				$rel = $userFolder->getRelativePath($node->getPath());

				return $this->buildSublibraryLibrary(
					$lib,
					$sublib,
					'/' . ltrim($rel, '/'),
					$folderId,
					true,
					$lib->userId,
					$canWrite ? self::ACCESS_WRITE : self::ACCESS_READ,
					$canWrite,
				);
			} catch (\Throwable) {
				return null;
			}
		}

		$lib = $this->libraryMapper->findById($libraryId);
		if ($lib === null) {
			return null;
		}

		$access = $this->getAccess($userId, $lib);
		if ($access === self::ACCESS_NONE) {
			return null;
		}

		if ($lib->userId === $userId) {
			$lib->isShared = false;
			$lib->ownerId = $userId;
			$lib->ownerDisplayName = $this->getDisplayName($userId);
			$lib->permission = self::ACCESS_ADMIN;
			$lib->canWrite = true;
			$this->ensureLibraryFolderId($lib);
			return $lib;
		}

		$shared = clone $lib;
		$shared->isShared = true;
		$shared->ownerId = $lib->userId;
		$shared->ownerDisplayName = $this->getDisplayName($lib->userId);
		$shared->permission = $access;
		$shared->canWrite = ($access === self::ACCESS_WRITE);

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$folderId = $this->ensureLibraryFolderId($lib);
			if ($folderId !== null) {
				$nodes = $userFolder->getById($folderId);
				if (!empty($nodes)) {
					$rel = $userFolder->getRelativePath($nodes[0]->getPath());
					$shared->path = '/' . ltrim($rel, '/');
				}
			}
		} catch (\Throwable) {
			// Fallback to original path
		}

		return $shared;
	}

	/**
	 * @return list<InternalLibrary>
	 */
	public function getAccessibleLibraries(string $userId): array {
		$result = [];
		$handledIds = [];

		// 1. Owned libraries
		$owned = $this->libraryMapper->findAllForUser($userId);
		$userDisplayName = $this->getDisplayName($userId);
		foreach ($owned as $lib) {
			$this->ensureLibraryFolderId($lib);
			$lib->isShared = false;
			$lib->ownerId = $userId;
			$lib->ownerDisplayName = $userDisplayName;
			$lib->permission = self::ACCESS_ADMIN;
			$lib->canWrite = true;
			$result[] = $lib;
			if ($lib->id !== null) {
				$handledIds[$lib->id] = true;
			}
		}

		// 2. Shared libraries
		try {
			$foreignLibraries = $this->libraryMapper->findAllNotOwnedBy($userId);
			$userFolder = $this->rootFolder->getUserFolder($userId);

			foreach ($foreignLibraries as $fLib) {
				if ($fLib->id === null || isset($handledIds[$fLib->id])) {
					continue;
				}

				$folderId = $this->ensureLibraryFolderId($fLib);
				if ($folderId === null) {
					continue;
				}

				$nodes = $userFolder->getById($folderId);
				if (empty($nodes)) {
					continue;
				}

				$node = $nodes[0];
				if (!$node->isReadable()) {
					continue;
				}

				$canWrite = $node->isUpdateable() && $node->isCreatable();
				$permission = $canWrite ? self::ACCESS_WRITE : self::ACCESS_READ;
				$rel = $userFolder->getRelativePath($node->getPath());

				$shared = clone $fLib;
				$shared->path = '/' . ltrim($rel, '/');
				$shared->isShared = true;
				$shared->ownerId = $fLib->userId;
				$shared->ownerDisplayName = $this->getDisplayName($fLib->userId);
				$shared->permission = $permission;
				$shared->canWrite = $canWrite;

				$result[] = $shared;
				$handledIds[$fLib->id] = true;
			}
		} catch (\Throwable $e) {
			$this->logger?->warning('Error querying shared libraries for user: ' . $e->getMessage(), [
				'exception' => $e,
				'userId' => $userId,
			]);
		}

		// 3. Shared sub-libraries
		try {
			if ($this->sublibraryMapper !== null) {
				$foreignSublibraries = $this->sublibraryMapper->findAllNotOwnedBy($userId);
				$userFolder = $this->rootFolder->getUserFolder($userId);

				foreach ($foreignSublibraries as $fSub) {
					if ($fSub->id === null || $fSub->folderId === null) {
						continue;
					}

					$syntheticId = self::SUBLIBRARY_ID_OFFSET + (int)$fSub->id;
					if (isset($handledIds[$syntheticId])) {
						continue;
					}

					$parentLib = $this->libraryMapper->findById($fSub->libraryId);
					if ($parentLib === null) {
						continue;
					}

					// If the entire parent library is already accessible to the user, skip sublibrary
					if (isset($handledIds[$parentLib->id])) {
						continue;
					}

					$nodes = $userFolder->getById($fSub->folderId);
					if (empty($nodes) || !$nodes[0]->isReadable()) {
						continue;
					}

					$node = $nodes[0];
					$canWrite = $node->isUpdateable() && $node->isCreatable();
					$permission = $canWrite ? self::ACCESS_WRITE : self::ACCESS_READ;
					$rel = $userFolder->getRelativePath($node->getPath());

					$sharedSub = $this->buildSublibraryLibrary(
						$parentLib,
						$fSub,
						'/' . ltrim($rel, '/'),
						$fSub->folderId,
						true,
						$parentLib->userId,
						$permission,
						$canWrite,
					);

					$result[] = $sharedSub;
					$handledIds[$syntheticId] = true;
				}
			}
		} catch (\Throwable $e) {
			$this->logger?->warning('Error querying shared sub-libraries for user: ' . $e->getMessage(), [
				'exception' => $e,
				'userId' => $userId,
			]);
		}

		return $result;
	}

	public function findSharedLibraryByFolderPath(string $userId, string $folderPath): ?InternalLibrary {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$clean = trim($folderPath, '/');
			if ($clean === '') {
				$node = $userFolder;
			} elseif ($userFolder->nodeExists($clean)) {
				$node = $userFolder->get($clean);
			} else {
				return null;
			}

			if (!($node instanceof Folder)) {
				return null;
			}

			$folderId = $node->getId();
			$lib = $this->libraryMapper->findByFolderId($folderId);
			if ($lib !== null && $lib->userId !== $userId) {
				if (!$node->isReadable()) {
					return null;
				}

				$canWrite = $node->isUpdateable() && $node->isCreatable();
				$shared = clone $lib;
				$shared->path = '/' . $clean;
				$shared->isShared = true;
				$shared->ownerId = $lib->userId;
				$shared->ownerDisplayName = $this->getDisplayName($lib->userId);
				$shared->permission = $canWrite ? self::ACCESS_WRITE : self::ACCESS_READ;
				$shared->canWrite = $canWrite;
				return $shared;
			}

			if ($this->sublibraryMapper !== null) {
				$sub = $this->sublibraryMapper->findByFolderId($folderId);
				if ($sub !== null) {
					$parentLib = $this->libraryMapper->findById($sub->libraryId);
					if ($parentLib !== null && $parentLib->userId !== $userId) {
						if (!$node->isReadable()) {
							return null;
						}

						$canWrite = $node->isUpdateable() && $node->isCreatable();
						return $this->buildSublibraryLibrary(
							$parentLib,
							$sub,
							'/' . $clean,
							$folderId,
							true,
							$parentLib->userId,
							$canWrite ? self::ACCESS_WRITE : self::ACCESS_READ,
							$canWrite,
						);
					}
				}
			}

			return null;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @return array{library: InternalLibrary, token: string, share: IShare}|null
	 */
	public function findLibraryByPublicToken(string $token): ?array {
		if ($this->shareManager === null) {
			return null;
		}

		try {
			$share = $this->shareManager->getShareByToken($token);
			if ($share === null) {
				return null;
			}

			$node = $share->getNode();
			if (!($node instanceof Folder)) {
				return null;
			}

			$folderId = $node->getId();
			$lib = $this->libraryMapper->findByFolderId($folderId);
			if ($lib === null) {
				// Fallback: match by owner path
				$target = '/' . trim($share->getTarget(), '/');
				$lib = $this->libraryMapper->findByPathForUser($share->getSharedBy(), $target);
				if ($lib !== null && $lib->folderId === null && $lib->id !== null) {
					$this->libraryMapper->updateFolderId($lib->id, $folderId);
					$lib->folderId = $folderId;
				}
			}

			if ($lib === null && $this->sublibraryMapper !== null) {
				$sub = $this->sublibraryMapper->findByFolderId($folderId);
				if ($sub !== null) {
					$parentLib = $this->libraryMapper->findById((int)$sub->libraryId);
					if ($parentLib !== null) {
						$shared = $this->buildSublibraryLibrary(
							$parentLib,
							$sub,
							$parentLib->path . ' / ' . $sub->name,
							$folderId,
							true,
							$parentLib->userId,
							self::ACCESS_READ,
							false,
						);

						return [
							'library' => $shared,
							'sublibrary' => $sub,
							'token' => $token,
							'share' => $share,
						];
					}
				}
			}

			if ($lib === null) {
				return null;
			}

			$shared = clone $lib;
			$shared->path = '/' . trim($share->getTarget(), '/');
			$shared->isShared = true;
			$shared->ownerId = $lib->userId;
			$shared->ownerDisplayName = $this->getDisplayName($lib->userId);
			$shared->permission = self::ACCESS_READ;
			$shared->canWrite = false;

			return [
				'library' => $shared,
				'token' => $token,
				'share' => $share,
			];
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function getShares(int $libraryId, string $userId): array {
		$lib = $this->libraryMapper->findById($libraryId);
		if ($lib === null || $lib->userId !== $userId) {
			throw new SecurityException('Only the library owner can manage shares');
		}

		if ($this->shareManager === null) {
			return [];
		}

		$node = $this->getLibraryFolderNode($lib);
		if ($node === null) {
			return [];
		}

		$shares = [];
		foreach ([IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_LINK] as $type) {
			try {
				$found = $this->shareManager->getSharesBy($userId, $type, $node, false, -1);
				foreach ($found as $s) {
					$shares[] = $this->formatShare($s);
				}
			} catch (\Throwable $e) {
				$this->logger?->warning('Failed to query shares for library: ' . $e->getMessage(), [
					'exception' => $e,
					'libraryId' => $libraryId,
					'type' => $type,
				]);
			}
		}

		return $shares;
	}

	/**
	 * Returns an overview of all shares for a library, including main library shares and all sublibrary shares.
	 *
	 * @return array{
	 *     library: array{id: int, name: string, path: string, shares: list<array<string, mixed>>},
	 *     sublibraries: list<array{id: int, name: string, slug: string, filterRules: array<string, mixed>, shares: list<array<string, mixed>>}>
	 * }
	 */
	public function getAllSharesOverview(int $libraryId, string $userId): array {
		$lib = $this->libraryMapper->findById($libraryId);
		if ($lib === null || $lib->userId !== $userId) {
			throw new SecurityException('Only the library owner can manage shares');
		}

		$mainShares = $this->getShares($libraryId, $userId);
		$sublibrariesData = [];

		if ($this->sublibraryMapper !== null) {
			$sublibs = $this->sublibraryMapper->findAllForLibrary($libraryId);
			foreach ($sublibs as $sub) {
				$subShares = [];
				try {
					$subShares = $this->getSublibraryShares($libraryId, (int)$sub->id, $userId);
				} catch (\Throwable $e) {
					$this->logger?->warning('Failed to get sublibrary shares for overview: ' . $e->getMessage());
				}
				$sublibrariesData[] = [
					'id' => (int)$sub->id,
					'name' => $sub->name,
					'slug' => $sub->slug,
					'filters' => $sub->filters,
					'shares' => $subShares,
				];
			}
		}

		$cleanPath = trim($lib->path, '/');
		$libName = basename($cleanPath);
		if (str_ends_with(strtolower($cleanPath), '.db')) {
			$parentDir = dirname($cleanPath);
			$libName = $parentDir !== '.' ? basename($parentDir) : $libName;
		}

		return [
			'library' => [
				'id' => $libraryId,
				'name' => $libName,
				'path' => $lib->path,
				'shares' => $mainShares,
			],
			'sublibraries' => $sublibrariesData,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function createShare(
		int $libraryId,
		string $userId,
		int $shareType,
		string $shareWith,
		string $permission,
		?string $password = null,
	): array {
		$lib = $this->libraryMapper->findById($libraryId);
		if ($lib === null || $lib->userId !== $userId) {
			throw new SecurityException('Only the library owner can manage shares');
		}

		if ($this->shareManager === null) {
			throw new \RuntimeException('Nextcloud ShareManager is not available');
		}

		$node = $this->getLibraryFolderNode($lib);
		if ($node === null) {
			throw new \InvalidArgumentException('Library folder node not found');
		}

		$perms = ($permission === 'write')
			? (Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_CREATE | Constants::PERMISSION_DELETE)
			: Constants::PERMISSION_READ;

		$share = $this->shareManager->newShare();
		$share->setNode($node);
		$share->setShareType($shareType);
		$share->setSharedBy($userId);

		if ($shareType !== IShare::TYPE_LINK) {
			$share->setSharedWith($shareWith);
		}
		if ($password !== null && trim($password) !== '') {
			$share->setPassword(trim($password));
		}
		$share->setPermissions($perms);

		$created = $this->shareManager->createShare($share);
		return $this->formatShare($created);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function updateShare(int $libraryId, string $userId, int|string $shareId, string $permission): array {
		$lib = $this->libraryMapper->findById($libraryId);
		if ($lib === null || $lib->userId !== $userId) {
			throw new SecurityException('Only the library owner can manage shares');
		}

		if ($this->shareManager === null) {
			throw new \RuntimeException('Nextcloud ShareManager is not available');
		}

		$fullId = str_contains((string)$shareId, ':') ? (string)$shareId : 'ocinternal:' . $shareId;
		$share = $this->shareManager->getShareById($fullId);
		if ($share->getSharedBy() !== $userId) {
			throw new SecurityException('You do not have permission to modify this share');
		}

		$perms = ($permission === 'write')
			? (Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_CREATE | Constants::PERMISSION_DELETE)
			: Constants::PERMISSION_READ;

		$share->setPermissions($perms);
		$updated = $this->shareManager->updateShare($share);
		return $this->formatShare($updated);
	}

	public function deleteShare(int $libraryId, string $userId, int|string $shareId): void {
		$lib = $this->libraryMapper->findById($libraryId);
		if ($lib === null || $lib->userId !== $userId) {
			throw new SecurityException('Only the library owner can manage shares');
		}

		if ($this->shareManager === null) {
			throw new \RuntimeException('Nextcloud ShareManager is not available');
		}

		$fullId = str_contains((string)$shareId, ':') ? (string)$shareId : 'ocinternal:' . $shareId;
		$share = $this->shareManager->getShareById($fullId);
		if ($share->getSharedBy() !== $userId) {
			throw new SecurityException('You do not have permission to delete this share');
		}

		$this->shareManager->deleteShare($share);
	}

	/**
	 * @return array{sublibrary: InternalSublibrary, library: InternalLibrary}|null
	 */
	public function findSublibraryByFolderId(int $folderId): ?array {
		if ($this->sublibraryMapper === null) {
			return null;
		}

		$sublib = $this->sublibraryMapper->findByFolderId($folderId);
		if ($sublib === null) {
			return null;
		}

		$lib = $this->libraryMapper->findById($sublib->libraryId);
		if ($lib === null) {
			return null;
		}

		return [
			'sublibrary' => $sublib,
			'library' => $lib,
		];
	}

	/**
	 * @return array{sublibrary: InternalSublibrary, library: InternalLibrary, token: string, share: IShare}|null
	 */
	public function findSublibraryByPublicToken(string $token): ?array {
		if ($this->shareManager === null) {
			return null;
		}

		try {
			$share = $this->shareManager->getShareByToken($token);
			if ($share === null) {
				return null;
			}

			$node = $share->getNode();
			if (!($node instanceof Folder)) {
				return null;
			}

			$info = $this->findSublibraryByFolderId($node->getId());
			if ($info === null) {
				return null;
			}

			return [
				'sublibrary' => $info['sublibrary'],
				'library' => $info['library'],
				'token' => $token,
				'share' => $share,
			];
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function getSublibraryShares(int $libraryId, int $sublibraryId, string $userId): array {
		$lib = $this->libraryMapper->findById($libraryId);
		if ($lib === null || $lib->userId !== $userId) {
			throw new SecurityException('Only the library owner can manage shares');
		}

		$sublib = $this->sublibraryMapper?->findById($sublibraryId);
		if ($sublib === null || (int)$sublib->libraryId !== $libraryId) {
			throw new \InvalidArgumentException('Sublibrary not found');
		}

		if ($this->shareManager === null) {
			return [];
		}

		$node = $this->getSublibraryFolderNode($sublib, $lib);
		if ($node === null) {
			return [];
		}

		$shares = [];
		foreach ([IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_LINK] as $type) {
			try {
				$found = $this->shareManager->getSharesBy($userId, $type, $node, false, -1);
				foreach ($found as $s) {
					$shares[] = $this->formatShare($s);
				}
			} catch (\Throwable $e) {
				$this->logger?->warning('Failed to query shares for sublibrary: ' . $e->getMessage(), [
					'exception' => $e,
					'sublibraryId' => $sublibraryId,
					'type' => $type,
				]);
			}
		}

		return $shares;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function createSublibraryShare(
		int $libraryId,
		int $sublibraryId,
		string $userId,
		int $shareType,
		string $shareWith,
		string $permission,
		?string $password = null,
	): array {
		$lib = $this->libraryMapper->findById($libraryId);
		if ($lib === null || $lib->userId !== $userId) {
			throw new SecurityException('Only the library owner can manage shares');
		}

		$sublib = $this->sublibraryMapper?->findById($sublibraryId);
		if ($sublib === null || (int)$sublib->libraryId !== $libraryId) {
			throw new \InvalidArgumentException('Sublibrary not found');
		}

		if ($this->shareManager === null) {
			throw new \RuntimeException('Nextcloud ShareManager is not available');
		}

		$node = $this->getSublibraryFolderNode($sublib, $lib);
		if ($node === null) {
			throw new \InvalidArgumentException('Sublibrary folder node not found');
		}

		$perms = ($permission === 'write')
			? (Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_CREATE | Constants::PERMISSION_DELETE)
			: Constants::PERMISSION_READ;

		$share = $this->shareManager->newShare();
		$share->setNode($node);
		$share->setShareType($shareType);
		$share->setSharedBy($userId);

		if ($shareType !== IShare::TYPE_LINK) {
			$share->setSharedWith($shareWith);
		}
		if ($password !== null && trim($password) !== '') {
			$share->setPassword(trim($password));
		}
		$share->setPermissions($perms);

		$created = $this->shareManager->createShare($share);
		return $this->formatShare($created);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function updateSublibraryShare(
		int $libraryId,
		int $sublibraryId,
		string $userId,
		int|string $shareId,
		string $permission,
	): array {
		$lib = $this->libraryMapper->findById($libraryId);
		if ($lib === null || $lib->userId !== $userId) {
			throw new SecurityException('Only the library owner can manage shares');
		}

		$sublib = $this->sublibraryMapper?->findById($sublibraryId);
		if ($sublib === null || (int)$sublib->libraryId !== $libraryId) {
			throw new \InvalidArgumentException('Sublibrary not found');
		}

		return $this->updateShare($libraryId, $userId, $shareId, $permission);
	}

	public function deleteSublibraryShare(
		int $libraryId,
		int $sublibraryId,
		string $userId,
		int|string $shareId,
	): void {
		$lib = $this->libraryMapper->findById($libraryId);
		if ($lib === null || $lib->userId !== $userId) {
			throw new SecurityException('Only the library owner can manage shares');
		}

		$sublib = $this->sublibraryMapper?->findById($sublibraryId);
		if ($sublib === null || (int)$sublib->libraryId !== $libraryId) {
			throw new \InvalidArgumentException('Sublibrary not found');
		}

		$this->deleteShare($libraryId, $userId, $shareId);
	}

	private function getLibraryFolderNode(InternalLibrary $lib): ?Folder {
		try {
			$userFolder = $this->rootFolder->getUserFolder($lib->userId);
			$libPath = trim($lib->path, '/');
			if (str_ends_with(strtolower($libPath), '.db')) {
				$libFolder = dirname($libPath);
				$libPath = ($libFolder === '.' ? '' : $libFolder);
			}

			if ($libPath === '') {
				return $userFolder;
			}
			if ($userFolder->nodeExists($libPath)) {
				$node = $userFolder->get($libPath);
				return $node instanceof Folder ? $node : $node->getParent();
			}
		} catch (\Throwable) {
			// Ignore
		}
		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function formatShare(IShare $s): array {
		$canWrite = ($s->getPermissions() & Constants::PERMISSION_UPDATE) !== 0
			|| ($s->getPermissions() & Constants::PERMISSION_CREATE) !== 0;

		$token = $s->getToken();
		$publicUrl = null;
		$webdavUrl = null;
		if ($token !== null && $token !== '') {
			if ($this->urlGenerator !== null) {
				$publicUrl = $this->urlGenerator->getAbsoluteURL('/s/' . $token);
				$webdavUrl = $this->urlGenerator->getAbsoluteURL('/public.php/dav/files/' . $token);
			}
		}

		$shareWith = $s->getSharedWith();
		$shareWithDisplayName = $shareWith;
		if ($s->getShareType() === IShare::TYPE_USER && $shareWith !== '' && $this->userManager !== null) {
			$u = $this->userManager->get($shareWith);
			if ($u !== null) {
				$shareWithDisplayName = $u->getDisplayName();
			}
		}

		return [
			'id' => $s->getId(),
			'fullId' => $s->getFullId(),
			'shareType' => $s->getShareType(),
			'shareWith' => $shareWith,
			'shareWithDisplayName' => $shareWithDisplayName,
			'permission' => $canWrite ? 'write' : 'read',
			'canWrite' => $canWrite,
			'token' => $token,
			'publicUrl' => $publicUrl,
			'webdavUrl' => $webdavUrl,
		];
	}

	private function getDisplayName(string $userId): string {
		if ($this->userManager !== null) {
			$user = $this->userManager->get($userId);
			if ($user !== null) {
				return $user->getDisplayName();
			}
		}
		return $userId;
	}
}
