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
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPathResolver;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf;
use OCP\Files\File;
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
				$actualPageCount = $pageCount;

				// Verify physical file if stored count is only 1 page
				if ($pageCount <= 1) {
					try {
						$pathObj = $this->pathResolver->fromStored($path, $user);
						if ($pathObj !== null) {
							$relativeToTarget = $this->pathResolver->relativeToFolder($pathObj->internal, $targetFolderPath);
							$cleanRelative = ltrim($relativeToTarget, '/');
							if ($cleanRelative !== '' && $targetFolder->nodeExists($cleanRelative)) {
								$node = $targetFolder->get($cleanRelative);
								if ($node instanceof File) {
									$content = $node->getContent();
									if (is_string($content) && $content !== '') {
										$detected = MobileSheetsPdf::pageCount($content);
										if ($detected > 1) {
											$actualPageCount = $detected;
										}
									}
								}
							}
						}
					} catch (\Throwable) {
						// Fall back to recorded pageCount
					}
				}

				$pageOrder = trim((string)($file->pageOrder ?? ''));
				if ($pageOrder === '') {
					$pageOrder = MobileSheetsPdf::pageOrder($actualPageCount);
				}

				$suggestedPageOrder = MobileSheetsPdf::pageOrder($actualPageCount);
				$isStuckOneToOne = ($pageOrder === '1-1' || $pageOrder === '1') && $actualPageCount > 1;
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
					'actualPageCount' => $actualPageCount,
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
					if (($pageOrder === '1-1' || $pageOrder === '1') && $pageCount > 1) {
						$filesToReset[] = ['song' => $song, 'file' => $file];
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

			// Determine page count
			$actualPageCount = max(1, $fileRecord->pageCount);
			if ($actualPageCount <= 1) {
				try {
					$pathObj = $this->pathResolver->fromStored($file->path, $user);
					if ($pathObj !== null) {
						$rel = ltrim($this->pathResolver->relativeToFolder($pathObj->internal, $targetFolderPath), '/');
						if ($rel !== '' && $targetFolder->nodeExists($rel)) {
							$node = $targetFolder->get($rel);
							if ($node instanceof File) {
								$cnt = $node->getContent();
								if (is_string($cnt) && $cnt !== '') {
									$actualPageCount = max(1, MobileSheetsPdf::pageCount($cnt));
								}
							}
						}
					}
				} catch (\Throwable) {
					// Keep stored page count
				}
			}

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
}
