<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\ForScore;

use OCA\SheetMusic\Db\InternalSongAnnotation;

class ForScoreAnnotationAdapter {
	public const DEFAULT_PDF_WIDTH = 595.0; // standard A4 points (72 DPI)
	public const DEFAULT_PDF_HEIGHT = 842.0;

	/**
	 * Convert forScore annotation dictionary records into canonical InternalSongAnnotation entities.
	 *
	 * @param list<array<string, mixed>> $forScoreRecords
	 * @return list<InternalSongAnnotation>
	 */
	public function importAnnotations(
		array $forScoreRecords,
		int $songId,
		int $fileId,
		float $pdfWidth = self::DEFAULT_PDF_WIDTH,
		float $pdfHeight = self::DEFAULT_PDF_HEIGHT,
	): array {
		$annotations = [];
		$w = $pdfWidth > 0 ? $pdfWidth : self::DEFAULT_PDF_WIDTH;
		$h = $pdfHeight > 0 ? $pdfHeight : self::DEFAULT_PDF_HEIGHT;

		foreach ($forScoreRecords as $index => $rec) {
			$typeStr = strtolower((string)($rec['type'] ?? 'drawing'));
			$page = (int)($rec['page'] ?? 0);
			$layer = (int)($rec['layer'] ?? 0);
			$uuid = (string)($rec['uuid'] ?? sprintf('forscore-%08x-%04x', $index, $index & 0xFFFF));

			if ($typeStr === 'text') {
				$rect = $rec['rect'] ?? [0, 0, 100, 30];
				$x = (float)($rect[0] ?? 0) / $w;
				$y = (float)($rect[1] ?? 0) / $h;
				$bw = (float)($rect[2] ?? 100) / $w;
				$bh = (float)($rect[3] ?? 30) / $h;

				$color = self::colorArrayToHex($rec['color'] ?? [0, 0, 0, 1]);

				$annotations[] = new InternalSongAnnotation(
					id: null,
					uuid: $uuid,
					songId: $songId,
					fileId: $fileId,
					pageNumber: $page,
					layerIndex: $layer,
					type: 'text',
					x: round($x, 4),
					y: round($y, 4),
					width: round($bw, 4),
					height: round($bh, 4),
					style: [
						'color' => $color,
					],
					data: [
						'text' => (string)($rec['text'] ?? ''),
						'fontSize' => (float)($rec['fontSize'] ?? 14.0),
					],
				);
			} elseif ($typeStr === 'link') {
				$rect = $rec['rect'] ?? [0, 0, 50, 50];
				$annotations[] = new InternalSongAnnotation(
					id: null,
					uuid: $uuid,
					songId: $songId,
					fileId: $fileId,
					pageNumber: $page,
					layerIndex: $layer,
					type: 'link',
					x: round((float)($rect[0] ?? 0) / $w, 4),
					y: round((float)($rect[1] ?? 0) / $h, 4),
					width: round((float)($rect[2] ?? 50) / $w, 4),
					height: round((float)($rect[3] ?? 50) / $h, 4),
					style: [],
					data: [
						'targetPage' => (int)($rec['targetPage'] ?? $page),
					],
				);
			} else {
				// Freehand drawing / highlight
				$rawPoints = $rec['points'] ?? [];
				$normalizedPoints = [];
				foreach ($rawPoints as $pt) {
					$normalizedPoints[] = [
						'x' => round((float)($pt[0] ?? 0) / $w, 5),
						'y' => round((float)($pt[1] ?? 0) / $h, 5),
					];
				}

				if (empty($normalizedPoints)) {
					continue;
				}

				$color = self::colorArrayToHex($rec['color'] ?? [1, 0, 0, 1]);
				$opacity = (float)($rec['opacity'] ?? 1.0);
				$lineWidth = (float)($rec['lineWidth'] ?? 2.0);
				$isHighlight = $typeStr === 'highlight' || $opacity < 0.6;

				$pathD = '';
				foreach ($normalizedPoints as $i => $pt) {
					$sx = round($pt['x'] * 1000, 1);
					$sy = round($pt['y'] * 1000, 1);
					$pathD .= ($i === 0 ? "M $sx $sy" : " L $sx $sy");
				}

				$annotations[] = new InternalSongAnnotation(
					id: null,
					uuid: $uuid,
					songId: $songId,
					fileId: $fileId,
					pageNumber: $page,
					layerIndex: $layer,
					type: $isHighlight ? 'highlight' : 'drawing',
					x: 0.0,
					y: 0.0,
					width: 1.0,
					height: 1.0,
					style: [
						'stroke' => $color,
						'strokeWidth' => round($lineWidth / $w * 1000, 2),
						'opacity' => $opacity,
						'blendMode' => $isHighlight ? 'multiply' : 'normal',
					],
					data: [
						'path' => $pathD,
						'points' => $normalizedPoints,
					],
				);
			}
		}

		return $annotations;
	}

