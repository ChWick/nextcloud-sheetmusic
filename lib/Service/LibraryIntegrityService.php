<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Service;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

class LibraryIntegrityService {
	public function __construct(
		private LibraryService $libraryService,
		private SongMapper $songMapper,
		private FileManager $fileManager,
		private IRootFolder $rootFolder,
		private MobileSheetsPathResolver $pathResolver,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Perform a full integrity audit on a MobileSheets library.
	 *
	 * Checks:
	 * 1. Missing files (database points to a file path that does not exist in Nextcloud).
	 * 2. Duplicate file paths (multiple database file records pointing to the exact same physical path).
	 * 3. Naming rule mismatches (files that deviate from the standard naming conventions).
	 * 4. Orphan files (files present in the library directory structure not referenced by any song).
	 *
	 * @return list<IntegrityIssue>
	 */
	public function checkIntegrity(int $libraryId, string $userId): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$user = $userFolder->getOwner();
		['databasePath' => $databasePath, 'targetFolder' => $targetFolder] = $this->fileManager->libraryContext($libraryId, $user);

		$songs = $this->songMapper->findSongs($databasePath);
		$targetFolderPath = (string) $targetFolder->getPath();

		$issues = [];
		$referencedRelativePaths = []; // relativeToTarget => list<array{songId: int, songTitle: string, fileId: int}>

		foreach ($songs as $song) {
			foreach ($song->files as $file) {
				$pathObj = $this->pathResolver->fromStored($file->path, $user);
				if ($pathObj === null) {
					$issues[] = new IntegrityIssue(
						type: IntegrityIssue::TYPE_MISSING_FILE,
						severity: IntegrityIssue::SEVERITY_ERROR,
						message: sprintf('File path "%s" sits outside the library folder.', $file->path),
						songId: $song->id,
						songTitle: $song->title,
						fileId: $file->id,
						path: $file->path,
						fixable: false,
					);
					continue;
				}

				// Compute path relative to the library target folder
				$internalPath = $pathObj->internal;
				$relativeToTarget = null;
				if ($internalPath === $targetFolderPath) {
					$relativeToTarget = '';
				} elseif (str_starts_with($internalPath, $targetFolderPath . '/')) {
					$relativeToTarget = substr($internalPath, strlen($targetFolderPath) + 1);
				}

				$sourceExists = ($relativeToTarget !== null && $targetFolder->nodeExists($relativeToTarget));

				// Calculate valid target paths (naming rules)
				$validTargetPaths = [];
				try {
					$validTargetPaths = createTargetPaths(
						$song,
						getFileExtension($file->path),
						fileIndexOf($song, $file),
						fileCountForExtension($song, $file),
					);
				} catch (NoNamingRuleException) {
					// Song missing composer, artist, or collection - keeps existing name
				}

				// Check if the expected target file already exists on disk
				$targetExists = false;
				if (!empty($validTargetPaths)) {
					foreach ($validTargetPaths as $validPath) {
						$cleanValid = ltrim($validPath, '/');
						if ($targetFolder->nodeExists($cleanValid)) {
							$targetExists = true;
							// Mark target file as referenced by song so it is not flagged as an orphan
							$referencedRelativePaths[$cleanValid][] = [
								'songId' => $song->id,
								'songTitle' => $song->title,
								'fileId' => $file->id,
							];
							break;
						}
					}
				}

				if ($sourceExists) {
					$referencedRelativePaths[$relativeToTarget][] = [
						'songId' => $song->id,
						'songTitle' => $song->title,
						'fileId' => $file->id,
					];

					// Check naming rule compliance
					$isCompliant = false;
					$normalizedRelative = strtolower(ltrim($relativeToTarget, '/'));
					foreach ($validTargetPaths as $validPath) {
						if ($normalizedRelative === strtolower(ltrim($validPath, '/'))) {
							$isCompliant = true;
							break;
						}
					}

					if (!$isCompliant) {
						$issues[] = new IntegrityIssue(
							type: IntegrityIssue::TYPE_INVALID_NAMING_RULE,
							severity: IntegrityIssue::SEVERITY_WARNING,
							message: sprintf('File path "%s" does not match standard naming rule.', basename($file->path)),
							songId: $song->id,
							songTitle: $song->title,
							fileId: $file->id,
							path: $relativeToTarget,
							suggestedPath: $validTargetPaths[0] ?? null,
							fixable: true,
						);
					}
				} else {
					// Source file does not exist at stored path.
					// If the target file ALREADY exists at the expected naming-rule path on disk:
					if ($targetExists) {
						$issues[] = new IntegrityIssue(
							type: IntegrityIssue::TYPE_INVALID_NAMING_RULE,
							severity: IntegrityIssue::SEVERITY_WARNING,
							message: sprintf('Database path "%s" is outdated; file already exists at the standard naming path.', basename($file->path)),
							songId: $song->id,
							songTitle: $song->title,
							fileId: $file->id,
							path: $relativeToTarget ?? $file->path,
							suggestedPath: $validTargetPaths[0] ?? null,
							fixable: true,
						);
					} else {
						// Genuinely missing file (neither source nor target exist on disk)
						$issues[] = new IntegrityIssue(
							type: IntegrityIssue::TYPE_MISSING_FILE,
							severity: IntegrityIssue::SEVERITY_ERROR,
							message: sprintf('File "%s" referenced by song "%s" does not exist in Nextcloud storage.', basename($file->path), $song->title),
							songId: $song->id,
							songTitle: $song->title,
							fileId: $file->id,
							path: $relativeToTarget ?? $file->path,
							fixable: false,
						);
					}
				}
			}
		}

		// Check for duplicate physical file references
		foreach ($referencedRelativePaths as $relPath => $references) {
			if (count($references) > 1) {
				foreach ($references as $ref) {
					$issues[] = new IntegrityIssue(
						type: IntegrityIssue::TYPE_DUPLICATE_FILE_PATH,
						severity: IntegrityIssue::SEVERITY_ERROR,
						message: sprintf('Multiple database entries point to the same file path "%s".', basename($relPath)),
						songId: $ref['songId'],
						songTitle: $ref['songTitle'],
						fileId: $ref['fileId'],
						path: $relPath,
						fixable: true,
					);
				}
			}
		}

		// Scan folder for orphan files (files in storage not in DB)
		$this->scanOrphans($targetFolder, $targetFolderPath, $referencedRelativePaths, $issues);

		return $issues;
	}

