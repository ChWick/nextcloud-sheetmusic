<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

/**
 * Binary points/stroke packing & unpacking, ARGB<->Hex conversion,
 * music symbol mapping, and deterministic UUID generation for MobileSheets annotations.
 */
final class MobileSheetsAnnotationCodec {
	public const FLOAT_MAX = 3.4028234663852886e+38;

	/**
	 * Map MobileSheets music font character to standard Unicode glyph for web rendering.
	 */
	public static function mapMobileSheetsSymbol(string $symbol, int $font = 1): string {
		if ($font > 1) {
			return $symbol;
		}
		return match ($symbol) {
			'q' => '♩',
			'e' => '♪',
			'ee' => '♫',
			'h' => '𝅗𝅥',
			'w' => '𝅝',
			's' => '𝅘𝅥𝅯',
			'b' => '♭',
			'bb', 'BB', '∫', '𝄫', '♭♭' => '𝄫',
			'#', 'sharp' => '♯',
			'##', 'x', 'X' => '𝄪',
			'n' => '♮',
			'm', 'tr', '𝆖' => '𝆖',
			'π', '\pi', 'pp', '𝆏𝆏' => '𝆏𝆏',
			'p', '𝆏' => '𝆏',
			'f', '𝆑' => '𝆑',
			'ff', '𝆑𝆑' => '𝆑𝆑',
			'fff', '𝆑𝆑𝆑' => '𝆑𝆑𝆑',
			'ppp', '𝆏𝆏𝆏' => '𝆏𝆏𝆏',
			'mp', '𝆐𝆏' => '𝆐𝆏',
			'mf', '𝆐𝆑' => '𝆐𝆑',
			'fp', '𝆑𝆏' => '𝆑𝆏',
			'sfz', '𝆍𝆑𝆎' => '𝆍𝆑𝆎',
			default => $symbol,
		};
	}

	/**
	 * Map standard Unicode music glyphs back to MobileSheets font codes.
	 */
	public static function mapToMobileSheetsSymbol(string $symbol): string {
		return match ($symbol) {
			'♩' => 'q',
			'♪' => 'e',
			'♫' => 'ee',
			'𝅗𝅥' => 'h',
			'𝅝' => 'w',
			'𝅘𝅥𝅯' => 's',
			'♭' => 'b',
			'𝄫', '♭♭', '∫', 'bb', 'BB' => '∫',
			'♯' => '#',
			'𝄪' => 'x',
			'♮' => 'n',
			'𝆖', 'tr' => 'm',
			'𝆏𝆏', 'pp' => 'π',
			'𝆏', 'p' => 'p',
			'𝆑', 'f' => 'f',
			'𝆑𝆑', 'ff' => 'ff',
			'𝆑𝆑𝆑', 'fff' => 'fff',
			'𝆏𝆏𝆏', 'ppp' => 'ppp',
			'𝆐𝆏', 'mp' => 'mp',
			'𝆐𝆑', 'mf' => 'mf',
			'𝆑𝆏', 'fp' => 'fp',
			'𝆍𝆑𝆎', 'sfz' => 'sfz',
			default => $symbol,
		};
	}

	/**
	 * Unpack MobileSheets binary Points BLOB into multiple strokes of normalized [0..1] coordinates.
	 *
	 * @return list<list<array{x: float, y: float}>>
	 */
	public static function unpackPointsStrokes(string $blob, float $sourceW, float $sourceH): array {
		$len = strlen($blob);
		$doublesCount = intdiv($len, 8);
		if ($doublesCount < 2) {
			return [];
		}

		$doubles = array_values(unpack('d*', $blob) ?: []);
		$w = $sourceW > 0 ? $sourceW : 595.0;
		$h = $sourceH > 0 ? $sourceH : 842.0;

		// Check if this is a segmented stroke format (starts with 1.0, where doubles[1] is segment length, not total count)
		if (count($doubles) >= 7 && $doubles[0] == 1.0 && ((int)$doubles[1] + 3 !== count($doubles))) {
			return self::unpackSegmentedStrokes($doubles, $w, $h);
		}

		return self::unpackStandardStrokes($doubles, $w, $h);
	}