	/**
	 * Convert canonical InternalSongAnnotation entities into forScore annotation dictionary records.
	 *
	 * @param list<InternalSongAnnotation> $annotations
	 * @return list<array<string, mixed>>
	 */
	public function exportAnnotations(
		array $annotations,
		float $pdfWidth = self::DEFAULT_PDF_WIDTH,
		float $pdfHeight = self::DEFAULT_PDF_HEIGHT,
	): array {
		$records = [];
		$w = $pdfWidth > 0 ? $pdfWidth : self::DEFAULT_PDF_WIDTH;
		$h = $pdfHeight > 0 ? $pdfHeight : self::DEFAULT_PDF_HEIGHT;

		foreach ($annotations as $ann) {
			if ($ann->deletedAt !== null) {
				continue;
			}

			if ($ann->type === 'text') {
				$records[] = [
					'uuid' => $ann->uuid,
					'type' => 'text',
					'page' => $ann->pageNumber,
					'layer' => $ann->layerIndex,
					'rect' => [
						round($ann->x * $w, 1),
						round($ann->y * $h, 1),
						round($ann->width * $w, 1),
						round($ann->height * $h, 1),
					],
					'text' => (string)($ann->data['text'] ?? ''),
					'fontSize' => (float)($ann->data['fontSize'] ?? 14.0),
					'color' => self::hexToColorArray((string)($ann->style['color'] ?? '#000000')),
				];
			} elseif ($ann->type === 'link') {
				$records[] = [
					'uuid' => $ann->uuid,
					'type' => 'link',
					'page' => $ann->pageNumber,
					'layer' => $ann->layerIndex,
					'rect' => [
						round($ann->x * $w, 1),
						round($ann->y * $h, 1),
						round($ann->width * $w, 1),
						round($ann->height * $h, 1),
					],
					'targetPage' => (int)($ann->data['targetPage'] ?? $ann->pageNumber),
				];
			} else {
				// Drawing or highlight
				$points = $ann->data['points'] ?? [];
				$rawPoints = [];
				foreach ($points as $pt) {
					$rawPoints[] = [
						round((float)($pt['x'] ?? 0) * $w, 2),
						round((float)($pt['y'] ?? 0) * $h, 2),
					];
				}

				$colorHex = (string)($ann->style['stroke'] ?? '#ff0000');
				$strokeW = (float)($ann->style['strokeWidth'] ?? 2.0);
				$opacity = (float)($ann->style['opacity'] ?? 1.0);

				$records[] = [
					'uuid' => $ann->uuid,
					'type' => $ann->type === 'highlight' ? 'highlight' : 'drawing',
					'page' => $ann->pageNumber,
					'layer' => $ann->layerIndex,
					'lineWidth' => round($strokeW * ($w / 1000.0), 2),
					'opacity' => $opacity,
					'color' => self::hexToColorArray($colorHex),
					'points' => $rawPoints,
				];
			}
		}

		return $records;
	}

	/**
	 * Convert RGBA float array [r, g, b, a] (0.0 to 1.0) into Hex string.
	 *
	 * @param list<float|int> $color
	 */
	public static function colorArrayToHex(array $color): string {
		$r = (int)round((float)($color[0] ?? 0.0) * 255);
		$g = (int)round((float)($color[1] ?? 0.0) * 255);
		$b = (int)round((float)($color[2] ?? 0.0) * 255);
		$a = (int)round((float)($color[3] ?? 1.0) * 255);

		if ($a === 255) {
			return sprintf('#%02x%02x%02x', $r, $g, $b);
		}
		return sprintf('#%02x%02x%02x%02x', $r, $g, $b, $a);
	}

	/**
	 * Convert Hex string into RGBA float array [r, g, b, a].
	 *
	 * @return list<float>
	 */
	public static function hexToColorArray(string $hex): array {
		$hex = ltrim($hex, '#');
		$r = 0;
		$g = 0;
		$b = 0;
		$a = 1.0;

		if (strlen($hex) === 6) {
			$r = hexdec(substr($hex, 0, 2)) / 255.0;
			$g = hexdec(substr($hex, 2, 2)) / 255.0;
			$b = hexdec(substr($hex, 4, 2)) / 255.0;
		} elseif (strlen($hex) === 8) {
			$r = hexdec(substr($hex, 0, 2)) / 255.0;
			$g = hexdec(substr($hex, 2, 2)) / 255.0;
			$b = hexdec(substr($hex, 4, 2)) / 255.0;
			$a = hexdec(substr($hex, 6, 2)) / 255.0;
		}

		return [round($r, 3), round($g, 3), round($b, 3), round($a, 3)];
	}
}
