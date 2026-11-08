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
	 * Applies quadratic Bezier midpoint curve smoothing (Android Path.quadTo algorithm)
	 * for freehand strokes with 3+ points.
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

		// Quadratic Bezier midpoint smoothing: connects midpoints between successive control points
		$d = "M $sx0 $sy0";
		for ($i = 1; $i < $count - 1; $i++) {
			$cx = round($filtered[$i]['x'] * 1000, 1);
			$cy = round($filtered[$i]['y'] * 1000, 1);
			$endX = round((($filtered[$i]['x'] + $filtered[$i + 1]['x']) / 2.0) * 1000, 1);
			$endY = round((($filtered[$i]['y'] + $filtered[$i + 1]['y']) / 2.0) * 1000, 1);

			$d .= " Q $cx $cy, $endX $endY";
		}
		$lastX = round($filtered[$count - 1]['x'] * 1000, 1);
		$lastY = round($filtered[$count - 1]['y'] * 1000, 1);
		$d .= " L $lastX $lastY";

		return $d;
	}

	/**
	 * Generate an SVG path for a crescendo (<) or decrescendo (>) hairpin wedge.
	 *
	 * @param array{x: float, y: float} $p1 Start point [0..1]
	 * @param array{x: float, y: float} $p2 End point [0..1]
	 * @param bool $isDecrescendo False for crescendo (<), true for decrescendo (>)
	 * @param float|null $aperture Opening height in points or null for proportional
	 */
	public static function generateHairpinPath(
		array $p1,
		array $p2,
		bool $isDecrescendo = false,
		?float $aperture = null,
	): string {
		$x1 = $p1['x'] * 1000.0;
		$y1 = $p1['y'] * 1000.0;
		$x2 = $p2['x'] * 1000.0;
		$y2 = $p2['y'] * 1000.0;

		$dx = $x2 - $x1;
		$dy = $y2 - $y1;
		$len = hypot($dx, $dy);
		if ($len < 0.001) {
			$len = 1.0;
		}

		// Unit perpendicular vector
		$nx = -$dy / $len;
		$ny = $dx / $len;

		// Half-height of the opening (aperture)
		$h = $aperture !== null && $aperture > 0
			? min(45.0, max(14.0, $aperture * 4.5))
			: min(35.0, max(16.0, $len * 0.12));

		if (!$isDecrescendo) {
			// Crescendo (<): closed at p1, open at p2
			$topX = round($x2 + $nx * $h, 1);
			$topY = round($y2 + $ny * $h, 1);
			$midX = round($x1, 1);
			$midY = round($y1, 1);
			$botX = round($x2 - $nx * $h, 1);
			$botY = round($y2 - $ny * $h, 1);

			return "M $topX $topY L $midX $midY L $botX $botY";
		}

		// Decrescendo (>): open at p1, closed at p2
		$topX = round($x1 + $nx * $h, 1);
		$topY = round($y1 + $ny * $h, 1);
		$midX = round($x2, 1);
		$midY = round($y2, 1);
		$botX = round($x1 - $nx * $h, 1);
		$botY = round($y1 - $ny * $h, 1);

		return "M $topX $topY L $midX $midY L $botX $botY";
	}

	/**
	 * Calculate bounding box for a hairpin.
	 *
	 * @return array{x: float, y: float, width: float, height: float}
	 */
	public static function calculateHairpinBoundingBox(
		array $p1,
		array $p2,
		bool $isDecrescendo = false,
		?float $aperture = null,
	): array {
		$x1 = $p1['x'] * 1000.0;
		$y1 = $p1['y'] * 1000.0;
		$x2 = $p2['x'] * 1000.0;
		$y2 = $p2['y'] * 1000.0;

		$dx = $x2 - $x1;
		$dy = $y2 - $y1;
		$len = hypot($dx, $dy);
		if ($len < 0.001) {
			$len = 1.0;
		}

		$nx = -$dy / $len;
		$ny = $dx / $len;

		$h = $aperture !== null && $aperture > 0
			? min(45.0, max(14.0, $aperture * 4.5))
			: min(35.0, max(16.0, $len * 0.12));

		if (!$isDecrescendo) {
			$pts = [
				['x' => ($x2 + $nx * $h) / 1000.0, 'y' => ($y2 + $ny * $h) / 1000.0],
				['x' => $p1['x'], 'y' => $p1['y']],
				['x' => ($x2 - $nx * $h) / 1000.0, 'y' => ($y2 - $ny * $h) / 1000.0],
			];
		} else {
			$pts = [
				['x' => ($x1 + $nx * $h) / 1000.0, 'y' => ($y1 + $ny * $h) / 1000.0],
				['x' => $p2['x'], 'y' => $p2['y']],
				['x' => ($x1 - $nx * $h) / 1000.0, 'y' => ($y1 - $ny * $h) / 1000.0],
			];
		}

		return self::calculateBoundingBox($pts);
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
