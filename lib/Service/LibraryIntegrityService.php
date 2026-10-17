<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalMiscMapper;
use OCA\SheetMusic\Db\InternalSongAttributeMapper;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

class LibraryIntegrityService {
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
		private ?InternalSongAttributeMapper $internalSongAttributeMapper = null,
		private ?InternalMiscMapper $internalMiscMapper = null,
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

		$songs = $this->internalSongMapper->findLibraryData($libraryId, $user)['songs'];
		$targetFolderPath = (string)$targetFolder->getPath();

		$issues = [];
		$referencedRelativePaths = []; // relativeToTarget => list<array{songId: int, songTitle: string, fileId: int}>
		$songsById = [];
		foreach ($songs as $song) {
			$songsById[$song->id] = $song;
		}

		$descriptionByType = [
			'composers' => 'composers',
			'composer' => 'composers',
			'books' => 'books',
			'book' => 'books',
			'collections' => 'collections',
			'collection' => 'collections',
			'artists' => 'artists',
			'artist' => 'artists',
			'genres' => 'genres',
			'genre' => 'genres',
			'keys' => 'keys',
			'key' => 'keys',
			'customgroups' => 'custom groups',
			'customgroup' => 'custom groups',
			'years' => 'years',
			'year' => 'years',
			'signatures' => 'time signatures',
			'signature' => 'time signatures',
			'sourcetypes' => 'source types',
			'sourcetype' => 'source types',
			'instruments' => 'instruments',
			'instrument' => 'instruments',
		];
		$duplicateAssignmentsBySong = [];
		$assignmentsList = $this->internalEntityMapper->findDuplicateSongEntityAssignments($libraryId);
		foreach ($assignmentsList as $assignment) {
			$duplicateAssignmentsBySong[$assignment['songId']][] = $assignment;
		}
		foreach ($duplicateAssignmentsBySong as $songId => $assignments) {
			$song = $songsById[$songId] ?? null;
			$assignmentDescriptions = array_map(
				static fn (array $assignment): string => sprintf(
					'%d duplicate %s assignment(s)',
					$assignment['count'] - 1,
					$descriptionByType[$assignment['entityType']] ?? $assignment['entityType'],
				),
				$assignments,
			);
			$issues[] = new IntegrityIssue(
				type: IntegrityIssue::TYPE_DUPLICATE_ENTITY_ASSIGNMENT,
				severity: IntegrityIssue::SEVERITY_WARNING,
				message: sprintf(
					'Song "%s" has repeated entity assignments: %s.',
					$song?->title ?? sprintf('ID %d', $songId),
					implode(', ', $assignmentDescriptions),
				),
				songId: (int)$songId,
				songTitle: $song?->title,
				fixable: true,
				details: ['duplicates' => $assignments],
			);
		}
		$entityTypeLabels = [
			'composers' => 'composer',
			'composer' => 'composer',
			'books' => 'book',
			'book' => 'book',
			'collections' => 'collection',
			'collection' => 'collection',
			'artists' => 'artist',
			'artist' => 'artist',
			'genres' => 'genre',
			'genre' => 'genre',
			'keys' => 'key',
			'key' => 'key',
			'customgroups' => 'custom group',
			'customgroup' => 'custom group',
			'years' => 'year',
			'year' => 'year',
			'signatures' => 'time signature',
			'signature' => 'time signature',
			'sourcetypes' => 'source type',
			'sourcetype' => 'source type',
			'instruments' => 'instrument',
			'instrument' => 'instrument',
		];
		$duplicatesList = $this->internalEntityMapper->findDuplicateEntities($libraryId);
		foreach ($duplicatesList as $duplicateEntity) {
			$label = $entityTypeLabels[$duplicateEntity['entityType']] ?? $duplicateEntity['entityType'];
			$issues[] = new IntegrityIssue(
				type: IntegrityIssue::TYPE_DUPLICATE_ENTITY,
				severity: IntegrityIssue::SEVERITY_WARNING,
				message: sprintf(
					'Duplicate %s "%s" entries found (IDs %s). Auto-fix will keep ID %d and reassign songs.',
					$label,
					$duplicateEntity['name'],
					implode(', ', array_merge([$duplicateEntity['keepId']], $duplicateEntity['duplicateIds'])),
					$duplicateEntity['keepId'],
				),
				fixable: true,
				details: $duplicateEntity,
			);
		}

