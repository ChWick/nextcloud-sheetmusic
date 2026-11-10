<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\LibraryMapper;
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

	public function __construct(
		private LibraryMapper $libraryMapper,
		private IRootFolder $rootFolder,
		private ?IShareManager $shareManager = null,
		private ?IUserManager $userManager = null,
		private ?IURLGenerator $urlGenerator = null,
		private ?LoggerInterface $logger = null,
	) {
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

	public function getAccessibleLibrary(string $userId, int $libraryId): ?InternalLibrary {
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
			if ($lib === null || $lib->userId === $userId) {
				return null;
			}

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
