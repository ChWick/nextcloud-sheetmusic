<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf;

class InternalSongFile {
	public const FILE_TYPE_OTHER = 0;
	public const FILE_TYPE_PDF = 1;
	public const FILE_TYPE_IMAGE = 2;
	public const FILE_TYPE_TEXT = 3;
	public const FILE_TYPE_ABC = 4;

	public static function determineFileType(string $path): int {
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return match ($extension) {
			'pdf' => self::FILE_TYPE_PDF,
			'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg' => self::FILE_TYPE_IMAGE,
			'txt', 'text', 'cho', 'chopro', 'chordpro', 'crd', 'pro' => self::FILE_TYPE_TEXT,
			'abc' => self::FILE_TYPE_ABC,
			default => self::FILE_TYPE_OTHER,
		};
	}

	public function __construct(
		public ?int $id = null,
		public string $uuid = '',
		public int $songId = 0,
		public int $fileId = 0,
		public ?string $pageOrder = null,
		public int $pageCount = 0,
		public int $fileSize = 0,
		public ?int $fileHash = null,
		public int $type = 0,
		public ?int $clientId = null,
		public int $createdAt = 0,
		public int $updatedAt = 0,
		public ?int $deletedAt = null,
		public ?string $cropBoxes = null,
		public ?int $cropBoxesHash = null,
	) {
	}

	/**
	 * Validate and normalize a map of 1-indexed page numbers to normalized [0..1] crop boxes.
	 *
	 * @param array<mixed> $raw
	 * @return array<string, array{left: float, top: float, right: float, bottom: float}>
	 */
	public static function sanitizeCropBoxes(array $raw): array {
		$clean = [];
		foreach ($raw as $pageKey => $box) {
			if (!is_numeric($pageKey)) {
				continue;
			}
			$pageNum = (int)$pageKey;
			if ($pageNum < 1 || $pageNum > 5000 || !is_array($box)) {
				continue;
			}
			$left = isset($box['left']) && is_numeric($box['left']) ? (float)$box['left'] : null;
			$top = isset($box['top']) && is_numeric($box['top']) ? (float)$box['top'] : null;
			$right = isset($box['right']) && is_numeric($box['right']) ? (float)$box['right'] : null;
			$bottom = isset($box['bottom']) && is_numeric($box['bottom']) ? (float)$box['bottom'] : null;
			if ($left === null || $top === null || $right === null || $bottom === null) {
				continue;
			}
			$left = max(0.0, min(1.0, round($left, 4)));
			$top = max(0.0, min(1.0, round($top, 4)));
			$right = max(0.0, min(1.0, round($right, 4)));
			$bottom = max(0.0, min(1.0, round($bottom, 4)));
			if ($right <= $left || $bottom <= $top) {
				continue;
			}
			$clean[(string)$pageNum] = [
				'left' => $left,
				'top' => $top,
				'right' => $right,
				'bottom' => $bottom,
			];
		}
		return $clean;
	}

	/**
	 * Decode cached per-page tight crop boxes if present and matching the current file hash.
	 *
	 * @return ?array<string, array{left: float, top: float, right: float, bottom: float}>
	 */
	public function getCropBoxesMap(): ?array {
		if ($this->cropBoxes === null || $this->cropBoxes === '') {
			return null;
		}
		if ($this->fileHash !== null && $this->cropBoxesHash !== null && $this->cropBoxesHash !== $this->fileHash) {
			return null;
		}
		$decoded = json_decode($this->cropBoxes, true);
		if (!is_array($decoded) || $decoded === []) {
			return null;
		}
		$valid = self::sanitizeCropBoxes($decoded);
		return $valid !== [] ? $valid : null;
	}

	public function invalidateCropBoxes(): void {
		$this->cropBoxes = null;
		$this->cropBoxesHash = null;
	}

	/**
	 * Normalizes a page order string.
	 * If empty, defaults to '1-N' (or '1-1').
	 * If provided, preserves whatever MobileSheets uploaded (e.g. '1', '1-1', '1,2,3').
	 */
	public static function normalizePageOrder(?string $pageOrder, int $pageCount = 1): ?string {
		if ($pageOrder === null) {
			return null;
		}
		$trimmed = trim($pageOrder);
		if ($trimmed === '') {
			return MobileSheetsPdf::pageOrder($pageCount);
		}
		return $trimmed;
	}

	/**
	 * Page order to keep after a file's page count changed.
	 *
	 * An empty order, or the default whole-file order derived from the old page
	 * count (e.g. '1-1' recorded while the file was still empty), is replaced
	 * with the whole-file order for the new count. A custom order set by
	 * MobileSheets (e.g. '1,3,2') is preserved.
	 */
	public static function refreshPageOrder(?string $currentOrder, int $oldPageCount, int $newPageCount): string {
		$current = trim((string)$currentOrder);
		if ($current === ''
			|| $current === MobileSheetsPdf::pageOrder($oldPageCount)
			|| $current === MobileSheetsPdf::pageOrder(1)
			|| $current === '1') {
			return MobileSheetsPdf::pageOrder($newPageCount);
		}
		return $current;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self {
		$pageCount = isset($row['page_count']) ? (int)$row['page_count'] : 0;
		$rawPageOrder = isset($row['page_order']) && $row['page_order'] !== null ? (string)$row['page_order'] : null;
		$pageOrder = self::normalizePageOrder($rawPageOrder, $pageCount);

		return new self(
			id: isset($row['id']) ? (int)$row['id'] : null,
			uuid: (string)$row['uuid'],
			songId: (int)$row['song_id'],
			fileId: (int)$row['file_id'],
			pageOrder: $pageOrder,
			pageCount: $pageCount,
			fileSize: isset($row['file_size']) ? (int)$row['file_size'] : 0,
			fileHash: isset($row['file_hash']) && $row['file_hash'] !== null ? (int)$row['file_hash'] : null,
			type: isset($row['type']) ? (int)$row['type'] : 0,
			clientId: isset($row['client_id']) && $row['client_id'] !== null ? (int)$row['client_id'] : null,
			createdAt: isset($row['created_at']) ? (int)$row['created_at'] : 0,
			updatedAt: isset($row['updated_at']) ? (int)$row['updated_at'] : 0,
			deletedAt: isset($row['deleted_at']) && $row['deleted_at'] !== null ? (int)$row['deleted_at'] : null,
			cropBoxes: isset($row['crop_boxes']) && $row['crop_boxes'] !== null ? (string)$row['crop_boxes'] : null,
			cropBoxesHash: isset($row['crop_boxes_hash']) && $row['crop_boxes_hash'] !== null ? (int)$row['crop_boxes_hash'] : null,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toRow(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'song_id' => $this->songId,
			'file_id' => $this->fileId,
			'page_order' => $this->pageOrder,
			'page_count' => $this->pageCount,
			'file_size' => $this->fileSize,
			'file_hash' => $this->fileHash,
			'type' => $this->type,
			'client_id' => $this->clientId,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
			'deleted_at' => $this->deletedAt,
			'crop_boxes' => $this->cropBoxes,
			'crop_boxes_hash' => $this->cropBoxesHash,
		];
	}
}
