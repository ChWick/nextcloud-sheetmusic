<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Util;

/**
 * Utility for SVG path curve smoothing and coordinates calculation.
 */
final class SvgCurveUtil {
	/**
	 * Generate SVG path string with 0..1000 coordinate space.
	 * Applies Catmull-Rom cubic Bezier curve smoothing for freehand strokes with 3+ points.
	 *
	 * @param list<array{x: float, y: float}> $points Normalized [0..1] coordinates
	 */
	public static function generateSvgPath(array $points, bool $smooth = true): string {
		if (empty($points)) {
			return '';
		}

		$filtered = [];
		foreach ($points as $pt) {
			$last = end($filtered);
			if ($last === false || abs($last['x'] - $pt['x']) > 0.0001 || abs($last['y'] - $pt['y']) > 0.0001) {
				$filtered[] = $pt;
			}
		}

		$count = count($filtered);
		if ($count === 0) {
			return '';
		}

		$sx0 = round($filtered[0]['x'] * 1000, 1);
		$sy0 = round($filtered[0]['y'] * 1000, 1);

		if ($count === 1) {
			return "M $sx0 $sy0";
		}
		if ($count === 2 || !$smooth) {
			$d = "M $sx0 $sy0";
			for ($i = 1; $i < $count; $i++) {
				$sx = round($filtered[$i]['x'] * 1000, 1);
				$sy = round($filtered[$i]['y'] * 1000, 1);
				$d .= " L $sx $sy";
			}
			return $d;
		}

		// Catmull-Rom spline converted to cubic Bezier curves
		$d = "M $sx0 $sy0";
		for ($i = 0; $i < $count - 1; $i++) {
			$p0 = $filtered[max(0, $i - 1)];
			$p1 = $filtered[$i];
			$p2 = $filtered[$i + 1];
			$p3 = $filtered[min($count - 1, $i + 2)];

			$cp1x = round(($p1['x'] + ($p2['x'] - $p0['x']) / 6.0) * 1000, 1);
			$cp1y = round(($p1['y'] + ($p2['y'] - $p0['y']) / 6.0) * 1000, 1);
			$cp2x = round(($p2['x'] - ($p3['x'] - $p1['x']) / 6.0) * 1000, 1);
			$cp2y = round(($p2['y'] - ($p3['y'] - $p1['y']) / 6.0) * 1000, 1);
			$endX = round($p2['x'] * 1000, 1);
			$endY = round($p2['y'] * 1000, 1);

			$d .= " C $cp1x $cp1y, $cp2x $cp2y, $endX $endY";
		}

		return $d;
	}

	/**
	 * @param list<array{x: float, y: float}> $points
	 * @return array{x: float, y: float, width: float, height: float}
	 */
	public static function calculateBoundingBox(array $points): array {
		if (empty($points)) {
			return ['x' => 0.0, 'y' => 0.0, 'width' => 0.0, 'height' => 0.0];
		}

		$minX = 1.0;
		$minY = 1.0;
		$maxX = 0.0;
		$maxY = 0.0;

		foreach ($points as $pt) {
			$minX = min($minX, $pt['x']);
			$minY = min($minY, $pt['y']);
			$maxX = max($maxX, $pt['x']);
			$maxY = max($maxY, $pt['y']);
		}

		return [
			'x' => round($minX, 4),
			'y' => round($minY, 4),
			'width' => round(max(0.001, $maxX - $minX), 4),
			'height' => round(max(0.001, $maxY - $minY), 4),
		];
	}
}