		$orphanAssignments = $this->internalEntityMapper->findOrphanSongEntityAssignments($libraryId);
		if (!empty($orphanAssignments)) {
			$issues[] = new IntegrityIssue(
				type: IntegrityIssue::TYPE_ORPHAN_ENTITY_ASSIGNMENT,
				severity: IntegrityIssue::SEVERITY_WARNING,
				message: sprintf(
					'Found %d orphan song-entity assignment(s) referencing deleted or missing records.',
					count($orphanAssignments),
				),
				fixable: true,
				details: [
					'count' => count($orphanAssignments),
					'assignments' => $orphanAssignments,
				],
			);
		}

		$duplicateSongClientIds = $this->internalSongMapper->findDuplicateClientIds($libraryId);
		foreach ($duplicateSongClientIds as $dup) {
			$issues[] = new IntegrityIssue(
				type: IntegrityIssue::TYPE_DUPLICATE_CLIENT_ID,
				severity: IntegrityIssue::SEVERITY_WARNING,
				message: sprintf(
					'Duplicate song client_id %d shared by song IDs %s. Auto-fix will keep client_id on ID %d and reset duplicates.',
					$dup['client_id'],
					implode(', ', array_merge([$dup['keep_id']], $dup['duplicate_ids'])),
					$dup['keep_id'],
				),
				songId: $dup['keep_id'],
				fixable: true,
				details: [
					'target' => 'song',
					'clientId' => $dup['client_id'],
					'keepId' => $dup['keep_id'],
					'duplicateIds' => $dup['duplicate_ids'],
				],
			);
		}

		$duplicateEntityClientIds = $this->internalEntityMapper->findDuplicateClientIds($libraryId);
		foreach ($duplicateEntityClientIds as $dup) {
			$label = $entityTypeLabels[$dup['entity_type']] ?? $dup['entity_type'];
			$issues[] = new IntegrityIssue(
				type: IntegrityIssue::TYPE_DUPLICATE_CLIENT_ID,
				severity: IntegrityIssue::SEVERITY_WARNING,
				message: sprintf(
					'Duplicate %s client_id %d shared by entity IDs %s. Auto-fix will keep client_id on ID %d and reset duplicates.',
					$label,
					$dup['client_id'],
					implode(', ', array_merge([$dup['keep_id']], $dup['duplicate_ids'])),
					$dup['keep_id'],
				),
				fixable: true,
				details: [
					'target' => 'entity',
					'entityType' => $dup['entity_type'],
					'clientId' => $dup['client_id'],
					'keepId' => $dup['keep_id'],
					'duplicateIds' => $dup['duplicate_ids'],
				],
			);
		}

		$songsWithoutFiles = $this->internalSongMapper->findSongsWithoutFiles($libraryId);
		foreach ($songsWithoutFiles as $row) {
			$issues[] = new IntegrityIssue(
				type: IntegrityIssue::TYPE_SONG_WITHOUT_FILES,
				severity: IntegrityIssue::SEVERITY_WARNING,
				message: sprintf('Song "%s" (ID %d) has no associated files.', $row['title'], $row['id']),
				songId: (int)$row['id'],
				songTitle: (string)$row['title'],
				fixable: false,
			);
		}

		$orphanSongFiles = $this->internalSongFileMapper->findOrphanSongFiles($libraryId);
		if (!empty($orphanSongFiles)) {
			$issues[] = new IntegrityIssue(
				type: IntegrityIssue::TYPE_ORPHAN_SONG_FILE,
				severity: IntegrityIssue::SEVERITY_WARNING,
				message: sprintf(
					'Found %d orphan song file record(s) referencing deleted songs.',
					count($orphanSongFiles),
				),
				fixable: true,
				details: [
					'count' => count($orphanSongFiles),
					'files' => $orphanSongFiles,
				],
			);
		}

		if ($this->internalSongAttributeMapper !== null) {
			$orphanAttrs = $this->internalSongAttributeMapper->findOrphanSongAttributes($libraryId);
			if (!empty($orphanAttrs)) {
				$issues[] = new IntegrityIssue(
					type: IntegrityIssue::TYPE_ORPHAN_SONG_ATTRIBUTE,
					severity: IntegrityIssue::SEVERITY_WARNING,
					message: sprintf(
						'Found %d orphan song attribute record(s) referencing deleted songs.',
						count($orphanAttrs),
					),
					fixable: true,
					details: [
						'count' => count($orphanAttrs),
						'attributes' => $orphanAttrs,
					],
				);
			}
		}

