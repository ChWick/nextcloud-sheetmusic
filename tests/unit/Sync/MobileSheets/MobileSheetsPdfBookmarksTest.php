<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdfBookmarks;
use PHPUnit\Framework\TestCase;

final class MobileSheetsPdfBookmarksTest extends TestCase {
	public function testExtractBookmarksReturnsEmptyWhenNoOutlinesPresent(): void {
		$pdf = self::pdfWithoutOutlines();
		self::assertSame([], MobileSheetsPdfBookmarks::extractBookmarks($pdf));
		self::assertSame([], MobileSheetsPdf::bookmarks($pdf));
	}

	public function testExtractBookmarksWithExplicitDestinations(): void {
		$objects = "1 0 obj << /Type /Catalog /Pages 2 0 R /Outlines 5 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Count 3 /Kids [3 0 R 4 0 R 7 0 R] >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. "4 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. "7 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. "5 0 obj << /Type /Outlines /First 6 0 R /Last 8 0 R /Count 2 >> endobj\n"
			. "6 0 obj << /Title (Movement 1: Allegro) /Parent 5 0 R /Next 8 0 R /Dest [3 0 R /Fit] >> endobj\n"
			. "8 0 obj << /Title (Movement 2: Adagio) /Parent 5 0 R /Prev 6 0 R /Dest [4 0 R /Fit] >> endobj\n";

		$pdf = self::assemblePdf($objects, 9, 1);
		$bookmarks = MobileSheetsPdfBookmarks::extractBookmarks($pdf);

		self::assertCount(2, $bookmarks);
		self::assertSame('Movement 1: Allegro', $bookmarks[0]['name']);
		self::assertSame(1, $bookmarks[0]['pageNum']);
		self::assertSame(1, $bookmarks[0]['endPage']);

		self::assertSame('Movement 2: Adagio', $bookmarks[1]['name']);
		self::assertSame(2, $bookmarks[1]['pageNum']);
		self::assertSame(3, $bookmarks[1]['endPage']);
	}

	public function testExtractBookmarksWithActionGoToAndUtf16Title(): void {
		$utfTitle = '<FEFF' . strtoupper(bin2hex(mb_convert_encoding('III. Rondo: Presto', 'UTF-16BE', 'UTF-8'))) . '>';
		$objects = "1 0 obj << /Type /Catalog /Pages 2 0 R /Outlines 5 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Count 3 /Kids [3 0 R 4 0 R 7 0 R] >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. "4 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. "7 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. "5 0 obj << /Type /Outlines /First 6 0 R /Last 6 0 R /Count 3 >> endobj\n"
			. "6 0 obj << /Title (Sonata No. 1) /Parent 5 0 R /First 8 0 R /Last 9 0 R /Dest [3 0 R /Fit] >> endobj\n"
			. "8 0 obj << /Title (I. Allegro) /Parent 6 0 R /Next 9 0 R /A << /S /GoTo /D [3 0 R /XYZ 0 0 0] >> >> endobj\n"
			. "9 0 obj << /Title {$utfTitle} /Parent 6 0 R /Prev 8 0 R /Dest [7 0 R /Fit] >> endobj\n";

		$pdf = self::assemblePdf($objects, 10, 1);
		$bookmarks = MobileSheetsPdfBookmarks::extractBookmarks($pdf);

		self::assertCount(3, $bookmarks);
		self::assertSame('Sonata No. 1', $bookmarks[0]['name']);
		self::assertSame(1, $bookmarks[0]['pageNum']);
		self::assertSame(1, $bookmarks[0]['endPage']);

		self::assertSame('I. Allegro', $bookmarks[1]['name']);
		self::assertSame(1, $bookmarks[1]['pageNum']);
		self::assertSame(2, $bookmarks[1]['endPage']);

		self::assertSame('III. Rondo: Presto', $bookmarks[2]['name']);
		self::assertSame(3, $bookmarks[2]['pageNum']);
		self::assertSame(3, $bookmarks[2]['endPage']);
	}

	public function testExtractBookmarksWithNamedDestinations(): void {
		$objects = "1 0 obj << /Type /Catalog /Pages 2 0 R /Outlines 5 0 R /Dests 10 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Count 2 /Kids [3 0 R 4 0 R] >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. "4 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. "10 0 obj << /Intro [3 0 R /Fit] /Finale [4 0 R /Fit] >> endobj\n"
			. "5 0 obj << /Type /Outlines /First 6 0 R /Last 7 0 R /Count 2 >> endobj\n"
			. "6 0 obj << /Title (Introduction) /Parent 5 0 R /Next 7 0 R /Dest /Intro >> endobj\n"
			. "7 0 obj << /Title (Finale) /Parent 5 0 R /Prev 6 0 R /Dest /Finale >> endobj\n";

		$pdf = self::assemblePdf($objects, 11, 1);
		$bookmarks = MobileSheetsPdfBookmarks::extractBookmarks($pdf);

		self::assertCount(2, $bookmarks);
		self::assertSame('Introduction', $bookmarks[0]['name']);
		self::assertSame(1, $bookmarks[0]['pageNum']);
		self::assertSame(1, $bookmarks[0]['endPage']);

		self::assertSame('Finale', $bookmarks[1]['name']);
		self::assertSame(2, $bookmarks[1]['pageNum']);
		self::assertSame(2, $bookmarks[1]['endPage']);
	}

	public function testPdfBookmarksViaMobileSheetsPdfHelper(): void {
		$objects = "1 0 obj << /Type /Catalog /Pages 2 0 R /Outlines 5 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Count 2 /Kids [3 0 R 4 0 R] >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. "4 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. "5 0 obj << /Type /Outlines /First 6 0 R /Last 6 0 R /Count 1 >> endobj\n"
			. "6 0 obj << /Title (Overture) /Parent 5 0 R /Dest [3 0 R /Fit] >> endobj\n"
			. "7 0 obj << /Title (Magic Flute) /Composer (Mozart) >> endobj\n";

		$pdf = self::assemblePdf($objects, 8, 1, 7);
		$bookmarks = MobileSheetsPdf::bookmarks($pdf);

		self::assertCount(1, $bookmarks);
		self::assertSame('Overture', $bookmarks[0]['name']);
		self::assertSame(1, $bookmarks[0]['pageNum']);
		self::assertSame(2, $bookmarks[0]['endPage']);
	}

	private static function pdfWithoutOutlines(): string {
		$objects = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Count 1 /Kids [3 0 R] >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R >> endobj\n";

		return self::assemblePdf($objects, 4, 1);
	}

	private static function assemblePdf(string $objects, int $size, int $rootNum, ?int $infoNum = null): string {
		$infoRef = $infoNum !== null ? " /Info {$infoNum} 0 R" : '';
		$xrefOffset = strlen("%PDF-1.4\n" . $objects);

		return "%PDF-1.4\n"
			. $objects
			. "xref\n0 {$size}\n0000000000 65535 f \n"
			. "trailer << /Size {$size} /Root {$rootNum} 0 R{$infoRef} >>\n"
			. "startxref\n{$xrefOffset}\n%%EOF\n";
	}
}
