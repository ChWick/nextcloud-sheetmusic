<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFile;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFileLogEntry;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsHashCodesFile;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPath;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPathResolver;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdfMetadata;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
use OCA\SheetMusic\Sync\MobileSheets\SongMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use Psr\Log\LoggerInterface;

function getFileExtension(string $filename): string {
	return SongPathNamingRule::getFileExtension($filename);
}

function fileIndexPrefix(?int $fileIndex, int $fileCount): string {
	return SongPathNamingRule::fileIndexPrefix($fileIndex, $fileCount);
}

function fileIndexOf(MobileSheetsSong $song, MobileSheetsFile $file): ?int {
	return SongPathNamingRule::fileIndexOf($song, $file);
}

function fileCountForExtension(MobileSheetsSong $song, MobileSheetsFile $file): int {
	return SongPathNamingRule::fileCountForExtension($song, $file);
}

function normalizeGeneratedPath(string $path): string {
	return SongPathNamingRule::normalizeGeneratedPath($path);
}

function normalizeGeneratedPathSegment(string $segment): string {
	return SongPathNamingRule::normalizeGeneratedPathSegment($segment);
}

function normalizeKeyNameForGeneratedPath(string $name): string {
	return SongPathNamingRule::normalizeKeyNameForGeneratedPath($name);
}

function extractNonEmptyEntityNames(array $entities, string $property = 'name'): array {
	return SongPathNamingRule::extractNonEmptyEntityNames($entities, $property);
}

function sortStringsByLengthDescending(array $items): array {
	return SongPathNamingRule::sortStringsByLengthDescending($items);
}

function titleContainsComponent(string $title, string $component): bool {
	return SongPathNamingRule::titleContainsComponent($title, $component);
}

function titleContainsKey(string $title, string $key): bool {
	return SongPathNamingRule::titleContainsKey($title, $key);
}

function buildSongTitlePart(MobileSheetsSong $song): string {
	return SongPathNamingRule::buildSongTitlePart($song);
}

function createTargetPaths(
	MobileSheetsSong $song,
	string $ext,
	?int $fileIndex = null,
	int $fileCount = 1,
	string $preset = SongPathNamingRule::PRESET_AUTO,
): array {
	return SongPathNamingRule::createTargetPaths($song, $ext, $fileIndex, $fileCount, $preset);
}

function createTargetPath(
	MobileSheetsSong $song,
	string $ext,
	?int $fileIndex = null,
	int $fileCount = 1,
	string $preset = SongPathNamingRule::PRESET_AUTO,
): string {
	return SongPathNamingRule::createTargetPath($song, $ext, $fileIndex, $fileCount, $preset);
}

class FileManager {
	/** Highest counter tried when a file name has to be made unique. */
	public const MAX_UNIQUE_NAME_ATTEMPTS = FilePathRegenerator::MAX_UNIQUE_NAME_ATTEMPTS;

	private FilePathRegenerator $pathRegenerator;
	private ?FileMetadataSyncer $metadataSyncer = null;