		if ($this->internalMiscMapper !== null) {
			$orphanMisc = $this->internalMiscMapper->findOrphanMiscData($libraryId);
			if (!empty($orphanMisc)) {
				$issues[] = new IntegrityIssue(
					type: IntegrityIssue::TYPE_ORPHAN_MISC_DATA,
					severity: IntegrityIssue::SEVERITY_WARNING,
					message: sprintf(
						'Found %d orphan sync/misc data record(s) referencing deleted songs.',
						count($orphanMisc),
					),
					fixable: true,
					details: [
						'count' => count($orphanMisc),
						'misc' => $orphanMisc,
					],
				);
			}
		}

		$autoPaths = $this->libraryService->isAutoPathsEnabled($userId, $libraryId);
		$autoPdfMetadata = $this->libraryService->isAutoPdfMetadataEnabled($userId, $libraryId);

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
				$existingPdfPath = $sourceExists ? $relativeToTarget : null;

				// Calculate valid target paths (naming rules)
				$validTargetPaths = [];
				$hasNamingRule = true;
				if ($autoPaths) {
					try {
						$validTargetPaths = createTargetPaths(
							$song,
							getFileExtension($file->path),
							fileIndexOf($song, $file),
							fileCountForExtension($song, $file),
						);
					} catch (NoNamingRuleException) {
						// Song missing composer, artist, or collection - keeps existing name
						$hasNamingRule = false;
					}
				}

				// Check if the expected target file already exists on disk
				$targetExists = false;
				if (!empty($validTargetPaths)) {
					foreach ($validTargetPaths as $validPath) {
						$cleanValid = ltrim($validPath, '/');
						if ($targetFolder->nodeExists($cleanValid)) {
							$targetExists = true;
							$existingPdfPath ??= $cleanValid;
							if (!$sourceExists) {
								// Mark target file as referenced by song so it is not flagged as an orphan
								$cleanKey = strtolower(ltrim($cleanValid, '/'));
								$referencedRelativePaths[$cleanKey][] = [
									'songId' => $song->id,
									'songTitle' => $song->title,
									'fileId' => $file->id,
								];
							}
							break;
						}
					}
				}

				if ($autoPdfMetadata && $existingPdfPath !== null && str_ends_with(strtolower($file->path), '.pdf')) {
					$pdfIssue = $this->checkPdfMetadata($targetFolder, $existingPdfPath, $song, $file->id);
					if ($pdfIssue !== null) {
						$issues[] = $pdfIssue;
					}
				}

				if ($sourceExists) {
					$cleanRel = strtolower(ltrim($relativeToTarget, '/'));
					$referencedRelativePaths[$cleanRel][] = [
						'songId' => $song->id,
						'songTitle' => $song->title,
						'fileId' => $file->id,
					];

					// Check naming rule compliance
					$isCompliant = !$autoPaths || !$hasNamingRule;
					if ($autoPaths && $hasNamingRule) {
						$normalizedRelative = strtolower(ltrim($relativeToTarget, '/'));
						foreach ($validTargetPaths as $validPath) {
							if ($normalizedRelative === strtolower(ltrim($validPath, '/'))) {
								$isCompliant = true;
								break;
							}
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
							fixable: !empty($validTargetPaths),
						);
					}
				} else {
					// Source file does not exist at stored path.
					// If the target file ALREADY exists at the expected naming-rule path on disk:
					if ($autoPaths && $targetExists) {
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
				$songIds = array_values(array_unique(array_column($references, 'songId')));

				if (count($songIds) > 1) {
					$referencingSongs = array_values(array_filter(
						array_map(fn ($id) => $songsById[$id] ?? null, $songIds)
					));

					$areExactDuplicates = $this->areSongsExactDuplicates($referencingSongs);
					$songTitles = array_map(fn ($s) => sprintf('"%s" (ID %d)', $s->title, $s->id), $referencingSongs);

					$issues[] = new IntegrityIssue(
						type: IntegrityIssue::TYPE_DUPLICATE_FILE_PATH,
						severity: IntegrityIssue::SEVERITY_ERROR,
						message: sprintf(
							'Multiple database entries point to the same file path "%s" (%s).',
							basename($relPath),
							implode(', ', $songTitles)
						),
						songId: $referencingSongs[0]->id ?? $references[0]['songId'],
						songTitle: $referencingSongs[0]->title ?? $references[0]['songTitle'],
						fileId: $references[0]['fileId'],
						path: $relPath,
						fixable: $areExactDuplicates,
						details: [
							'songIds' => $songIds,
							'exactDuplicate' => $areExactDuplicates,
						],
					);
				}
			}
		}