	/**
	 * @param list<float> $doubles
	 * @return list<list<array{x: float, y: float}>>
	 */
	private static function unpackSegmentedStrokes(array $doubles, float $w, float $h): array {
		$strokes = [];
		$i = 1;
		$currentStroke = [];
		$total = count($doubles);

		while ($i < $total) {
			$segLen = (int)$doubles[$i];
			if ($segLen <= 0 || $i + 1 >= $total) {
				break;
			}

			$segPoints = [];
			for ($j = $i + 2; $j < $i + $segLen; $j += 2) {
				if ($j + 1 < $total) {
					$x = $doubles[$j];
					$y = $doubles[$j + 1];
					if (self::isSentinel($x) || self::isSentinel($y)) {
						break;
					}
					$segPoints[] = [
						'x' => round($x / $w, 5),
						'y' => round($y / $h, 5),
					];
				}
			}

			if (!empty($segPoints)) {
				if (!empty($currentStroke)) {
					$lastPt = end($currentStroke);
					$firstPt = $segPoints[0];
					if (abs($lastPt['x'] - $firstPt['x']) < 1e-4 && abs($lastPt['y'] - $firstPt['y']) < 1e-4) {
						for ($k = 1; $k < count($segPoints); $k++) {
							$currentStroke[] = $segPoints[$k];
						}
					} else {
						$strokes[] = $currentStroke;
						$currentStroke = $segPoints;
					}
				} else {
					$currentStroke = $segPoints;
				}
			}

			$i += $segLen;
			while ($i < $total && self::isSentinel($doubles[$i])) {
				$i++;
			}
		}

		if (!empty($currentStroke)) {
			$strokes[] = $currentStroke;
		}
		return $strokes;
	}

	/**
	 * @param list<float> $doubles
	 * @return list<list<array{x: float, y: float}>>
	 */
	private static function unpackStandardStrokes(array $doubles, float $w, float $h): array {
		$strokes = [];
		$total = count($doubles);
		$i = ($total >= 3 && ($doubles[0] == 0.0 || (int)$doubles[1] + 3 === $total)) ? 3 : 0;
		$currentStroke = [];

		while ($i < $total) {
			$x = $doubles[$i];
			if (self::isSentinel($x)) {
				if (!empty($currentStroke)) {
					$strokes[] = $currentStroke;
					$currentStroke = [];
				}
				$i++;
				continue;
			}
			if ($i + 1 < $total) {
				$y = $doubles[$i + 1];
				if (self::isSentinel($y)) {
					if (!empty($currentStroke)) {
						$strokes[] = $currentStroke;
						$currentStroke = [];
					}
					$i += 2;
					continue;
				}
				$currentStroke[] = [
					'x' => round($x / $w, 5),
					'y' => round($y / $h, 5),
				];
				$i += 2;
			} else {
				$i++;
			}
		}

		if (!empty($currentStroke)) {
			$strokes[] = $currentStroke;
		}

		return $strokes;
	}

	private static function isSentinel(float $value): bool {
		return abs($value - self::FLOAT_MAX) < 1e30;
	}

	/**
	 * Unpack MobileSheets binary Points BLOB of IEEE 754 64-bit doubles into normalized [0..1] coordinates.
	 *
	 * @return list<array{x: float, y: float}>
	 */
	public static function unpackPointsBlob(string $blob, float $sourceW, float $sourceH): array {
		$strokes = self::unpackPointsStrokes($blob, $sourceW, $sourceH);
		return !empty($strokes) ? array_merge(...$strokes) : [];
	}

