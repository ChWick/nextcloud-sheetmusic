<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\MobileSheetsFile;
use OCA\SheetMusic\Db\MobileSheetsSong;
use OCA\SheetMusic\Db\SongMapper;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class FileMetadataSyncer {
	public function __construct(
		private IRootFolder $rootFolder,
		private MobileSheetsPathResolver $pathResolver,
		private LoggerInterface $logger,
		private ?InternalSongFileMapper $internalSongFileMapper = null,
		private ?SongMapper $songMapper = null,
	) {
	}

	/**
	 * Write the metadata of a song into its PDF document properties.
	 *
	 * @return string the report code: CODE_METADATA_UPDATED,
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
		if (!str_ends_with(strtolower($file->path), '.pdf')) {
			return MobileSheetsFileLogEntry::CODE_NOT_PDF;
		}

		$path = $this->pathResolver->fromStored($file->path, $user);
		if ($path === null) {
			throw new SyncException(MobileSheetsFileLogEntry::CODE_SOURCE_OUTSIDE_LIBRARY, sprintf(
				'Cannot sync the metadata of file %d: stored path "%s" is not below "%s"',
				$file->id,
				$file->path,
				$this->pathResolver->webdavRoot($user),
			));
		}

		$relative = $this->pathResolver->relativeToUserFolder($path->internal, $user) ?? '';
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());

		try {
			$node = $userFolder->get($relative);
		} catch (\Throwable) {
			throw new SyncException(
				MobileSheetsFileLogEntry::CODE_METADATA_FILE_MISSING,
				sprintf('Cannot sync the metadata of file %d: "%s" does not exist', $file->id, $file->path),
			);
		}

		if (!($node instanceof File)) {
			throw new SyncException(
				MobileSheetsFileLogEntry::CODE_METADATA_NOT_A_FILE,
				sprintf('Cannot sync the metadata of file %d: "%s" is not a file', $file->id, $file->path),
			);
		}

		try {
			$content = $node->getContent();
		} catch (\Throwable $exception) {
			throw new SyncException(
				MobileSheetsFileLogEntry::CODE_METADATA_UNREADABLE,
				sprintf('Cannot sync the metadata of file %d: "%s" could not be read', $file->id, $file->path),
				$exception,
			);
		}
		if (!is_string($content) || $content === '') {
			throw new SyncException(
				MobileSheetsFileLogEntry::CODE_METADATA_UNREADABLE,
				sprintf('Cannot sync the metadata of file %d: "%s" is empty', $file->id, $file->path),
			);
		}

		try {
			$upToDate = MobileSheetsPdfMetadata::matchesSong($content, $song);
		} catch (\Throwable) {
			// Properties that cannot be read are worth rewriting: writing may
			// repair what reading could not make sense of.
			$upToDate = false;
		}
		if ($upToDate) {
			return MobileSheetsFileLogEntry::CODE_METADATA_CURRENT;
		}

		try {
			$node->putContent(
				MobileSheetsPdfMetadata::withInfo($content, MobileSheetsPdfMetadata::fieldsFor($song)),
			);
		} catch (\Throwable $exception) {
			throw new SyncException(
				MobileSheetsFileLogEntry::CODE_METADATA_WRITE_FAILED,
				sprintf('Cannot sync the metadata of file %d: "%s" could not be written', $file->id, $file->path),
				$exception,
			);
		}

		try {
			// The bytes changed, so the hash, size and timestamp stored for
			// the file have to follow them - everywhere they are kept.
			$facts = MobileSheetsFileFacts::fromNode($node);
			if ($this->internalSongFileMapper !== null) {
				$internalSongFile = $this->internalSongFileMapper->findById($file->id);
				if ($internalSongFile !== null) {
					$internalSongFile->fileSize = $facts->fileSize;
					$internalSongFile->fileHash = $facts->fileHash;
					$internalSongFile->updatedAt = time();
					$this->internalSongFileMapper->update($internalSongFile);
				}
			}
			if ($this->songMapper !== null && $databasePath !== '' && file_exists($databasePath) && is_writable($databasePath)) {
				$this->songMapper->updateFile($databasePath, $file->id, $file->path, $facts);
			}
			$hashCodes?->updateFacts(
				$file->path,
				(string)$facts->fileHash,
				(string)$facts->lastModified,
				(string)$facts->fileSize,
			);
		} catch (\Throwable $exception) {
			throw new SyncException(
				MobileSheetsFileLogEntry::CODE_METADATA_FACTS_FAILED,
				sprintf(
					'The metadata of file %d was written, but the database was not refreshed',
					$file->id,
				),
				$exception,
			);
		}

		return MobileSheetsFileLogEntry::CODE_METADATA_UPDATED;
	}
}
