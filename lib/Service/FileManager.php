<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Service;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\MobileSheetsFile;
use OCA\MobileSheetsManager\Db\MobileSheetsSong;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use Psr\Log\LoggerInterface;

function getFileExtension(string $filename): string {
	$extension = pathinfo($filename, PATHINFO_EXTENSION);

	return $extension !== '' ? '.' . $extension : '';
}

function createWithComposerOrArtist(MobileSheetsSong $song, string $ext): string {
	$composer = $song->composers[0]->name ?? '';
	$artist = $song->artists[0]->name ?? '';

	if ($composer === '' && $artist === '') {
		throw new \Exception('No composer or artist');
	}

	$compArtist = $composer !== '' ? $composer : $artist;
	$book = $song->books[0]->title ?? '';
	$collection = $song->collections[0]->name ?? '';
	$ds = DIRECTORY_SEPARATOR;
	$newPath = $compArtist;

	if ($book !== '') {
		$newPath .= $ds . "{$compArtist} - {$book}";
		$newPath .= $ds . "{$compArtist} - {$book} - {$song->title}" . $ext;
	} elseif ($collection !== '') {
		$newPath .= $ds . "{$compArtist} - {$collection}";
		$newPath .= $ds . "{$compArtist} - {$collection} - {$song->title}" . $ext;
	} else {
		$newPath .= $ds . "{$compArtist} - {$song->title}" . $ext;
	}

	return $newPath;
}

function createWithCollection(MobileSheetsSong $song, string $ext): string {
	$collection = $song->collections[0]->name ?? '';

	if ($collection === '') {
		throw new \Exception('No collection');
	}

	$ds = DIRECTORY_SEPARATOR;
	$newPath = $collection;
	$newPath .= $ds . "{$collection} - {$song->title}" . $ext;

	return $newPath;
}

function createTargetPath(MobileSheetsSong $song, string $ext): string {
	$composer = $song->composers[0]->name ?? '';
	$artist = $song->artists[0]->name ?? '';

	if ($composer !== '' || $artist !== '') {
		return createWithComposerOrArtist($song, $ext);
	}

	if (($song->collections[0]->name ?? '') !== '') {
		return createWithCollection($song, $ext);
	}

	throw new \Exception('No composer, artist, or collection');
}

class FileManager {
	public function __construct(
		private LibraryMapper $libraryMapper,
		private SongMapper $songMapper,
		private IRootFolder $rootFolder,
		private MobileSheetsPathResolver $pathResolver,
		private LoggerInterface $logger,
	) {
	}

	public function regenerateFilePaths(int $libraryId, IUser $user): void {
		$library = $this->libraryMapper->findById($libraryId);
		if ($library === null) {
			throw new \Exception("Library with ID $libraryId not found");
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());

		$path = $library['path'] ?? '';
		$node = $userFolder->get(ltrim($path, '/'));
		$databasePath = $node->getStorage()->getLocalFile($node->getInternalPath());
		if ($databasePath === false) {
			throw new \Exception("Could not retrieve local file path for library with ID $libraryId");
		}

		$songs = $this->songMapper->findSongs($databasePath);

		// MobileSheets stores absolute WebDAV paths that sit next to the library
		// database, so regenerated files belong in the database's parent folder.
		$targetFolder = $node->getParent();

		// The hash codes file tracks each file by its WebDAV path, so the moves
		// below have to be reflected there as well. It is parsed once, edited in
		// memory and written back a single time.
		$hashCodes = MobileSheetsHashCodesFile::forDatabase($databasePath, $this->logger);

		try {
			foreach ($songs as $song) {
				foreach ($song->files as $file) {
					$this->regenerateFilePath($song, $file, $user, $targetFolder, $databasePath, $hashCodes);
				}
			}
		} finally {
			// Save even when the run aborts, so the hash codes file keeps matching
			// the moves that already happened.
			$hashCodes?->save();
		}
	}

