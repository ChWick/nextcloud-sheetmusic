<?php

declare(strict_types=1);

namespace OCA\MobileSheetsManager\Service;

use OCA\MobileSheetsManager\Db\SongMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class SongFileService {
	public function __construct(
		private LibraryService $libraryService,
		private SongMapper $songMapper,
		private FileManager $fileManager,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	public function importSongMetadata(?array $fileInfo, ?string $path, IUser $user): array {
		$content = null;

		if ($fileInfo !== null && isset($fileInfo['tmp_name']) && (is_uploaded_file($fileInfo['tmp_name']) || file_exists($fileInfo['tmp_name']))) {
			$content = file_get_contents($fileInfo['tmp_name']);
		} elseif (!empty($path)) {
			try {
				$userFolder = $this->rootFolder->getUserFolder($user->getUID());
				$relativePath = ltrim((string)$path, '/');
				$davPrefix = 'remote.php/dav/files/' . $user->getUID() . '/';
				if (str_starts_with($relativePath, $davPrefix)) {
					$relativePath = substr($relativePath, strlen($davPrefix));
				}
				$node = $userFolder->get(rawurldecode($relativePath));
				if ($node instanceof \OCP\Files\File) {
					$content = $node->getContent();
				}
			} catch (\Throwable $e) {
				$this->logger->info('Could not resolve Nextcloud file for metadata import', [
					'path' => $path,
					'exception' => $e,
				]);
			}
		}

		if ($content === false || $content === null || $content === '') {
			throw new \InvalidArgumentException('Could not read file content');
		}

		try {
			return MobileSheetsPdfMetadata::prefillFor($content);
		} catch (\Throwable $exception) {
			$this->logger->info('Could not read the metadata of an imported PDF', [
				'exception' => $exception,
			]);

			throw new \RuntimeException('Could not read the PDF metadata', 0, $exception);
		}
	}

	public function uploadSongFile(IUser $user, int $libraryId, int $songId, ?array $fileInfo): ?array {
		if ($fileInfo === null || !isset($fileInfo['tmp_name'])) {
			throw new \InvalidArgumentException('No file uploaded');
		}
		if (is_uploaded_file($fileInfo['tmp_name']) === false && file_exists($fileInfo['tmp_name']) === false) {
			throw new \InvalidArgumentException('No file uploaded');
		}

		$fileName = basename((string)($fileInfo['name'] ?? 'song.file'));

		$paths = $this->libraryService->getLibraryAndDatabasePath($user->getUID(), $libraryId);
		if ($paths === null) {
			return null;
		}
		[$libraryPath, $databasePath] = $paths;

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$libraryDir = $this->libraryFolderPath($libraryPath);
		$targetFolder = $libraryDir === '' ? $userFolder : $userFolder->get($libraryDir);
		if (!($targetFolder instanceof Folder)) {
			return null;
		}

		$content = file_get_contents($fileInfo['tmp_name']);
		if ($content === false) {
			throw new \InvalidArgumentException('Could not read the uploaded file');
		}

		$targetRelative = ($libraryDir === '' ? '' : $libraryDir . '/') . $fileName;
		$targetRelative = $this->fileManager->uniqueRelativePath($userFolder, $targetRelative);
		$targetFile = $userFolder->newFile($targetRelative);
		$targetFile->putContent($content);

		$this->recordSongFile($songId, $databasePath, $targetFile, $targetFolder, $user);

		return $this->songMapper->findLibraryData($databasePath);
	}

	public function attachNextcloudFile(
		IUser $user,
		int $libraryId,
		int $songId,
		string $sourcePath,
		string $action = 'copy',
	): ?array {
		if ($sourcePath === '') {
			throw new \InvalidArgumentException('Source path cannot be empty');
		}
		$sourcePath = '/' . ltrim($sourcePath, '/');

		$paths = $this->libraryService->getLibraryAndDatabasePath($user->getUID(), $libraryId);
		if ($paths === null) {
			return null;
		}
		[$libraryPath, $databasePath] = $paths;

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$sourceNode = $userFolder->get(ltrim($sourcePath, '/'));
		if ($sourceNode->getType() !== 'file') {
			return null;
		}

		$libraryDir = $this->libraryFolderPath($libraryPath);
		$targetFolder = $libraryDir === '' ? $userFolder : $userFolder->get($libraryDir);
		if (!($targetFolder instanceof Folder)) {
			return null;
		}

		$targetName = $sourceNode->getName();
		$targetRelative = ($libraryDir === '' ? '' : $libraryDir . '/') . $targetName;
		$sourceRelative = ltrim($sourcePath, '/');

		if ($sourceRelative !== $targetRelative) {
			$targetRelative = $this->fileManager->uniqueRelativePath($userFolder, $targetRelative);
			$targetName = basename($targetRelative);

			$targetInternalPath = $targetFolder->getPath() . '/' . $targetName;
			if ($userFolder->nodeExists($targetRelative)) {
				throw new \RuntimeException(sprintf('Cannot attach file: target path "%s" already exists.', $targetRelative));
			}
			if ($action === 'move') {
				$sourceNode->move($targetInternalPath);
			} else {
				$sourceNode->copy($targetInternalPath);
			}
		}

		$attachedFile = $targetFolder->get($targetName);
		$this->recordSongFile($songId, $databasePath, $attachedFile, $targetFolder, $user);

		return $this->songMapper->findLibraryData($databasePath);
	}

	public function deleteSongFile(IUser $user, int $libraryId, int $songId, int $fileId): ?array {
		$paths = $this->libraryService->getLibraryAndDatabasePath($user->getUID(), $libraryId);
		if ($paths === null) {
			return null;
		}
		[$libraryPath, $databasePath] = $paths;

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$libraryDir = $this->libraryFolderPath($libraryPath);
		$targetFolder = $libraryDir === '' ? $userFolder : $userFolder->get($libraryDir);
		if (!($targetFolder instanceof Folder)) {
			return null;
		}

		$filePath = $this->songMapper->removeSongFile($databasePath, $songId, $fileId);

		if ($filePath !== null) {
			$deletedFile = $this->fileManager->pathOfStoredFile($filePath, $user);

			if ($deletedFile !== null && !$this->songMapper->isPathReferencedByOtherSong($databasePath, $filePath, $songId)) {
				$this->deleteFileNode($deletedFile->internal);
			}
		}

		$this->relocateSongFiles($songId, $databasePath, $targetFolder, $user, $filePath);

		return $this->songMapper->findLibraryData($databasePath);
	}

	public function syncSongFileMetadata(IUser $user, string $databasePath, int $songId): void {
		$song = $this->songMapper->findSong($databasePath, $songId);
		if ($song === null) {
			$this->logger->warning('Could not find the song to sync its metadata into attached files', [
				'databasePath' => $databasePath,
				'songId' => $songId,
			]);
			return;
		}

		$hashCodes = $this->hashCodesFor($databasePath);
		foreach ($song->files as $file) {
			$this->logger->debug('Syncing song metadata into an attached file', [
				'songId' => $songId,
				'fileId' => $file->id,
				'path' => $file->path,
			]);

			try {
				$this->fileManager->syncMetadataIntoFile($song, $file, $user, $databasePath, $hashCodes);
			} catch (\Throwable $exception) {
				$this->logger->warning('Could not write the song metadata into an attached file', [
					'exception' => $exception,
					'songId' => $songId,
					'fileId' => $file->id,
					'path' => $file->path,
				]);
			}
		}

		try {
			$hashCodes?->save();
		} catch (\Throwable $exception) {
			$this->logger->warning('Could not refresh the MobileSheets hash codes file', [
				'exception' => $exception,
				'databasePath' => $databasePath,
				'songId' => $songId,
			]);
		}
	}

	public function deleteFileNode(string $internalPath): void {
		try {
			$node = $this->rootFolder->get($internalPath);
		} catch (NotFoundException) {
			return;
		}

		if ($node->getType() !== 'file') {
			$this->logger->warning('Refusing to delete a stored MobileSheets path that is not a file', [
				'internalPath' => $internalPath,
			]);

			return;
		}

		$node->delete();
	}

	public function hashCodesFor(string $databasePath): ?MobileSheetsHashCodesFile {
		try {
			return MobileSheetsHashCodesFile::forDatabase($databasePath, $this->logger);
		} catch (\Throwable $exception) {
			$this->logger->warning('Could not read the MobileSheets hash codes file', [
				'exception' => $exception,
				'databasePath' => $databasePath,
			]);

			return null;
		}
	}

	private function libraryFolderPath(string $libraryPath): string {
		$directory = dirname(ltrim($libraryPath, '/'));

		return ($directory === '' || $directory === '.') ? '' : $directory;
	}

	private function recordSongFile(int $songId, string $databasePath, Node $file, Folder $targetFolder, IUser $user): void {
		$storedPath = $this->fileManager->pathOfExistingFile($file->getPath(), $user);

		foreach ($this->songMapper->findSong($databasePath, $songId)?->files ?? [] as $existing) {
			if ($existing->path === $storedPath->webdav) {
				$this->logger->debug('Song already lists this file, nothing to add', [
					'songId' => $songId,
					'path' => $storedPath->webdav,
				]);
				return;
			}
		}

		$this->songMapper->setSongFile($databasePath, $songId, $storedPath->webdav, MobileSheetsFileFacts::fromNode($file));

		$this->relocateSongFiles($songId, $databasePath, $targetFolder, $user);
	}

	private function relocateSongFiles(
		int $songId,
		string $databasePath,
		Folder $targetFolder,
		IUser $user,
		?string $removedPath = null,
	): void {
		$hashCodes = $this->hashCodesFor($databasePath);

		try {
			if ($removedPath !== null) {
				$hashCodes?->removePath($removedPath);
			}

			$this->fileManager->regenerateSongFilePaths($songId, $user, $targetFolder, $databasePath, $hashCodes);
		} catch (\Throwable $exception) {
			$this->logger->warning('Could not move the song files to their regenerated paths', [
				'exception' => $exception,
				'databasePath' => $databasePath,
				'songId' => $songId,
			]);
		}

		try {
			$hashCodes?->save();
		} catch (\Throwable $exception) {
			$this->logger->warning('Could not save the MobileSheets hash codes file', [
				'exception' => $exception,
				'databasePath' => $databasePath,
			]);
		}
	}
}

