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

/**
 * Zero-padded index prefix used to tell the files of a multi-file song apart,
 * e.g. "001 " producing "001 Miles - Real Book - Blue.pdf".
 *
 * A song with a single file keeps its plain, unprefixed name.
 */
function fileIndexPrefix(?int $fileIndex, int $fileCount): string {
	if ($fileIndex === null || $fileCount < 2) {
		return '';
	}

	return sprintf('%03d ', $fileIndex);
}

/** 1-based position of $file within $song, or null when it is not listed there. */
function fileIndexOf(MobileSheetsSong $song, MobileSheetsFile $file): ?int {
	foreach ($song->files as $position => $candidate) {
		if ($candidate->id === $file->id) {
			return $position + 1;
		}
	}

	return null;
}

function createWithComposerOrArtist(MobileSheetsSong $song, string $ext, string $filePrefix = ''): string {
	$composer = $song->composers[0]->name ?? '';
	$artist = $song->artists[0]->name ?? '';

	if ($composer === '' && $artist === '') {
		throw new NoNamingRuleException('No composer or artist');
	}

	$compArtist = $composer !== '' ? $composer : $artist;
	$book = $song->books[0]->title ?? '';
	$collection = $song->collections[0]->name ?? '';

	if ($book !== '') {
		$folder = $compArtist . DIRECTORY_SEPARATOR . "{$compArtist} - {$book}";
		$fileName = "{$compArtist} - {$book} - {$song->title}";
	} elseif ($collection !== '') {
		$folder = $compArtist . DIRECTORY_SEPARATOR . "{$compArtist} - {$collection}";
		$fileName = "{$compArtist} - {$collection} - {$song->title}";
	} else {
		$folder = $compArtist;
		$fileName = "{$compArtist} - {$song->title}";
	}

	return $folder . DIRECTORY_SEPARATOR . $filePrefix . $fileName . $ext;
}

function createWithCollection(MobileSheetsSong $song, string $ext, string $filePrefix = ''): string {
	$collection = $song->collections[0]->name ?? '';

	if ($collection === '') {
		throw new NoNamingRuleException('No collection');
	}

	$fileName = "{$collection} - {$song->title}";

	return $collection . DIRECTORY_SEPARATOR . $filePrefix . $fileName . $ext;
}

function createTargetPath(
	MobileSheetsSong $song,
	string $ext,
	?int $fileIndex = null,
	int $fileCount = 1,
): string {
	$filePrefix = fileIndexPrefix($fileIndex, $fileCount);

	$composer = $song->composers[0]->name ?? '';
	$artist = $song->artists[0]->name ?? '';

	if ($composer !== '' || $artist !== '') {
		return createWithComposerOrArtist($song, $ext, $filePrefix);
	}

	if (($song->collections[0]->name ?? '') !== '') {
		return createWithCollection($song, $ext, $filePrefix);
	}

	throw new NoNamingRuleException('No composer, artist, or collection');
}

class FileManager {
	/** Highest counter tried when a file name has to be made unique. */
	private const MAX_UNIQUE_NAME_ATTEMPTS = 1000;