	/**
	 * Automatically resolve/fix a specific integrity issue.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function fixIssue(int $libraryId, string $userId, string $issueType, ?int $songId = null, ?int $fileId = null): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$user = $userFolder->getOwner();

		if ($issueType === IntegrityIssue::TYPE_INVALID_NAMING_RULE && $songId !== null) {
			['databasePath' => $databasePath, 'targetFolder' => $targetFolder] = $this->fileManager->libraryContext($libraryId, $user);
			$hashCodes = MobileSheetsHashCodesFile::forDatabase($databasePath, $this->logger);

			try {
				$this->fileManager->regenerateSongFilePaths($songId, $user, $targetFolder, $databasePath, $hashCodes);
				$hashCodes?->save();

				return ['success' => true, 'message' => sprintf('Regenerated file path for song ID %d.', $songId)];
			} catch (\Throwable $e) {
				return ['success' => false, 'message' => sprintf('Cannot fix naming rule for song ID %d: %s', $songId, $e->getMessage())];
			}
		}

		if ($issueType === IntegrityIssue::TYPE_DUPLICATE_FILE_PATH && $songId !== null) {
			['databasePath' => $databasePath, 'targetFolder' => $targetFolder] = $this->fileManager->libraryContext($libraryId, $user);
			$hashCodes = MobileSheetsHashCodesFile::forDatabase($databasePath, $this->logger);

			try {
				$this->fileManager->regenerateSongFilePaths($songId, $user, $targetFolder, $databasePath, $hashCodes);
				$hashCodes?->save();

				return ['success' => true, 'message' => sprintf('Assigned unique file path to duplicate entry for song ID %d.', $songId)];
			} catch (\Throwable $e) {
				return ['success' => false, 'message' => sprintf('Cannot resolve duplicate path for song ID %d: %s', $songId, $e->getMessage())];
			}
		}

		return ['success' => false, 'message' => 'Unrecognized or unfixable issue type.'];
	}

	/**
	 * Recursively check targetFolder for files not present in DB.
	 *
	 * @param array<string, mixed> $referencedRelativePaths
	 * @param list<IntegrityIssue> $issues
	 */
	private function scanOrphans(Folder $folder, string $targetFolderPath, array $referencedRelativePaths, array &$issues): void {
		$nodes = $folder->getDirectoryListing();
		foreach ($nodes as $node) {
			if ($node instanceof Folder) {
				$this->scanOrphans($node, $targetFolderPath, $referencedRelativePaths, $issues);
			} elseif ($node instanceof Node) {
				$nodePath = $node->getPath();
				$relativeToTarget = str_starts_with($nodePath, $targetFolderPath . '/')
					? substr($nodePath, strlen($targetFolderPath) + 1)
					: $node->getName();
				$filename = $node->getName();

				// Ignore database file and hash codes file
				if ($filename === 'mobilesheets.db' || str_ends_with($filename, '.db') || $filename === 'hashcodes.txt') {
					continue;
				}

				if (!isset($referencedRelativePaths[$relativeToTarget])) {
					$issues[] = new IntegrityIssue(
						type: IntegrityIssue::TYPE_ORPHAN_FILE,
						severity: IntegrityIssue::SEVERITY_WARNING,
						message: sprintf('File "%s" exists in library storage but is not linked to any song.', $filename),
						path: $relativeToTarget,
						fixable: false,
					);
				}
			}
		}
	}
}
