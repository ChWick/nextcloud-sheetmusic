<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongHistory;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryData;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPathResolver;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdfMetadata;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
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
		private ?SongHistoryService $songHistoryService = null,
	) {
	}

	public function getLogger(): LoggerInterface {
		return $this->logger;
	}

	private function resolveSongFileRecord(int $fileId, int $songId = 0): ?InternalSongFile {
		$fileRecord = $this->internalSongFileMapper->findById($fileId);
		if ($fileRecord !== null) {
			if ($songId <= 0 || $fileRecord->songId === $songId) {
				return $fileRecord;
			}
		}

		$byFileId = $this->internalSongFileMapper->findByFileId($fileId);
		foreach ($byFileId as $rec) {
			if ($songId <= 0 || $rec->songId === $songId) {
				return $rec;
			}
		}

		return null;
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
		$content = $this->readUploadedFileContent($fileInfo);
		$fileName = basename((string)($fileInfo['name'] ?? 'song.file'));

		$target = $this->resolveLibraryTarget($user, $libraryId);
		if ($target === null) {
			return null;
		}
		['userFolder' => $userFolder, 'targetFolder' => $targetFolder, 'libraryDir' => $libraryDir] = $target;

		$autoPaths = $this->libraryService->isAutoPathsEnabled($user->getUID(), $libraryId);
		$targetRelative = $this->resolveDestinationPath($userFolder, $libraryDir, $fileName, $autoPaths);
		$this->ensureParentFolder($userFolder, $targetRelative);
		// Create the file with its content in one step: an empty newFile()
		// followed by putContent() lets file listeners index a 0-byte PDF and
		// record a stale page count / page order of '1-1'.
		$targetFile = $userFolder->newFile($targetRelative, $content);

		$this->cleanupAutoIndexedStubForNewUpload($songId, (int)$targetFile->getId());
		$this->recordSongFile($songId, $user, $targetFile, $libraryId);

		return $this->internalSongMapper->findLibraryData($libraryId, $user);
	}

	public function updateSongFile(IUser $user, int $libraryId, int $songId, int $fileId, ?array $fileInfo): ?LibraryData {
		$content = $this->readUploadedFileContent($fileInfo);

		$fileRecord = $this->resolveSongFileRecord($fileId, $songId);
		if ($fileRecord === null) {
			throw new \InvalidArgumentException('Song file record not found');
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$nodes = $userFolder->getById($fileRecord->fileId);
		if (empty($nodes) || !($nodes[0] instanceof Node)) {
			throw new \RuntimeException('Nextcloud file node not found');
		}

		$targetNode = $nodes[0];
		if (!($targetNode instanceof File)) {
			throw new \RuntimeException('Target node is not a file');
		}

		$this->captureFileVersion($user, $userFolder, $targetNode, $fileId);

		// Overwrite content in place on existing File node:
		// Preserves fileId, path, and triggers Nextcloud files_versions app to capture history
		$targetNode->putContent($content);

		$fileRecord->fileSize = strlen($content);
		$ext = strtolower(pathinfo($targetNode->getName(), PATHINFO_EXTENSION));
		if ($ext === 'pdf') {
			$facts = $this->extractPdfFacts($content, $targetNode->getName());
			$fileRecord->fileHash = $facts['fileHash'];
			$fileRecord->pageCount = $facts['pageCount'];
			$fileRecord->pageOrder = $facts['pageOrder'];
		}
		$fileRecord->updatedAt = time();
		$this->internalSongFileMapper->update($fileRecord);

		$this->libraryMapper->touch($libraryId);
		$this->syncSongFileMetadata($user, '', $songId, $libraryId);

		if ($songId > 0) {
			$song = $this->internalSongMapper->findById($songId);
			if ($song !== null) {
				$song->updatedAt = time();
				$this->internalSongMapper->update($song);
				if ($this->songHistoryService !== null) {
					$this->songHistoryService->recordSnapshot(
						$song,
						InternalSongHistory::ACTION_UPDATED,
						$user->getUID(),
						'Modified attached PDF: ' . $targetNode->getName()
					);
				}
			}
		}

		return $this->internalSongMapper->findLibraryData($libraryId, $user);
	}

	/**
	 * @return list<array{version: string, revision: int, timestamp: int, humanReadableTimestamp: string, size: int, name: string}>
	 */
	public function getSongFileVersions(IUser $user, int $fileId): array {
		$fileRecord = $this->resolveSongFileRecord($fileId);
		if ($fileRecord === null) {
			return [];
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$nodes = $userFolder->getById($fileRecord->fileId);
		if (empty($nodes) || !($nodes[0] instanceof Node)) {
			return [];
		}

		$targetNode = $nodes[0];
		if (!($targetNode instanceof \OCP\Files\File)) {
			return [];
		}

		$relativePath = $userFolder->getRelativePath($targetNode->getPath());
		if ($relativePath === null || $relativePath === false) {
			return [];
		}

		if ((interface_exists(\OCA\Files_Versions\Versions\IVersionManager::class) || class_exists(\OCA\Files_Versions\Versions\IVersionManager::class))
			&& \OC::$server->has(\OCA\Files_Versions\Versions\IVersionManager::class)) {
			try {
				/** @var \OCA\Files_Versions\Versions\IVersionManager $versionManager */
				$versionManager = \OC::$server->get(\OCA\Files_Versions\Versions\IVersionManager::class);
				$versions = $versionManager->getVersionsForFile($user, $targetNode);
				$result = [];
				foreach ($versions as $v) {
					try {
						$stream = $versionManager->read($v);
						if (is_resource($stream)) {
							fclose($stream);
						}
					} catch (\Throwable) {
						// Skip synthesized current file or unreadable version records
						continue;
					}
					$revision = (int)$v->getRevisionId();
					$result[] = [
						'version' => (string)$revision,
						'revision' => $revision,
						'timestamp' => $v->getTimestamp(),
						'humanReadableTimestamp' => date('Y-m-d H:i:s', $v->getTimestamp()),
						'size' => (int)$v->getSize(),
						'name' => (string)$v->getSourceFileName(),
					];
				}
				if (!empty($result)) {
					usort($result, static fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']);
					return $result;
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Could not get versions via IVersionManager: ' . $e->getMessage(), [
					'exception' => $e,
					'fileId' => $fileId,
				]);
			}
		}

		if (class_exists(\OCA\Files_Versions\Storage::class)) {
			$rawVersions = \OCA\Files_Versions\Storage::getVersions($user->getUID(), '/' . ltrim($relativePath, '/'));
			$result = [];
			foreach ($rawVersions as $key => $v) {
				$revision = (int)($v['version'] ?? $key);
				if ($revision === (int)$targetNode->getMtime()) {
					continue;
				}
				$result[] = [
					'version' => (string)$revision,
					'revision' => $revision,
					'timestamp' => $revision,
					'humanReadableTimestamp' => (string)($v['humanReadableTimestamp'] ?? ''),
					'size' => (int)($v['size'] ?? 0),
					'name' => (string)($v['name'] ?? $targetNode->getName()),
				];
			}
			usort($result, static fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']);
			return $result;
		}

		return [];
	}

	public function revertSongFileVersion(IUser $user, int $libraryId, int $songId, int $fileId, string|int $revision): ?LibraryData {
		$fileRecord = $this->resolveSongFileRecord($fileId, $songId);
		if ($fileRecord === null) {
			throw new \InvalidArgumentException('Song file record not found');
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$nodes = $userFolder->getById($fileRecord->fileId);
		if (empty($nodes) || !($nodes[0] instanceof Node)) {
			throw new \RuntimeException('Nextcloud file node not found');
		}

		$targetNode = $nodes[0];
		$relativePath = $userFolder->getRelativePath($targetNode->getPath());
		if ($relativePath === null || $relativePath === false) {
			throw new \RuntimeException('Cannot determine relative path for file');
		}

		$rolledBack = false;
		if ((interface_exists(\OCA\Files_Versions\Versions\IVersionManager::class) || class_exists(\OCA\Files_Versions\Versions\IVersionManager::class))
			&& \OC::$server->has(\OCA\Files_Versions\Versions\IVersionManager::class)) {
			try {
				/** @var \OCA\Files_Versions\Versions\IVersionManager $versionManager */
				$versionManager = \OC::$server->get(\OCA\Files_Versions\Versions\IVersionManager::class);
				$versions = $versionManager->getVersionsForFile($user, $targetNode);
				foreach ($versions as $v) {
					if ((int)$v->getRevisionId() === (int)$revision) {
						$res = $versionManager->rollback($v);
						if ($res !== false) {
							$rolledBack = true;
						}
						break;
					}
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Could not rollback version via IVersionManager: ' . $e->getMessage(), [
					'exception' => $e,
					'fileId' => $fileId,
					'revision' => $revision,
				]);
			}
		}

		if (!$rolledBack && class_exists(\OCA\Files_Versions\Storage::class)) {
			$userRoot = $userFolder->getParent();
			if (!$userRoot->nodeExists('files_versions')) {
				$userRoot->newFolder('files_versions');
			}
			$userView = new \OC\Files\View('/' . $user->getUID());
			\OCA\Files_Versions\Storage::createMissingDirectories($relativePath, $userView);
			$success = \OCA\Files_Versions\Storage::rollback('/' . ltrim($relativePath, '/'), (int)$revision, $user);
			if ($success !== false) {
				$rolledBack = true;
			}
		}

		if (!$rolledBack) {
			throw new \RuntimeException('Failed to rollback file revision');
		}

		// Re-extract PDF facts to synchronize sheetmusic_song_files with reverted file content
		if ($targetNode instanceof \OCP\Files\File) {
			$revertedContent = $targetNode->getContent();
			if (is_string($revertedContent)) {
				$fileRecord->fileSize = strlen($revertedContent);
				$ext = strtolower(pathinfo($targetNode->getName(), PATHINFO_EXTENSION));
				if ($ext === 'pdf') {
					$facts = $this->extractPdfFacts($revertedContent, $targetNode->getName());
					$fileRecord->fileHash = $facts['fileHash'];
					$fileRecord->pageCount = $facts['pageCount'];
					$fileRecord->pageOrder = $facts['pageOrder'];
				}
				$fileRecord->updatedAt = time();
				$this->internalSongFileMapper->update($fileRecord);
			}
		}

		$this->libraryMapper->touch($libraryId);
		$this->syncSongFileMetadata($user, '', $songId, $libraryId);

		$targetSongId = $fileRecord->songId;
		if ($targetSongId > 0) {
			$song = $this->internalSongMapper->findById($targetSongId);
			if ($song !== null) {
				$song->updatedAt = time();
				$this->internalSongMapper->update($song);
				if ($this->songHistoryService !== null) {
					$this->songHistoryService->recordSnapshot(
						$song,
						InternalSongHistory::ACTION_REVERTED,
						$user->getUID(),
						'Reverted file to revision ' . $revision . ': ' . $targetNode->getName()
					);
				}
			}
		}

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

		if ($songId > 0 && $this->songHistoryService !== null) {
			$song = $this->internalSongMapper->findById($songId);
			if ($song !== null) {
				$this->songHistoryService->recordSnapshot(
					$song,
					InternalSongHistory::ACTION_UPDATED,
					$user->getUID(),
					'Attached file: ' . $targetName
				);
			}
		}

		return $this->internalSongMapper->findLibraryData($libraryId, $user);
	}

	public function deleteSongFile(IUser $user, int $libraryId, int $songId, int $fileId): ?LibraryData {
		$fileRecord = $this->resolveSongFileRecord($fileId, $songId);
		if ($fileRecord !== null) {
			$targetFileId = $fileRecord->fileId;
			$targetSongId = $fileRecord->songId;
			$this->internalSongFileMapper->delete($fileRecord->id);
			$this->libraryMapper->touch($libraryId);

			$others = $this->internalSongFileMapper->findByFileId($targetFileId);
			$activeOthers = array_filter($others, fn (InternalSongFile $f) => $f->id !== $fileRecord->id && $f->deletedAt === null);

			$fileName = '';
			if (empty($activeOthers)) {
				try {
					$userFolder = $this->rootFolder->getUserFolder($user->getUID());
					$node = $userFolder->getById($targetFileId);
					if (!empty($node) && $node[0] instanceof Node) {
						$fileName = $node[0]->getName();
						$node[0]->delete();
					}
				} catch (\Throwable $e) {
					$this->logger->warning('Could not delete physical file node', [
						'fileId' => $targetFileId,
						'exception' => $e,
					]);
				}
			}

			if ($targetSongId > 0 && $this->songHistoryService !== null) {
				$song = $this->internalSongMapper->findById($targetSongId);
				if ($song !== null) {
					$this->songHistoryService->recordSnapshot(
						$song,
						InternalSongHistory::ACTION_UPDATED,
						$user->getUID(),
						'Deleted attached file' . ($fileName !== '' ? ': ' . $fileName : '')
					);
				}
			}

			return $this->internalSongMapper->findLibraryData($libraryId, $user);
		}

		return null;
	}

	public function detachSongFile(IUser $user, int $libraryId, int $songId, int $fileId): ?LibraryData {
		try {
			$fileRecord = $this->resolveSongFileRecord($fileId, $songId);
			if ($fileRecord === null) {
				return null;
			}

			$targetSongId = $fileRecord->songId;
			$this->internalSongFileMapper->delete($fileRecord->id);
			$this->libraryMapper->touch($libraryId);

			if ($targetSongId > 0 && $this->songHistoryService !== null) {
				$song = $this->internalSongMapper->findById($targetSongId);
				if ($song !== null) {
					$this->songHistoryService->recordSnapshot(
						$song,
						InternalSongHistory::ACTION_UPDATED,
						$user->getUID(),
						'Detached attached file'
					);
				}
			}

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

	public function updateSongFilePageOrder(IUser $user, int $libraryId, int $songId, int $fileId, ?string $pageOrder): ?LibraryData {
		try {
			$fileRecord = $this->resolveSongFileRecord($fileId, $songId);
			if ($fileRecord === null) {
				return null;
			}

			$normalized = InternalSongFile::normalizePageOrder($pageOrder, $fileRecord->pageCount);
			$fileRecord->pageOrder = $normalized;
			$now = time();
			$fileRecord->updatedAt = $now;
			$this->internalSongFileMapper->update($fileRecord);

			$targetSongId = $fileRecord->songId;
			if ($targetSongId > 0) {
				$song = $this->internalSongMapper->findById($targetSongId);
				if ($song !== null) {
					$song->updatedAt = $now;
					$this->internalSongMapper->update($song);
					if ($this->songHistoryService !== null) {
						$this->songHistoryService->recordSnapshot(
							$song,
							InternalSongHistory::ACTION_UPDATED,
							$user->getUID(),
							'Updated page order to ' . ($normalized ?? 'default')
						);
					}
				}
			}

			$this->libraryMapper->touch($libraryId);
			return $this->internalSongMapper->findLibraryData($libraryId, $user);
		} catch (\Throwable $exception) {
			$this->logger->error('Could not update song file page order', [
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
		$clean = trim($libraryPath, '/');
		if ($clean === '' || $clean === '.') {
			return '';
		}
		if (str_ends_with(strtolower($clean), '.db')) {
			$directory = dirname($clean);
			return ($directory === '' || $directory === '.') ? '' : $directory;
		}

		return $clean;
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

	private function cleanupAutoIndexedStubForNewUpload(int $targetSongId, int $physicalFileId): void {
		$existingForFile = $this->internalSongFileMapper->findByFileId($physicalFileId, includeDeleted: false);
		foreach ($existingForFile as $sf) {
			if ($sf->songId === $targetSongId || $sf->id === null || $sf->clientId !== null) {
				continue;
			}
			$otherSong = $this->internalSongMapper->findById($sf->songId);
			$this->internalSongFileMapper->delete($sf->id, hardDelete: true);
			if ($otherSong !== null && $otherSong->id !== null && $otherSong->clientId === null) {
				$remaining = $this->internalSongFileMapper->findAllForSong($otherSong->id, includeDeleted: false);
				if ($remaining === []) {
					$this->internalSongMapper->delete($otherSong->id, hardDelete: true);
				}
			}
		}
	}

	private function recordSongFile(
		int $songId,
		IUser $user,
		Node $file,
		int $libraryId,
	): void {
		$fileSize = (int)$file->getSize();
		$fileHash = null;
		$pageCount = 1;
		$pageOrder = MobileSheetsPdf::pageOrder(1);
		$fileName = $file->getName();
		$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		if ($ext === 'pdf' && $file instanceof File) {
			$content = $file->getContent();
			if (is_string($content)) {
				$facts = $this->extractPdfFacts($content, $fileName);
				$fileHash = $facts['fileHash'];
				$pageCount = $facts['pageCount'];
				$pageOrder = $facts['pageOrder'];
			}
		}

		$existingFiles = $this->internalSongFileMapper->findAllForSong($songId);
		foreach ($existingFiles as $existing) {
			if ($existing->fileId === (int)$file->getId()) {
				$existing->fileSize = $fileSize;
				$existing->fileHash = $fileHash;
				$existing->pageOrder = InternalSongFile::refreshPageOrder($existing->pageOrder, $existing->pageCount, $pageCount);
				$existing->pageCount = $pageCount;
				$existing->updatedAt = time();
				$this->internalSongFileMapper->update($existing);
				$this->libraryMapper->touch($libraryId);
				$this->syncSongFileMetadata($user, '', $songId, $libraryId);
				return;
			}
		}

		$internalSongFile = new InternalSongFile(
			id: null,
			uuid: InternalEntity::generateUuid(),
			songId: $songId,
			fileId: (int)$file->getId(),
			pageOrder: $pageOrder,
			pageCount: $pageCount,
			fileSize: $fileSize,
			fileHash: $fileHash,
			type: InternalSongFile::determineFileType($fileName),
		);
		$this->internalSongFileMapper->insert($internalSongFile);
		$this->libraryMapper->touch($libraryId);

		$this->syncSongFileMetadata($user, '', $songId, $libraryId);
	}

	private function readUploadedFileContent(?array $fileInfo): string {
		if ($fileInfo === null || !isset($fileInfo['tmp_name'])) {
			throw new \InvalidArgumentException('No file uploaded');
		}
		if (is_uploaded_file($fileInfo['tmp_name']) === false && file_exists($fileInfo['tmp_name']) === false) {
			throw new \InvalidArgumentException('No file uploaded');
		}

		$content = file_get_contents($fileInfo['tmp_name']);
		if ($content === false) {
			throw new \InvalidArgumentException('Could not read the uploaded file');
		}

		return $content;
	}

	private function captureFileVersion(IUser $user, Folder $userFolder, File $targetNode, int $fileId): void {
		if ($targetNode->getSize() <= 0) {
			return;
		}

		$versionCaptured = false;
		if ((interface_exists(\OCA\Files_Versions\Versions\IVersionManager::class) || class_exists(\OCA\Files_Versions\Versions\IVersionManager::class))
			&& \OC::$server->has(\OCA\Files_Versions\Versions\IVersionManager::class)) {
			try {
				/** @var \OCA\Files_Versions\Versions\IVersionManager $versionManager */
				$versionManager = \OC::$server->get(\OCA\Files_Versions\Versions\IVersionManager::class);
				$versionManager->createVersion($user, $targetNode);
				$versionCaptured = true;
			} catch (\Throwable $e) {
				$this->logger->warning('Could not capture file version via IVersionManager before update: ' . $e->getMessage(), [
					'exception' => $e,
					'fileId' => $fileId,
				]);
			}
		}

		if (!$versionCaptured && class_exists(\OCA\Files_Versions\Storage::class)) {
			$relativePath = $userFolder->getRelativePath($targetNode->getPath());
			if ($relativePath !== null) {
				try {
					\OCA\Files_Versions\Storage::store('/' . ltrim($relativePath, '/'));
				} catch (\Throwable $e) {
					$this->logger->warning('Could not capture file version before update: ' . $e->getMessage(), [
						'exception' => $e,
						'fileId' => $fileId,
					]);
				}
			}
		}
	}

	/**
	 * @return array{fileHash: ?string, pageCount: int, pageOrder: string}
	 */
	private function extractPdfFacts(string $content, string $fileName): array {
		try {
			if ($content !== '') {
				$pageCount = MobileSheetsPdf::pageCount($content);
				return [
					'fileHash' => MobileSheetsPdf::contentHash($content),
					'pageCount' => $pageCount,
					'pageOrder' => MobileSheetsPdf::pageOrder($pageCount),
				];
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to compute PDF metadata for song file: ' . $fileName, [
				'exception' => $e,
			]);
		}

		return [
			'fileHash' => null,
			'pageCount' => 1,
			'pageOrder' => MobileSheetsPdf::pageOrder(1),
		];
	}
}
