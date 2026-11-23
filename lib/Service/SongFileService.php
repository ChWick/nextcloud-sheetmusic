<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongHistory;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryData;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Listener\SheetMusicFileEventListener;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFileFacts;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPathResolver;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdfMetadata;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
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
		private ?SublibraryService $sublibraryService = null,
	) {
	}

	public function getLogger(): LoggerInterface {
		return $this->logger;
	}

	private function findLibraryDataForResponse(IUser $user, int $libraryId): LibraryData {
		$targetLibraryId = $this->libraryService->getTargetLibraryId($libraryId);
		$sublibrary = null;
		if ($libraryId >= LibraryShareService::SUBLIBRARY_ID_OFFSET && $this->sublibraryService !== null) {
			$sublibrary = $this->sublibraryService->getSublibrary($libraryId - LibraryShareService::SUBLIBRARY_ID_OFFSET);
		}
		$library = $this->libraryService->getAccessibleLibrary($user->getUID(), $libraryId);
		return $this->internalSongMapper->findLibraryData($targetLibraryId, $user, null, $library, $sublibrary);
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

	/**
	 * Resolve a song file DB record and its backing Nextcloud File node.
	 *
	 * @return array{fileRecord: InternalSongFile, userFolder: Folder, node: File}
	 */
	private function resolveSongFileNode(IUser $user, int $fileId, int $songId = 0): array {
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

		return [
			'fileRecord' => $fileRecord,
			'userFolder' => $userFolder,
			'node' => $targetNode,
		];
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
					$content = MobileSheetsFileFacts::readFileContent($node);
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

	public function uploadSongFile(
		IUser $user,
		int $libraryId,
		int $songId,
		?array $fileInfo,
		?int $explicitPageCount = null,
		?string $explicitPageOrder = null,
	): ?LibraryData {
		$autoPaths = $this->libraryService->isAutoPathsEnabled($user->getUID(), $libraryId);
		$stored = $this->storeUploadedSongFile(
			$user,
			$libraryId,
			$songId,
			$fileInfo,
			defaultName: 'song.file',
			alwaysUniquePath: $autoPaths,
			explicitPageCount: $explicitPageCount,
			explicitPageOrder: $explicitPageOrder,
			syncAfter: true,
		);
		if ($stored === null) {
			return null;
		}

		return $this->findLibraryDataForResponse($user, $libraryId);
	}

	public function updateSongFile(IUser $user, int $libraryId, int $songId, int $fileId, ?array $fileInfo): ?LibraryData {
		$content = $this->readUploadedFileContent($fileInfo);
		['fileRecord' => $fileRecord, 'userFolder' => $userFolder, 'node' => $targetNode] = $this->resolveSongFileNode($user, $fileId, $songId);

		$this->captureFileVersion($user, $userFolder, $targetNode, $fileId);

		// Overwrite content in place on existing File node:
		// Preserves fileId, path, and triggers Nextcloud files_versions app to capture history
		SheetMusicFileEventListener::runWithoutListener(static function () use ($targetNode, $content): void {
			$targetNode->putContent($content);
		});

		$this->refreshSongFileRecordFacts($fileRecord, $targetNode->getName(), $content);

		$targetLibraryId = $this->libraryService->getTargetLibraryId($libraryId);
		$this->libraryMapper->touch($targetLibraryId);
		$this->syncSongFileMetadata($user, '', $songId, $targetLibraryId);
		$this->recordSongFileHistory($songId, $user, 'Modified attached PDF: ' . $targetNode->getName(), touchSong: true);

		return $this->findLibraryDataForResponse($user, $libraryId);
	}

	/**
	 * Save a merged PDF for a song, optionally delete the merged source PDFs,
	 * and recompute all song file paths according to the library naming rules.
	 *
	 * @param list<int> $deleteFileIds
	 */
	public function mergeSongFiles(
		IUser $user,
		int $libraryId,
		int $songId,
		?array $fileInfo,
		array $deleteFileIds = [],
		?int $explicitPageCount = null,
		?string $explicitPageOrder = null,
	): ?LibraryData {
		$stored = $this->storeUploadedSongFile(
			$user,
			$libraryId,
			$songId,
			$fileInfo,
			defaultName: 'merged.pdf',
			alwaysUniquePath: true,
			explicitPageCount: $explicitPageCount,
			explicitPageOrder: $explicitPageOrder,
			syncAfter: false,
		);
		if ($stored === null) {
			return null;
		}
		['file' => $mergedFile, 'userFolder' => $userFolder, 'targetLibraryId' => $targetLibraryId] = $stored;
		$mergedPhysicalId = (int)$mergedFile->getId();

		foreach (array_unique($deleteFileIds) as $deleteId) {
			$fileRecord = $this->resolveSongFileRecord($deleteId, $songId);
			if ($fileRecord === null || $fileRecord->fileId === $mergedPhysicalId) {
				continue;
			}

			$this->removeSongFileRecordAndPhysicalNode($fileRecord, $user, $userFolder);
		}

		$this->libraryMapper->touch($targetLibraryId);
		$this->syncSongFiles($user, $songId, $targetLibraryId);
		$this->recordSongFileHistory($songId, $user, 'Merged PDFs into: ' . $mergedFile->getName(), touchSong: true);

		return $this->findLibraryDataForResponse($user, $libraryId);
	}

	/**
	 * Store an uploaded file node in the library folder and record it on the song.
	 *
	 * @return array{file: File, userFolder: Folder, targetLibraryId: int}|null
	 */
	private function storeUploadedSongFile(
		IUser $user,
		int $libraryId,
		int $songId,
		?array $fileInfo,
		string $defaultName,
		bool $alwaysUniquePath,
		?int $explicitPageCount,
		?string $explicitPageOrder,
		bool $syncAfter,
	): ?array {
		$content = $this->readUploadedFileContent($fileInfo);
		$fileName = basename((string)($fileInfo['name'] ?? $defaultName));

		$target = $this->resolveLibraryTarget($user, $libraryId);
		if ($target === null) {
			return null;
		}
		['userFolder' => $userFolder, 'libraryDir' => $libraryDir] = $target;

		$targetRelative = $this->resolveDestinationPath($userFolder, $libraryDir, $fileName, $alwaysUniquePath);
		$this->fileManager->ensureParentFolder($userFolder, $targetRelative);
		// Create the file with its content in one step, suppressing the file event
		// listener so it does not parse the PDF and create a redundant stub song.
		$targetFile = SheetMusicFileEventListener::runWithoutListener(
			static fn () => $userFolder->newFile($targetRelative, $content)
		);

		$targetLibraryId = $this->libraryService->getTargetLibraryId($libraryId);
		$this->cleanupAutoIndexedStubForNewUpload($songId, (int)$targetFile->getId());
		$this->recordSongFile(
			$songId,
			$user,
			$targetFile,
			$targetLibraryId,
			$explicitPageCount,
			$explicitPageOrder,
			$content,
			syncAfter: $syncAfter,
		);

		return [
			'file' => $targetFile,
			'userFolder' => $userFolder,
			'targetLibraryId' => $targetLibraryId,
		];
	}

	/**
	 * @return list<array{version: string, revision: int, timestamp: int, humanReadableTimestamp: string, size: int, name: string}>
	 */
	public function getSongFileVersions(IUser $user, int $fileId): array {
		try {
			['userFolder' => $userFolder, 'node' => $targetNode] = $this->resolveSongFileNode($user, $fileId);
		} catch (\InvalidArgumentException|\RuntimeException) {
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
		['fileRecord' => $fileRecord, 'userFolder' => $userFolder, 'node' => $targetNode] = $this->resolveSongFileNode($user, $fileId, $songId);
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
			$revertedContent = MobileSheetsFileFacts::readFileContent($targetNode);
			if ($revertedContent !== '') {
				$this->refreshSongFileRecordFacts($fileRecord, $targetNode->getName(), $revertedContent);
			}
		}

		$targetLibraryId = $this->libraryService->getTargetLibraryId($libraryId);
		$this->libraryMapper->touch($targetLibraryId);
		$this->syncSongFileMetadata($user, '', $songId, $targetLibraryId);
		$this->recordSongFileHistory(
			$fileRecord->songId,
			$user,
			'Reverted file to revision ' . $revision . ': ' . $targetNode->getName(),
			action: InternalSongHistory::ACTION_REVERTED,
			touchSong: true,
		);

		return $this->findLibraryDataForResponse($user, $libraryId);
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

			SheetMusicFileEventListener::runWithoutListener(static function () use ($action, $sourceNode, $targetInternalPath): void {
				if ($action === 'move') {
					$sourceNode->move($targetInternalPath);
				} else {
					$sourceNode->copy($targetInternalPath);
				}
			});
		}

		$attachedFile = $targetFolder->get($targetName);
		$targetLibraryId = $this->libraryService->getTargetLibraryId($libraryId);
		$this->recordSongFile($songId, $user, $attachedFile, $targetLibraryId);
		$this->recordSongFileHistory($songId, $user, 'Attached file: ' . $targetName);

		return $this->findLibraryDataForResponse($user, $libraryId);
	}

	public function deleteSongFile(IUser $user, int $libraryId, int $songId, int $fileId): ?LibraryData {
		$fileRecord = $this->resolveSongFileRecord($fileId, $songId);
		if ($fileRecord !== null) {
			$targetSongId = $fileRecord->songId;
			$fileName = $this->removeSongFileRecordAndPhysicalNode($fileRecord, $user);
			$targetLibraryId = $this->libraryService->getTargetLibraryId($libraryId);
			$this->libraryMapper->touch($targetLibraryId);

			if ($targetSongId > 0) {
				$this->regenerateSongFilePathsIfEnabled($user, $targetSongId, $targetLibraryId);
				$this->recordSongFileHistory(
					$targetSongId,
					$user,
					'Deleted attached file' . ($fileName !== '' ? ': ' . $fileName : ''),
				);
			}

			return $this->findLibraryDataForResponse($user, $libraryId);
		}

		return null;
	}

	/**
	 * Delete a song file DB record and remove the underlying Nextcloud file node
	 * when no other active song references it. Returns the deleted file's name, if any.
	 */
	private function removeSongFileRecordAndPhysicalNode(
		InternalSongFile $fileRecord,
		IUser $user,
		?Folder $userFolder = null,
	): string {
		$physicalId = $fileRecord->fileId;
		$this->internalSongFileMapper->delete($fileRecord->id);

		$others = $this->internalSongFileMapper->findByFileId($physicalId);
		$activeOthers = array_filter(
			$others,
			fn (InternalSongFile $f) => $f->id !== $fileRecord->id && $f->deletedAt === null,
		);

		$fileName = '';
		if (empty($activeOthers)) {
			try {
				$userFolder ??= $this->rootFolder->getUserFolder($user->getUID());
				$nodes = $userFolder->getById($physicalId);
				if (!empty($nodes) && $nodes[0] instanceof Node) {
					$fileName = $nodes[0]->getName();
					$nodes[0]->delete();
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Could not delete physical file node', [
					'fileId' => $physicalId,
					'exception' => $e,
				]);
			}
		}

		return $fileName;
	}

	public function detachSongFile(IUser $user, int $libraryId, int $songId, int $fileId): ?LibraryData {
		try {
			$fileRecord = $this->resolveSongFileRecord($fileId, $songId);
			if ($fileRecord === null) {
				return null;
			}

			$targetSongId = $fileRecord->songId;
			$this->internalSongFileMapper->delete($fileRecord->id);
			$targetLibraryId = $this->libraryService->getTargetLibraryId($libraryId);
			$this->libraryMapper->touch($targetLibraryId);

			if ($targetSongId > 0) {
				$this->regenerateSongFilePathsIfEnabled($user, $targetSongId, $targetLibraryId);
				$this->recordSongFileHistory($targetSongId, $user, 'Detached attached file');
			}

			return $this->findLibraryDataForResponse($user, $libraryId);
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

			$this->recordSongFileHistory(
				$fileRecord->songId,
				$user,
				'Updated page order to ' . ($normalized ?? 'default'),
				touchSong: true,
				timestamp: $now,
			);

			$targetLibraryId = $this->libraryService->getTargetLibraryId($libraryId);
			$this->libraryMapper->touch($targetLibraryId);
			return $this->findLibraryDataForResponse($user, $libraryId);
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

	public function syncSongFileMetadata(
		IUser $user,
		string $databasePath = '',
		int $songId = 0,
		?int $libraryId = null,
		?MobileSheetsSong $resolvedSong = null,
	): void {
		if ($libraryId !== null && !$this->libraryService->isAutoPdfMetadataEnabled($user->getUID(), $libraryId)) {
			return;
		}

		if ($libraryId === null) {
			return;
		}

		$song = $resolvedSong ?? $this->internalSongMapper->findSong($songId, $user);

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
		return InternalLibrary::normalizeFolderPath($libraryPath);
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
		?int $explicitPageCount = null,
		?string $explicitPageOrder = null,
		?string $uploadedContent = null,
		bool $syncAfter = true,
	): void {
		$fileSize = (int)$file->getSize();
		$fileHash = null;
		$pageCount = $explicitPageCount !== null && $explicitPageCount > 0 ? $explicitPageCount : 1;
		$pageOrder = $explicitPageOrder !== null && $explicitPageOrder !== ''
			? $explicitPageOrder
			: MobileSheetsPdf::pageOrder($pageCount);
		$fileName = $file->getName();
		$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		if ($ext === 'pdf') {
			$content = $uploadedContent;
			if ($content === null && $file instanceof File) {
				$raw = MobileSheetsFileFacts::readFileContent($file);
				if ($raw !== '') {
					$content = $raw;
				}
			}
			if (is_string($content) && $content !== '') {
				$facts = $this->extractPdfFacts($content, $fileName);
				$fileHash = $facts['fileHash'];
				$detectedCount = (int)$facts['pageCount'];
				if ($explicitPageCount !== null && $explicitPageCount > 0) {
					$pageCount = $explicitPageCount;
				} elseif ($detectedCount > 0) {
					$pageCount = $detectedCount;
				}
				if ($explicitPageOrder !== null && $explicitPageOrder !== '') {
					$pageOrder = $explicitPageOrder;
				} else {
					$pageOrder = MobileSheetsPdf::pageOrder($pageCount);
				}
			}
		}

		$existingFiles = $this->internalSongFileMapper->findAllForSong($songId);
		$updatedExisting = false;
		foreach ($existingFiles as $existing) {
			if ($existing->fileId === (int)$file->getId()) {
				$existing->fileSize = $fileSize;
				$existing->fileHash = $fileHash;
				$existing->pageOrder = InternalSongFile::refreshPageOrder($existing->pageOrder, $existing->pageCount, $pageCount);
				$existing->pageCount = $pageCount;
				$existing->updatedAt = time();
				$this->internalSongFileMapper->update($existing);
				$updatedExisting = true;
				break;
			}
		}

		if (!$updatedExisting) {
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
		}

		if ($syncAfter) {
			$this->libraryMapper->touch($libraryId);
			$this->syncSongFiles($user, $songId, $libraryId);
		}
	}

	/**
	 * Pre-resolve library auto-path settings and folder once for batch song syncs.
	 *
	 * @return array{autoPaths: bool, preset: ?string, targetFolder: ?Folder}
	 */
	public function resolveSyncContext(IUser $user, int $targetLibraryId): array {
		$autoPaths = $this->libraryService->isAutoPathsEnabled($user->getUID(), $targetLibraryId);
		$preset = null;
		$targetFolder = null;
		if ($autoPaths) {
			$preset = $this->libraryService->getNamingRulePreset($user->getUID(), $targetLibraryId);
			if (!SongPathNamingRule::isDisabledPreset($preset)) {
				try {
					$targetFolder = $this->fileManager->libraryFolder($targetLibraryId, $user);
				} catch (\Throwable) {
					$targetFolder = null;
				}
			}
		}

		return [
			'autoPaths' => $autoPaths,
			'preset' => $preset,
			'targetFolder' => $targetFolder,
		];
	}

	/**
	 * Regenerate file paths and sync PDF metadata for multiple songs using a shared library context.
	 *
	 * @param list<int> $songIds
	 */
	public function syncMultipleSongFiles(IUser $user, array $songIds, int $targetLibraryId): void {
		if ($songIds === []) {
			return;
		}

		['autoPaths' => $autoPaths, 'preset' => $preset, 'targetFolder' => $targetFolder] = $this->resolveSyncContext($user, $targetLibraryId);
		foreach ($songIds as $songId) {
			$this->syncSongFiles($user, (int)$songId, $targetLibraryId, $autoPaths, $preset, $targetFolder);
		}
	}

	/**
	 * Regenerate file paths (when autoPaths is enabled and a naming rule is active)
	 * and sync PDF metadata (when autoPdfMetadata is enabled) for a song.
	 */
	public function syncSongFiles(
		IUser $user,
		int $songId,
		int $targetLibraryId,
		?bool $autoPaths = null,
		?string $preset = null,
		?Folder $targetFolder = null,
	): void {
		$resolvedSong = $this->regenerateSongFilePathsIfEnabled(
			$user,
			$songId,
			$targetLibraryId,
			$autoPaths,
			$preset,
			$targetFolder,
		);
		$this->syncSongFileMetadata($user, '', $songId, $targetLibraryId, $resolvedSong);
	}

	/**
	 * Move a song's files to their naming-rule paths when autoPaths is enabled
	 * and a naming rule preset is active in the library settings.
	 */
	private function regenerateSongFilePathsIfEnabled(
		IUser $user,
		int $songId,
		int $targetLibraryId,
		?bool $autoPaths = null,
		?string $preset = null,
		?Folder $targetFolder = null,
	): ?MobileSheetsSong {
		if ($songId <= 0) {
			return null;
		}

		$autoPaths ??= $this->libraryService->isAutoPathsEnabled($user->getUID(), $targetLibraryId);
		if (!$autoPaths) {
			return null;
		}

		$preset ??= $this->libraryService->getNamingRulePreset($user->getUID(), $targetLibraryId);
		if (SongPathNamingRule::isDisabledPreset($preset)) {
			return null;
		}

		try {
			$targetFolder ??= $this->fileManager->libraryFolder($targetLibraryId, $user);
			return $this->fileManager->regenerateSongFilePaths($songId, $user, $targetFolder, '', null, $preset);
		} catch (\Throwable $e) {
			$this->logger->debug('Could not regenerate file paths for song', [
				'exception' => $e,
				'libraryId' => $targetLibraryId,
				'songId' => $songId,
			]);
			return null;
		}
	}

	private function recordSongFileHistory(
		int $songId,
		IUser $user,
		string $summary,
		string $action = InternalSongHistory::ACTION_UPDATED,
		bool $touchSong = false,
		?int $timestamp = null,
	): void {
		if ($songId <= 0 || (!$touchSong && $this->songHistoryService === null)) {
			return;
		}

		$song = $this->internalSongMapper->findById($songId);
		if ($song === null) {
			return;
		}

		if ($touchSong) {
			$song->updatedAt = $timestamp ?? time();
			$this->internalSongMapper->update($song);
		}

		$this->songHistoryService?->recordSnapshot(
			$song,
			$action,
			$user->getUID(),
			$summary,
		);
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

	private function refreshSongFileRecordFacts(InternalSongFile $fileRecord, string $fileName, string $content): void {
		$fileRecord->fileSize = strlen($content);
		$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		if ($ext === 'pdf') {
			$facts = $this->extractPdfFacts($content, $fileName);
			$fileRecord->fileHash = $facts['fileHash'];
			$fileRecord->pageCount = $facts['pageCount'];
			$fileRecord->pageOrder = $facts['pageOrder'];
		}
		$fileRecord->updatedAt = time();
		$this->internalSongFileMapper->update($fileRecord);
	}

	/**
	 * @return array{fileHash: ?int, pageCount: int, pageOrder: string}
	 */
	private function extractPdfFacts(string $content, string $fileName): array {
		try {
			return MobileSheetsPdf::extractFacts($content);
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to compute PDF metadata for song file: ' . $fileName, [
				'exception' => $e,
			]);
		}

		return MobileSheetsPdf::extractFacts('');
	}
}
