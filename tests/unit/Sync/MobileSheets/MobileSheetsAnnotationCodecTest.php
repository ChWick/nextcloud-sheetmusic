<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsAnnotationCodec;
use PHPUnit\Framework\TestCase;

final class MobileSheetsAnnotationCodecTest extends TestCase {
	public function testArgbHexRoundTrip(): void {
		self::assertSame('#ff3366', MobileSheetsAnnotationCodec::argbToHex(MobileSheetsAnnotationCodec::hexToArgb('#ff3366')));
		self::assertSame('#ff336680', MobileSheetsAnnotationCodec::argbToHex(MobileSheetsAnnotationCodec::hexToArgb('#ff336680')));
	}

	public function testPackAndUnpackMultiStrokeDrawing(): void {
		$strokes = [
			[['x' => 0.1, 'y' => 0.2], ['x' => 0.3, 'y' => 0.4]],
			[['x' => 0.6, 'y' => 0.7], ['x' => 0.8, 'y' => 0.9]],
		];
		[$blob, $count] = MobileSheetsAnnotationCodec::packPointsBlob([], 1000.0, 1000.0, 'drawing', 2.0, null, 0, $strokes);
		self::assertGreaterThan(0, $count);
		$unpacked = MobileSheetsAnnotationCodec::unpackPointsStrokes($blob, 1000.0, 1000.0);
		self::assertCount(2, $unpacked);
		self::assertEqualsWithDelta(0.1, $unpacked[0][0]['x'], 1e-4);
		self::assertEqualsWithDelta(0.9, $unpacked[1][1]['y'], 1e-4);
	}

	public function testSymbolMappingAndDeterministicUuid(): void {
		self::assertSame('♩', MobileSheetsAnnotationCodec::mapMobileSheetsSymbol('q'));
		self::assertSame('other', MobileSheetsAnnotationCodec::mapMobileSheetsSymbol('other', 2));
		self::assertSame('q', MobileSheetsAnnotationCodec::mapToMobileSheetsSymbol('♩'));

		$u1 = MobileSheetsAnnotationCodec::generateDeterministicUuid('stamp', 42);
		$u2 = MobileSheetsAnnotationCodec::generateDeterministicUuid('stamp', 42);
		self::assertSame($u1, $u2);
		self::assertNotSame($u1, MobileSheetsAnnotationCodec::generateDeterministicUuid('stamp', 43));
	}
}
