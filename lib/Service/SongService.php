<?php

declare(strict_types=1);

namespace OCA\MobileSheetsManager\Service;

use OCA\MobileSheetsManager\Db\MobileSheetsFile;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class SongService {
	public function __construct(
		private LibraryService $libraryService,
		private SongMapper $songMapper,
		private SongFileService $songFileService,
		private FileManager $fileManager,
		private LoggerInterface $logger,
	) {
	}

	public function getSongs(IUser $user, int $libraryId): ?array {
		$databasePath = $this->libraryService->getDatabasePath($user->getUID(), $libraryId);
		if ($databasePath === null) {
			return null;
		}

		try {
			return $this->songMapper->findLibraryData($databasePath);
		} catch (\Throwable $e) {
			$this->logger->error('Could not load songs from MobileSheets library', [
				'exception' => $e,
				'libraryId' => $libraryId,
				'userId' => $user->getUID(),
			]);

			throw $e;
		}
	}

	public function createSong(
		IUser $user,
		int $libraryId,
		string $title,
		array $composerIds = [],
		array $bookIds = [],
		array $collectionIds = [],
		array $artistIds = [],
		array $genreIds = [],
		array $keyIds = [],
		array $customGroupIds = [],
		array $yearIds = [],
		array $signatureIds = [],
		array $sourceTypeIds = [],
		int $stars = 0,
		int $difficulty = 0,
		int $duration = 0,
		string $keywords = '',
		string $custom = '',
		string $custom2 = '',
		string $sortTitle = '',
		?array $notes = null,
	): ?array {
		$title = trim($title);
		if ($title === '') {
			return null;
		}

		$databasePath = $this->libraryService->getDatabasePath($user->getUID(), $libraryId);
		if ($databasePath === null) {
			return null;
		}

		try {
			$songId = $this->songMapper->createSong(
				$databasePath,
				$title,
				$composerIds,
				$bookIds,
				$collectionIds,
				$artistIds,
				$genreIds,
				$keyIds,
				$customGroupIds,
				$yearIds,
				$signatureIds,
				$sourceTypeIds,
				$stars,
				$difficulty,
				$duration,
				$keywords,
				$custom,
				$custom2,
				$sortTitle,
				$notes,
			);

			return ['createdSongId' => $songId] + $this->songMapper->findLibraryData($databasePath);
		} catch (\Throwable $e) {
			$this->logger->error('Could not create song in MobileSheets library', [
				'exception' => $e,
				'libraryId' => $libraryId,
				'title' => $title,
			]);

			throw $e;
		}
	}

	public function updateSong(
		IUser $user,
		int $songId,
		int $libraryId,
		string $title,
		array $composerIds = [],
		array $bookIds = [],
		array $collectionIds = [],
		array $artistIds = [],
		array $genreIds = [],
		array $keyIds = [],
		array $customGroupIds = [],
		array $yearIds = [],
		array $signatureIds = [],
		array $sourceTypeIds = [],
		int $stars = 0,
		int $difficulty = 0,
		int $duration = 0,
		string $keywords = '',
		string $custom = '',
		string $custom2 = '',
		string $sortTitle = '',
		?array $notes = null,
	): ?array {
		if ($title === '') {
			return null;
		}

		$databasePath = $this->libraryService->getDatabasePath($user->getUID(), $libraryId);
		if ($databasePath === null) {
			return null;
		}

		try {
			$this->songMapper->updateSong(
				$databasePath,
				$songId,
				$title,
				$composerIds,
				$bookIds,
				$collectionIds,
				$artistIds,
				$genreIds,
				$keyIds,
				$customGroupIds,
				$yearIds,
				$signatureIds,
				$sourceTypeIds,
				$stars,
				$difficulty,
				$duration,
				$keywords,
				$custom,
				$custom2,
				$sortTitle,
				$notes,
			);
			$this->songFileService->syncSongFileMetadata($user, $databasePath, $songId);

			return $this->songMapper->findLibraryData($databasePath);
		} catch (\Throwable $e) {
			$this->logger->error('Could not update song in MobileSheets library', [
				'exception' => $e,
				'libraryId' => $libraryId,
				'songId' => $songId,
			]);

			throw $e;
		}
	}

	public function batchUpdateSongs(
		IUser $user,
		int $libraryId,
		array $songIds,
		?array $composers = null,
		?array $books = null,
		?array $collections = null,
		?array $artists = null,
		?array $genres = null,
		?array $keys = null,
		?array $customGroups = null,
		?array $years = null,
		?array $signatures = null,
		?array $sourceTypes = null,
	): ?array {
		if (empty($songIds)) {
			return null;
		}

		$databasePath = $this->libraryService->getDatabasePath($user->getUID(), $libraryId);
		if ($databasePath === null) {
			return null;
		}

		try {
			$this->songMapper->batchUpdateSongs(
				$databasePath,
				$songIds,
				$composers,
				$books,
				$collections,
				$artists,
				$genres,
				$keys,
				$customGroups,
				$years,
				$signatures,
				$sourceTypes,
			);

			foreach ($songIds as $songId) {
				$this->songFileService->syncSongFileMetadata($user, $databasePath, (int)$songId);
			}

			return $this->songMapper->findLibraryData($databasePath);
		} catch (\Throwable $e) {
			$this->logger->error('Could not batch update songs in MobileSheets library', [
				'exception' => $e,
				'libraryId' => $libraryId,
				'songIds' => $songIds,
			]);

			throw $e;
		}
	}

	public function deleteSong(IUser $user, int $songId, int $libraryId, bool $deleteFiles = true): ?array {
		$databasePath = $this->libraryService->getDatabasePath($user->getUID(), $libraryId);
		if ($databasePath === null) {
			return null;
		}

		try {
			$song = $this->songMapper->findSong($databasePath, $songId);
			if ($song === null) {
				return null;
			}

			$filePaths = array_map(
				static fn (MobileSheetsFile $file): string => $file->path,
				$song->files,
			);
			if ($deleteFiles) {
				$hashCodes = $this->songFileService->hashCodesFor($databasePath);
				foreach ($filePaths as $filePath) {
					$storedFile = $this->fileManager->pathOfStoredFile($filePath, $user);
					if ($storedFile !== null) {
						$this->songFileService->deleteFileNode($storedFile->internal);
					}
					$hashCodes?->removePath($filePath);
				}
				$hashCodes?->save();
			}

			$this->songMapper->deleteSong($databasePath, $songId);

			$this->logger->info('Deleted MobileSheets song', [
				'libraryId' => $libraryId,
				'songId' => $songId,
				'title' => $song->title,
				'files' => count($filePaths),
				'filesDeleted' => $deleteFiles,
			]);

			return $this->songMapper->findLibraryData($databasePath);
		} catch (\Throwable $e) {
			$this->logger->error('Could not delete song from MobileSheets library', [
				'exception' => $e,
				'libraryId' => $libraryId,
				'songId' => $songId,
			]);

			throw $e;
		}
	}
}