	/**
	 * Move a song's file to its regenerated location and persist the new path.
	 *
	 * @throws \RuntimeException when the stored path cannot be resolved, when the
	 *                           file is missing, or when the target already exists
	 */
	public function regenerateFilePath(
		MobileSheetsSong $song,
		MobileSheetsFile $file,
		IUser $user,
		Folder $targetFolder,
		string $databasePath,
		?MobileSheetsHashCodesFile $hashCodes = null,
	): void {
		// Relative target as produced by the naming rules, e.g.
		// "Miles/Miles - Real Book/Miles - Real Book - Blue.pdf".
		$relativeTarget = createTargetPath($song, getFileExtension($file->path));

		// The four path variants:
		//   sourceWebdav / sourceInternal  - where the database says the file is
		//   targetWebdav / targetInternal  - where the naming rules want it
		$source = $this->pathResolver->fromStored($file->path, $user);
		$target = $this->pathResolver->forTarget($relativeTarget, $user, $targetFolder->getPath());

		if ($source === null) {
			throw new \RuntimeException(sprintf(
				'Cannot regenerate file %d: stored path "%s" is not below "%s"',
				$file->id,
				$file->path,
				$this->pathResolver->webdavRoot($user),
			));
		}

		// Filesystem work always happens on the internal path.
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());

		if ($source->internal === $target->internal) {
			$this->logger->debug('MobileSheets file already at its target path', [
				'fileId' => $file->id,
				'path' => $source->internal,
			]);
			return;
		}

		$sourceRelative = $this->pathResolver->relativeToUserFolder($source->internal, $user);
		$targetRelative = $this->pathResolver->relativeToUserFolder($target->internal, $user);
		if ($sourceRelative === null || $targetRelative === null) {
			throw new \RuntimeException(sprintf(
				'Cannot regenerate file %d: "%s" or "%s" escapes the user folder',
				$file->id,
				$source->internal,
				$target->internal,
			));
		}

		// TODO: resolve collisions (unique name or overwrite) instead of failing.
		if ($userFolder->nodeExists($targetRelative)) {
			throw new \RuntimeException(sprintf(
				'Refusing to move file %d (%s): target "%s" already exists',
				$file->id,
				$source->webdav,
				$target->webdav,
			));
		}

		if (!$userFolder->nodeExists($sourceRelative)) {
			throw new \RuntimeException(sprintf(
				'Cannot regenerate file %d: source "%s" does not exist',
				$file->id,
				$source->webdav,
			));
		}

		// Regenerated paths usually live in folders that do not exist yet.
		$createdFolders = $this->ensureParentFolder($userFolder, $targetRelative);

		// Node::move() resolves against the filesystem root, so it needs the full
		// internal path rather than one relative to the user folder.
		$userFolder->get($sourceRelative)->move($target->internal);

		try {
			$this->songMapper->updateFile($databasePath, $file->id, $target->webdav);
		} catch (\Throwable $exception) {
			$this->revertMove(
				$userFolder,
				$targetRelative,
				$source->internal,
				$createdFolders,
				$file,
				$target,
				$exception,
			);

			throw new \RuntimeException(sprintf(
				'Could not persist the new path for file %d; the move was reverted',
				$file->id,
			), 0, $exception);
		}

		// Only tidy up once the move and the database update both succeeded: doing it
		// earlier would break the revert above, which needs the source folder back.
		$emptyFolderBoundary = $this->pathResolver->relativeToUserFolder($targetFolder->getPath(), $user) ?? '';
		$removedFolders = $this->removeEmptyParentFolders($userFolder, $sourceRelative, $emptyFolderBoundary);

		// The hash codes file keys files by their WebDAV path, so it needs the
		// rename too. Hash, modification time and size are unchanged by a move.
		$hashCodes?->updatePath($source->webdav, $target->webdav);