	public function __construct(
		private LibraryMapper $libraryMapper,
		private IRootFolder $rootFolder,
		private MobileSheetsPathResolver $pathResolver,
		private LoggerInterface $logger,
		private ?LibraryService $libraryService = null,
		private ?InternalSongFileMapper $internalSongFileMapper = null,
		private ?InternalSongMapper $internalSongMapper = null,
		private ?SongMapper $songMapper = null,
		?FilePathRegenerator $pathRegenerator = null,
	) {
		$this->pathRegenerator = $pathRegenerator ?? new FilePathRegenerator(
			$this->rootFolder,
			$this->pathResolver,
			$this->logger,
			$this->internalSongFileMapper,
			$this->songMapper,
			$this->internalSongMapper,
		);
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
	 * @param string $targetRelative path relative to the user folder, e.g. "Noten/X.pdf"
	 * @param string|null $keepRelative path that counts as free because the file being
	 *                                  handled already sits there; keeps repeated runs
	 *                                  from renaming the same file again
	 * @return string the given path, or the first free variant of it
	 * @throws \RuntimeException when even the last variant is taken
	 */
	public function uniqueRelativePath(Folder $userFolder, string $targetRelative, ?string $keepRelative = null): string {
		return $this->pathRegenerator->uniqueRelativePath($userFolder, $targetRelative, $keepRelative);
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
		if ($library !== null && (!$library->autoPaths || SongPathNamingRule::isDisabledPreset($library->namingRulePreset))) {
			return [];
		}

		$preset = $library?->namingRulePreset ?? SongPathNamingRule::PRESET_AUTO;

		['databasePath' => $databasePath, 'targetFolder' => $targetFolder] = $this->libraryContext($libraryId, $user);

		$songs = $this->internalSongMapper !== null
			? $this->internalSongMapper->findLibraryData($libraryId, $user)->songs
			: ($this->songMapper !== null ? $this->songMapper->findSongs($databasePath) : []);

		// MobileSheets stores absolute WebDAV paths that sit next to the library
		// database, so regenerated files belong in the database's parent folder -
		// which libraryContext() hands over as the target folder.

		// The hash codes file tracks each file by its WebDAV path, so the moves
		// below have to be reflected there as well. It is parsed once, edited in
		// memory and written back a single time.
		$hashCodes = MobileSheetsHashCodesFile::forDatabase($databasePath, $this->logger);

		$log = [];
		try {
			foreach ($songs as $song) {
				$this->regenerateSongFiles($song, $user, $targetFolder, $databasePath, $hashCodes, $preset, $log);
			}
		} finally {
			// Save even when the run aborts, so the hash codes file keeps matching
			// the moves that already happened.
			$hashCodes?->save();
		}

		return $log;
	}

	/**
	 * Resolve the target folder of a library.
	 *
	 * @throws \Exception when the library is unknown or its folder cannot be resolved
	 */
	public function libraryFolder(int $libraryId, IUser $user): Folder {
		$library = $this->libraryMapper->findById($libraryId);
		if ($library === null) {
			throw new \Exception("Library with ID $libraryId not found");
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$path = $library->path;

		// Try to resolve as physical file node first (for backward compatibility and test mocks)
		try {
			$node = $userFolder->get(ltrim($path, '/'));
			if ($node instanceof Folder) {
				return $node;
			}
			return $node->getParent();
		} catch (\Throwable $e) {
			// Purely virtual: resolve folder
			$folderNode = $userFolder->get(InternalLibrary::normalizeFolderPath($path));
			if ($folderNode instanceof Folder) {
				return $folderNode;
			}
			throw new \Exception("Library folder not found for ID $libraryId", 0, $e);
		}
	}

	/**
	 * Resolve the library database file and the folder its files belong in.
	 *
	 * Both phases of a library sync need the same context: the local path of the
	 * library database to read songs from, and the database's parent folder as
	 * the base the naming rules produce paths against.
	 *
	 * @return array{databasePath: string, targetFolder: Folder}
	 * @throws \Exception when the library is unknown or its path cannot be resolved
	 */
	public function libraryContext(int $libraryId, IUser $user): array {
		$targetFolder = $this->libraryFolder($libraryId, $user);

		$library = $this->libraryMapper->findById($libraryId);
		$path = $library?->path ?? '';

		$databasePath = null;
		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$node = $userFolder->get(ltrim($path, '/'));
			if (!($node instanceof Folder)) {
				$databasePath = $node->getStorage()->getLocalFile($node->getInternalPath());
			}
		} catch (\Throwable) {
		}

		$databasePath ??= $this->libraryService?->getDatabasePath($user->getUID(), $libraryId);

		if ($databasePath === null || $databasePath === false) {
			throw new \Exception("Could not retrieve local file path for library with ID $libraryId");
		}

		return [
			'databasePath' => $databasePath,
			'targetFolder' => $targetFolder,
		];
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
	 * @throws \RuntimeException when the song is not part of the library
	 * @throws \Exception when the song has no composer, artist or collection
	 *
	 * @see createTargetPath() for the naming rules
	 */
	public function regenerateSongFilePaths(
		int $songId,
		IUser $user,
		Folder $targetFolder,
		string $databasePath = '',
		?MobileSheetsHashCodesFile $hashCodes = null,
		?string $preset = null,
	): ?MobileSheetsSong {
		$song = $this->internalSongMapper?->findSong($songId, $user)
			?? ($this->songMapper !== null && $databasePath !== '' ? $this->songMapper->findSong($databasePath, $songId) : null);
		if ($song === null) {
			throw new \RuntimeException("Song with ID $songId not found");
		}

		if ($preset === null && $this->internalSongMapper !== null) {
			$internalSong = $this->internalSongMapper->findById($songId);
			if ($internalSong !== null && $internalSong->libraryId !== null) {
				$lib = $this->libraryMapper->findById($internalSong->libraryId);
				$preset = $lib?->namingRulePreset;
			}
		}
		$preset ??= SongPathNamingRule::PRESET_AUTO;

		$anyMoved = $this->regenerateSongFiles($song, $user, $targetFolder, $databasePath, $hashCodes, $preset);
		return $anyMoved ? null : $song;
	}

	/**
	 * Move each file the song lists, in the order that decides the numeric prefix.
	 *
	 * When $log is given, a line is recorded for every file the song lists - moved,
	 * already in place, or failed - and the run continues past failures; otherwise
	 * a failure is thrown, which suits the single-song relocations that have no
	 * report to fill in.
	 *
	 * Failures are graded and coded by the exception that caused them, so the
	 * report can both filter them and link to the file they are about.
	 *
	 * @param list<MobileSheetsFileLogEntry>|null $log collects a line per file when given
	 */
	private function regenerateSongFiles(
		MobileSheetsSong $song,
		IUser $user,
		Folder $targetFolder,
		string $databasePath,
		?MobileSheetsHashCodesFile $hashCodes,
		string $preset = SongPathNamingRule::PRESET_AUTO,
		?array &$log = null,
	): bool {
		$anyMoved = false;
		foreach ($song->files as $file) {
			try {
				$entry = $this->regenerateFilePath($song, $file, $user, $targetFolder, $databasePath, $hashCodes, $preset);
				if ($entry->code === MobileSheetsFileLogEntry::CODE_PATH_MOVED) {
					$anyMoved = true;
				}
				if ($log !== null) {
					$log[] = $entry;
				}
			} catch (\Throwable $exception) {
				if ($log === null) {
					throw $exception;
				}
				// A song with no naming rule keeps its name on purpose, so it is only a
				// warning; anything else that stops the move is a real error. Known
				// problems grade and code themselves, anything unexpected is an error
				// with the generic path-failed code.
				$level = $exception instanceof SyncException
					? $exception->errorLevel()
					: MobileSheetsFileLogEntry::LEVEL_ERROR;
				$code = $exception instanceof SyncException
					? $exception->errorCode()
					: MobileSheetsFileLogEntry::CODE_PATH_FAILED;
				$log[] = MobileSheetsFileLogEntry::forSongFile(
					$song,
					$file,
					$exception->getMessage(),
					$level,
					$code,
					$this->nodeIdOfStoredFile($file->path, $user),
				);
			}
		}

		return $anyMoved;
	}

	/**
	 * The Nextcloud node id of a stored file, for linking straight to it.
	 *
	 * Null when the path belongs to another user or the file does not exist -
	 * which is exactly the case where the report has to offer a folder link
	 * instead of a file link.
	 */
	public function nodeIdOfStoredFile(string $webdavPath, IUser $user): ?int {
		return $this->pathRegenerator->nodeIdOfStoredFile($webdavPath, $user);
	}

	/**
	 * Move a song's file to its regenerated location and persist the new path.
	 *
	 * A target that is already taken is not an error: the file gets a unique name so it
	 * cannot replace or share another song's file.
	 *
	 * @return MobileSheetsFileLogEntry the report line for the file: "moved" or
	 *                                  "already in place", with the node id of the
	 *                                  file where it now lives
	 * @throws SyncException when the stored path cannot be resolved, when the source
	 *                       file is missing, or when the database update failed -
	 *                       carrying the code the report shows
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
		return $this->pathRegenerator->regenerateFilePath(
			$song,
			$file,
			$user,
			$targetFolder,
			$databasePath,
			$hashCodes,
			$preset,
		);
	}

	/**
	 * Bring one attached file's document properties up to date with the song.
	 *
	 * The PDF's Info dictionary is (re)written with the song's data - title,
	 * composers, artists, books, collections - so importing the file back into
	 * MobileSheets recreates the song, and the file facts the changed bytes
	 * invalidate are refreshed in the Files table and the hash codes file.
	 *
	 * The write is skipped when the file already carries exactly the song's
	 * properties ({@see MobileSheetsPdfMetadata::matchesSong()}), which keeps
	 * repeated runs from piling up incremental revisions in a file that is
	 * already up to date.
	 *
	 * @param MobileSheetsHashCodesFile|null $hashCodes parsed hash codes file to
	 *                                                  keep in step, or null when
	 *                                                  the library has none
	 *
	 * @return string one of MobileSheetsFileLogEntry::CODE_METADATA_UPDATED,
	 *                CODE_METADATA_CURRENT or CODE_NOT_PDF
	 * @throws SyncException carrying the code the report shows
	 */
	public function syncMetadataIntoFile(
		MobileSheetsSong $song,
		MobileSheetsFile $file,
		IUser $user,
		string $databasePath,
		?MobileSheetsHashCodesFile $hashCodes = null,
	): string {
		$this->metadataSyncer ??= new FileMetadataSyncer(
			rootFolder: $this->rootFolder,
			pathResolver: $this->pathResolver,
			logger: $this->logger,
			internalSongFileMapper: $this->internalSongFileMapper,
			songMapper: $this->songMapper,
			internalSongMapper: $this->internalSongMapper,
		);

		return $this->metadataSyncer->syncMetadataIntoFile($song, $file, $user, $databasePath, $hashCodes);
	}

	/**
	 * Create the folder chain a target path needs, one level at a time.
	 *
	 * @param string $targetRelative path relative to the user folder
	 * @return list<string> the relative paths that were created, shallowest first
	 */
	public function ensureParentFolder(Folder $userFolder, string $targetRelative): array {
		return $this->pathRegenerator->ensureParentFolder($userFolder, $targetRelative);
	}
}
