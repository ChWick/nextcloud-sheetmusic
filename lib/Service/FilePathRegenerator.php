<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Listener\SheetMusicFileEventListener;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFile;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFileFacts;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFileLogEntry;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsHashCodesFile;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPath;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPathResolver;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
use OCA\SheetMusic\Sync\MobileSheets\SongMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Handles regenerating individual file paths according to library naming rules,
 * unique name generation, physical moves, revert on failure, and record updates.
 */
class FilePathRegenerator {
	public const MAX_UNIQUE_NAME_ATTEMPTS = 1000;

	public function __construct(
		private IRootFolder $rootFolder,
		private MobileSheetsPathResolver $pathResolver,
		private LoggerInterface $logger,
		private ?InternalSongFileMapper $internalSongFileMapper = null,
		private ?SongMapper $songMapper = null,
		private ?InternalSongMapper $internalSongMapper = null,
	) {
	}

	/**
	 * Turn a desired relative path into one that does not exist in the folder yet.
	 *
	 * When the path is taken, a counter is inserted before the extension:
	 * "Lead Sheet.pdf" -> "Lead Sheet (2).pdf" -> "Lead Sheet (3).pdf". The file
	 * that already carries the path can be exempted: this is used when a file is
	 * checked against itself, so a second run does not rename it to "(2)".
	 *
	 * @param string $targetRelative path relative to the user folder
	 * @param string|null $keepRelative path of the node itself, to keep the check
	 *                                  from renaming the same file again
	 * @return string the given path, or the first free variant of it
	 * @throws \RuntimeException when even the last variant is taken
	 */
	public function uniqueRelativePath(Folder $userFolder, string $targetRelative, ?string $keepRelative = null): string {
		$targetRelative = ltrim($targetRelative, '/');
		$keepRelative = $keepRelative === null ? null : ltrim($keepRelative, '/');

		if ($targetRelative === $keepRelative || !$userFolder->nodeExists($targetRelative)) {
			return $targetRelative;
		}

		// "X.pdf" -> "X (2).pdf", keeping the folder and the extension untouched.
		$fileName = basename($targetRelative);
		$extension = SongPathNamingRule::getFileExtension($fileName);
		$base = $extension === '' ? $fileName : substr($fileName, 0, -strlen($extension));
		if ($base === '') {
			// Dot files such as ".hidden" have no name part to count up.
			$base = $fileName;
			$extension = '';
		}
		$directory = str_replace(DIRECTORY_SEPARATOR, '/', dirname($targetRelative));
		$prefix = ($directory === '' || $directory === '.') ? '' : rtrim($directory, '/') . '/';

		for ($counter = 2; $counter <= self::MAX_UNIQUE_NAME_ATTEMPTS; $counter++) {
			$candidate = $prefix . $base . ' (' . $counter . ')' . $extension;
			if ($candidate === $keepRelative || !$userFolder->nodeExists($candidate)) {
				return $candidate;
			}
		}

		throw new \RuntimeException(sprintf(
			'Cannot find a free file name for "%s" in "%s": %d variants are taken',
			$targetRelative,
			$userFolder->getPath(),
			self::MAX_UNIQUE_NAME_ATTEMPTS,
		));
	}