		$this->logger->info('Moved MobileSheets file and updated the database', [
			'fileId' => $file->id,
			'sourceInternal' => $source->internal,
			'targetInternal' => $target->internal,
			'targetWebdav' => $target->webdav,
			'createdFolders' => $createdFolders,
			'removedEmptyFolders' => $removedFolders,
		]);
	}

	/**
	 * Delete the now-empty folders a moved-out file left behind.
	 *
	 * Walks upwards from the source's parent, stopping at the first folder that still
	 * has content and never touching $boundaryRelative itself, so the library folder
	 * (and anything above it) is safe.
	 *
	 * @param string $sourceRelative   path relative to the user folder
	 * @param string $boundaryRelative folder to stop at, relative to the user folder
	 *                                 ('' means the user folder itself)
	 * @return list<string> the relative paths that were deleted, deepest first
	 */
	private function removeEmptyParentFolders(
		Folder $userFolder,
		string $sourceRelative,
		string $boundaryRelative,
	): array {
		$boundaryRelative = trim($boundaryRelative, '/');
		$removed = [];

		$parent = str_replace(DIRECTORY_SEPARATOR, '/', dirname($sourceRelative));

		while ($parent !== '' && $parent !== '.' && $parent !== '/') {
			$parent = trim($parent, '/');
			if ($parent === '' || $parent === $boundaryRelative) {
				break;
			}
			// Never leave the boundary folder's subtree.
			if ($boundaryRelative !== '' && !str_starts_with($parent, $boundaryRelative . '/')) {
				break;
			}

			$next = str_replace(DIRECTORY_SEPARATOR, '/', dirname($parent));

			try {
				if (!$userFolder->nodeExists($parent)) {
					$parent = $next;
					continue;
				}

				$node = $userFolder->get($parent);
				if (!($node instanceof Folder) || count($node->getDirectoryListing()) > 0) {
					// Still has content, so every ancestor does too.
					break;
				}

				$node->delete();
				$removed[] = $parent;
			} catch (\Throwable $exception) {
				// Leaving an empty folder behind is cosmetic; never fail the move over it.
				$this->logger->warning('Could not remove an empty source folder', [
					'folder' => $parent,
					'exception' => $exception,
				]);
				break;
			}

			if ($next === $parent) {
				break;
			}
			$parent = $next;
		}

		return $removed;
	}

	/**
	 * Create the folder chain a target path needs, one level at a time.
	 *
	 * @param string $targetRelative path relative to the user folder
	 * @return list<string> the relative paths that were created, shallowest first
	 */
	private function ensureParentFolder(Folder $userFolder, string $targetRelative): array {
		$parent = str_replace(DIRECTORY_SEPARATOR, '/', dirname($targetRelative));
		if ($parent === '' || $parent === '.' || $parent === '/') {
			return [];
		}

		$created = [];
		$current = '';
		foreach (explode('/', $parent) as $segment) {
			if ($segment === '') {
				continue;
			}
			$current = $current === '' ? $segment : "$current/$segment";
			if (!$userFolder->nodeExists($current)) {
				$userFolder->newFolder($current);
				$created[] = $current;
			}
		}

		return $created;
	}

	/**
	 * Undo a move whose database update failed, so the tree and database stay in sync.
	 *
	 * @param string $sourceInternal  full internal path the file came from
	 * @param list<string> $createdFolders
	 */
	private function revertMove(
		Folder $userFolder,
		string $targetRelative,
		string $sourceInternal,
		array $createdFolders,
		MobileSheetsFile $file,
		MobileSheetsPath $target,
		\Throwable $cause,
	): void {
		try {
			$userFolder->get($targetRelative)->move($sourceInternal);
		} catch (\Throwable $revertException) {
			$this->logger->error('Could not revert the file move; file and database are now inconsistent', [
				'fileId' => $file->id,
				'expectedInternal' => $target->internal,
				'databaseWebdav' => $file->path,
				'exception' => $revertException,
			]);

			return;
		}

		// Best-effort cleanup so the aborted move leaves no empty folders behind.
		foreach (array_reverse($createdFolders) as $folder) {
			try {
				$node = $userFolder->get($folder);
				if (count($node->getDirectoryListing()) === 0) {
					$node->delete();
				}
			} catch (\Throwable) {
				// Leaving an empty folder behind is harmless; the file move is what matters.
			}
		}

		$this->logger->warning('Reverted the file move because the database update failed', [
			'fileId' => $file->id,
			'restoredInternal' => $sourceInternal,
			'removedFolders' => $createdFolders,
			'exception' => $cause,
		]);
	}
}