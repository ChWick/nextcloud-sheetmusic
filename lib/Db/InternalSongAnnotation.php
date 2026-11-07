<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Db;

use JsonSerializable;

class InternalSongAnnotation implements JsonSerializable {
	public function __construct(
		public ?int $id,
		public string $uuid,
		public int $songId,
		public int $fileId,
		public int $pageNumber = 0,
		public int $layerIndex = 0,
		public string $type = 'drawing',
		public float $x = 0.0,
		public float $y = 0.0,
		public float $width = 0.0,
		public float $height = 0.0,
		public array $style = [],
		public array $data = [],
		public int $createdAt = 0,
		public int $updatedAt = 0,
		public ?int $deletedAt = null,
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self {
		$style = [];
		if (isset($row['style_json']) && is_string($row['style_json'])) {
			$decoded = json_decode($row['style_json'], true);
			if (is_array($decoded)) {
				$style = $decoded;
			}
		}

		$data = [];
		if (isset($row['data_json']) && is_string($row['data_json'])) {
			$decoded = json_decode($row['data_json'], true);
			if (is_array($decoded)) {
				$data = $decoded;
			}
		}

		return new self(
			id: isset($row['id']) ? (int)$row['id'] : null,
			uuid: (string)($row['uuid'] ?? ''),
			songId: (int)($row['song_id'] ?? 0),
			fileId: (int)($row['file_id'] ?? 0),
			pageNumber: (int)($row['page_number'] ?? 0),
			layerIndex: (int)($row['layer_index'] ?? 0),
			type: (string)($row['type'] ?? 'drawing'),
			x: (float)($row['x'] ?? 0.0),
			y: (float)($row['y'] ?? 0.0),
			width: (float)($row['width'] ?? 0.0),
			height: (float)($row['height'] ?? 0.0),
			style: $style,
			data: $data,
			createdAt: isset($row['created_at']) ? (int)$row['created_at'] : 0,
			updatedAt: isset($row['updated_at']) ? (int)$row['updated_at'] : 0,
			deletedAt: isset($row['deleted_at']) && $row['deleted_at'] !== null ? (int)$row['deleted_at'] : null,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'songId' => $this->songId,
			'fileId' => $this->fileId,
			'pageNumber' => $this->pageNumber,
			'layerIndex' => $this->layerIndex,
			'type' => $this->type,
			'x' => $this->x,
			'y' => $this->y,
			'width' => $this->width,
			'height' => $this->height,
			'style' => $this->style,
			'data' => $this->data,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'deletedAt' => $this->deletedAt,
		];
	}
}
