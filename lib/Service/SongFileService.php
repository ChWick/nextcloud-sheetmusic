<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryData;
use OCA\SheetMusic\Db\LibraryMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class SongFileService {
	public function __construct(
		private LibraryService $libraryService,
		private FileManager $fileManager,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
		private InternalSongFileMapper $internalSongFileMapper,
		private InternalSongMapper $internalSongMapper,
		private LibraryMapper $libraryMapper,
	) {
	}

	public function getLogger(): LoggerInterface {
		return $this->logger;
	}

	public function importSongMetadata(?array $fileInfo, ?string $path, IUser $user): array {
		$content = null;

		if ($fileInfo !== null && isset($fileInfo['tmp_name']) && (is_uploaded_file($fileInfo['tmp_name']) || file_exists($fileInfo['tmp_name']))) {
			$content = file_get_contents($fileInfo['tmp_name']);
		} elseif (!empty($path)) {
			try {
				$userFolder = $this->rootFolder->getUserFolder($user->getUID());
				$relativePath = ltrim((string)$path, '/');
				$davPrefix = ltrim(MobileSheetsPathResolver::WEBDAV_ROOT, '/') . '/' . $user->getUID() . '/';
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

	public function uploadSongFile(IUser $user, int $libraryId, int $songId, ?array $fileInfo): ?LibraryData {
		if ($fileInfo === null || !isset($fileInfo['tmp_name'])) {
			throw new \InvalidArgumentException('No file uploaded');
		}
		if (is_uploaded_file($fileInfo['tmp_name']) === false && file_exists($fileInfo['tmp_name']) === false) {
			throw new \InvalidArgumentException('No file uploaded');
		}

		$fileName = basename((string)($fileInfo['name'] ?? 'song.file'));

		$target = $this->resolveLibraryTarget($user, $libraryId);
		if ($target === null) {
			return null;
		}
		['userFolder' => $userFolder, 'targetFolder' => $targetFolder, 'libraryDir' => $libraryDir] = $target;

		$content = file_get_contents($fileInfo['tmp_name']);
		if ($content === false) {
			throw new \InvalidArgumentException('Could not read the uploaded file');
		}

		$autoPaths = $this->libraryService->isAutoPathsEnabled($user->getUID(), $libraryId);
		$targetRelative = $this->resolveDestinationPath($userFolder, $libraryDir, $fileName, $autoPaths);
		$this->ensureParentFolder($userFolder, $targetRelative);
		$targetFile = $userFolder->newFile($targetRelative);
		$targetFile->putContent($content);

		$this->recordSongFile($songId, $user, $targetFile, $libraryId);

		return $this->internalSongMapper->findLibraryData($libraryId, $user);
	}

	public function attachNextcloudFile(
		IUser $user,
		int $libraryId,
		int $songId,
		string $sourcePath,
		string $action = 'copy',
	): ?LibraryData {
		if ($sourcePath === '') {
			throw new \InvalidArgumentException('Source path cannot be empty');
		}
		$sourcePath = '/' . ltrim($sourcePath, '/');

		$target = $this->resolveLibraryTarget($user, $libraryId);
		if ($target === null) {
			return null;
		}
		['userFolder' => $userFolder, 'targetFolder' => $targetFolder, 'libraryDir' => $libraryDir] = $target;

		$sourceNode = $userFolder->get(ltrim($sourcePath, '/'));
		if ($sourceNode->getType() !== 'file') {
			return null;
		}

		$autoPaths = $this->libraryService->isAutoPathsEnabled($user->getUID(), $libraryId);
		$targetName = $sourceNode->getName();
		$sourceRelative = ltrim($sourcePath, '/');
		$targetRelative = ($libraryDir === '' ? '' : $libraryDir . '/') . $targetName;

		if ($sourceRelative !== $targetRelative) {
			$targetRelative = $this->resolveDestinationPath($userFolder, $libraryDir, $targetName, $autoPaths);
			$targetName = basename($targetRelative);
			$targetInternalPath = $targetFolder->getPath() . '/' . $targetName;

			if ($action === 'move') {
				$sourceNode->move($targetInternalPath);
			} else {
				$sourceNode->copy($targetInternalPath);
			}
		}

		$attachedFile = $targetFolder->get($targetName);
		$this->recordSongFile($songId, $user, $attachedFile, $libraryId);

		return $this->internalSongMapper->findLibraryData($libraryId, $user);
	}

	public function deleteSongFile(IUser $user, int $libraryId, int $songId, int $fileId): ?LibraryData {
		$fileRecord = $this->internalSongFileMapper->findById($fileId);
		if ($fileRecord !== null && ($songId <= 0 || $fileRecord->songId === $songId)) {
			$targetFileId = $fileRecord->fileId;
			$this->internalSongFileMapper->delete($fileId);
			$this->libraryMapper->touch($libraryId);

			$others = $this->internalSongFileMapper->findByFileId($targetFileId);
			$activeOthers = array_filter($others, fn (InternalSongFile $f) => $f->id !== $fileId && $f->deletedAt === null);

			if (empty($activeOthers)) {
				try {
					$userFolder = $this->rootFolder->getUserFolder($user->getUID());
					$node = $userFolder->getById($targetFileId);
					if (!empty($node) && $node[0] instanceof Node) {
						$node[0]->delete();
					}
				} catch (\Throwable $e) {
					$this->logger->warning('Could not delete physical file node', [
						'fileId' => $targetFileId,
						'exception' => $e,
					]);
				}
			}

			return $this->internalSongMapper->findLibraryData($libraryId, $user);
		}

		return null;
	}

	public function detachSongFile(IUser $user, int $libraryId, int $songId, int $fileId): ?LibraryData {
		try {
			$this->internalSongFileMapper->delete($fileId);
			$this->libraryMapper->touch($libraryId);

			return $this->internalSongMapper->findLibraryData($libraryId, $user);
		} catch (\Throwable $exception) {
			$this->logger->error('Could not detach a file from a song', [
				'exception' => $exception,
				'userId' => $user->getUID(),
				'libraryId' => $libraryId,
				'songId' => $songId,
				'fileId' => $fileId,
			]);
			throw $exception;
		}
	}

	public function syncSongFileMetadata(IUser $user, string $databasePath = '', int $songId = 0, ?int $libraryId = null): void {
		if ($libraryId !== null && !$this->libraryService->isAutoPdfMetadataEnabled($user->getUID(), $libraryId)) {
			return;
		}

		if ($libraryId === null) {
			return;
		}

		$song = null;
		$data = $this->internalSongMapper->findLibraryData($libraryId, $user);
		foreach ($data->songs as $s) {
			if ($s->id === $songId) {
				$song = $s;
				break;
			}
		}

		if ($song === null) {
			$this->logger->warning('Could not find the song to sync its metadata into attached files', [
				'libraryId' => $libraryId,
				'songId' => $songId,
			]);
			return;
		}

		foreach ($song->files as $file) {
			$this->logger->debug('Syncing song metadata into an attached file', [
				'songId' => $songId,
				'fileId' => $file->id,
				'path' => $file->path,
			]);

			try {
				$this->fileManager->syncMetadataIntoFile($song, $file, $user, $databasePath);
			} catch (\Throwable $exception) {
				$this->logger->warning('Could not write the song metadata into an attached file', [
					'exception' => $exception,
					'songId' => $songId,
					'fileId' => $file->id,
					'path' => $file->path,
				]);
			}
		}
	}


	private function libraryFolderPath(string $libraryPath): string {
		$directory = dirname(ltrim($libraryPath, '/'));

		return ($directory === '' || $directory === '.') ? '' : $directory;
	}

	/**
	 * @return array{userFolder: Folder, targetFolder: Folder, libraryDir: string}|null
	 */
	private function resolveLibraryTarget(IUser $user, int $libraryId): ?array {
		$libraryPath = $this->libraryService->resolveLibraryPath($user->getUID(), $libraryId);
		if ($libraryPath === null) {
			return null;
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$libraryDir = $this->libraryFolderPath($libraryPath);
		$targetFolder = $libraryDir === '' ? $userFolder : $userFolder->get($libraryDir);
		if (!($targetFolder instanceof Folder)) {
			return null;
		}

		return [
			'userFolder' => $userFolder,
			'targetFolder' => $targetFolder,
			'libraryDir' => $libraryDir,
		];
	}

	private function resolveDestinationPath(
		Folder $userFolder,
		string $libraryDir,
		string $fileName,
		bool $autoPaths,
	): string {
		$targetRelative = ($libraryDir === '' ? '' : $libraryDir . '/') . $fileName;
		if (!$autoPaths) {
			if ($userFolder->nodeExists($targetRelative)) {
				throw new FileAlreadyExistsException(sprintf('File "%s" already exists.', $fileName));
			}
			return $targetRelative;
		}

		return $this->fileManager->uniqueRelativePath($userFolder, $targetRelative);
	}

	private function ensureParentFolder(Folder $userFolder, string $targetRelative): void {
		$parent = str_replace(DIRECTORY_SEPARATOR, '/', dirname($targetRelative));
		if ($parent === '' || $parent === '.' || $parent === '/') {
			return;
		}

		$current = '';
		foreach (explode('/', $parent) as $segment) {
			if ($segment === '') {
				continue;
			}
			$current = $current === '' ? $segment : "$current/$segment";
			if (!$userFolder->nodeExists($current)) {
				$userFolder->newFolder($current);
			}
		}
	}

	private function recordSongFile(
		int $songId,
		IUser $user,
		Node $file,
		int $libraryId,
	): void {
		$existingFiles = $this->internalSongFileMapper->findAllForSong($songId);
		foreach ($existingFiles as $existing) {
			if ($existing->fileId === (int)$file->getId()) {
				$this->logger->debug('Song already lists this file, nothing to add', [
					'songId' => $songId,
					'fileId' => $file->getId(),
				]);
				$this->syncSongFileMetadata($user, '', $songId, $libraryId);
				return;
			}
		}

		$internalSongFile = new InternalSongFile(
			id: null,
			uuid: InternalEntity::generateUuid(),
			songId: $songId,
			fileId: (int)$file->getId(),
			type: InternalSongFile::determineFileType($file->getName()),
		);
		$this->internalSongFileMapper->insert($internalSongFile);
		$this->libraryMapper->touch($libraryId);

		$this->syncSongFileMetadata($user, '', $songId, $libraryId);
	}
}