	public function __construct(
		private LibraryMapper $libraryMapper,
		private SongMapper $songMapper,
		private IRootFolder $rootFolder,
		private MobileSheetsPathResolver $pathResolver,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Both representations of a file that already sits in the user's folder.
	 *
	 * Used for a file that was just uploaded or copied next to the library database:
	 * the naming rules move it to its final location afterwards, but it has to be
	 * recorded in the database under its current, real WebDAV path first.
	 *
	 * @param string $internalPath Nextcloud node path of the file, e.g. "/admin/files/Noten/song.pdf"
	 * @throws \InvalidArgumentException when the path is not inside the user's folder
	 */
	public function pathOfExistingFile(string $internalPath, IUser $user): MobileSheetsPath {
		$path = $this->pathResolver->fromInternal($internalPath, $user);
		if ($path === null) {
			throw new \InvalidArgumentException(sprintf(
				"File '%s' is not inside the user folder '%s'",
				$internalPath,
				$this->pathResolver->internalRoot($user),
			));
		}

		return $path;
	}

	/**
	 * Both representations of a file that is stored in the MobileSheets database.
	 *
	 * Used for a file the database points at, e.g. when it has to be deleted from the
	 * user's folder. Null when the stored path does not belong to this user: MobileSheets
	 * keeps absolute WebDAV paths, so a library may reference files of another instance.
	 *
	 * @param string $webdavPath path as stored in the database,
	 *                           e.g. "/remote.php/dav/files/admin/Noten/song.pdf"
	 */
	public function pathOfStoredFile(string $webdavPath, IUser $user): ?MobileSheetsPath {
		return $this->pathResolver->fromStored($webdavPath, $user);
	}

	/**
	 * Path a file can take without replacing another one.
	 *
	 * MobileSheets names files after composer, book, collection and title, so two songs
	 * that only share a title produce the same name - and an upload or attachment keeps
	 * the name the file had at its source, which can be taken in the library folder as
	 * well. Without this an added file would reuse the file of another song, or two
	 * entries of one song would point at a single file.
	 *
	 * An occupied name gets a counter, "X.pdf" becoming "X (2).pdf", then "X (3).pdf".
	 *
	 * @param string      $targetRelative path relative to the user folder, e.g. "Noten/X.pdf"
	 * @param string|null $keepRelative   path that counts as free because the file being
	 *                                    handled already sits there; keeps repeated runs
	 *                                    from renaming the same file again
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
		$extension = getFileExtension($fileName);
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
	 * Move every file of the library to its regenerated path.
	 *
	 * The run is best-effort: a file that cannot be moved - because its target is
	 * taken, its source is gone, or the database rejects the update - is recorded
	 * and the run continues with the remaining files, so one bad file cannot lose
	 * the work done for the rest of the library. A line is collected for every file
	 * the run touches and returned, so the caller can show the user a log of what
	 * happened.
	 *
	 * @return list<MobileSheetsFileLogEntry> one line per file the run touched
	 */
	public function regenerateFilePaths(int $libraryId, IUser $user): array {
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

		$log = [];
		try {
			foreach ($songs as $song) {
				$this->regenerateSongFiles($song, $user, $targetFolder, $databasePath, $hashCodes, $log);
			}
		} finally {
			// Save even when the run aborts, so the hash codes file keeps matching
			// the moves that already happened.
			$hashCodes?->save();
		}

		return $log;
	}

	/**
	 * Move every file of one song to its regenerated location.
	 *
	 * The song is read from the database rather than passed in, so the file list is
	 * up to date and ordered: the position of a file decides its numeric prefix, and a
	 * file that was just added to the song has to take part in the numbering.
	 *
	 * @param Folder $targetFolder folder the regenerated files belong in, normally the
	 *                             library database's parent
	 * @throws \RuntimeException     when the song is not part of the library
	 * @throws \Exception            when the song has no composer, artist or collection
	 *
	 * @see createTargetPath() for the naming rules
	 */
	public function regenerateSongFilePaths(
		int $songId,
		IUser $user,
		Folder $targetFolder,
		string $databasePath,
		?MobileSheetsHashCodesFile $hashCodes = null,
	): void {
		$song = $this->songMapper->findSong($databasePath, $songId);
		if ($song === null) {
			throw new \RuntimeException("Song with ID $songId not found in the library '$databasePath'");
		}

		$this->regenerateSongFiles($song, $user, $targetFolder, $databasePath, $hashCodes);
	}

	/**
	 * Move each file the song lists, in the order that decides the numeric prefix.
	 *
	 * When $log is given, a line is recorded for every file the song lists - moved,
	 * already in place, or failed - and the run continues past failures; otherwise
	 * a failure is thrown, which suits the single-song relocations that have no
	 * report to fill in.
	 *
	 * @param list<MobileSheetsFileLogEntry>|null $log collects a line per file when given
	 */
	private function regenerateSongFiles(
		MobileSheetsSong $song,
		IUser $user,
		Folder $targetFolder,
		string $databasePath,
		?MobileSheetsHashCodesFile $hashCodes,
		?array &$log = null,
	): void {
		foreach ($song->files as $file) {
			try {
				$message = $this->regenerateFilePath($song, $file, $user, $targetFolder, $databasePath, $hashCodes);
				if ($log !== null) {
					$log[] = $this->logEntry($song, $file, $message, MobileSheetsFileLogEntry::LEVEL_INFO);
				}
			} catch (\Throwable $exception) {
				if ($log === null) {
					throw $exception;
				}
				// A song with no naming rule keeps its name on purpose, so it is only a
				// warning; anything else that stops the move is a real error.
				$level = $exception instanceof NoNamingRuleException
					? MobileSheetsFileLogEntry::LEVEL_WARNING
					: MobileSheetsFileLogEntry::LEVEL_ERROR;
				$log[] = $this->logEntry($song, $file, $exception->getMessage(), $level);
			}
		}
	}

	/**
	 * Build one line of the run's log.
	 *
	 * Lines are shown to the user in a dialog, so they are deliberately not written
	 * to the Nextcloud log as well: a per-file entry there would only restate what
	 * the user already sees, and logging the exception would repeat the whole
	 * accumulated report in its arguments for every single file. Genuine recovery
	 * problems are still logged where they happen, i.e. when a move is reverted.
	 */
	private function logEntry(MobileSheetsSong $song, MobileSheetsFile $file, string $message, string $level): MobileSheetsFileLogEntry {
		return new MobileSheetsFileLogEntry(
			$song->id,
			$song->title,
			$file->path,
			$message,
			$level,
		);
	}

	/**
	 * Move a song's file to its regenerated location and persist the new path.
	 *
	 * A target that is already taken is not an error: the file gets a unique name so it
	 * cannot replace or share another song's file.
	 *
	 * @return string a one-line description of what happened to the file
	 * @throws \RuntimeException when the stored path cannot be resolved, when the source
	 *                           file is missing, or when the database update failed
	 */
	public function regenerateFilePath(
		MobileSheetsSong $song,
		MobileSheetsFile $file,
		IUser $user,
		Folder $targetFolder,
		string $databasePath,
		?MobileSheetsHashCodesFile $hashCodes = null,
	): string {
		// Relative target as produced by the naming rules, e.g.
		// "Miles/Miles - Real Book/Miles - Real Book - Blue.pdf". Songs with more
		// than one file would otherwise all map onto the same name, so each file
		// gets its position as a prefix: "001 Miles - Real Book - Blue.pdf".
		$relativeTarget = createTargetPath(
			$song,
			getFileExtension($file->path),
			fileIndexOf($song, $file),
			count($song->files),
		);

		// The four path variants:
		//   sourceWebdav / sourceInternal  - where the database says the file is
		//   targetWebdav / targetInternal  - where the naming rules want it
		$source = $this->pathResolver->fromStored($file->path, $user);
		if ($source === null) {
			throw new \RuntimeException(sprintf(
				'Cannot regenerate file %d: stored path "%s" is not below "%s"',
				$file->id,
				$file->path,
				$this->pathResolver->webdavRoot($user),
			));
		}

		$sourceRelative = $this->pathResolver->relativeToUserFolder($source->internal, $user);
		if ($sourceRelative === null) {
			throw new \RuntimeException(sprintf(
				'Cannot regenerate file %d: source "%s" escapes the user folder',
				$file->id,
				$source->internal,
			));
		}

		// Filesystem work always happens on the internal path.
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());

		// The naming rules produce paths below the library folder, while uniqueRelativePath()
		// and the node lookups work with paths relative to the user folder - a target that
		// is checked against the wrong base would look free and another song's file would
		// be replaced. Two songs that share a title map onto the same target, so a taken
		// name gets a counter instead.
		$targetBaseRelative = $this->pathResolver->relativeToUserFolder($targetFolder->getPath(), $user) ?? '';
		$targetRelative = $this->uniqueRelativePath(
			$userFolder,
			($targetBaseRelative === '' ? '' : rtrim($targetBaseRelative, '/') . '/') . $relativeTarget,
			$sourceRelative,
		);
		$target = $this->pathResolver->forTarget($targetRelative, $user, $userFolder->getPath());

		if ($source->internal === $target->internal) {
			// Already in place. This also covers a file that already carries the counter
			// this run would produce, so running twice does not rename it again.
			$this->logger->debug('MobileSheets file already at its target path', [
				'fileId' => $file->id,
				'path' => $source->internal,
			]);
			return 'Already at its regenerated path';
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
		// internal path rather than one relative to the user folder. A move does not
		// touch the file's content, so its metadata is read up front and used to
		// refresh LastModified (and the derived fields) alongside the new path.
		$sourceNode = $userFolder->get($sourceRelative);
		$facts = MobileSheetsFileFacts::fromNode($sourceNode);
		$sourceNode->move($target->internal);

		try {
			$this->songMapper->updateFile($databasePath, $file->id, $target->webdav, $facts);
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

		return sprintf('Moved to "%s"', $targetRelative);
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