<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalMiscMapper;
use OCA\SheetMusic\Db\InternalSongAttributeMapper;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFileFacts;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsHashCodesFile;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPathResolver;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsTableHelper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

class LibraryIntegrityFixer {
	public function __construct(
		private LibraryService $libraryService,
		private FileManager $fileManager,
		private IRootFolder $rootFolder,
		private MobileSheetsPathResolver $pathResolver,
		private LoggerInterface $logger,
		private InternalEntityMapper $internalEntityMapper,
		private InternalSongMapper $internalSongMapper,
		private InternalSongFileMapper $internalSongFileMapper,
		private LibraryMapper $libraryMapper,
		private InternalSongAttributeMapper $internalSongAttributeMapper,
		private InternalMiscMapper $internalMiscMapper,
		private LibraryOrphanScanner $orphanScanner,
		private ?LibraryIntegrityService $integrityService = null,
	) {
	}

	public function setIntegrityService(LibraryIntegrityService $integrityService): void {
		$this->integrityService = $integrityService;
	}

	/**
	 * @return list<IntegrityIssue>
	 */
	private function checkIntegrity(int $libraryId, string $userId): array {
		if ($this->integrityService === null) {
			return [];
		}

		return $this->integrityService->checkIntegrity($libraryId, $userId);
	}

