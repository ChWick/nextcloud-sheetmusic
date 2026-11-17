<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

use OCA\SheetMusic\Db\InternalAnnotationSyncMapper;
use OCA\SheetMusic\Db\InternalSongAnnotation;
use OCA\SheetMusic\Db\InternalSongAnnotationMapper;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Sync\ForScore\ForScoreAnnotationAdapter;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsAnnotationAdapter;
use PDO;

class SongAnnotationService {
	public function __construct(
		private InternalSongAnnotationMapper $annotationMapper,
		private ?InternalAnnotationSyncMapper $syncMapper = null,
		private ?MobileSheetsAnnotationAdapter $mobileSheetsAdapter = null,
		private ?ForScoreAnnotationAdapter $forScoreAdapter = null,
		private ?InternalSongFileMapper $songFileMapper = null,
	) {
		$this->mobileSheetsAdapter ??= new MobileSheetsAnnotationAdapter();
		$this->forScoreAdapter ??= new ForScoreAnnotationAdapter();
	}

	/**
	 * @return array<int, true>
	 */
	public function findSongIdsWithAnnotations(int $libraryId, bool $includeDeleted = false): array {
		return $this->annotationMapper->findSongIdsWithAnnotations($libraryId, $includeDeleted);
	}

	/**
	 * @return list<InternalSongAnnotation>
	 */
	public function getAnnotationsForSong(int $songId, ?int $fileId = null, bool $includeDeleted = false): array {
		if ($fileId !== null && $fileId > 0) {
			return $this->annotationMapper->findForSongFile($songId, $fileId, $includeDeleted);
		}
		return $this->annotationMapper->findForSong($songId, $includeDeleted);
	}

	/**
	 * @return list<InternalSongAnnotation>
	 */
	public function getAnnotationsForFile(int $fileId, bool $includeDeleted = false): array {
		if ($fileId <= 0) {
			return [];
		}
		return $this->annotationMapper->findForFile($fileId, $includeDeleted);
	}

	public function findAnnotationById(int $id): ?InternalSongAnnotation {
		return $this->annotationMapper->findById($id);
	}

	public function saveAnnotation(InternalSongAnnotation $annotation): InternalSongAnnotation {
		return $this->annotationMapper->save($annotation);
	}

	public function deleteAnnotation(int $id, bool $soft = true): void {
		$this->annotationMapper->delete($id, $soft);
	}

	/**
	 * Import annotations from MobileSheets SQLite database and save to canonical storage.
	 *
	 * @return list<InternalSongAnnotation>
	 */
	public function importFromMobileSheets(PDO $db, int $songId, int $fileId, int $clientSongId): array {
		$existing = $this->annotationMapper->findForSong($songId, includeDeleted: false);
		$imported = $this->mobileSheetsAdapter->importAnnotations($db, $songId, $fileId, $clientSongId);
		$saved = [];
		$importedUuids = [];

		foreach ($imported as $ann) {
			$clientRecId = (string)($ann->data['clientRecordId'] ?? '');
			$scopedClientRecId = $clientRecId !== '' ? "{$songId}:{$clientRecId}" : '';
			if ($clientRecId !== '' && $this->syncMapper !== null) {
				$existingId = $this->syncMapper->findAnnotationId('mobilesheets', $scopedClientRecId)
					?? $this->syncMapper->findAnnotationId('mobilesheets', $clientRecId);
				if ($existingId !== null) {
					$existingAnn = $this->annotationMapper->findById($existingId);
					if ($existingAnn !== null && $existingAnn->songId === $songId) {
						$ann->id = $existingAnn->id;
						$ann->uuid = $existingAnn->uuid;
					}
				}
			}

			// Fingerprint fallback: match by page, type, and geometry to prevent duplicate creation if client renumbered IDs
			if ($ann->id === null) {
				foreach ($existing as $old) {
					if (
						$old->pageNumber === $ann->pageNumber
						&& $old->type === $ann->type
						&& abs($old->x - $ann->x) < 0.001
						&& abs($old->y - $ann->y) < 0.001
						&& abs($old->width - $ann->width) < 0.001
						&& abs($old->height - $ann->height) < 0.001
					) {
						$ann->id = $old->id;
						$ann->uuid = $old->uuid;
						break;
					}
				}
			}

			$savedAnn = $this->saveAnnotation($ann);
			if ($savedAnn->id !== null) {
				$importedUuids[$savedAnn->uuid] = true;
				if ($clientRecId !== '' && $this->syncMapper !== null) {
					$this->syncMapper->link($savedAnn->id, 'mobilesheets', $scopedClientRecId);
				}
			}
			$saved[] = $savedAnn;
		}

		// Reconcile deletions: any annotation previously synced from MobileSheets that is missing in the incoming set was deleted on client
		foreach ($existing as $old) {
			if ($old->id === null || isset($importedUuids[$old->uuid])) {
				continue;
			}
			$clientRecId = $this->syncMapper?->findClientRecordId($old->id, 'mobilesheets');
			$isFromMobileSheets = $clientRecId !== null || str_starts_with($old->uuid, 'mobilesheets');
			if ($isFromMobileSheets) {
				$this->annotationMapper->delete($old->id, soft: true);
				if ($this->syncMapper !== null) {
					$this->syncMapper->unlink($old->id, 'mobilesheets');
				}
			}
		}

		return $saved;
	}

