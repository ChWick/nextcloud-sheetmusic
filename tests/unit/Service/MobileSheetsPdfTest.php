<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\MobileSheetsManager\Service\MobileSheetsPdf;
use PHPUnit\Framework\TestCase;

final class MobileSheetsPdfTest extends TestCase {
	public function testContentHashIsCrc32AsASigned32BitInteger(): void {
		// CRC-32 of "123456789" is the standard check value 0xCBF43926, which as
		// a signed 32-bit integer is negative.
		self::assertSame(-873187034, MobileSheetsPdf::contentHash('123456789'));
		self::assertSame(0, MobileSheetsPdf::contentHash(''));
	}

	public function testContentHashStaysWithinTheSigned32BitRange(): void {
		$hash = MobileSheetsPdf::contentHash('MobileSheets');
		self::assertGreaterThanOrEqual(-2147483648, $hash);
		self::assertLessThanOrEqual(2147483647, $hash);
	}

	public function testPageCountCountsThePageObjectsOfTheTree(): void {
		$pdf = "<< /Type /Pages /Count 2 /Kids [3 0 R 4 0 R] >>\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. "4 0 obj << /Type /Page /Parent 2 0 R >> endobj\n";

		self::assertSame(2, MobileSheetsPdf::pageCount($pdf));
	}

	public function testPageCountDoesNotCountThePageTreeNodeItself(): void {
		$pdf = "<< /Type /Pages /Count 1 /Kids [3 0 R] >>\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R >> endobj\n";

		self::assertSame(1, MobileSheetsPdf::pageCount($pdf));
	}

	public function testPageCountFallsBackToThePageTreesTotal(): void {
		// No page object at all - only the page tree's /Count is readable.
		self::assertSame(3, MobileSheetsPdf::pageCount('<< /Type /Pages /Count 3 /Kids [] >>'));
	}

	public function testPageCountReadsPagesFromCompressedObjectStreams(): void {
		$objects = '<< /Type /Page /Parent 2 0 R >> << /Type /Page /Parent 2 0 R >>';
		$stream = gzcompress($objects, 9);
		self::assertNotFalse($stream);

		$pdf = '2 0 obj << /Type /ObjStm /N 2 /Filter /FlateDecode /Length ' . strlen($stream) . " >>\n"
			. "stream\n" . $stream . "\nendstream\nendobj\n";

		self::assertSame(2, MobileSheetsPdf::pageCount($pdf));
	}

	public function testPageCountDefaultsToASinglePage(): void {
		self::assertSame(1, MobileSheetsPdf::pageCount('not a pdf at all'));
	}

	public function testPageOrderCoversTheWholeFile(): void {
		self::assertSame('1-1', MobileSheetsPdf::pageOrder(1));
		self::assertSame('1-7', MobileSheetsPdf::pageOrder(7));
	}
}