	/**
	 * Pack normalized [0..1] coordinate pairs into MobileSheets binary BLOB of IEEE doubles.
	 *
	 * @param list<array{x: float, y: float}> $points
	 * @param list<list<array{x: float, y: float}>> $strokes
	 * @return array{string, int} [blob, count]
	 */
	public static function packPointsBlob(
		array $points,
		float $sourceW,
		float $sourceH,
		string $type = 'drawing',
		float $lineWidth = 2.0,
		?float $aperture = null,
		int $drawMode = 0,
		array $strokes = [],
	): array {
		if (empty($points) && empty($strokes)) {
			return ['', 0];
		}

		$doubles = [];
		$w = $sourceW > 0 ? $sourceW : 595.0;
		$h = $sourceH > 0 ? $sourceH : 842.0;

		if ($type === 'drawing' || $type === 'highlight') {
			$strokeList = !empty($strokes) ? $strokes : [$points];
			$strokeDoubles = [];
			foreach ($strokeList as $stk) {
				if (empty($stk)) {
					continue;
				}
				foreach ($stk as $pt) {
					$strokeDoubles[] = (float)$pt['x'] * $w;
					$strokeDoubles[] = (float)$pt['y'] * $h;
				}
				$strokeDoubles[] = self::FLOAT_MAX;
				$strokeDoubles[] = self::FLOAT_MAX;
			}
			$doubles[] = 0.0;
			$doubles[] = (float)count($strokeDoubles);
			$doubles[] = $lineWidth;
			foreach ($strokeDoubles as $sd) {
				$doubles[] = $sd;
			}
		} elseif ($type === 'shape' && ($drawMode === 4 || $drawMode === 5 || $aperture !== null) && count($points) === 2) {
			$doubles[] = (float)$points[0]['x'] * $w;
			$doubles[] = (float)$points[0]['y'] * $h;
			$doubles[] = (float)$points[1]['x'] * $w;
			$doubles[] = (float)$points[1]['y'] * $h;
			$doubles[] = $aperture !== null ? (float)$aperture : 4.0;
		} else {
			foreach ($points as $pt) {
				$doubles[] = (float)$pt['x'] * $w;
				$doubles[] = (float)$pt['y'] * $h;
			}
		}

		$blob = '';
		foreach ($doubles as $d) {
			$blob .= pack('d', (float)$d);
		}

		return [$blob, count($doubles)];
	}

	/**
	 * Convert 32-bit signed ARGB integer to Hex string #RRGGBB or #RRGGBBAA.
	 */
	public static function argbToHex(int $argb): string {
		$uint = $argb & 0xFFFFFFFF;
		$a = ($uint >> 24) & 0xFF;
		$r = ($uint >> 16) & 0xFF;
		$g = ($uint >> 8) & 0xFF;
		$b = $uint & 0xFF;

		if ($a === 255) {
			return sprintf('#%02x%02x%02x', $r, $g, $b);
		}
		return sprintf('#%02x%02x%02x%02x', $r, $g, $b, $a);
	}

	/**
	 * Convert Hex string #RRGGBB or #RRGGBBAA to 32-bit signed ARGB integer.
	 */
	public static function hexToArgb(string $hex): int {
		$hex = ltrim($hex, '#');
		$a = 255;
		$r = 0;
		$g = 0;
		$b = 0;

		if (strlen($hex) === 6) {
			$r = hexdec(substr($hex, 0, 2));
			$g = hexdec(substr($hex, 2, 2));
			$b = hexdec(substr($hex, 4, 2));
		} elseif (strlen($hex) === 8) {
			$r = hexdec(substr($hex, 0, 2));
			$g = hexdec(substr($hex, 2, 2));
			$b = hexdec(substr($hex, 4, 2));
			$a = hexdec(substr($hex, 6, 2));
		}

		$uint = (($a & 0xFF) << 24) | (($r & 0xFF) << 16) | (($g & 0xFF) << 8) | ($b & 0xFF);
		if ($uint > 0x7FFFFFFF) {
			$uint -= 0x100000000;
		}
		return $uint;
	}

	public static function generateDeterministicUuid(string $prefix, int $id): string {
		return sprintf('%s-%08x-%04x', substr(md5($prefix . '_' . $id), 0, 18), $id, $id & 0xFFFF);
	}
}
