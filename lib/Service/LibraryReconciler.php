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
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongBookmarkMapper;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdfMetadata;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class LibraryReconciler {
	/** Ignored filenames and extensions for sheet music */
	private const IGNORED_NAMES = [
		'mobilesheets.db',
		'mobilesheets.db-wal',
		'mobilesheets.db-journal',
		'mobilesheets.db-shm',
		'mobilesheets_hashcodes.txt',
	];

	public function __construct(
		private InternalSongMapper $songMapper,
		private InternalSongFileMapper $songFileMapper,
		private LibraryMapper $libraryMapper,
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private LoggerInterface $logger,
		private ?InternalEntityMapper $entityMapper = null,
		private ?InternalSongBookmarkMapper $bookmarkMapper = null,
	) {
	}

	/**
	 * Reconcile physical files in the library directory with the internal database.
	 *
	 * @return array{indexed: int, pruned: int}
	 */
	public function reconcile(
		InternalLibrary|array|int $library,
		?callable $onProgress = null,
		bool $importPdfMetadata = false,
	): array {
		$lib = $library instanceof InternalLibrary ? $library : $this->libraryMapper->resolveLibrary($library);
		if ($lib->id === null) {
			return ['indexed' => 0, 'pruned' => 0];
		}

		$user = $this->userManager->get($lib->userId);
		if ($user === null) {
			return ['indexed' => 0, 'pruned' => 0];
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$cleanLibPath = ltrim($lib->path, '/');
		if (str_ends_with(strtolower($cleanLibPath), '.db')) {
			$cleanLibPath = dirname($cleanLibPath);
			if ($cleanLibPath === '.') {
				$cleanLibPath = '';
			}
		}

		if ($cleanLibPath === '') {
			$libFolderNode = $userFolder;
		} else {
			if (!$userFolder->nodeExists($cleanLibPath)) {
				return ['indexed' => 0, 'pruned' => 0];
			}
			$libFolderNode = $userFolder->get($cleanLibPath);
		}

		if (!($libFolderNode instanceof Folder)) {
			return ['indexed' => 0, 'pruned' => 0];
		}

		// 1. Collect all physical music files in the library folder
		$physicalFiles = []; // fileId => File
		$this->collectMusicFiles($libFolderNode, $physicalFiles);

		// 2. Load existing tracked files from database
		$trackedFiles = $this->songFileMapper->findAllForLibrary($lib->id, includeDeleted: false);
		$trackedByFileId = [];
		foreach ($trackedFiles as $tf) {
			$trackedByFileId[$tf->fileId] = $tf;
		}

		$indexedCount = 0;
		$prunedCount = 0;
		$updatedFactsCount = 0;
		$totalPhysical = count($physicalFiles);
		$currentIndex = 0;
		$entityCache = [];

		// 3. Index unindexed physical files and update changed facts
		foreach ($physicalFiles as $fileId => $fileNode) {
			$currentIndex++;
			if ($onProgress !== null) {
				$onProgress('reconcile', $currentIndex, $totalPhysical, $fileNode->getName());
			}

			if (isset($trackedByFileId[$fileId])) {
				if ($this->updateFileFactsIfChanged($lib, $trackedByFileId[$fileId], $fileNode)) {
					$updatedFactsCount++;
				}
				continue;
			}

			$this->indexFile($lib, $fileNode, $importPdfMetadata, $entityCache);
			$indexedCount++;
		}

		// 4. Do not prune tracked files whose physical file is no longer present.
		// Missing physical files must remain attached as tracked files so the integrity check
		// can report them as missing files. Only manual user action should detach or delete them.

		if ($indexedCount > 0 || $updatedFactsCount > 0) {
			$this->libraryMapper->touch($lib->id);
		}

		return [
			'indexed' => $indexedCount,
			'pruned' => $prunedCount,
		];
	}

	public function updateFileFactsIfChanged(InternalLibrary $library, InternalSongFile $songFile, File $fileNode): bool {
		$fileSize = $fileNode->getSize();
		$nodeMtime = $fileNode->getMTime();
		$filename = $fileNode->getName();

		$needsUpdate = false;
		if ($songFile->fileSize !== $fileSize) {
			$needsUpdate = true;
		}

		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		$hash = $songFile->fileHash;
		$pageCount = $songFile->pageCount ?? 1;
		$pageOrder = $songFile->pageOrder;

		if ($needsUpdate || $hash === null) {
			if ($ext === 'pdf') {
				[$newHash, $newPageCount] = $this->extractPdfFacts($fileNode);
				if ($newHash !== null && ($hash !== $newHash || $pageCount !== $newPageCount)) {
					$hash = $newHash;
					$pageOrder = InternalSongFile::refreshPageOrder($pageOrder, $pageCount, $newPageCount);
					$pageCount = $newPageCount;
					$needsUpdate = true;
				}
			}
		}

		if ($needsUpdate) {
			$songFile->fileSize = $fileSize;
			$songFile->fileHash = $hash;
			$songFile->pageCount = $pageCount;
			if (!empty($pageOrder)) {
				$songFile->pageOrder = $pageOrder;
			}
			$songFile->updatedAt = $nodeMtime ?: time();
			$this->songFileMapper->update($songFile);
			return true;
		}

		return false;
	}

	/**
	 * @param array<string, array<string, int>> $entityCache
	 */
	public function indexFile(
		InternalLibrary $library,
		File $fileNode,
		bool $importPdfMetadata = false,
		array &$entityCache = [],
	): void {
		$filename = $fileNode->getName();
		$title = $this->extractTitleFromFilename($filename);
		$pdfMetadata = null;
		$pdfBookmarks = [];

		// Calculate facts
		$fileSize = $fileNode->getSize();
		$hash = null;
		$pageCount = 1;
		$pageOrder = MobileSheetsPdf::pageOrder(1);

		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		if ($ext === 'pdf') {
			try {
				$content = $fileNode->getContent();
				if ($content !== false && $content !== '') {
					$hash = MobileSheetsPdf::contentHash($content);
					$pageCount = MobileSheetsPdf::pageCount($content);
					$pageOrder = MobileSheetsPdf::pageOrder($pageCount);

					if ($importPdfMetadata) {
						try {
							$pdfMetadata = MobileSheetsPdfMetadata::prefillFor($content);
						} catch (\Throwable $e) {
							$this->logger->info('Could not parse PDF metadata: ' . $filename, ['exception' => $e]);
						}

						try {
							$pdfBookmarks = MobileSheetsPdf::bookmarks($content);
						} catch (\Throwable $e) {
							$this->logger->info('Could not extract PDF bookmarks: ' . $filename, ['exception' => $e]);
						}
					}
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Failed to compute PDF metadata: ' . $filename, ['exception' => $e]);
			}
		}

		if ($pdfMetadata !== null && trim($pdfMetadata['title']) !== '') {
			$title = trim($pdfMetadata['title']);
		}

		// Check if song with that title already exists in the library
		$song = $this->songMapper->findByTitle($library->id, $title);
		if ($song === null) {
			$song = new InternalSong(
				uuid: InternalEntity::generateUuid(),
				libraryId: $library->id,
				title: $title,
				sortTitle: $title,
				createdAt: time(),
				updatedAt: time(),
			);
			$song = $this->songMapper->insert($song);
		}

		$songFile = new InternalSongFile(
			uuid: InternalEntity::generateUuid(),
			songId: $song->id,
			fileId: $fileNode->getId(),
			pageOrder: $pageOrder,
			pageCount: $pageCount,
			fileSize: $fileSize,
			fileHash: $hash,
			type: InternalSongFile::determineFileType($filename),
			createdAt: time(),
			updatedAt: time(),
		);

		$this->songFileMapper->insert($songFile);

		if ($importPdfMetadata && $pdfMetadata !== null && $this->entityMapper !== null && $song->id !== null) {
			$this->assignPdfEntitiesToSong($library->id, $song->id, $pdfMetadata, $entityCache);
		}

		if ($importPdfMetadata && !empty($pdfBookmarks) && $this->bookmarkMapper !== null && $song->id !== null) {
			$existingBookmarks = $this->bookmarkMapper->findForSong($song->id);
			if (empty($existingBookmarks)) {
				$this->bookmarkMapper->setBookmarksForSong($song->id, $pdfBookmarks);
			}
		}
	}

	/**
	 * @param array{title: string, composers: list<string>, books: list<string>, collections: list<string>, artists: list<string>} $pdfMetadata
	 * @param array<string, array<string, int>> $entityCache
	 */
	private function assignPdfEntitiesToSong(
		int $libraryId,
		int $songId,
		array $pdfMetadata,
		array &$entityCache,
	): void {
		if ($this->entityMapper === null) {
			return;
		}

		$mapping = [
			'composer' => $pdfMetadata['composers'] ?? [],
			'collection' => $pdfMetadata['collections'] ?? [],
			'book' => $pdfMetadata['books'] ?? [],
			'artist' => $pdfMetadata['artists'] ?? [],
		];

		foreach ($mapping as $type => $names) {
			foreach ($names as $name) {
				$cleanName = trim((string)$name);
				if ($cleanName === '') {
					continue;
				}

				$entityId = $this->resolveOrCreateEntity($libraryId, $type, $cleanName, $entityCache);
				if ($entityId !== null) {
					$this->entityMapper->linkSongEntity($songId, $entityId);
				}
			}
		}
	}

	/**
	 * Find an existing entity by type and name in the library, or create a new one.
	 * Caches results in $entityCache to guarantee identical names share the exact same entity ID.
	 *
	 * @param array<string, array<string, int>> $entityCache
	 */
	public function resolveOrCreateEntity(
		int $libraryId,
		string $type,
		string $name,
		array &$entityCache = [],
	): ?int {
		if ($this->entityMapper === null) {
			return null;
		}

		$normType = InternalEntityMapper::normalizeType($type);
		$lookupKey = mb_strtolower(trim($name), 'UTF-8');
		if ($lookupKey === '') {
			return null;
		}

		if (isset($entityCache[$normType][$lookupKey])) {
			return $entityCache[$normType][$lookupKey];
		}

		$existing = $this->entityMapper->findByName($libraryId, $normType, $name);
		if ($existing !== null && $existing->id !== null) {
			$entityCache[$normType][$lookupKey] = $existing->id;
			return $existing->id;
		}

		// Check if an existing entity in the library matches case-insensitively
		$allInLib = $this->entityMapper->findAllForLibrary($libraryId, $normType);
		foreach ($allInLib as $candidate) {
			if ($candidate->id !== null && mb_strtolower($candidate->name, 'UTF-8') === $lookupKey) {
				$entityCache[$normType][$lookupKey] = $candidate->id;
				return $candidate->id;
			}
		}

		$newEntity = new InternalEntity(
			id: null,
			uuid: InternalEntity::generateUuid(),
			libraryId: $libraryId,
			type: $normType,
			name: $name,
			createdAt: time(),
			updatedAt: time(),
		);

		$created = $this->entityMapper->insert($newEntity);
		if ($created->id !== null) {
			$entityCache[$normType][$lookupKey] = $created->id;
			return $created->id;
		}

		return null;
	}

	/**
	 * @return array{?int, int, string} [hash, pageCount, pageOrder]
	 */
	private function extractPdfFacts(File $fileNode): array {
		try {
			$content = $fileNode->getContent();
			if ($content !== false && $content !== '') {
				$hash = MobileSheetsPdf::contentHash($content);
				$pageCount = MobileSheetsPdf::pageCount($content);
				$pageOrder = MobileSheetsPdf::pageOrder($pageCount);
				return [$hash, $pageCount, $pageOrder];
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to compute PDF metadata: ' . $fileNode->getName(), ['exception' => $e]);
		}

		return [null, 1, MobileSheetsPdf::pageOrder(1)];
	}

	public function extractTitleFromFilename(string $filename): string {
		$base = pathinfo($filename, PATHINFO_FILENAME);
		// Remove 3-digit index prefixes like "001 "
		$cleaned = preg_replace('/^\d{3}\s+/', '', $base);
		// Replace underscores with spaces
		$cleaned = str_replace('_', ' ', $cleaned ?? $base);

		return trim($cleaned);
	}

	public function isIgnoredFilename(string $filename): bool {
		$lower = strtolower($filename);
		if (in_array($lower, self::IGNORED_NAMES, true)) {
			return true;
		}
		if (str_ends_with($lower, '.db') || str_ends_with($lower, '_hashcodes.txt')) {
			return true;
		}
		return false;
	}

	/**
	 * @param array<int, File> $result
	 */
	private function collectMusicFiles(Folder $folder, array &$result): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File) {
				$name = $node->getName();
				if ($this->isIgnoredFilename($name)) {
					continue;
				}

				$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
				if ($this->isMusicExtension($ext)) {
					$result[$node->getId()] = $node;
				}
			} elseif ($node instanceof Folder) {
				$this->collectMusicFiles($node, $result);
			}
		}
	}

	private function isMusicExtension(string $extension): bool {
		return match ($extension) {
			'pdf', 'png', 'jpg', 'jpeg', 'bmp', 'gif', 'webp', 'svg',
			'txt', 'cho', 'chopro', 'crd', 'chordpro', 'abc',
			'mp3', 'm4a', 'ogg', 'flac', 'wav' => true,
			default => false,
		};
	}
}