		// Scan folder for orphan files (files in storage not in DB)
		if ($autoPaths) {
			$this->scanOrphans($targetFolder, $targetFolderPath, $referencedRelativePaths, $issues, $user);
		}

		// Check for songs with identical metadata (informational)
		$groupedByMetadata = [];
		foreach ($songs as $s) {
			$metaKey = $this->getCanonicalSongMetadataKey($s);
			$groupedByMetadata[$metaKey][] = $s;
		}

		foreach ($groupedByMetadata as $identicalSongs) {
			if (count($identicalSongs) > 1) {
				$identicalIds = array_map(fn ($s) => $s->id, $identicalSongs);
				$songTitles = array_map(fn ($s) => sprintf('"%s" (ID %d)', $s->title, $s->id), $identicalSongs);

				$issues[] = new IntegrityIssue(
					type: IntegrityIssue::TYPE_IDENTICAL_SONG,
					severity: IntegrityIssue::SEVERITY_INFO,
					message: sprintf(
						'Found %d songs with identical metadata (%s). Propose to merge or keep separate.',
						count($identicalSongs),
						implode(', ', $songTitles)
					),
					songId: $identicalSongs[0]->id,
					songTitle: $identicalSongs[0]->title,
					fixable: true,
					details: [
						'songIds' => $identicalIds,
						'exactDuplicate' => true,
					],
				);
			}
		}