	/**
	 * Automatically resolve/fix a single integrity issue.
	 *
	 * @return array{success: bool, fixedCount: int, message: string}
	 */
	public function fixIssue(
		int $libraryId,
		string $userId,
		string $issueType,
		?int $songId = null,
		?int $fileId = null,
		?string $entityType = null,
		?int $entityId = null,
		?string $path = null,
	): array {
		if ($issueType === IntegrityIssue::TYPE_MISSING_FILE && ($fileId !== null || ($path !== null && trim($path) !== ''))) {
			$fixed = 0;
			if ($fileId !== null) {
				$fileRecord = $this->internalSongFileMapper->findById($fileId);
				if ($fileRecord !== null) {
					$this->internalSongFileMapper->delete($fileId, hardDelete: false);
					$fixed++;
				}
			}

			try {
				$userFolder = $this->rootFolder->getUserFolder($userId);
				$user = $userFolder->getOwner();
				['databasePath' => $databasePath] = $this->fileManager->libraryContext($libraryId, $user);
				if ($databasePath !== '' && file_exists($databasePath)) {
					$db = new \PDO('sqlite:' . $databasePath);
					$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
					if (MobileSheetsTableHelper::tableExists($db, 'Files')) {
						$stmt = $db->prepare('DELETE FROM Files WHERE Id = :id OR (Path = :path AND :path != \'\')');
						$stmt->execute([
							':id' => $fileId ?? -1,
							':path' => $path ?? '',
						]);
						if ($stmt->rowCount() > 0) {
							$fixed += $stmt->rowCount();
						}
					}
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Could not remove file from SQLite database: ' . $e->getMessage());
			}

			if ($fixed > 0) {
				$this->libraryMapper->touch($libraryId);

				return [
					'success' => true,
					'fixedCount' => $fixed,
					'message' => 'Missing file record removed.',
				];
			}

			return [
				'success' => false,
				'fixedCount' => 0,
				'message' => 'Missing file record could not be found.',
			];
		}

		if ($issueType === IntegrityIssue::TYPE_ORPHAN_FILE) {
			if ($path === null || trim($path) === '') {
				return [
					'success' => false,
					'fixedCount' => 0,
					'message' => 'Orphan file path is required.',
				];
			}

			return $this->fixOrphanFileIssue($libraryId, $userId, $path, $songId);
		}

		if ($issueType === IntegrityIssue::TYPE_DUPLICATE_ENTITY && $entityType !== null && $entityId !== null) {
			$normType = InternalEntityMapper::normalizeType($entityType);
			foreach ($this->internalEntityMapper->findDuplicateEntities($libraryId) as $duplicateEntity) {
				if ($duplicateEntity->entityType !== $normType || $duplicateEntity->keepId !== $entityId) {
					continue;
				}

				$merged = $this->internalEntityMapper->mergeEntities(
					$libraryId,
					$normType,
					$entityId,
					$duplicateEntity->duplicateIds,
				);
				$this->libraryMapper->touch($libraryId);

				return [
					'success' => $merged > 0,
					'fixedCount' => $merged > 0 ? 1 : 0,
					'message' => $merged > 0
						? sprintf('Merged %d duplicate entity record(s) into ID %d.', $merged, $entityId)
						: 'No duplicate entity records remained to fix.',
				];
			}

			return [
				'success' => false,
				'fixedCount' => 0,
				'message' => 'The duplicate entity records were not found in the current library audit.',
			];
		}

		if ($issueType === IntegrityIssue::TYPE_DUPLICATE_ENTITY_ASSIGNMENT && $songId !== null) {
			$isCurrentIssue = false;
			foreach ($this->checkIntegrity($libraryId, $userId) as $issue) {
				if ($issue->type === IntegrityIssue::TYPE_DUPLICATE_ENTITY_ASSIGNMENT && $issue->songId === $songId) {
					$isCurrentIssue = true;
					break;
				}
			}
			if (!$isCurrentIssue) {
				return [
					'success' => false,
					'fixedCount' => 0,
					'message' => 'The duplicate entity assignments were not found in the current library audit.',
				];
			}

			$removed = $this->internalEntityMapper->removeDuplicateSongEntityAssignments($libraryId, $songId);
			$this->libraryMapper->touch($libraryId);

			return [
				'success' => $removed > 0,
				'fixedCount' => $removed > 0 ? 1 : 0,
				'message' => $removed > 0
					? sprintf('Removed %d duplicate entity assignment(s).', $removed)
					: 'No duplicate entity assignments remained to fix.',
			];
		}

		$fixedRelational = $this->fixRelationalIssue($libraryId, $issueType);
		if ($fixedRelational !== null) {
			return [
				'success' => $fixedRelational > 0,
				'fixedCount' => $fixedRelational > 0 ? 1 : 0,
				'message' => $this->describeRelationalFix($issueType, $fixedRelational),
			];
		}

		if ($issueType === IntegrityIssue::TYPE_INVALID_NAMING_RULE && $songId !== null) {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$user = $userFolder->getOwner();
			['databasePath' => $databasePath, 'targetFolder' => $targetFolder] = $this->fileManager->libraryContext($libraryId, $user);
			$hashCodes = MobileSheetsHashCodesFile::forDatabase($databasePath, $this->logger);

			$song = $this->internalSongMapper->findSong($songId, $user);
			if ($song !== null) {
				$targetFolderPath = (string)$targetFolder->getPath();
				foreach ($song->files as $songFile) {
					$pathObj = $this->pathResolver->fromStored($songFile->path, $user);
					if ($pathObj === null) {
						return [
							'success' => false,
							'fixedCount' => 0,
							'message' => sprintf('Cannot apply naming rule for "%s": file "%s" is missing.', $song->title, basename($songFile->path)),
						];
					}
					$relativeToTarget = $this->pathResolver->relativeToFolder($pathObj->internal, $targetFolderPath);
					if (!$this->orphanScanner->fileNodeExists($targetFolder, $relativeToTarget)) {
						return [
							'success' => false,
							'fixedCount' => 0,
							'message' => sprintf('Cannot apply naming rule for "%s": file "%s" does not exist.', $song->title, basename($songFile->path)),
						];
					}
				}
			}

			try {
				$this->fileManager->regenerateSongFilePaths($songId, $user, $targetFolder, $databasePath, $hashCodes);
				$hashCodes?->save();
				$this->libraryMapper->touch($libraryId);
				$this->refreshFolderDatabaseAndHashcodes($targetFolder, $libraryId, $user);

				return [
					'success' => true,
					'fixedCount' => 1,
					'message' => 'File path updated according to naming rules.',
				];
			} catch (\Throwable $e) {
				$this->logger->warning(sprintf('Cannot auto-fix naming rule for song ID %d: %s', $songId, $e->getMessage()));
				return [
					'success' => false,
					'fixedCount' => 0,
					'message' => 'Failed to apply naming rule: ' . $e->getMessage(),
				];
			}
		}

		if ($issueType !== IntegrityIssue::TYPE_PDF_METADATA_MISMATCH || $songId === null || $fileId === null) {
			return [
				'success' => false,
				'fixedCount' => 0,
				'message' => 'This issue does not include the information required for an automatic fix.',
			];
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$user = $userFolder->getOwner();
		['databasePath' => $databasePath, 'targetFolder' => $targetFolder] = $this->fileManager->libraryContext($libraryId, $user);
		$issue = $this->findPdfMetadataMismatch($libraryId, $userId, $songId, $fileId);
		if ($issue === null) {
			return [
				'success' => false,
				'fixedCount' => 0,
				'message' => 'The PDF metadata mismatch was not found in the current library audit.',
			];
		}

		$hashCodes = MobileSheetsHashCodesFile::forDatabase($databasePath, $this->logger);
		$this->fixPdfMetadataMismatch($issue, $libraryId, $databasePath, $user, $hashCodes);
		$hashCodes?->save();
		$this->libraryMapper->touch($libraryId);
		$this->refreshFolderDatabaseAndHashcodes($targetFolder, $libraryId, $user);

		return [
			'success' => true,
			'fixedCount' => 1,
			'message' => 'Updated the PDF metadata to match its song.',
		];
	}

	/**
	 * Automatically resolve/fix all fixable issues of a specific type (or all fixable types).
	 *
	 * @return array{success: bool, fixedCount: int, message: string}
	 */
	public function fixAllIssues(int $libraryId, string $userId, ?string $issueType = null): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$user = $userFolder->getOwner();
		['databasePath' => $databasePath, 'targetFolder' => $targetFolder] = $this->fileManager->libraryContext($libraryId, $user);

		$issues = $this->checkIntegrity($libraryId, $userId);
		$hashCodes = MobileSheetsHashCodesFile::forDatabase($databasePath, $this->logger);

		$fixedCount = 0;
		$processedSongs = [];
		$duplicateClientIdsFixed = false;
		$errors = [];

		foreach ($issues as $issue) {
			if (!$issue->fixable) {
				continue;
			}
			if ($issueType !== null && $issueType !== '' && $issue->type !== $issueType) {
				continue;
			}

			if ($issue->type === IntegrityIssue::TYPE_INVALID_NAMING_RULE && $issue->songId !== null) {
				if (isset($processedSongs[$issue->songId])) {
					continue;
				}
				$hasMissingFile = false;
				foreach ($issues as $checkIssue) {
					if ($checkIssue->songId === $issue->songId && $checkIssue->type === IntegrityIssue::TYPE_MISSING_FILE) {
						$hasMissingFile = true;
						break;
					}
				}
				if ($hasMissingFile) {
					continue;
				}
				try {
					$this->fileManager->regenerateSongFilePaths($issue->songId, $user, $targetFolder, $databasePath, $hashCodes);
					$processedSongs[$issue->songId] = true;
					$fixedCount++;
					$this->libraryMapper->touch($libraryId);
				} catch (\Throwable $e) {
					$this->logger->warning(sprintf('Cannot auto-fix naming rule for song ID %d during batch fix: %s', $issue->songId, $e->getMessage()));
					$songName = $issue->songTitle ?? ('song ID ' . $issue->songId);
					$errors[] = sprintf('Failed to apply naming rule for "%s": %s', $songName, $e->getMessage());
				}
			} elseif ($issue->type === IntegrityIssue::TYPE_DUPLICATE_ENTITY_ASSIGNMENT && $issue->songId !== null) {
				try {
					$removed = $this->internalEntityMapper->removeDuplicateSongEntityAssignments($libraryId, $issue->songId);
					$this->libraryMapper->touch($libraryId);
					if ($removed > 0) {
						$fixedCount++;
					}
					$processedSongs[$issue->songId] = true;
				} catch (\Throwable $e) {
					$this->logger->warning('Cannot auto-fix duplicate entity assignments', [
						'exception' => $e,
						'songId' => $issue->songId,
					]);
					$errors[] = sprintf('Failed to remove duplicate entity assignments: %s', $e->getMessage());
				}
			} elseif ($issue->type === IntegrityIssue::TYPE_DUPLICATE_ENTITY) {
				$details = $issue->details;
				if (!is_array($details)
					|| !isset($details['entityType'])
					|| !isset($details['keepId'])
					|| !isset($details['duplicateIds'])
					|| !is_string($details['entityType'])
					|| !is_int($details['keepId'])
					|| !is_array($details['duplicateIds'])
				) {
					continue;
				}
				try {
					$normType = InternalEntityMapper::normalizeType($details['entityType']);
					$merged = $this->internalEntityMapper->mergeEntities(
						$libraryId,
						$normType,
						$details['keepId'],
						$details['duplicateIds'],
					);
					$this->libraryMapper->touch($libraryId);
					if ($merged > 0) {
						$fixedCount++;
					}
				} catch (\Throwable $e) {
					$this->logger->warning('Cannot auto-fix duplicate entity records', [
						'exception' => $e,
						'entityType' => $details['entityType'] ?? 'unknown',
						'keepId' => $details['keepId'] ?? null,
					]);
					$errors[] = sprintf('Failed to merge duplicate entity records: %s', $e->getMessage());
				}
			} elseif ($this->isRelationalIssueType($issue->type)) {
				if ($issue->type === IntegrityIssue::TYPE_DUPLICATE_CLIENT_ID) {
					if ($duplicateClientIdsFixed) {
						continue;
					}
					$duplicateClientIdsFixed = true;
				}
				$fixedRelational = $this->fixRelationalIssue($libraryId, $issue->type);
				if ($fixedRelational !== null && $fixedRelational > 0) {
					$fixedCount++;
				}
			} elseif ($issue->type === IntegrityIssue::TYPE_ORPHAN_FILE && $issue->path !== null) {
				try {
					$res = $this->fixOrphanFileIssue($libraryId, $userId, $issue->path, $issue->songId);
					if ($res['success']) {
						$fixedCount++;
					} else {
						$errors[] = sprintf('Failed to link orphan file "%s": %s', basename($issue->path), $res['message']);
					}
				} catch (\Throwable $e) {
					$this->logger->warning(sprintf('Cannot auto-fix orphan file "%s": %s', $issue->path, $e->getMessage()));
					$errors[] = sprintf('Failed to link orphan file "%s": %s', basename($issue->path), $e->getMessage());
				}
			} elseif ($issue->type === IntegrityIssue::TYPE_PDF_METADATA_MISMATCH && $issue->songId !== null && $issue->fileId !== null) {
				try {
					$this->fixPdfMetadataMismatch($issue, $libraryId, $databasePath, $user, $hashCodes);
					$fixedCount++;
				} catch (\Throwable $e) {
					$this->logger->warning(sprintf(
						'Cannot auto-fix PDF metadata for song ID %d and file ID %d: %s',
						$issue->songId,
						$issue->fileId,
						$e->getMessage(),
					));
					$errors[] = sprintf(
						'Failed to update PDF metadata for "%s": %s',
						$issue->songTitle ?? ('song ID ' . $issue->songId),
						$e->getMessage(),
					);
				}
			} elseif ($issue->type === IntegrityIssue::TYPE_MISSING_FILE && $issue->fixable) {
				try {
					$res = $this->fixIssue(
						$libraryId,
						$userId,
						$issue->songId,
						issueType: IntegrityIssue::TYPE_MISSING_FILE,
						fileId: $issue->fileId,
						path: $issue->path,
					);
					if ($res['success']) {
						$fixedCount++;
					}
				} catch (\Throwable $e) {
					$this->logger->warning('Cannot auto-fix missing file: ' . $e->getMessage());
				}
			}
		}

		$hashCodes?->save();
		if ($fixedCount > 0) {
			$this->libraryMapper->touch($libraryId);
			$this->refreshFolderDatabaseAndHashcodes($targetFolder, $libraryId, $user);
		}

		$success = $fixedCount > 0 || empty($errors);
		if ($fixedCount > 0 && empty($errors)) {
			$message = sprintf('Successfully fixed %d issue(s).', $fixedCount);
		} elseif ($fixedCount > 0) {
			$message = sprintf('Fixed %d issue(s), but some could not be resolved: %s', $fixedCount, implode(' ', $errors));
		} elseif (!empty($errors)) {
			$message = sprintf('Could not fix issues: %s', implode(' ', $errors));
		} else {
			$message = 'No fixable issues found.';
		}

		return [
			'success' => $success,
			'fixedCount' => $fixedCount,
			'message' => $message,
		];
	}

	private function findPdfMetadataMismatch(int $libraryId, string $userId, int $songId, int $fileId): ?IntegrityIssue {
		foreach ($this->checkIntegrity($libraryId, $userId) as $issue) {
			if ($issue->type === IntegrityIssue::TYPE_PDF_METADATA_MISMATCH
				&& $issue->songId === $songId
				&& $issue->fileId === $fileId) {
				return $issue;
			}
		}

		return null;
	}

	public function fixPdfMetadataMismatch(
		IntegrityIssue $issue,
		int $libraryId,
		string $databasePath,
		\OCP\IUser $user,
		?MobileSheetsHashCodesFile $hashCodes,
	): void {
		if ($issue->songId === null || $issue->fileId === null) {
			throw new \RuntimeException('The PDF metadata issue does not identify a song and file.');
		}

		$song = null;
		$data = $this->internalSongMapper->findLibraryData($libraryId, $user);
		foreach ($data->songs as $s) {
			if ($s->id === $issue->songId) {
				$song = $s;
				break;
			}
		}
		if ($song === null) {
			throw new \RuntimeException(sprintf('Song %d was not found while fixing PDF metadata.', $issue->songId));
		}

		foreach ($song->files as $file) {
			if ($file->id === $issue->fileId) {
				$this->fileManager->syncMetadataIntoFile($song, $file, $user, $databasePath, $hashCodes);
				return;
			}
		}

		throw new \RuntimeException(sprintf(
			'File %d is no longer attached to song %d.',
			$issue->fileId,
			$issue->songId,
		));
	}

	/**
	 * Auto-fix an orphan file by attaching it to the single matched song.
	 *
	 * @return array{success: bool, fixedCount: int, message: string}
	 */
	public function fixOrphanFileIssue(int $libraryId, string $userId, string $path, ?int $songId = null): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$user = $userFolder->getOwner();
		['databasePath' => $databasePath, 'targetFolder' => $targetFolder] = $this->fileManager->libraryContext($libraryId, $user);

		$cleanPath = ltrim(str_replace('\\', '/', $path), '/');
		try {
			$orphanNode = $targetFolder->get($cleanPath);
		} catch (\Throwable) {
			return [
				'success' => false,
				'fixedCount' => 0,
				'message' => sprintf('Orphan file "%s" was not found in library storage.', basename($path)),
			];
		}

		if (!($orphanNode instanceof Node)) {
			return [
				'success' => false,
				'fixedCount' => 0,
				'message' => sprintf('Orphan file "%s" is not a valid file.', basename($path)),
			];
		}

		$targetFolderPath = (string)$targetFolder->getPath();
		$songs = $this->internalSongMapper->findLibraryData($libraryId, $user)->songs;
		$missingSongIds = $this->orphanScanner->findMissingSongIds($songs, $targetFolder, $targetFolderPath, $user);
		$candidateSong = $this->orphanScanner->findOrphanFileCandidateSong($cleanPath, $songs, $missingSongIds);

		if ($candidateSong === null) {
			return [
				'success' => false,
				'fixedCount' => 0,
				'message' => sprintf('Could not uniquely identify a candidate song without files for "%s".', basename($path)),
			];
		}

		if ($songId !== null && $candidateSong->id !== $songId) {
			return [
				'success' => false,
				'fixedCount' => 0,
				'message' => sprintf('Candidate song ID %d does not match requested song ID %d.', $candidateSong->id, $songId),
			];
		}

		$missingFiles = [];
		foreach ($candidateSong->files as $f) {
			$pathObj = $this->pathResolver->fromStored($f->path, $user);
			if ($pathObj === null) {
				$missingFiles[] = $f;
				continue;
			}
			$rel = $this->pathResolver->relativeToFolder($pathObj->internal, $targetFolderPath);
			if ($rel === null || !$targetFolder->nodeExists($rel)) {
				$missingFiles[] = $f;
			}
		}

		$fileId = (int)$orphanNode->getId();
		$existingFiles = $this->internalSongFileMapper->findAllForSong($candidateSong->id);
		$alreadyLinked = false;
		foreach ($existingFiles as $ef) {
			if ($ef->fileId === $fileId) {
				$alreadyLinked = true;
				break;
			}
		}

		if (!$alreadyLinked) {
			if (!empty($missingFiles)) {
				foreach ($missingFiles as $mf) {
					$rec = $this->internalSongFileMapper->findById($mf->id);
					if ($rec !== null && $rec->songId === $candidateSong->id) {
						$this->internalSongFileMapper->delete($rec->id);
					}
				}
			}

			$fileSize = 0;
			try {
				$fileSize = $orphanNode->getSize();
			} catch (\Throwable) {
			}

			$internalSongFile = new InternalSongFile(
				id: null,
				uuid: InternalEntity::generateUuid(),
				songId: $candidateSong->id,
				fileId: $fileId,
				fileSize: $fileSize,
				type: InternalSongFile::determineFileType($orphanNode->getName()),
				createdAt: time(),
				updatedAt: time(),
			);
			$this->internalSongFileMapper->insert($internalSongFile);
			$this->libraryMapper->touch($libraryId);
		}

		// If physical/cached SQLite database exists, keep it consistent with the linked file
		if ($databasePath !== '' && file_exists($databasePath)) {
			try {
				$db = new \PDO('sqlite:' . $databasePath);
				$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
				$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Files'")->fetchAll();
				if (!empty($tables)) {
					$internalSong = $this->internalSongMapper->findById($candidateSong->id);
					$sqliteSongId = $internalSong?->clientId;
					if ($sqliteSongId === null && MobileSheetsTableHelper::tableExists($db, 'Songs')) {
						$check = $db->prepare('SELECT Id FROM Songs WHERE Id = :id');
						$check->execute([':id' => $candidateSong->id]);
						if ($check->fetch() !== false) {
							$sqliteSongId = $candidateSong->id;
						}
					}

					if ($sqliteSongId !== null) {
						$facts = MobileSheetsFileFacts::fromNode($orphanNode);
						$fileType = InternalSongFile::determineFileType($cleanPath);
						$updatedExisting = false;

						if (!empty($missingFiles)) {
							foreach ($missingFiles as $mf) {
								$mfPathObj = $this->pathResolver->fromStored($mf->path, $user);
								$mfInternal = $mfPathObj?->internal ?? '';
								$mfRel = ($mfPathObj !== null ? $this->pathResolver->relativeToFolder($mfPathObj->internal, $targetFolderPath) : null)
									?? basename($mf->path);

								$stmtFind = $db->prepare('SELECT Id FROM Files WHERE SongId = :songId AND (Path = :p1 OR Path = :p2 OR Id = :fid)');
								$stmtFind->execute([
									':songId' => $sqliteSongId,
									':p1' => $mfRel,
									':p2' => $mf->path,
									':fid' => $mf->id,
								]);
								$row = $stmtFind->fetch();
								if ($row !== false) {
									$stmtUpdate = $db->prepare('UPDATE Files SET Path = :path, PageOrder = :pageOrder, FileSize = :fileSize, LastModified = :lastModified, Type = :type, SourceFilePageCount = :pageCount, FileHash = :fileHash WHERE Id = :id');
									$stmtUpdate->execute([
										':id' => $row['Id'],
										':path' => $cleanPath,
										':pageOrder' => $facts->pageOrder,
										':fileSize' => $facts->fileSize,
										':lastModified' => $facts->lastModified,
										':type' => $fileType,
										':pageCount' => $facts->sourceFilePageCount,
										':fileHash' => $facts->fileHash,
									]);
									$updatedExisting = true;
									break;
								}
							}
						}

						if (!$updatedExisting) {
							$existing = $db->prepare('SELECT Id FROM Files WHERE SongId = :songId AND Path = :path');
							$existing->execute([':songId' => $sqliteSongId, ':path' => $cleanPath]);
							if ($existing->fetch() === false) {
								$stmt = $db->prepare('INSERT INTO Files (SongId, Path, PageOrder, FileSize, LastModified, Source, Type, Password, SourceFilePageCount, FileHash, Width, Height) VALUES (:songId, :path, :pageOrder, :fileSize, :lastModified, 0, :type, \'\', :pageCount, :fileHash, 0, 0)');
								$stmt->execute([
									':songId' => $sqliteSongId,
									':path' => $cleanPath,
									':pageOrder' => $facts->pageOrder,
									':fileSize' => $facts->fileSize,
									':lastModified' => $facts->lastModified,
									':type' => $fileType,
									':pageCount' => $facts->sourceFilePageCount,
									':fileHash' => $facts->fileHash,
								]);
							}
						}
					}
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Could not record orphan file in SQLite database: ' . $e->getMessage());
			}
		}

		$autoPaths = $this->libraryService->isAutoPathsEnabled($userId, $libraryId);
		if ($autoPaths) {
			$hashCodes = MobileSheetsHashCodesFile::forDatabase($databasePath, $this->logger);
			try {
				$this->fileManager->regenerateSongFilePaths($candidateSong->id, $user, $targetFolder, $databasePath, $hashCodes);
				$hashCodes?->save();
			} catch (\Throwable $e) {
				$this->logger->debug('Naming rule not applied after linking orphan file: ' . $e->getMessage());
			}
		}

		return [
			'success' => true,
			'fixedCount' => 1,
			'message' => sprintf('Linked orphan file "%s" to song "%s".', $orphanNode->getName(), $candidateSong->title),
		];
	}

	public function isRelationalIssueType(string $type): bool {
		return in_array($type, [
			IntegrityIssue::TYPE_ORPHAN_ENTITY_ASSIGNMENT,
			IntegrityIssue::TYPE_ORPHAN_SONG_FILE,
			IntegrityIssue::TYPE_ORPHAN_SONG_ATTRIBUTE,
			IntegrityIssue::TYPE_ORPHAN_MISC_DATA,
			IntegrityIssue::TYPE_DUPLICATE_CLIENT_ID,
		], true);
	}

	/**
	 * Automatically resolves a relational integrity issue (orphans or duplicate client IDs).
	 *
	 * @return int|null Number of fixed rows, or null if the issue type is not a relational issue.
	 */
	public function fixRelationalIssue(int $libraryId, string $issueType): ?int {
		$fixed = match ($issueType) {
			IntegrityIssue::TYPE_ORPHAN_ENTITY_ASSIGNMENT => $this->internalEntityMapper->removeOrphanSongEntityAssignments($libraryId),
			IntegrityIssue::TYPE_ORPHAN_SONG_FILE => $this->internalSongFileMapper->removeOrphanSongFiles($libraryId),
			IntegrityIssue::TYPE_ORPHAN_SONG_ATTRIBUTE => $this->internalSongAttributeMapper->removeOrphanSongAttributes($libraryId),
			IntegrityIssue::TYPE_ORPHAN_MISC_DATA => $this->internalMiscMapper->removeOrphanMiscData($libraryId),
			IntegrityIssue::TYPE_DUPLICATE_CLIENT_ID => $this->internalSongMapper->fixDuplicateClientIds($libraryId) + $this->internalEntityMapper->fixDuplicateClientIds($libraryId),
			default => null,
		};

		if ($fixed !== null && $fixed > 0) {
			$this->libraryMapper->touch($libraryId);
		}

		return $fixed;
	}

	public function describeRelationalFix(string $issueType, int $fixed): string {
		if ($fixed <= 0) {
			return match ($issueType) {
				IntegrityIssue::TYPE_ORPHAN_ENTITY_ASSIGNMENT => 'No orphan entity assignments remained to fix.',
				IntegrityIssue::TYPE_ORPHAN_SONG_FILE => 'No orphan song file records remained to fix.',
				IntegrityIssue::TYPE_ORPHAN_SONG_ATTRIBUTE => 'No orphan song attributes remained to fix.',
				IntegrityIssue::TYPE_ORPHAN_MISC_DATA => 'No orphan sync data records remained to fix.',
				IntegrityIssue::TYPE_DUPLICATE_CLIENT_ID => 'No duplicate client_id records remained to fix.',
				default => 'No records remained to fix.',
			};
		}

		return match ($issueType) {
			IntegrityIssue::TYPE_ORPHAN_ENTITY_ASSIGNMENT => sprintf('Removed %d orphan entity assignment(s).', $fixed),
			IntegrityIssue::TYPE_ORPHAN_SONG_FILE => sprintf('Cleaned up %d orphan song file record(s).', $fixed),
			IntegrityIssue::TYPE_ORPHAN_SONG_ATTRIBUTE => sprintf('Cleaned up %d orphan song attribute record(s).', $fixed),
			IntegrityIssue::TYPE_ORPHAN_MISC_DATA => sprintf('Cleaned up %d orphan sync data record(s).', $fixed),
			IntegrityIssue::TYPE_DUPLICATE_CLIENT_ID => sprintf('Reset %d duplicate client_id record(s).', $fixed),
			default => sprintf('Fixed %d record(s).', $fixed),
		};
	}

	private function refreshFolderDatabaseAndHashcodes(Folder $targetFolder, int $libraryId, \OCP\IUser $user): void {
		try {
			if ($targetFolder->nodeExists('mobilesheets_hashcodes.txt')) {
				$hashcodesContent = $this->libraryService->generateHashcodes($libraryId);
				$hcNode = $targetFolder->get('mobilesheets_hashcodes.txt');
				if ($hcNode instanceof File) {
					$hcNode->putContent($hashcodesContent);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Could not refresh mobilesheets_hashcodes.txt in library folder', ['exception' => $e]);
		}

		try {
			if ($targetFolder->nodeExists('mobilesheets.db')) {
				$tmpDb = tempnam(sys_get_temp_dir(), 'ms_db_refresh');
				if ($tmpDb !== false) {
					try {
						$this->libraryService->exportDatabaseToFile($libraryId, $tmpDb, $user);
						$dbNode = $targetFolder->get('mobilesheets.db');
						if ($dbNode instanceof File) {
							$dbNode->putContent((string)file_get_contents($tmpDb));
						}
					} finally {
						@unlink($tmpDb);
					}
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Could not refresh mobilesheets.db in library folder', ['exception' => $e]);
		}
	}
}
