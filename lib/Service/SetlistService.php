<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalSetlistFile;
use OCA\SheetMusic\Db\InternalSetlistFileMapper;
use OCA\SheetMusic\Db\InternalSetlistItem;
use OCA\SheetMusic\Db\InternalSetlistItemMapper;
use OCA\SheetMusic\Db\InternalSongBookmarkMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Listener\SheetMusicFileEventListener;
use OCA\SheetMusic\Util\SongSortingUtil;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class SetlistService {
	public const SETLISTS_DIR = 'SetLists';
	/** @var list<string> */
	public const ALLOWED_FILE_EXTENSIONS = ['pdf', 'docx', 'xlsx'];

	public function __construct(
		private LibraryMapper $libraryMapper,
		private InternalEntityMapper $internalEntityMapper,
		private InternalSetlistItemMapper $internalSetlistItemMapper,
		private InternalSongMapper $internalSongMapper,
		private InternalSongBookmarkMapper $internalSongBookmarkMapper,
		private LoggerInterface $logger,
		private ?LibraryService $libraryService = null,
		private ?InternalSetlistFileMapper $internalSetlistFileMapper = null,
		private ?IRootFolder $rootFolder = null,
	) {
	}

	/**
	 * @return list<array<string, mixed>>|null
	 */
	public function getSetlists(IUser $user, int $libraryId): ?array {
		if ($this->findUserLibrary($user, $libraryId) === null) {
			return null;
		}

		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$setlists = $this->internalEntityMapper->findAllForLibrary($targetLibraryId, 'setlist', includeDeleted: false);
		if (empty($setlists)) {
			return [];
		}

		$setlistIds = array_map(fn ($s) => (int)$s->id, $setlists);
		$counts = $this->internalSetlistItemMapper->getItemCountForSetlists($setlistIds);
		$fileCounts = $this->internalSetlistFileMapper?->getFileCountForSetlists($setlistIds) ?? [];

		$result = [];
		foreach ($setlists as $s) {
			$id = (int)$s->id;
			$result[] = [
				'id' => $id,
				'uuid' => $s->uuid,
				'name' => $s->name,
				'sortBy' => $s->sortBy,
				'ascending' => $s->ascending,
				'itemCount' => $counts[$id] ?? 0,
				'fileCount' => $fileCounts[$id] ?? 0,
				'createdAt' => $s->createdAt,
				'updatedAt' => $s->updatedAt,
			];
		}

		return $result;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function getSetlistsForPublicToken(int $libraryId): array {
		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$setlists = $this->internalEntityMapper->findAllForLibrary($targetLibraryId, 'setlist', includeDeleted: false);
		if (empty($setlists)) {
			return [];
		}

		$setlistIds = array_map(fn ($s) => (int)$s->id, $setlists);
		$counts = $this->internalSetlistItemMapper->getItemCountForSetlists($setlistIds);
		$fileCounts = $this->internalSetlistFileMapper?->getFileCountForSetlists($setlistIds) ?? [];

		$result = [];
		foreach ($setlists as $s) {
			$id = (int)$s->id;
			$result[] = [
				'id' => $id,
				'uuid' => $s->uuid,
				'name' => $s->name,
				'sortBy' => $s->sortBy,
				'ascending' => $s->ascending,
				'itemCount' => $counts[$id] ?? 0,
				'fileCount' => $fileCounts[$id] ?? 0,
				'createdAt' => $s->createdAt,
				'updatedAt' => $s->updatedAt,
			];
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getSetlistForPublicToken(int $libraryId, int $id): ?array {
		$setlist = $this->findActiveSetlist($libraryId, $id);
		if ($setlist === null) {
			return null;
		}

		$items = $this->internalSetlistItemMapper->findForSetlist($id);
		$files = $this->internalSetlistFileMapper?->findForSetlist($id) ?? [];

		return [
			'id' => $setlist->id,
			'uuid' => $setlist->uuid,
			'name' => $setlist->name,
			'sortBy' => $setlist->sortBy,
			'ascending' => $setlist->ascending,
			'items' => $items,
			'files' => $files,
			'createdAt' => $setlist->createdAt,
			'updatedAt' => $setlist->updatedAt,
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getSetlist(IUser $user, int $libraryId, int $id): ?array {
		if ($this->findUserLibrary($user, $libraryId) === null) {
			return null;
		}

		$setlist = $this->findActiveSetlist($libraryId, $id);
		if ($setlist === null) {
			return null;
		}

		$items = $this->internalSetlistItemMapper->findForSetlist($id);
		$files = $this->internalSetlistFileMapper?->findForSetlist($id) ?? [];

		return [
			'id' => $setlist->id,
			'uuid' => $setlist->uuid,
			'name' => $setlist->name,
			'sortBy' => $setlist->sortBy,
			'ascending' => $setlist->ascending,
			'items' => $items,
			'files' => $files,
			'createdAt' => $setlist->createdAt,
			'updatedAt' => $setlist->updatedAt,
		];
	}

	public function createSetlist(IUser $user, int $libraryId, string $name): ?InternalEntity {
		$name = trim($name);
		if ($name === '' || $this->findUserWritableLibrary($user, $libraryId) === null) {
			return null;
		}

		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$now = time();
		$setlist = new InternalEntity(
			id: null,
			uuid: InternalEntity::generateUuid(),
			libraryId: $targetLibraryId,
			type: 'setlist',
			name: $name,
			sortBy: SongSortingUtil::MODE_MANUAL,
			ascending: 1,
			createdAt: $now,
			updatedAt: $now,
		);

		$created = $this->internalEntityMapper->insert($setlist);
		$this->libraryMapper->touch($targetLibraryId);

		return $created;
	}

	public function updateSetlist(
		IUser $user,
		int $libraryId,
		int $id,
		?string $name = null,
		?int $sortBy = null,
		?int $ascending = null,
	): ?InternalEntity {
		if ($this->findUserWritableLibrary($user, $libraryId) === null) {
			return null;
		}

		$setlist = $this->findActiveSetlist($libraryId, $id);
		if ($setlist === null) {
			return null;
		}

		$changed = false;
		if ($name !== null) {
			$name = trim($name);
			if ($name !== '' && $name !== $setlist->name) {
				$setlist->name = $name;
				$changed = true;
			}
		}

		if ($sortBy !== null && $sortBy !== $setlist->sortBy) {
			$setlist->sortBy = $sortBy;
			$changed = true;
		}

		if ($ascending !== null && $ascending !== $setlist->ascending) {
			$setlist->ascending = $ascending;
			$changed = true;
		}

		if ($changed) {
			$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
			$setlist->updatedAt = time();
			$this->internalEntityMapper->update($setlist);
			$this->libraryMapper->touch($targetLibraryId);
		}

		return $setlist;
	}

	public function deleteSetlist(IUser $user, int $libraryId, int $id): bool {
		if ($this->findUserWritableLibrary($user, $libraryId) === null) {
			return false;
		}

		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$setlist = $this->internalEntityMapper->findById($id);
		if ($setlist === null || $setlist->libraryId !== $targetLibraryId || $setlist->type !== 'setlist') {
			return false;
		}

		$this->internalSetlistItemMapper->deleteForSetlist($id);
		$this->internalSetlistFileMapper?->deleteForSetlist($id);
		$this->deleteSetlistStorageFolder($targetLibraryId, $id);
		$this->internalEntityMapper->delete($id);
		$this->libraryMapper->touch($targetLibraryId);

		return true;
	}

	public function addItem(
		IUser $user,
		int $libraryId,
		int $setlistId,
		int $songId,
		?int $bookmarkId = null,
		?int $position = null,
	): ?InternalSetlistItem {
		if ($this->findUserWritableLibrary($user, $libraryId) === null) {
			return null;
		}

		$setlist = $this->findActiveSetlist($libraryId, $setlistId);
		if ($setlist === null) {
			return null;
		}

		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$song = $this->internalSongMapper->findById($songId);
		if ($song === null || $song->libraryId !== $targetLibraryId || $song->deletedAt !== null) {
			return null;
		}

		if ($bookmarkId !== null) {
			$bm = $this->internalSongBookmarkMapper->findById($bookmarkId);
			if ($bm === null || $bm->songId !== $songId) {
				return null;
			}
		}

		if ($position === null) {
			$position = $this->internalSetlistItemMapper->getNextPosition($setlistId);
		}

		$now = time();
		$item = new InternalSetlistItem(
			id: null,
			setlistId: $setlistId,
			songId: $songId,
			bookmarkId: $bookmarkId,
			position: $position,
			createdAt: $now,
			updatedAt: $now,
		);

		$inserted = $this->internalSetlistItemMapper->insert($item);

		// Touch setlist and library
		$setlist->updatedAt = $now;
		$this->internalEntityMapper->update($setlist);
		$this->libraryMapper->touch($targetLibraryId);

		return $this->internalSetlistItemMapper->findById((int)$inserted->id);
	}

	public function updateItem(
		IUser $user,
		int $libraryId,
		int $setlistId,
		int $itemId,
		?int $bookmarkId = null,
		bool $hasBookmarkParam = true,
	): ?InternalSetlistItem {
		if ($this->findUserWritableLibrary($user, $libraryId) === null) {
			return null;
		}

		$setlist = $this->findActiveSetlist($libraryId, $setlistId);
		if ($setlist === null) {
			return null;
		}

		$item = $this->internalSetlistItemMapper->findById($itemId);
		if ($item === null || $item->setlistId !== $setlistId) {
			return null;
		}

		$changed = false;
		if ($hasBookmarkParam) {
			if ($bookmarkId !== null) {
				$bm = $this->internalSongBookmarkMapper->findById($bookmarkId);
				if ($bm === null || $bm->songId !== $item->songId) {
					return null;
				}
			}
			if ($item->bookmarkId !== $bookmarkId) {
				$item->bookmarkId = $bookmarkId;
				$changed = true;
			}
		}

		if ($changed) {
			$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
			$now = time();
			$item->updatedAt = $now;
			$this->internalSetlistItemMapper->update($item);

			// Touch setlist and library
			$setlist->updatedAt = $now;
			$this->internalEntityMapper->update($setlist);
			$this->libraryMapper->touch($targetLibraryId);
		}

		return $this->internalSetlistItemMapper->findById($itemId);
	}

	public function removeItem(IUser $user, int $libraryId, int $setlistId, int $itemId): bool {
		if ($this->findUserWritableLibrary($user, $libraryId) === null) {
			return false;
		}

		$item = $this->internalSetlistItemMapper->findById($itemId);
		if ($item === null || $item->setlistId !== $setlistId) {
			return false;
		}

		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$this->internalSetlistItemMapper->delete($itemId);

		$now = time();
		$setlist = $this->internalEntityMapper->findById($setlistId);
		if ($setlist !== null) {
			$setlist->updatedAt = $now;
			$this->internalEntityMapper->update($setlist);
		}
		$this->libraryMapper->touch($targetLibraryId);

		return true;
	}

	/**
	 * @param list<int> $itemIds
	 */
	public function reorderItems(IUser $user, int $libraryId, int $setlistId, array $itemIds): bool {
		if ($this->findUserWritableLibrary($user, $libraryId) === null) {
			return false;
		}

		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$setlist = $this->internalEntityMapper->findById($setlistId);
		if ($setlist === null || $setlist->libraryId !== $targetLibraryId || $setlist->type !== 'setlist') {
			return false;
		}

		$this->internalSetlistItemMapper->reorder($setlistId, $itemIds);

		$now = time();
		$setlist->updatedAt = $now;
		$this->internalEntityMapper->update($setlist);
		$this->libraryMapper->touch($targetLibraryId);

		return true;
	}

	private function resolveTargetLibraryId(int $libraryId): int {
		if ($this->libraryService !== null) {
			return $this->libraryService->getTargetLibraryId($libraryId);
		}
		return $libraryId;
	}

	private function findUserLibrary(IUser $user, int $libraryId): ?\OCA\SheetMusic\Db\InternalLibrary {
		if ($this->libraryService !== null) {
			return $this->libraryService->getAccessibleLibrary($user->getUID(), $libraryId);
		}
		return $this->libraryMapper->findForUser($user->getUID(), $libraryId);
	}

	private function findUserWritableLibrary(IUser $user, int $libraryId): ?\OCA\SheetMusic\Db\InternalLibrary {
		if ($this->libraryService !== null) {
			if (!$this->libraryService->canWriteLibrary($user->getUID(), $libraryId)) {
				return null;
			}
			return $this->libraryService->getAccessibleLibrary($user->getUID(), $libraryId);
		}
		return $this->libraryMapper->findForUser($user->getUID(), $libraryId);
	}

	private function findActiveSetlist(int $libraryId, int $setlistId): ?InternalEntity {
		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$setlist = $this->internalEntityMapper->findById($setlistId);
		if ($setlist === null || $setlist->libraryId !== $targetLibraryId || $setlist->type !== 'setlist' || $setlist->deletedAt !== null) {
			return null;
		}
		return $setlist;
	}

	/**
	 * @return list<InternalSetlistFile>|null
	 */
	public function getSetlistFiles(IUser $user, int $libraryId, int $setlistId): ?array {
		if ($this->findUserLibrary($user, $libraryId) === null) {
			return null;
		}
		if ($this->findActiveSetlist($libraryId, $setlistId) === null) {
			return null;
		}

		return $this->internalSetlistFileMapper?->findForSetlist($setlistId) ?? [];
	}

	/**
	 * @return list<InternalSetlistFile>|null
	 */
	public function getSetlistFilesForPublicToken(int $libraryId, int $setlistId): ?array {
		if ($this->findActiveSetlist($libraryId, $setlistId) === null) {
			return null;
		}

		return $this->internalSetlistFileMapper?->findForSetlist($setlistId) ?? [];
	}

	/**
	 * @param array<string, mixed>|null $fileInfo
	 */
	public function addSetlistFile(IUser $user, int $libraryId, int $setlistId, ?array $fileInfo): ?InternalSetlistFile {
		if ($this->internalSetlistFileMapper === null || $this->rootFolder === null) {
			throw new \RuntimeException('Setlist file storage is not configured');
		}
		if ($this->findUserWritableLibrary($user, $libraryId) === null) {
			return null;
		}
		$setlist = $this->findActiveSetlist($libraryId, $setlistId);
		if ($setlist === null) {
			return null;
		}

		if ($fileInfo === null || !isset($fileInfo['tmp_name']) || !is_string($fileInfo['tmp_name']) || $fileInfo['tmp_name'] === '') {
			throw new \InvalidArgumentException('No file uploaded');
		}
		if (isset($fileInfo['error']) && (int)$fileInfo['error'] !== UPLOAD_ERR_OK) {
			throw new \InvalidArgumentException('File upload failed');
		}

		$tmpName = $fileInfo['tmp_name'];
		if (!is_file($tmpName)) {
			throw new \InvalidArgumentException('Uploaded file is missing');
		}
		$content = file_get_contents($tmpName);
		if ($content === false) {
			throw new \RuntimeException('Failed to read uploaded file');
		}

		$rawName = isset($fileInfo['name']) && is_string($fileInfo['name']) ? basename($fileInfo['name']) : 'document.pdf';
		$ext = strtolower(pathinfo($rawName, PATHINFO_EXTENSION));
		if (!in_array($ext, self::ALLOWED_FILE_EXTENSIONS, true)) {
			throw new \InvalidArgumentException('Unsupported file type. Allowed types: ' . implode(', ', self::ALLOWED_FILE_EXTENSIONS));
		}

		$safeFilename = SongPathNamingRule::normalizeGeneratedPathSegment($rawName);
		if (!str_ends_with(strtolower($safeFilename), '.' . $ext)) {
			$safeFilename .= '.' . $ext;
		}

		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$targetLibrary = $this->libraryMapper->findById($targetLibraryId);
		if ($targetLibrary === null) {
			return null;
		}

		$libFolder = $this->getLibraryFolderNode($targetLibrary);
		if ($libFolder === null) {
			throw new \RuntimeException('Library folder not found');
		}

		$setlistFolder = $this->ensureSetlistStorageFolder($libFolder, $setlistId, $setlist->name);
		$uniqueFilename = $this->resolveUniqueFilename($setlistFolder, $safeFilename);
		$relativePath = self::SETLISTS_DIR . '/' . $setlistFolder->getName() . '/' . $uniqueFilename;

		/** @var File $createdNode */
		$createdNode = SheetMusicFileEventListener::runWithoutListener(static fn () => $setlistFolder->newFile($uniqueFilename, $content));

		$mimeType = $this->resolveMimeType($ext, isset($fileInfo['type']) && is_string($fileInfo['type']) ? $fileInfo['type'] : '');
		$now = time();
		$record = new InternalSetlistFile(
			id: null,
			setlistId: $setlistId,
			libraryId: $targetLibraryId,
			ocFileId: (int)$createdNode->getId(),
			path: $relativePath,
			filename: $uniqueFilename,
			mimeType: $mimeType,
			fileSize: strlen($content),
			sortOrder: $this->internalSetlistFileMapper->getNextSortOrder($setlistId),
			createdAt: $now,
			updatedAt: $now,
		);

		$inserted = $this->internalSetlistFileMapper->insert($record);
		$setlist->updatedAt = $now;
		$this->internalEntityMapper->update($setlist);
		$this->libraryMapper->touch($targetLibraryId);

		return $inserted;
	}

	public function attachNextcloudSetlistFile(
		IUser $user,
		int $libraryId,
		int $setlistId,
		string $sourcePath,
		string $action = 'copy',
	): ?InternalSetlistFile {
		if ($this->internalSetlistFileMapper === null || $this->rootFolder === null) {
			throw new \RuntimeException('Setlist file storage is not configured');
		}
		if ($sourcePath === '') {
			throw new \InvalidArgumentException('Source path cannot be empty');
		}
		if (!in_array($action, ['copy', 'move'], true)) {
			throw new \InvalidArgumentException('Invalid action. Allowed actions: copy, move');
		}
		if ($this->findUserWritableLibrary($user, $libraryId) === null) {
			return null;
		}
		$setlist = $this->findActiveSetlist($libraryId, $setlistId);
		if ($setlist === null) {
			return null;
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$relSource = ltrim($sourcePath, '/');
		if (!$userFolder->nodeExists($relSource)) {
			throw new \InvalidArgumentException('Selected file does not exist');
		}
		$sourceNode = $userFolder->get($relSource);
		if (!($sourceNode instanceof File)) {
			throw new \InvalidArgumentException('Selected item is not a file');
		}

		$rawName = $sourceNode->getName();
		$ext = strtolower(pathinfo($rawName, PATHINFO_EXTENSION));
		if (!in_array($ext, self::ALLOWED_FILE_EXTENSIONS, true)) {
			throw new \InvalidArgumentException('Unsupported file type. Allowed types: ' . implode(', ', self::ALLOWED_FILE_EXTENSIONS));
		}

		$safeFilename = SongPathNamingRule::normalizeGeneratedPathSegment($rawName);
		if (!str_ends_with(strtolower($safeFilename), '.' . $ext)) {
			$safeFilename .= '.' . $ext;
		}

		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$targetLibrary = $this->libraryMapper->findById($targetLibraryId);
		if ($targetLibrary === null) {
			return null;
		}

		$libFolder = $this->getLibraryFolderNode($targetLibrary);
		if ($libFolder === null) {
			throw new \RuntimeException('Library folder not found');
		}

		$setlistFolder = $this->ensureSetlistStorageFolder($libFolder, $setlistId, $setlist->name);
		$uniqueFilename = $this->resolveUniqueFilename($setlistFolder, $safeFilename);
		$targetInternalPath = $setlistFolder->getPath() . '/' . $uniqueFilename;
		$relativePath = self::SETLISTS_DIR . '/' . $setlistFolder->getName() . '/' . $uniqueFilename;

		SheetMusicFileEventListener::runWithoutListener(static function () use ($action, $sourceNode, $targetInternalPath): void {
			if ($action === 'move') {
				$sourceNode->move($targetInternalPath);
			} else {
				$sourceNode->copy($targetInternalPath);
			}
		});

		$attachedNode = $setlistFolder->get($uniqueFilename);
		if (!($attachedNode instanceof File)) {
			throw new \RuntimeException('Failed to locate attached file');
		}

		$mimeType = $this->resolveMimeType($ext, $attachedNode->getMimeType());
		$now = time();
		$record = new InternalSetlistFile(
			id: null,
			setlistId: $setlistId,
			libraryId: $targetLibraryId,
			ocFileId: (int)$attachedNode->getId(),
			path: $relativePath,
			filename: $uniqueFilename,
			mimeType: $mimeType,
			fileSize: (int)$attachedNode->getSize(),
			sortOrder: $this->internalSetlistFileMapper->getNextSortOrder($setlistId),
			createdAt: $now,
			updatedAt: $now,
		);

		$inserted = $this->internalSetlistFileMapper->insert($record);
		$setlist->updatedAt = $now;
		$this->internalEntityMapper->update($setlist);
		$this->libraryMapper->touch($targetLibraryId);

		return $inserted;
	}

	/**
	 * @return array{file: InternalSetlistFile, content: string}|null
	 */
	public function getSetlistFileContent(IUser $user, int $libraryId, int $setlistId, int $fileId): ?array {
		if ($this->findUserLibrary($user, $libraryId) === null) {
			return null;
		}
		return $this->loadSetlistFileContent($libraryId, $setlistId, $fileId);
	}

	/**
	 * @return array{file: InternalSetlistFile, content: string}|null
	 */
	public function getSetlistFileContentForPublicToken(int $libraryId, int $setlistId, int $fileId): ?array {
		return $this->loadSetlistFileContent($libraryId, $setlistId, $fileId);
	}

	public function deleteSetlistFile(IUser $user, int $libraryId, int $setlistId, int $fileId): bool {
		if ($this->internalSetlistFileMapper === null) {
			return false;
		}
		if ($this->findUserWritableLibrary($user, $libraryId) === null) {
			return false;
		}
		$setlist = $this->findActiveSetlist($libraryId, $setlistId);
		if ($setlist === null) {
			return false;
		}

		$fileRecord = $this->internalSetlistFileMapper->findById($fileId);
		if ($fileRecord === null || $fileRecord->setlistId !== $setlistId) {
			return false;
		}

		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$targetLibrary = $this->libraryMapper->findById($targetLibraryId);
		if ($targetLibrary !== null) {
			$node = $this->resolveSetlistFileNode($targetLibrary, $fileRecord);
			if ($node !== null) {
				try {
					SheetMusicFileEventListener::runWithoutListener(static fn () => $node->delete());
				} catch (\Throwable $e) {
					$this->logger->warning('Failed to delete physical setlist file: ' . $e->getMessage(), [
						'fileId' => $fileId,
						'path' => $fileRecord->path,
					]);
				}
			}
		}

		$this->internalSetlistFileMapper->delete($fileId);
		$now = time();
		$setlist->updatedAt = $now;
		$this->internalEntityMapper->update($setlist);
		$this->libraryMapper->touch($targetLibraryId);

		return true;
	}

	/**
	 * @return array{file: InternalSetlistFile, content: string}|null
	 */
	private function loadSetlistFileContent(int $libraryId, int $setlistId, int $fileId): ?array {
		if ($this->internalSetlistFileMapper === null) {
			return null;
		}
		if ($this->findActiveSetlist($libraryId, $setlistId) === null) {
			return null;
		}

		$fileRecord = $this->internalSetlistFileMapper->findById($fileId);
		if ($fileRecord === null || $fileRecord->setlistId !== $setlistId) {
			return null;
		}

		$targetLibraryId = $this->resolveTargetLibraryId($libraryId);
		$targetLibrary = $this->libraryMapper->findById($targetLibraryId);
		if ($targetLibrary === null) {
			return null;
		}

		$node = $this->resolveSetlistFileNode($targetLibrary, $fileRecord);
		if ($node === null) {
			return null;
		}

		return [
			'file' => $fileRecord,
			'content' => $node->getContent(),
		];
	}

	private function getLibraryFolderNode(InternalLibrary $library): ?Folder {
		if ($this->rootFolder === null) {
			return null;
		}
		try {
			$userFolder = $this->rootFolder->getUserFolder($library->userId);
			if ($library->folderId !== null && $library->folderId > 0) {
				$nodes = $userFolder->getById($library->folderId);
				if (!empty($nodes) && $nodes[0] instanceof Folder) {
					return $nodes[0];
				}
			}
			$relPath = ltrim($library->path, '/');
			if ($relPath === '') {
				return $userFolder;
			}
			if ($userFolder->nodeExists($relPath)) {
				$node = $userFolder->get($relPath);
				if ($node instanceof Folder) {
					return $node;
				}
			}
		} catch (\Throwable) {
			// Ignore
		}
		return null;
	}

	private function ensureSetlistStorageFolder(Folder $libFolder, int $setlistId, string $setlistName): Folder {
		if (!$libFolder->nodeExists(self::SETLISTS_DIR)) {
			$libFolder->newFolder(self::SETLISTS_DIR);
		}
		$setlistsRoot = $libFolder->get(self::SETLISTS_DIR);
		if (!($setlistsRoot instanceof Folder)) {
			throw new \RuntimeException('Failed to access setlists storage directory');
		}

		$prefix = $setlistId . '-';
		foreach ($setlistsRoot->getDirectoryListing() as $child) {
			if ($child instanceof Folder && str_starts_with($child->getName(), $prefix)) {
				return $child;
			}
		}

		$slug = SongPathNamingRule::normalizeGeneratedPathSegment($setlistName);
		$folderName = $prefix . ($slug !== '' ? $slug : 'setlist');
		if (!$setlistsRoot->nodeExists($folderName)) {
			$setlistsRoot->newFolder($folderName);
		}
		$target = $setlistsRoot->get($folderName);
		if (!($target instanceof Folder)) {
			throw new \RuntimeException('Failed to create setlist storage folder');
		}
		return $target;
	}

	private function deleteSetlistStorageFolder(int $targetLibraryId, int $setlistId): void {
		if ($this->rootFolder === null) {
			return;
		}
		$targetLibrary = $this->libraryMapper->findById($targetLibraryId);
		if ($targetLibrary === null) {
			return;
		}
		$libFolder = $this->getLibraryFolderNode($targetLibrary);
		if ($libFolder === null || !$libFolder->nodeExists(self::SETLISTS_DIR)) {
			return;
		}
		try {
			$setlistsRoot = $libFolder->get(self::SETLISTS_DIR);
			if (!($setlistsRoot instanceof Folder)) {
				return;
			}
			$prefix = $setlistId . '-';
			foreach ($setlistsRoot->getDirectoryListing() as $child) {
				if ($child instanceof Folder && str_starts_with($child->getName(), $prefix)) {
					SheetMusicFileEventListener::runWithoutListener(static fn () => $child->delete());
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to delete setlist folder: ' . $e->getMessage(), [
				'setlistId' => $setlistId,
			]);
		}
	}

	private function resolveSetlistFileNode(InternalLibrary $library, InternalSetlistFile $fileRecord): ?File {
		if ($this->rootFolder === null) {
			return null;
		}
		try {
			$userFolder = $this->rootFolder->getUserFolder($library->userId);
			if ($fileRecord->ocFileId > 0) {
				$nodes = $userFolder->getById($fileRecord->ocFileId);
				if (!empty($nodes) && $nodes[0] instanceof File) {
					return $nodes[0];
				}
			}
			$libFolder = $this->getLibraryFolderNode($library);
			if ($libFolder !== null && $fileRecord->path !== '' && $libFolder->nodeExists($fileRecord->path)) {
				$node = $libFolder->get($fileRecord->path);
				if ($node instanceof File) {
					return $node;
				}
			}
		} catch (\Throwable) {
			// Ignore
		}
		return null;
	}

	private function resolveUniqueFilename(Folder $folder, string $filename): string {
		if (!$folder->nodeExists($filename)) {
			return $filename;
		}
		$dotPos = strrpos($filename, '.');
		$base = $dotPos !== false ? substr($filename, 0, $dotPos) : $filename;
		$ext = $dotPos !== false ? substr($filename, $dotPos) : '';
		$counter = 2;
		while ($folder->nodeExists("{$base} ({$counter}){$ext}")) {
			$counter++;
		}
		return "{$base} ({$counter}){$ext}";
	}

	private function resolveMimeType(string $ext, string $fallback): string {
		return match ($ext) {
			'pdf' => 'application/pdf',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			default => $fallback !== '' ? $fallback : 'application/octet-stream',
		};
	}
}