		return $issues;
	}

	private function checkPdfMetadata(
		Folder $targetFolder,
		string $relativePath,
		\OCA\SheetMusic\Db\MobileSheetsSong $song,
		int $fileId,
	): ?IntegrityIssue {
		try {
			$node = $targetFolder->get($relativePath);
			if (!$node instanceof File) {
				throw new \RuntimeException('The attached PDF path does not point to a file.');
			}

			$content = $node->getContent();
			if (!is_string($content) || $content === '') {
				throw new \RuntimeException('The attached PDF is empty or unreadable.');
			}

			$mismatchedFields = MobileSheetsPdfMetadata::mismatchedFields($content, $song);
		} catch (\Throwable $exception) {
			$this->logger->warning('Could not verify PDF metadata during library integrity audit', [
				'exception' => $exception,
				'fileId' => $fileId,
				'path' => $relativePath,
				'songId' => $song->id,
			]);

			return new IntegrityIssue(
				type: IntegrityIssue::TYPE_PDF_METADATA_UNREADABLE,
				severity: IntegrityIssue::SEVERITY_WARNING,
				message: sprintf('Could not verify PDF metadata for "%s".', basename($relativePath)),
				songId: $song->id,
				songTitle: $song->title,
				fileId: $fileId,
				path: $relativePath,
			);
		}

		if ($mismatchedFields === []) {
			return null;
		}

		return new IntegrityIssue(
			type: IntegrityIssue::TYPE_PDF_METADATA_MISMATCH,
			severity: IntegrityIssue::SEVERITY_WARNING,
			message: sprintf(
				'PDF metadata fields do not match the expected song values: %s.',
				implode(', ', $mismatchedFields),
			),
			songId: $song->id,
			songTitle: $song->title,
			fileId: $fileId,
			path: $relativePath,
			fixable: true,
			details: ['mismatchedFields' => $mismatchedFields],
		);
	}

	/**
	 * Automatically repair one PDF metadata mismatch.
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
	): array {
		if ($issueType === IntegrityIssue::TYPE_DUPLICATE_ENTITY && $entityType !== null && $entityId !== null) {
			$normType = InternalEntityMapper::normalizeType($entityType);
			foreach ($this->internalEntityMapper->findDuplicateEntities($libraryId) as $duplicateEntity) {
				if ($duplicateEntity['entityType'] !== $normType || $duplicateEntity['keepId'] !== $entityId) {
					continue;
				}

				$merged = $this->internalEntityMapper->mergeEntities(
					$libraryId,
					$normType,
					$entityId,
					$duplicateEntity['duplicateIds'],
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

		if ($issueType === IntegrityIssue::TYPE_ORPHAN_ENTITY_ASSIGNMENT) {
			$removed = $this->internalEntityMapper->removeOrphanSongEntityAssignments($libraryId);
			$this->libraryMapper->touch($libraryId);

			return [
				'success' => $removed > 0,
				'fixedCount' => $removed > 0 ? 1 : 0,
				'message' => $removed > 0
					? sprintf('Removed %d orphan entity assignment(s).', $removed)
					: 'No orphan entity assignments remained to fix.',
			];
		}

		if ($issueType === IntegrityIssue::TYPE_ORPHAN_SONG_FILE) {
			$removed = $this->internalSongFileMapper->removeOrphanSongFiles($libraryId);
			$this->libraryMapper->touch($libraryId);

			return [
				'success' => $removed > 0,
				'fixedCount' => $removed > 0 ? 1 : 0,
				'message' => $removed > 0
					? sprintf('Cleaned up %d orphan song file record(s).', $removed)
					: 'No orphan song file records remained to fix.',
			];
		}

		if ($issueType === IntegrityIssue::TYPE_ORPHAN_SONG_ATTRIBUTE && $this->internalSongAttributeMapper !== null) {
			$removed = $this->internalSongAttributeMapper->removeOrphanSongAttributes($libraryId);
			$this->libraryMapper->touch($libraryId);

			return [
				'success' => $removed > 0,
				'fixedCount' => $removed > 0 ? 1 : 0,
				'message' => $removed > 0
					? sprintf('Cleaned up %d orphan song attribute record(s).', $removed)
					: 'No orphan song attribute records remained to fix.',
			];
		}

		if ($issueType === IntegrityIssue::TYPE_ORPHAN_MISC_DATA && $this->internalMiscMapper !== null) {
			$removed = $this->internalMiscMapper->removeOrphanMiscData($libraryId);
			$this->libraryMapper->touch($libraryId);

			return [
				'success' => $removed > 0,
				'fixedCount' => $removed > 0 ? 1 : 0,
				'message' => $removed > 0
					? sprintf('Cleaned up %d orphan sync data record(s).', $removed)
					: 'No orphan sync data records remained to fix.',
			];
		}

		if ($issueType === IntegrityIssue::TYPE_DUPLICATE_CLIENT_ID) {
			$fixedSongs = $this->internalSongMapper->fixDuplicateClientIds($libraryId);
			$fixedEntities = $this->internalEntityMapper->fixDuplicateClientIds($libraryId);
			$total = $fixedSongs + $fixedEntities;
			$this->libraryMapper->touch($libraryId);

			return [
				'success' => $total > 0,
				'fixedCount' => $total > 0 ? 1 : 0,
				'message' => $total > 0
					? sprintf('Reset %d duplicate client_id record(s).', $total)
					: 'No duplicate client_id records remained to fix.',
			];
		}

		if ($issueType === IntegrityIssue::TYPE_INVALID_NAMING_RULE && $songId !== null) {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$user = $userFolder->getOwner();
			['databasePath' => $databasePath, 'targetFolder' => $targetFolder] = $this->fileManager->libraryContext($libraryId, $user);
			$hashCodes = MobileSheetsHashCodesFile::forDatabase($databasePath, $this->logger);

			try {
				$this->fileManager->regenerateSongFilePaths($songId, $user, $targetFolder, $databasePath, $hashCodes);
				$hashCodes?->save();

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
		['databasePath' => $databasePath] = $this->fileManager->libraryContext($libraryId, $user);
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
				try {
					$this->fileManager->regenerateSongFilePaths($issue->songId, $user, $targetFolder, $databasePath, $hashCodes);
					$processedSongs[$issue->songId] = true;
					$fixedCount++;
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
					|| !isset($details['entityType'], $details['keepId'], $details['duplicateIds'])
					|| !is_string($details['entityType'])
					|| !is_int($details['keepId'])
					|| !is_array($details['duplicateIds'])) {
					$this->logger->warning('Cannot auto-fix duplicate entity: audit issue details are invalid');
					continue;
				}

				try {
					$normType = InternalEntityMapper::normalizeType($details['entityType']);
					$merged = $this->internalEntityMapper->mergeEntities(
						$libraryId,
						$normType,
						$details['keepId'],
						array_values(array_filter($details['duplicateIds'], 'is_int')),
					);
					$this->libraryMapper->touch($libraryId);
					if ($merged > 0) {
						$fixedCount++;
					}
				} catch (\Throwable $e) {
					$this->logger->warning('Cannot auto-fix duplicate entity records', [
						'exception' => $e,
						'entityType' => $details['entityType'],
						'keepId' => $details['keepId'],
					]);
					$errors[] = sprintf('Failed to merge duplicate entity records: %s', $e->getMessage());
				}
			} elseif ($issue->type === IntegrityIssue::TYPE_ORPHAN_ENTITY_ASSIGNMENT) {
				try {
					$removed = $this->internalEntityMapper->removeOrphanSongEntityAssignments($libraryId);
					$this->libraryMapper->touch($libraryId);
					if ($removed > 0) {
						$fixedCount++;
					}
				} catch (\Throwable $e) {
					$this->logger->warning('Cannot auto-fix orphan entity assignments', ['exception' => $e]);
					$errors[] = sprintf('Failed to remove orphan entity assignments: %s', $e->getMessage());
				}
			} elseif ($issue->type === IntegrityIssue::TYPE_ORPHAN_SONG_FILE) {
				try {
					$removed = $this->internalSongFileMapper->removeOrphanSongFiles($libraryId);
					$this->libraryMapper->touch($libraryId);
					if ($removed > 0) {
						$fixedCount++;
					}
				} catch (\Throwable $e) {
					$this->logger->warning('Cannot auto-fix orphan song files', ['exception' => $e]);
					$errors[] = sprintf('Failed to remove orphan song files: %s', $e->getMessage());
				}
			} elseif ($issue->type === IntegrityIssue::TYPE_ORPHAN_SONG_ATTRIBUTE && $this->internalSongAttributeMapper !== null) {
				try {
					$removed = $this->internalSongAttributeMapper->removeOrphanSongAttributes($libraryId);
					$this->libraryMapper->touch($libraryId);
					if ($removed > 0) {
						$fixedCount++;
					}
				} catch (\Throwable $e) {
					$this->logger->warning('Cannot auto-fix orphan song attributes', ['exception' => $e]);
					$errors[] = sprintf('Failed to remove orphan song attributes: %s', $e->getMessage());
				}
			} elseif ($issue->type === IntegrityIssue::TYPE_ORPHAN_MISC_DATA && $this->internalMiscMapper !== null) {
				try {
					$removed = $this->internalMiscMapper->removeOrphanMiscData($libraryId);
					$this->libraryMapper->touch($libraryId);
					if ($removed > 0) {
						$fixedCount++;
					}
				} catch (\Throwable $e) {
					$this->logger->warning('Cannot auto-fix orphan sync data', ['exception' => $e]);
					$errors[] = sprintf('Failed to remove orphan sync data: %s', $e->getMessage());
				}
			} elseif ($issue->type === IntegrityIssue::TYPE_DUPLICATE_CLIENT_ID) {
				if (!$duplicateClientIdsFixed) {
					try {
						$fixedSongs = $this->internalSongMapper->fixDuplicateClientIds($libraryId);
						$fixedEntities = $this->internalEntityMapper->fixDuplicateClientIds($libraryId);
						$this->libraryMapper->touch($libraryId);
						if (($fixedSongs + $fixedEntities) > 0) {
							$fixedCount++;
						}
						$duplicateClientIdsFixed = true;
					} catch (\Throwable $e) {
						$this->logger->warning('Cannot auto-fix duplicate client IDs', ['exception' => $e]);
						$errors[] = sprintf('Failed to reset duplicate client IDs: %s', $e->getMessage());
					}
				}
			} elseif ($issue->type === IntegrityIssue::TYPE_PDF_METADATA_MISMATCH) {
				try {
					$this->fixPdfMetadataMismatch($issue, $libraryId, $databasePath, $user, $hashCodes);
					$this->libraryMapper?->touch($libraryId);
					$fixedCount++;
				} catch (\Throwable $e) {
					$this->logger->warning('Cannot auto-fix PDF metadata during batch fix', [
						'exception' => $e,
						'songId' => $issue->songId,
						'fileId' => $issue->fileId,
						'path' => $issue->path,
					]);
					$errors[] = sprintf('Failed to update PDF metadata: %s', $e->getMessage());
				}
			} elseif (($issue->type === IntegrityIssue::TYPE_DUPLICATE_FILE_PATH || $issue->type === IntegrityIssue::TYPE_IDENTICAL_SONG) && $issue->songId !== null) {
				if (isset($processedSongs[$issue->songId])) {
					continue;
				}
				try {
					$song = array_values(array_filter($songs, static fn ($s) => $s->id === $issue->songId))[0] ?? null;
					if ($song === null) {
						continue;
					}

					$allSongs = $songs;
					$duplicateSongIds = [];

					if ($issue->type === IntegrityIssue::TYPE_DUPLICATE_FILE_PATH) {
						foreach ($song->files as $f) {
							$cleanF = strtolower(ltrim($f->path, '/'));
							foreach ($allSongs as $otherSong) {
								foreach ($otherSong->files as $otherFile) {
									if ($cleanF === strtolower(ltrim($otherFile->path, '/'))) {
										$duplicateSongIds[] = $otherSong->id;
									}
								}
							}
						}
					} else {
						$targetMetaKey = $this->getCanonicalSongMetadataKey($song);
						foreach ($allSongs as $otherSong) {
							if ($this->getCanonicalSongMetadataKey($otherSong) === $targetMetaKey) {
								$duplicateSongIds[] = $otherSong->id;
							}
						}
					}

					$duplicateSongIds = array_values(array_unique($duplicateSongIds));
					if (count($duplicateSongIds) >= 2) {
						sort($duplicateSongIds);
						$targetSongId = $duplicateSongIds[0];

						for ($i = 1; $i < count($duplicateSongIds); $i++) {
							$droppedId = $duplicateSongIds[$i];
							$this->internalSongMapper->mergeSongs($libraryId, $targetSongId, $droppedId);
							$this->libraryMapper->touch($libraryId);
							$processedSongs[$droppedId] = true;
						}
						$processedSongs[$targetSongId] = true;
						$fixedCount++;
					}
				} catch (\Throwable $e) {
					$this->logger->warning(sprintf('Cannot auto-fix duplicate/identical songs for song ID %d during batch fix: %s', $issue->songId, $e->getMessage()));
					$errors[] = sprintf('Failed to merge identical songs: %s', $e->getMessage());
				}
			}
		}

		if ($fixedCount > 0) {
			$hashCodes?->save();
		}

		if ($fixedCount === 0 && !empty($errors)) {
			return [
				'success' => false,
				'fixedCount' => 0,
				'message' => implode("\n", $errors),
			];
		}

		$message = sprintf('Applied autofix to %d issue(s).', $fixedCount);
		if (!empty($errors)) {
			$message .= ' ' . sprintf('%d issue(s) failed: %s', count($errors), implode('; ', $errors));
		}

		return [
			'success' => true,
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

	private function fixPdfMetadataMismatch(
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
		foreach ($data['songs'] as $s) {
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
	 * Computes a canonical metadata string for a song to detect identical metadata duplicates.
	 */
	private function getCanonicalSongMetadataKey(\OCA\SheetMusic\Db\MobileSheetsSong $song): string {
		$extractNames = static function (array $entities): array {
			$names = array_map(static fn ($e) => $e->name ?? $e->title ?? (string)($e->id ?? ''), $entities);
			sort($names, SORT_STRING);
			return $names;
		};

		$parts = [
			'title:' => trim(strtolower($song->title)),
			'composers:' => implode('|', $extractNames($song->composers)),
			'artists:' => implode('|', $extractNames($song->artists)),
			'books:' => implode('|', $extractNames($song->books)),
			'collections:' => implode('|', $extractNames($song->collections)),
			'genres:' => implode('|', $extractNames($song->genres)),
			'keys:' => implode('|', $extractNames($song->keys)),
			'customGroups:' => implode('|', $extractNames($song->customGroups)),
			'years:' => implode('|', $extractNames($song->years)),
			'signatures:' => implode('|', $extractNames($song->signatures)),
			'sourceTypes:' => implode('|', $extractNames($song->sourceTypes)),
		];

		return json_encode($parts);
	}

	/**
	 * @param list<\OCA\SheetMusic\Db\MobileSheetsSong> $songs
	 */
	private function areSongsExactDuplicates(array $songs): bool {
		if (count($songs) <= 1) {
			return true;
		}

		$first = $songs[0];
		$extractNames = static function (array $entities): array {
			$names = array_map(static fn ($e) => $e->name ?? $e->title ?? (string)$e->id, $entities);
			sort($names, SORT_STRING);
			return $names;
		};

		$firstComposers = $extractNames($first->composers);
		$firstArtists = $extractNames($first->artists);
		$firstBooks = $extractNames($first->books);
		$firstCollections = $extractNames($first->collections);
		$firstGenres = $extractNames($first->genres);
		$firstKeys = $extractNames($first->keys);

		for ($i = 1; $i < count($songs); $i++) {
			$s = $songs[$i];
			if (trim(strtolower($s->title)) !== trim(strtolower($first->title))) {
				return false;
			}
			if ($extractNames($s->composers) !== $firstComposers) {
				return false;
			}
			if ($extractNames($s->artists) !== $firstArtists) {
				return false;
			}
			if ($extractNames($s->books) !== $firstBooks) {
				return false;
			}
			if ($extractNames($s->collections) !== $firstCollections) {
				return false;
			}
			if ($extractNames($s->genres) !== $firstGenres) {
				return false;
			}
			if ($extractNames($s->keys) !== $firstKeys) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Recursively check targetFolder for files not present in DB.
	 *
	 * @param array<string, mixed> $referencedRelativePaths
	 * @param list<IntegrityIssue> $issues
	 */
	private function scanOrphans(Folder $folder, string $targetFolderPath, array $referencedRelativePaths, array &$issues, \OCP\IUser $user): void {
		$nodes = $folder->getDirectoryListing();
		foreach ($nodes as $node) {
			if ($node instanceof Folder) {
				$this->scanOrphans($node, $targetFolderPath, $referencedRelativePaths, $issues, $user);
			} elseif ($node instanceof Node) {
				$nodePath = $node->getPath();
				$relativeToTarget = str_starts_with($nodePath, $targetFolderPath . '/')
					? substr($nodePath, strlen($targetFolderPath) + 1)
					: $node->getName();
				$filename = $node->getName();

				// Ignore database file and hash codes file
				if ($filename === 'mobilesheets.db' || str_ends_with($filename, '.db') || str_ends_with($filename, 'hashcodes.txt')) {
					continue;
				}

				$cleanRelative = strtolower(ltrim($relativeToTarget, '/'));
				if (!isset($referencedRelativePaths[$cleanRelative])) {
					$storedPath = null;
					try {
						$storedPath = $this->fileManager->pathOfExistingFile($node->getPath(), $user);
					} catch (\Throwable) {
						// ignore if path cannot be resolved
					}

					$userFolder = $this->rootFolder->getUserFolder($user->getUID());
					$userFolderPath = (string)$userFolder->getPath();
					$nodePath = (string)$node->getPath();
					$userRelativePath = str_starts_with($nodePath, $userFolderPath . '/')
						? substr($nodePath, strlen($userFolderPath) + 1)
						: ltrim($relativeToTarget, '/');

					$issues[] = new IntegrityIssue(
						type: IntegrityIssue::TYPE_ORPHAN_FILE,
						severity: IntegrityIssue::SEVERITY_WARNING,
						message: sprintf('File "%s" exists in library storage but is not linked to any song.', $filename),
						path: $relativeToTarget,
						fixable: false,
						details: [
							'webdavPath' => $storedPath?->webdav,
							'userRelativePath' => $userRelativePath,
							'filename' => $filename,
						],
					);
				}
			}
		}
	}

	/**
	 * Permanently delete an orphan file from library storage.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function deleteOrphanFile(int $libraryId, string $userId, string $orphanPath): array {
		if (trim($orphanPath) === '') {
			return ['success' => false, 'message' => 'Orphan file path cannot be empty.'];
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$user = $userFolder->getOwner();
		['targetFolder' => $targetFolder] = $this->fileManager->libraryContext($libraryId, $user);

		$cleanPath = ltrim($orphanPath, '/');
		$node = null;

		try {
			if ($userFolder->nodeExists($cleanPath)) {
				$node = $userFolder->get($cleanPath);
			}
		} catch (\Throwable) {
			// ignore
		}

		if ($node === null) {
			try {
				if ($targetFolder->nodeExists($cleanPath)) {
					$node = $targetFolder->get($cleanPath);
				}
			} catch (\Throwable) {
				// ignore
			}
		}

		if ($node === null) {
			return ['success' => false, 'message' => sprintf('Orphan file "%s" was not found.', basename($orphanPath))];
		}

		$filename = $node->getName();
		$node->delete();

		return [
			'success' => true,
			'message' => sprintf('Deleted orphan file "%s".', $filename),
		];
	}
}
