<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongHistory;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFileFacts;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPathResolver;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class LibraryToolService {
	public function __construct(
		private InternalSongMapper $internalSongMapper,
		private InternalSongFileMapper $internalSongFileMapper,
		private LibraryMapper $libraryMapper,
		private FileManager $fileManager,
		private IRootFolder $rootFolder,
		private MobileSheetsPathResolver $pathResolver,
		private LibraryService $libraryService,
		private LoggerInterface $logger,
		private ?SongHistoryService $songHistoryService = null,
	) {
	}

	/**
	 * Audit page orders for all files in a library.
	 *
	 * @return array{
	 *     totalFiles: int,
	 *     stuckCount: int,
	 *     customCount: int,
	 *     normalCount: int,
	 *     items: list<array{
	 *         id: int,
	 *         songId: int,
	 *         songTitle: string,
	 *         fileId: int,
	 *         fileName: string,
	 *         path: string,
	 *         pageOrder: string,
	 *         pageCount: int,
	 *         actualPageCount: int,
	 *         suggestedPageOrder: string,
	 *         isStuckOneToOne: bool,
	 *         isCustom: bool
	 *     }>
	 * }
	 */
	public function auditPageOrders(int $libraryId, IUser $user): array {
		$targetFolder = $this->fileManager->libraryFolder($libraryId, $user);
		$targetFolderPath = (string)$targetFolder->getPath();
		$libraryData = $this->internalSongMapper->findLibraryData($libraryId, $user);

		$items = [];
		$stuckCount = 0;
		$customCount = 0;
		$normalCount = 0;

		foreach ($libraryData->songs as $song) {
			foreach ($song->files as $file) {
				$path = $file->path;
				if (!str_ends_with(strtolower($path), '.pdf')) {
					continue;
				}

				$pageCount = max(1, $file->pageCount);
				$actualPageCount = $this->resolveActualPdfPageCount(
					(int)($file->fileId ?? 0),
					$path,
					$pageCount,
					$user,
					$targetFolder,
					$targetFolderPath,
				);

				// If physical PDF has more pages than stored, heal pageCount in database
				if ($actualPageCount > $file->pageCount && $file->id > 0) {
					try {
						$fileRecord = $this->internalSongFileMapper->findById((int)$file->id);
						if ($fileRecord !== null) {
							$fileRecord->pageCount = $actualPageCount;
							$this->internalSongFileMapper->update($fileRecord);
						}
					} catch (\Throwable) {
					}
				}

				$pageOrder = trim((string)($file->pageOrder ?? ''));
				$effectivePageCount = max($actualPageCount, $pageCount);
				if ($pageOrder === '') {
					$pageOrder = MobileSheetsPdf::pageOrder($effectivePageCount);
				}

				$suggestedPageOrder = MobileSheetsPdf::pageOrder($effectivePageCount);
				$isStuckOneToOne = ($pageOrder === '1-1' || $pageOrder === '1') && $effectivePageCount > 1;
				$isCustom = $pageOrder !== $suggestedPageOrder;

				if ($isStuckOneToOne) {
					$stuckCount++;
				} elseif ($isCustom) {
					$customCount++;
				} else {
					$normalCount++;
				}

				$items[] = [
					'id' => (int)$file->id,
					'songId' => $song->id,
					'songTitle' => $song->title,
					'fileId' => (int)$file->id,
					'fileName' => basename($path),
					'path' => $path,
					'pageOrder' => $pageOrder,
					'pageCount' => $pageCount,
					'actualPageCount' => $effectivePageCount,
					'suggestedPageOrder' => $suggestedPageOrder,
					'isStuckOneToOne' => $isStuckOneToOne,
					'isCustom' => $isCustom,
				];
			}
		}

		// Sort items: stuck 1-1 first, then custom, then alphabetically by song title
		usort($items, static function (array $a, array $b): int {
			if ($a['isStuckOneToOne'] !== $b['isStuckOneToOne']) {
				return $a['isStuckOneToOne'] ? -1 : 1;
			}
			if ($a['isCustom'] !== $b['isCustom']) {
				return $a['isCustom'] ? -1 : 1;
			}
			return strcasecmp($a['songTitle'], $b['songTitle']);
		});

		return [
			'totalFiles' => count($items),
			'stuckCount' => $stuckCount,
			'customCount' => $customCount,
			'normalCount' => $normalCount,
			'items' => $items,
		];
	}

	/**
	 * Reset page orders for specified fileIds or by mode.
	 *
	 * @param list<int>|null $fileIds Specific sheetmusic_song_files IDs to reset
	 * @param string $mode 'stuck' | 'all-1-1' | 'all'
	 * @param callable(string, int, int, string): void|null $onProgress
	 * @return array{success: bool, resetCount: int, message: string}
	 */
	public function resetPageOrders(
		int $libraryId,
		IUser $user,
		?array $fileIds = null,
		string $mode = 'stuck',
		?callable $onProgress = null,
	): array {
		$targetFolder = $this->fileManager->libraryFolder($libraryId, $user);
		$targetFolderPath = (string)$targetFolder->getPath();
		$libraryData = $this->internalSongMapper->findLibraryData($libraryId, $user);

		$filesToReset = [];
		$fileIdSet = $fileIds !== null && count($fileIds) > 0 ? array_flip($fileIds) : null;

		foreach ($libraryData->songs as $song) {
			foreach ($song->files as $file) {
				if (!str_ends_with(strtolower($file->path), '.pdf')) {
					continue;
				}

				$fId = (int)$file->id;
				if ($fileIdSet !== null) {
					if (isset($fileIdSet[$fId])) {
						$filesToReset[] = ['song' => $song, 'file' => $file];
					}
					continue;
				}

				// Otherwise filter by mode
				$pageOrder = trim((string)($file->pageOrder ?? ''));
				$pageCount = max(1, $file->pageCount);

				if ($mode === 'stuck') {
					$effective = $pageCount;
					$resolvedFromPdf = null;
					if ($effective <= 1) {
						$effective = $this->resolveActualPdfPageCount(
							(int)($file->fileId ?? 0),
							$file->path,
							$pageCount,
							$user,
							$targetFolder,
							$targetFolderPath,
						);
						$resolvedFromPdf = $effective;
					}
					if (($pageOrder === '1-1' || $pageOrder === '1') && $effective > 1) {
						$filesToReset[] = ['song' => $song, 'file' => $file, 'actualPageCount' => $resolvedFromPdf];
					}
				} elseif ($mode === 'all-1-1') {
					if ($pageOrder === '1-1' || $pageOrder === '1') {
						$filesToReset[] = ['song' => $song, 'file' => $file];
					}
				} elseif ($mode === 'all') {
					$filesToReset[] = ['song' => $song, 'file' => $file];
				}
			}
		}

		$total = count($filesToReset);
		if ($total === 0) {
			return [
				'success' => true,
				'resetCount' => 0,
				'message' => 'No matching files to reset.',
			];
		}

		$resetCount = 0;
		$now = time();
		$touchedSongs = [];

		foreach ($filesToReset as $index => $item) {
			$song = $item['song'];
			$file = $item['file'];
			$fileRecord = $this->internalSongFileMapper->findById((int)$file->id);
			if ($fileRecord === null) {
				continue;
			}

			if ($onProgress !== null) {
				$onProgress('resetting', $index + 1, $total, sprintf('Resetting page order for "%s"…', $song->title));
			}

			// Determine page count (reuse pre-resolved count from filter pass when available)
			$storedCount = max(1, $fileRecord->pageCount);
			$actualPageCount = isset($item['actualPageCount']) && is_int($item['actualPageCount']) && $item['actualPageCount'] > 0
				? $item['actualPageCount']
				: $this->resolveActualPdfPageCount(
					(int)$fileRecord->fileId,
					$file->path,
					$storedCount,
					$user,
					$targetFolder,
					$targetFolderPath,
				);

			$newOrder = MobileSheetsPdf::pageOrder($actualPageCount);
			$fileRecord->pageCount = $actualPageCount;
			$fileRecord->pageOrder = $newOrder;
			$fileRecord->updatedAt = $now;
			$this->internalSongFileMapper->update($fileRecord);
			$resetCount++;

			$touchedSongs[$song->id] = $song;
		}

		foreach ($touchedSongs as $song) {
			$songRecord = $this->internalSongMapper->findById($song->id);
			if ($songRecord !== null) {
				$songRecord->updatedAt = $now;
				$this->internalSongMapper->update($songRecord);
				if ($this->songHistoryService !== null) {
					try {
						$this->songHistoryService->recordSnapshot(
							$songRecord,
							InternalSongHistory::ACTION_UPDATED,
							$user->getUID(),
							'Reset page ordering to default full range'
						);
					} catch (\Throwable $e) {
						$this->logger->warning('Failed to record snapshot for page order reset: ' . $e->getMessage());
					}
				}
			}
		}

		$this->libraryMapper->touch($libraryId);

		return [
			'success' => true,
			'resetCount' => $resetCount,
			'message' => sprintf('Successfully reset page ordering for %d file(s).', $resetCount),
		];
	}

	/**
	 * Reliably resolve the physical PDF file node and extract its true page count.
	 */
	public function resolveActualPdfPageCount(
		int $fileId,
		string $path,
		int $storedPageCount,
		IUser $user,
		?Folder $targetFolder,
		string $targetFolderPath,
	): int {
		$actual = max(1, $storedPageCount);

		try {
			$node = null;
			$pathObj = $this->pathResolver->fromStored($path, $user);

			// 1. Fast path: resolve relative to the already-loaded library folder
			if ($pathObj !== null && $targetFolder !== null) {
				$relativeToTarget = $this->pathResolver->relativeToFolder($pathObj->internal, $targetFolderPath);
				if ($relativeToTarget !== null) {
					$cleanRelative = ltrim($relativeToTarget, '/');
					if ($cleanRelative !== '') {
						try {
							$candidate = $targetFolder->get($cleanRelative);
							if ($candidate instanceof File) {
								$node = $candidate;
							}
						} catch (\Throwable) {
						}
					}
				}
			}

			// 2. Fallback: direct Nextcloud fileId lookup in user folder or root folder
			if ($node === null && $fileId > 0) {
				try {
					$userFolder = $this->rootFolder->getUserFolder($user->getUID());
					$nodes = $userFolder->getById($fileId);
					if (!empty($nodes) && $nodes[0] instanceof File) {
						$node = $nodes[0];
					} else {
						$rootNodes = $this->rootFolder->getById($fileId);
						if (!empty($rootNodes) && $rootNodes[0] instanceof File && !str_starts_with($rootNodes[0]->getInternalPath(), 'files_trashbin/')) {
							$node = $rootNodes[0];
						}
					}
				} catch (\Throwable) {
					// Fall through to rootFolder path resolution
				}
			}

			// 3. Fallback: rootFolder internal path lookup
			if ($node === null && $pathObj !== null) {
				try {
					$candidate = $this->rootFolder->get($pathObj->internal);
					if ($candidate instanceof File) {
						$node = $candidate;
					}
				} catch (\Throwable) {
				}
			}

			// 4. Extract actual page count from physical PDF content
			if ($node instanceof File) {
				$content = MobileSheetsFileFacts::readFileContent($node);
				if ($content !== '') {
					$detected = MobileSheetsPdf::pageCount($content);
					if ($detected > 0) {
						$actual = $detected;
					}
				}
			}
		} catch (\Throwable) {
		}

		return $actual;
	}
}
