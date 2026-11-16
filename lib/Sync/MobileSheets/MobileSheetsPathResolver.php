<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

use OCP\Files\IRootFolder;
use OCP\IUser;

/**
 * Converts between the two MobileSheets path representations.
 *
 *   webdav   /remote.php/dav/files/{uid}/{relative}
 *   internal /{uid}/files/{relative}
 */
class MobileSheetsPathResolver {
	/** Every MobileSheets path is stored below this prefix. */
	public const WEBDAV_ROOT = '/remote.php/dav/files';
	public const LEGACY_WEBDAV_ROOT = '/remote.php/webdav';

	public function __construct(
		private IRootFolder $rootFolder,
	) {
	}

	/** Internal root of a user, e.g. "/admin/files". */
	public function internalRoot(IUser $user): string {
		return rtrim($this->rootFolder->getUserFolder($user->getUID())->getPath(), '/');
	}

	/** WebDAV root of a user, e.g. "/remote.php/dav/files/admin". */
	public function webdavRoot(IUser $user): string {
		return self::WEBDAV_ROOT . '/' . $user->getUID();
	}

	/**
	 * Build both representations for a path stored in the MobileSheets database.
	 *
	 * Returns null when the stored path does not belong to this user: MobileSheets
	 * keeps absolute WebDAV paths, so a library may legitimately reference files of
	 * another instance or user.
	 */
	public function fromStored(string $webdavPath, IUser $user): ?MobileSheetsPath {
		$webdavRoot = $this->webdavRoot($user);
		$relative = null;
		if (str_starts_with($webdavPath, $webdavRoot . '/')) {
			$relative = substr($webdavPath, strlen($webdavRoot) + 1);
		} elseif (str_starts_with($webdavPath, self::LEGACY_WEBDAV_ROOT . '/')) {
			$relative = substr($webdavPath, strlen(self::LEGACY_WEBDAV_ROOT) + 1);
		}

		if ($relative !== null) {
			$normalizedRelative = $this->stripSublibrarySegment($relative);
			$internal = $this->normalize($this->internalRoot($user) . '/' . rawurldecode($normalizedRelative));
			$canonicalWebdav = $this->webdavRoot($user) . '/' . $normalizedRelative;
			return new MobileSheetsPath($internal, $canonicalWebdav);
		}

		return null;
	}

	/**
	 * Strip virtual sublibrary directory segments if present.
	 * E.g. "test2/_SubLibraries_/organ/file.pdf" -> "test2/file.pdf"
	 * or "_SubLibraries_/organ/file.pdf" -> "file.pdf"
	 */
	public function stripSublibrarySegment(string $path): string {
		$stripped = preg_replace('#(?<=^|/)(?:\.sublibraries|_SubLibraries_|SubLibraries)/[^/]+/#', '', $path);
		return $stripped ?? $path;
	}

	/**
	 * Build both representations for a file that already exists in the user's folder.
	 *
	 * Returns null when the internal path is outside the user folder.
	 *
	 * @param string $internalPath Nextcloud node path, e.g. "/admin/files/Noten/sub/file.pdf"
	 */
	public function fromInternal(string $internalPath, IUser $user): ?MobileSheetsPath {
		$internal = $this->normalize($internalPath);
		$relative = $this->relativeToUserFolder($internal, $user);
		if ($relative === null) {
			return null;
		}

		return new MobileSheetsPath(
			$internal,
			$relative === '' ? $this->webdavRoot($user) : $this->webdavRoot($user) . '/' . $relative,
		);
	}

	/**
	 * Build both representations for a target path relative to an internal folder.
	 *
	 * @param string $relativePath path below $internalBaseFolder, e.g. "Miles/Miles - Book/Miles - Book - Blue.pdf"
	 * @param string $internalBaseFolder internal node path of the base folder, e.g. "/admin/files/Noten"
	 */
	public function forTarget(string $relativePath, IUser $user, string $internalBaseFolder): MobileSheetsPath {
		$internal = $this->normalize(rtrim($internalBaseFolder, '/') . '/' . ltrim($relativePath, '/'));

		$internalRoot = $this->normalize($this->internalRoot($user));
		if ($internal !== $internalRoot && !str_starts_with($internal, $internalRoot . '/')) {
			throw new \InvalidArgumentException(
				"Target path '$internal' is not inside the user folder '$internalRoot'",
			);
		}

		$relativeToRoot = $internal === $internalRoot
			? ''
			: substr($internal, strlen($internalRoot) + 1);

		return new MobileSheetsPath($internal, $this->webdavRoot($user) . '/' . $relativeToRoot);
	}

	/** Path of $internal relative to a folder, e.g. "sub/file.pdf" relative to "/admin/files/Noten". */
	public function relativeToFolder(string $internalPath, string $folderPath): ?string {
		$internalPath = $this->normalize($internalPath);
		$folderPath = $this->normalize($folderPath);
		if ($internalPath === $folderPath) {
			return '';
		}
		if (!str_starts_with($internalPath, $folderPath . '/')) {
			return null;
		}

		return substr($internalPath, strlen($folderPath) + 1);
	}

	/** Path of $internal relative to the user folder, e.g. "Noten/sub/file.pdf". */
	public function relativeToUserFolder(string $internalPath, IUser $user): ?string {
		return $this->relativeToFolder($internalPath, $this->internalRoot($user));
	}

	/**
	 * Collapse "." and ".." segments so the prefix checks above cannot be
	 * defeated by traversal sequences.
	 */
	private function normalize(string $path): string {
		$segments = [];
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				array_pop($segments);
				continue;
			}
			$segments[] = $segment;
		}

		return '/' . implode('/', $segments);
	}
}