	/**
	 * Export canonical annotations to a MobileSheets SQLite database.
	 */
	public function exportToMobileSheets(
		PDO $db,
		int $songId,
		int $clientSongId,
		float $sourceWidth = 595.0,
		float $sourceHeight = 842.0,
	): void {
		$annotations = $this->annotationMapper->findForSong($songId, includeDeleted: false);
		$clientRecordIds = [];
		if ($this->syncMapper !== null) {
			foreach ($annotations as $ann) {
				if ($ann->id !== null) {
					$recId = $this->syncMapper->findClientRecordId($ann->id, 'mobilesheets');
					if ($recId !== null) {
						if (str_contains($recId, ':')) {
							$recId = explode(':', $recId, 2)[1];
						}
						if (is_numeric($recId)) {
							$clientRecordIds[$ann->id] = (int)$recId;
						}
					}
				}
			}
		}

		$mapped = $this->mobileSheetsAdapter->exportAnnotations(
			$db,
			$clientSongId,
			$annotations,
			$sourceWidth,
			$sourceHeight,
			$clientRecordIds,
		);

		if ($this->syncMapper !== null && !empty($mapped)) {
			foreach ($mapped as $annId => $baseId) {
				$this->syncMapper->link($annId, 'mobilesheets', "{$songId}:{$baseId}");
			}
		}
	}

	/**
	 * Import annotations from forScore records and save to canonical storage.
	 *
	 * @param list<array<string, mixed>> $forScoreRecords
	 * @return list<InternalSongAnnotation>
	 */
	public function importFromForScore(
		array $forScoreRecords,
		int $songId,
		int $fileId,
		float $pdfWidth = 595.0,
		float $pdfHeight = 842.0,
	): array {
		$imported = $this->forScoreAdapter->importAnnotations($forScoreRecords, $songId, $fileId, $pdfWidth, $pdfHeight);
		$saved = [];
		foreach ($imported as $ann) {
			$savedAnn = $this->annotationMapper->save($ann);
			if ($this->syncMapper !== null && $savedAnn->id !== null) {
				$this->syncMapper->link($savedAnn->id, 'forscore', $savedAnn->uuid);
			}
			$saved[] = $savedAnn;
		}
		return $saved;
	}

	/**
	 * Export canonical annotations to forScore records.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function exportToForScore(
		int $songId,
		float $pdfWidth = 595.0,
		float $pdfHeight = 842.0,
	): array {
		$annotations = $this->annotationMapper->findForSong($songId, includeDeleted: false);
		return $this->forScoreAdapter->exportAnnotations($annotations, $pdfWidth, $pdfHeight);
	}
}
