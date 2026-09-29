<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Service;

use OCA\MobileSheetsManager\Db\MobileSheetsFile;
use OCA\MobileSheetsManager\Db\MobileSheetsSong;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * The library maintenance run behind the "sync" tool of the files view.
 *
 * The run has two best-effort phases, and both report every file they touch:
 *
 *   1. paths: every file the library database references is moved to the path
 *      its song's naming rules produce ({@see FileManager::regenerateFilePaths()}),
 *   2. metadata: every attached PDF carries its song's document properties, and
 *      the file facts and hash codes that the rewritten bytes invalidate follow
 *      ({@see FileManager::syncMetadataIntoFile()}).
 *
 * Phase two runs on the paths phase's result, so its entries describe files at
 * their final location. A file that fails in one phase does not stop the run or
 * the other phase - one bad file must never lose the work done for the rest of
 * the library. The merged log is what the report dialog shows; entries carry a
 * stable code and, where resolvable, the Nextcloud node id of their file, so a
 * warning can be acted on with one click.
 */
class LibrarySyncService {
	public function __construct(
		private FileManager $fileManager,
		private SongMapper $songMapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Sync every file path and every file's metadata of the library.
	 *
	 * @return list<MobileSheetsFileLogEntry> one line per file the run touched,
	 *                                        plus library-scope lines for problems
	 *                                        that belong to no single file
	 * @throws \Exception when the library cannot be resolved at all
	 */
	public function syncLibrary(int $libraryId, IUser $user): array {
		// Phase 1: file paths. Parses and saves the hash codes file itself, so
		// phase 2 below reads a fresh one that reflects the moves.
		$log = $this->fileManager->regenerateFilePaths($libraryId, $user);

		// Phase 2: document metadata, on the paths the first phase produced.
		['databasePath' => $databasePath] = $this->fileManager->libraryContext($libraryId, $user);

		$hashCodes = null;
		try {
			$hashCodes = MobileSheetsHashCodesFile::forDatabase($databasePath, $this->logger);
		} catch (\Throwable $exception) {
			// Continue without one: the metadata can still be written, only the
			// hash codes file will not learn about the rewritten bytes.
			$log[] = $this->libraryEntry(
				sprintf('Could not read the MobileSheets hash codes file: %s', $exception->getMessage()),
				MobileSheetsFileLogEntry::CODE_HASHCODES_UNREADABLE,
			);
		}

		foreach ($this->songMapper->findSongs($databasePath) as $song) {
			foreach ($song->files as $file) {
				$log[] = $this->metadataEntry($song, $file, $user, $databasePath, $hashCodes);
			}
		}

		// Saving the hash codes file is best-effort like everything above: a
		// failure must not lose the log of what the run did achieve.
		try {
			$hashCodes?->save();
		} catch (\Throwable $exception) {
			$log[] = $this->libraryEntry(
				sprintf('Could not save the MobileSheets hash codes file: %s', $exception->getMessage()),
				MobileSheetsFileLogEntry::CODE_HASHCODES_SAVE_FAILED,
			);
		}

		return $log;
	}

	/**
	 * The report line for one file of the metadata phase.
	 *
	 * A failure of that file - or of the library's hash codes file - is part of
	 * the report rather than an exception, so the run continues with the next
	 * file and the user sees everything that needs attention in one place.
	 */
	private function metadataEntry(
		MobileSheetsSong $song,
		MobileSheetsFile $file,
		IUser $user,
		string $databasePath,
		?MobileSheetsHashCodesFile $hashCodes,
	): MobileSheetsFileLogEntry {
		try {
			$code = $this->fileManager->syncMetadataIntoFile($song, $file, $user, $databasePath, $hashCodes);

			return new MobileSheetsFileLogEntry(
				$song->id,
				$song->title,
				$file->path,
				$this->messageForCode($code),
				MobileSheetsFileLogEntry::levelForCode($code),
				$code,
				$this->fileManager->nodeIdOfStoredFile($file->path, $user),
			);
		} catch (SyncException $exception) {
			$code = $exception->errorCode();
			$level = $exception->errorLevel();
			$message = $exception->getMessage();
		} catch (\Throwable $exception) {
			// Anything the known problems do not cover is still reported under a
			// stable code, so the report has wording and a link for it as well.
			$code = MobileSheetsFileLogEntry::CODE_METADATA_FAILED;
			$level = MobileSheetsFileLogEntry::LEVEL_ERROR;
			$message = $exception->getMessage();
		}

		return new MobileSheetsFileLogEntry(
			$song->id,
			$song->title,
			$file->path,
			$message,
			$level,
			$code,
			$this->fileManager->nodeIdOfStoredFile($file->path, $user),
		);
	}

	/** The report line for a problem that belongs to no single file of a song. */
	private function libraryEntry(string $message, string $code): MobileSheetsFileLogEntry {
		return new MobileSheetsFileLogEntry(
			0,
			'',
			'',
			$message,
			MobileSheetsFileLogEntry::levelForCode($code),
			$code,
			null,
		);
	}

	/** The English wording of a success code; failures bring their own message. */
	private function messageForCode(string $code): string {
		return match ($code) {
			MobileSheetsFileLogEntry::CODE_METADATA_UPDATED => 'Document properties updated',
			MobileSheetsFileLogEntry::CODE_METADATA_CURRENT => 'Document properties already up to date',
			MobileSheetsFileLogEntry::CODE_NOT_PDF => 'Not a PDF, nothing to update',
			default => 'Metadata checked',
		};
	}
}
