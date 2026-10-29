<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service\History;

use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

class SongHistoryFileVersionService {
	public function __construct(
		private InternalSongFileMapper $songFileMapper,
		private LoggerInterface $logger,
		private ?IRootFolder $rootFolder = null,
	) {
	}

	/**
	 * Restore earlier physical file version in Nextcloud files_versions if the file was modified since snapshot.
	 *
	 * @param array<string, mixed> $fileSnap
	 */
	public function restorePhysicalFileVersionIfNeeded(
		int $ocFileId,
		?string $userId,
		array $fileSnap,
		int $snapshotCreatedAt,
		InternalSongFile $fileRecord,
	): bool {
		if ($ocFileId <= 0 || $userId === null || $this->rootFolder === null) {
			return false;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$nodes = $userFolder->getById($ocFileId);
			if (empty($nodes) || !($nodes[0] instanceof \OCP\Files\File)) {
				return false;
			}

			/** @var \OCP\Files\File $targetNode */
			$targetNode = $nodes[0];
			$targetSize = (int)($fileSnap['fileSize'] ?? 0);
			$targetHash = $fileSnap['fileHash'] ?? null;

			// If current physical file already matches the snapshot size (and hash), no rollback is needed
			if ($targetSize > 0 && (int)$targetNode->getSize() === $targetSize) {
				if ($targetHash === null || $fileRecord->fileHash === $targetHash) {
					return true;
				}
			}

			$rolledBack = false;

			// Ensure files_versions root and parent directories exist
			if (class_exists(\OCA\Files_Versions\Storage::class)) {
				$userRoot = $userFolder->getParent();
				if (!$userRoot->nodeExists('files_versions')) {
					$userRoot->newFolder('files_versions');
				}
				$relativePath = $userFolder->getRelativePath($targetNode->getPath());
				if ($relativePath !== null && $relativePath !== false) {
					$userView = new \OC\Files\View('/' . $userId);
					\OCA\Files_Versions\Storage::createMissingDirectories($relativePath, $userView);
				}
			}

			// 1. Try IVersionManager
			if ((interface_exists(\OCA\Files_Versions\Versions\IVersionManager::class) || class_exists(\OCA\Files_Versions\Versions\IVersionManager::class))
				&& \OC::$server->has(\OCA\Files_Versions\Versions\IVersionManager::class)
				&& \OC::$server->has(\OCP\IUserManager::class)) {
				/** @var \OCA\Files_Versions\Versions\IVersionManager $versionManager */
				$versionManager = \OC::$server->get(\OCA\Files_Versions\Versions\IVersionManager::class);
				/** @var \OCP\IUserManager $userManager */
				$userManager = \OC::$server->get(\OCP\IUserManager::class);
				$user = $userManager->get($userId);
				if ($user !== null) {
					$versions = $versionManager->getVersionsForFile($user, $targetNode);

					$candidate = null;
					if ($targetSize > 0) {
						foreach ($versions as $v) {
							if ((int)$v->getSize() === $targetSize) {
								$candidate = $v;
								break;
							}
						}
					}

					if ($candidate === null) {
						foreach ($versions as $v) {
							if ((int)$v->getRevisionId() <= ($snapshotCreatedAt + 5)) {
								$candidate = $v;
								break;
							}
						}
					}

					if ($candidate !== null) {
						$res = $versionManager->rollback($candidate);
						if ($res !== false) {
							$rolledBack = true;
						}
					}
				}
			}

			// 2. Fallback to Storage::rollback
			if (!$rolledBack && class_exists(\OCA\Files_Versions\Storage::class)
				&& \OC::$server->has(\OCP\IUserManager::class)) {
				/** @var \OCP\IUserManager $userManager */
				$userManager = \OC::$server->get(\OCP\IUserManager::class);
				$user = $userManager->get($userId);
				if ($user !== null) {
					$relativePath = $userFolder->getRelativePath($targetNode->getPath());
					if ($relativePath !== null && $relativePath !== false) {
						$rawVersions = \OCA\Files_Versions\Storage::getVersions($userId, '/' . ltrim($relativePath, '/'));
						$targetRevision = null;
						if ($targetSize > 0) {
							foreach ($rawVersions as $key => $v) {
								if ((int)($v['size'] ?? 0) === $targetSize) {
									$targetRevision = (int)($v['version'] ?? $key);
									break;
								}
							}
						}
						if ($targetRevision === null) {
							foreach ($rawVersions as $key => $v) {
								$rev = (int)($v['version'] ?? $key);
								if ($rev <= ($snapshotCreatedAt + 5)) {
									$targetRevision = $rev;
									break;
								}
							}
						}

						if ($targetRevision !== null) {
							$res = \OCA\Files_Versions\Storage::rollback('/' . ltrim($relativePath, '/'), $targetRevision, $user);
							if ($res !== false) {
								$rolledBack = true;
							}
						}
					}
				}
			}

			if ($rolledBack) {
				// Re-extract facts and update fileRecord
				$freshNodes = $userFolder->getById($ocFileId);
				if (!empty($freshNodes) && $freshNodes[0] instanceof \OCP\Files\File) {
					$content = $freshNodes[0]->getContent();
					if (is_string($content)) {
						$fileRecord->fileSize = strlen($content);
						$ext = strtolower(pathinfo($freshNodes[0]->getName(), PATHINFO_EXTENSION));
						if ($ext === 'pdf') {
							try {
								$fileRecord->fileHash = MobileSheetsPdf::contentHash($content);
								$fileRecord->pageCount = MobileSheetsPdf::pageCount($content);
								$fileRecord->pageOrder = MobileSheetsPdf::pageOrder($fileRecord->pageCount);
							} catch (\Throwable) {
								// Keep existing
							}
						}
						$fileRecord->updatedAt = time();
						$this->songFileMapper->update($fileRecord);
					}
				}

				$this->logger->info('Reverted physical file version during song history revert', [
					'fileId' => $ocFileId,
					'userId' => $userId,
					'targetSize' => $targetSize,
				]);
				return true;
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not revert physical file version during song history revert: ' . $e->getMessage(), [
				'exception' => $e,
				'fileId' => $ocFileId,
				'userId' => $userId,
			]);
		}

		return false;
	}
}