	/**
	 * Create the folder chain a target path needs, one level at a time.
	 *
	 * @param string $targetRelative path relative to the user folder
	 * @return list<string> the relative paths that were created, shallowest first
	 */
	public function ensureParentFolder(Folder $userFolder, string $targetRelative): array {
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
	 * The Nextcloud node id of a stored file, for linking straight to it.
	 *
	 * Null when the path belongs to another user or the file does not exist.
	 */
	public function nodeIdOfStoredFile(string $webdavPath, IUser $user): ?int {
		$path = $this->pathResolver->fromStored($webdavPath, $user);
		if ($path === null) {
			return null;
		}

		$relative = $this->pathResolver->relativeToUserFolder($path->internal, $user);
		if ($relative === null || $relative === '') {
			return null;
		}

		try {
			$id = $this->rootFolder->getUserFolder($user->getUID())->get($relative)->getId();
		} catch (\Throwable) {
			return null;
		}

		return $id > 0 ? $id : null;
	}

	/**
	 * Move a song's file to its regenerated location and persist the new path.
	 *
	 * @return MobileSheetsFileLogEntry the report line for the file
	 * @throws SyncException when stored path cannot be resolved or database update fails
	 */
	public function regenerateFilePath(
		MobileSheetsSong $song,
		MobileSheetsFile $file,
		IUser $user,
		Folder $targetFolder,
		string $databasePath,
		?MobileSheetsHashCodesFile $hashCodes = null,
		string $preset = SongPathNamingRule::PRESET_AUTO,
	): MobileSheetsFileLogEntry {
		// Target paths produced by naming rules
		try {
			$validTargetPaths = SongPathNamingRule::createTargetPaths(
				$song,
				SongPathNamingRule::getFileExtension($file->path),
				SongPathNamingRule::fileIndexOf($song, $file),
				SongPathNamingRule::fileCountForExtension($song, $file),
				$preset,
			);
		} catch (NoNamingRuleException $e) {
			if ($preset === SongPathNamingRule::PRESET_DISABLED || $preset === 'none') {
				throw $e;
			}
			$validTargetPaths = [SongPathNamingRule::SONGS_DIR . '/' . SongPathNamingRule::normalizeGeneratedPathSegment(basename($file->path))];
		}
		$relativeTarget = $validTargetPaths[0];

		$source = $this->pathResolver->fromStored($file->path, $user);
		if ($source === null) {
			throw new SyncException(MobileSheetsFileLogEntry::CODE_SOURCE_OUTSIDE_LIBRARY, sprintf(
				'Cannot regenerate file %d: stored path "%s" is not below "%s"',
				$file->id,
				$file->path,
				$this->pathResolver->webdavRoot($user),
			));
		}

		$sourceRelative = $this->pathResolver->relativeToUserFolder($source->internal, $user);
		if ($sourceRelative === null) {
			throw new SyncException(MobileSheetsFileLogEntry::CODE_SOURCE_OUTSIDE_LIBRARY, sprintf(
				'Cannot regenerate file %d: source "%s" escapes the user folder',
				$file->id,
				$source->internal,
			));
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$targetBaseRelative = $this->pathResolver->relativeToUserFolder($targetFolder->getPath(), $user) ?? '';
		$targetPrefix = $targetBaseRelative === '' ? '' : rtrim($targetBaseRelative, '/') . '/';

		if (!$userFolder->nodeExists($sourceRelative)) {
			foreach ($validTargetPaths as $cand) {
				$candRel = $targetPrefix . $cand;
				if ($userFolder->nodeExists($candRel)) {
					$candTarget = $this->pathResolver->forTarget($candRel, $user, $userFolder->getPath());

					$existingNode = $userFolder->get($candRel);
					$isReferencedByOtherSong = $this->isNodeReferencedByOtherSong($existingNode, $song->id, $candTarget->webdav, $databasePath);

					if ($isReferencedByOtherSong) {
						if ($existingNode instanceof File) {
							return $this->copyFileForSeparateSong(
								$existingNode,
								$candRel,
								$userFolder,
								$user,
								$song,
								$file,
								$source->webdav,
								$databasePath,
								$hashCodes,
							);
						}
						continue;
					}

					$targetNode = $existingNode;
					$facts = $this->resolveFileFacts($file->id, $targetNode, $internalSongFile);
					$targetNodeId = (int)$targetNode->getId();
					$this->updateFileRecord(
						$file->id,
						$facts,
						newNodeId: $targetNodeId,
						databasePath: $databasePath,
						webdavPath: $candTarget->webdav,
						oldWebdavPath: $source->webdav,
						internalSongFile: $internalSongFile,
					);
					$hashCodes?->updatePath($source->webdav, $candTarget->webdav);

					return $this->logEntry(
						$song,
						$file,
						sprintf('Linked to existing target file at "%s"', $candRel),
						MobileSheetsFileLogEntry::LEVEL_INFO,
						MobileSheetsFileLogEntry::CODE_PATH_MOVED,
						$targetNodeId > 0 ? $targetNodeId : $this->nodeIdOfStoredFile($candTarget->webdav, $user),
					);
				}
			}

			throw new SyncException(MobileSheetsFileLogEntry::CODE_SOURCE_MISSING, sprintf(
				'Cannot regenerate file %d: source "%s" does not exist',
				$file->id,
				$source->webdav,
			));
		}

		$isAlreadyInPlace = false;
		foreach ($validTargetPaths as $validRel) {
			$validInternal = $this->pathResolver->forTarget(
				$targetPrefix . $validRel,
				$user,
				$userFolder->getPath(),
			)->internal;

			if ($source->internal === $validInternal) {
				$isAlreadyInPlace = true;
				break;
			}
		}

		$targetRelative = '';
		$target = null;
		if (!$isAlreadyInPlace) {
			$targetRelative = $this->uniqueRelativePath(
				$userFolder,
				$targetPrefix . $relativeTarget,
				$sourceRelative,
			);
			$target = $this->pathResolver->forTarget($targetRelative, $user, $userFolder->getPath());
			$isAlreadyInPlace = ($source->internal === $target->internal);
		}

		if ($isAlreadyInPlace) {
			$existingNode = $userFolder->get($sourceRelative);
			$isReferencedByOtherSong = $this->isNodeReferencedByOtherSong($existingNode, $song->id, $source->webdav, $databasePath);

			if ($isReferencedByOtherSong && $existingNode instanceof File) {
				return $this->copyFileForSeparateSong(
					$existingNode,
					$sourceRelative,
					$userFolder,
					$user,
					$song,
					$file,
					$source->webdav,
					$databasePath,
					$hashCodes,
				);
			}

			$this->logger->debug('MobileSheets file already at its target path', [
				'fileId' => $file->id,
				'path' => $source->internal,
			]);

			$existingNodeId = (int)$existingNode->getId();
			return $this->logEntry(
				$song,
				$file,
				'Already at its regenerated path',
				MobileSheetsFileLogEntry::LEVEL_INFO,
				MobileSheetsFileLogEntry::CODE_PATH_CURRENT,
				$existingNodeId > 0 ? $existingNodeId : $this->nodeIdOfStoredFile($file->path, $user),
			);
		}

		$createdFolders = $this->ensureParentFolder($userFolder, $targetRelative);

		$sourceNode = $userFolder->get($sourceRelative);
		$facts = $this->resolveFileFacts($file->id, $sourceNode, $internalSongFile);
		SheetMusicFileEventListener::runWithoutListener(static function () use ($sourceNode, $target): void {
			$sourceNode->move($target->internal);
		});

		$newNodeId = null;
		try {
			$targetNode = $userFolder->get($targetRelative);
			$newNodeId = (int)$targetNode->getId();
		} catch (\Throwable) {
		}

		try {
			$this->updateFileRecord(
				$file->id,
				$facts,
				newNodeId: $newNodeId,
				databasePath: $databasePath,
				webdavPath: $target->webdav,
				oldWebdavPath: $source->webdav,
				internalSongFile: $internalSongFile,
			);
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

			throw new SyncException(
				MobileSheetsFileLogEntry::CODE_PATH_PERSIST_FAILED,
				sprintf(
					'Could not persist the new path for file %d; the move was reverted',
					$file->id,
				),
				$exception,
			);
		}

		$emptyFolderBoundary = $this->pathResolver->relativeToUserFolder($targetFolder->getPath(), $user) ?? '';
		$removedFolders = $this->removeEmptyParentFolders($userFolder, $sourceRelative, $emptyFolderBoundary);

		$hashCodes?->updatePath($source->webdav, $target->webdav);

		$this->logger->info('Moved MobileSheets file and updated the database', [
			'fileId' => $file->id,
			'sourceInternal' => $source->internal,
			'targetInternal' => $target->internal,
			'targetWebdav' => $target->webdav,
			'createdFolders' => $createdFolders,
			'removedEmptyFolders' => $removedFolders,
		]);

		return $this->logEntry(
			$song,
			$file,
			sprintf('Moved to "%s"', $targetRelative),
			MobileSheetsFileLogEntry::LEVEL_INFO,
			MobileSheetsFileLogEntry::CODE_PATH_MOVED,
			$newNodeId !== null && $newNodeId > 0 ? $newNodeId : $this->nodeIdOfStoredFile($target->webdav, $user),
		);
	}

	/**
	 * Undo a move whose database update failed, so the tree and database stay in sync.
	 *
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
			$userFolderPath = $userFolder->getPath();
			if ($sourceInternal === $userFolderPath) {
				$sourceRelative = '';
			} elseif (str_starts_with($sourceInternal, $userFolderPath . '/')) {
				$sourceRelative = substr($sourceInternal, strlen($userFolderPath) + 1);
			} else {
				$sourceRelative = null;
			}

			if ($sourceRelative !== null && $userFolder->nodeExists($sourceRelative) && $sourceRelative !== $targetRelative) {
				$sourceRelative = $this->uniqueRelativePath($userFolder, $sourceRelative, $targetRelative);
				$sourceInternal = $userFolderPath . '/' . $sourceRelative;
			}
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

		foreach ($arrayReversed = array_reverse($createdFolders) as $folder) {
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

	/**
	 * Delete the now-empty folders a moved-out file left behind.
	 *
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
					break;
				}

				$node->delete();
				$removed[] = $parent;
			} catch (\Throwable $exception) {
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

	private function isNodeReferencedByOtherSong(
		Node $node,
		int $currentSongId,
		string $webdavPath,
		string $databasePath,
	): bool {
		if ($this->internalSongFileMapper !== null && $node instanceof File) {
			$others = $this->internalSongFileMapper->findByFileId((int)$node->getId());
			foreach ($others as $other) {
				if ($other->songId !== $currentSongId && $other->deletedAt === null) {
					return true;
				}
			}
		}

		if ($this->songMapper !== null && $databasePath !== '' && file_exists($databasePath)) {
			if ($this->songMapper->isPathReferencedByOtherSong($databasePath, $webdavPath, $currentSongId)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build file facts from the existing DB record when size, hash, and page count
	 * are already known, avoiding a full PDF read/decompression/CRC-32 on a simple move.
	 */
	private function resolveFileFacts(
		int $fileId,
		Node $node,
		?InternalSongFile &$internalSongFile = null,
	): MobileSheetsFileFacts {
		$internalSongFile = $this->internalSongFileMapper?->findById($fileId);
		if ($internalSongFile !== null
			&& $internalSongFile->fileSize > 0
			&& $internalSongFile->fileHash !== null
			&& $internalSongFile->pageCount > 0) {
			return new MobileSheetsFileFacts(
				fileHash: $internalSongFile->fileHash,
				fileSize: $internalSongFile->fileSize,
				lastModified: (int)$node->getMTime() * 1000,
				sourceFilePageCount: $internalSongFile->pageCount,
				pageOrder: $internalSongFile->pageOrder ?? MobileSheetsPdf::pageOrder($internalSongFile->pageCount),
			);
		}

		return MobileSheetsFileFacts::fromNode($node);
	}

	private function updateFileRecord(
		int $fileId,
		MobileSheetsFileFacts $facts,
		?int $newNodeId = null,
		string $databasePath = '',
		?string $webdavPath = null,
		?string $oldWebdavPath = null,
		?InternalSongFile $internalSongFile = null,
	): void {
		$sqliteFileId = $fileId;
		if ($this->internalSongFileMapper !== null) {
			$internalSongFile ??= $this->internalSongFileMapper->findById($fileId);
			if ($internalSongFile !== null) {
				if ($newNodeId !== null) {
					$internalSongFile->fileId = $newNodeId;
				}
				$internalSongFile->fileSize = $facts->fileSize;
				$internalSongFile->fileHash = $facts->fileHash;
				$internalSongFile->updatedAt = time();
				$this->internalSongFileMapper->update($internalSongFile);

				if ($internalSongFile->clientId !== null && $internalSongFile->clientId > 0) {
					$sqliteFileId = $internalSongFile->clientId;
				}
			}
		}

		if ($this->songMapper !== null && $databasePath !== '' && $webdavPath !== null && file_exists($databasePath) && is_writable($databasePath)) {
			$this->songMapper->updateFile($databasePath, $sqliteFileId, $webdavPath, $facts, $oldWebdavPath);
		}
	}

	private function copyFileForSeparateSong(
		File $existingNode,
		string $baseRelative,
		Folder $userFolder,
		IUser $user,
		MobileSheetsSong $song,
		MobileSheetsFile $file,
		string $sourceWebdav,
		string $databasePath,
		?MobileSheetsHashCodesFile $hashCodes,
	): MobileSheetsFileLogEntry {
		$targetRelative = $this->uniqueRelativePath($userFolder, $baseRelative, '');
		$this->ensureParentFolder($userFolder, $targetRelative);
		$copiedNode = SheetMusicFileEventListener::runWithoutListener(
			static fn (): Node => $existingNode->copy($targetRelative)
		);
		$copiedTarget = $this->pathResolver->forTarget($targetRelative, $user, $userFolder->getPath());
		$facts = $this->resolveFileFacts($file->id, $copiedNode, $internalSongFile);
		$copiedNodeId = (int)$copiedNode->getId();

		$this->updateFileRecord(
			$file->id,
			$facts,
			newNodeId: $copiedNodeId,
			databasePath: $databasePath,
			webdavPath: $copiedTarget->webdav,
			oldWebdavPath: $sourceWebdav,
			internalSongFile: $internalSongFile,
		);
		$hashCodes?->updatePath($sourceWebdav, $copiedTarget->webdav);

		return $this->logEntry(
			$song,
			$file,
			sprintf('Copied file for separate song to "%s"', $targetRelative),
			MobileSheetsFileLogEntry::LEVEL_INFO,
			MobileSheetsFileLogEntry::CODE_PATH_MOVED,
			$copiedNodeId > 0 ? $copiedNodeId : $this->nodeIdOfStoredFile($copiedTarget->webdav, $user),
		);
	}

	private function logEntry(
		MobileSheetsSong $song,
		MobileSheetsFile $file,
		string $message,
		string $level,
		string $code = '',
		?int $fileId = null,
	): MobileSheetsFileLogEntry {
		return new MobileSheetsFileLogEntry(
			$song->id,
			$song->title,
			$file->path,
			$message,
			$level,
			$code,
			$fileId,
		);
	}
}
