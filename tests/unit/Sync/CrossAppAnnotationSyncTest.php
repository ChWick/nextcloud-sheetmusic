<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync;

use OCA\SheetMusic\Sync\ForScore\ForScoreAnnotationAdapter;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsAnnotationAdapter;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsBaselineSchema;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end integration test for bidirectional cross-app annotation synchronization.
 * Uses the real MobileSheets database fixture and in-memory SQLite instances with zero mocks.
 */
final class CrossAppAnnotationSyncTest extends TestCase {
	private MobileSheetsAnnotationAdapter $msAdapter;
	private ForScoreAnnotationAdapter $fsAdapter;

	protected function setUp(): void {
		parent::setUp();
		$this->msAdapter = new MobileSheetsAnnotationAdapter();
		$this->fsAdapter = new ForScoreAnnotationAdapter();
	}

	public function testMobileSheetsToForScoreCrossAppExport(): void {
		$dbPath = __DIR__ . '/../../../tests/fixtures/mobilesheets.db';
		self::assertFileExists($dbPath);

		$msDb = new PDO('sqlite:' . $dbPath);
		$msDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// 1. Import real MobileSheets annotations for Song 87
		$canonical = $this->msAdapter->importAnnotations(
			$msDb,
			songId: 1,
			fileId: 1,
			clientSongId: 87,
		);

		self::assertNotEmpty($canonical);
		self::assertCount(11, $canonical);

		// 2. Export canonical annotations directly into forScore structure
		$pdfWidth = 595.0;
		$pdfHeight = 842.0;
		$forScoreExport = $this->fsAdapter->exportAnnotations($canonical, $pdfWidth, $pdfHeight);

		self::assertCount(11, $forScoreExport);

		// Verify first exported item
		$firstFs = $forScoreExport[0];
		self::assertSame('drawing', $firstFs['type']);
		self::assertSame(0, $firstFs['page']);
		self::assertSame([1.0, 0.0, 0.0, 1.0], $firstFs['color']); // Red
		self::assertNotEmpty($firstFs['points']);

		// 3. Re-import from forScore to verify roundtrip fidelity
		$reimported = $this->fsAdapter->importAnnotations($forScoreExport, songId: 1, fileId: 1, pdfWidth: $pdfWidth, pdfHeight: $pdfHeight);
		self::assertCount(11, $reimported);
		self::assertSame($canonical[0]->type, $reimported[0]->type);
		self::assertSame($canonical[0]->pageNumber, $reimported[0]->pageNumber);
		self::assertSame($canonical[0]->style['stroke'], $reimported[0]->style['stroke']);
	}

	public function testForScoreToMobileSheetsCrossAppExport(): void {
		// 1. Author annotations in forScore
		$forScoreInput = [
			[
				'uuid' => 'fs-orig-1',
				'type' => 'drawing',
				'page' => 1,
				'layer' => 0,
				'lineWidth' => 2.5,
				'opacity' => 1.0,
				'color' => [0.0, 0.0, 1.0, 1.0], // Blue
				'points' => [
					[120.0, 200.0],
					[180.0, 260.0],
					[240.0, 300.0],
				],
			],
			[
				'uuid' => 'fs-orig-2',
				'type' => 'text',
				'page' => 2,
				'layer' => 0,
				'rect' => [80.0, 100.0, 150.0, 30.0],
				'text' => 'Crescendo',
				'fontSize' => 16.0,
				'color' => [1.0, 0.0, 0.0, 1.0], // Red
			],
		];

		// 2. Import into Canonical SheetMusic entities
		$canonical = $this->fsAdapter->importAnnotations($forScoreInput, songId: 42, fileId: 100);
		self::assertCount(2, $canonical);

		// 3. Export to a real MobileSheets SQLite database
		$msDb = new PDO('sqlite::memory:');
		$msDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		foreach (['AnnotationsBase', 'DrawAnnotations', 'AnnotationPoints', 'TextboxAnnotations'] as $t) {
			$msDb->exec(MobileSheetsBaselineSchema::TABLES[$t]);
		}

		$this->msAdapter->exportAnnotations($msDb, clientSongId: 500, annotations: $canonical);

		// 4. Verify MobileSheets tables were written correctly
		$annBaseRows = $msDb->query('SELECT * FROM AnnotationsBase ORDER BY Id ASC')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(2, $annBaseRows);
		self::assertSame(500, (int)$annBaseRows[0]['SongId']);
		self::assertSame(1, (int)$annBaseRows[0]['Page']); // Drawing on page 1
		self::assertSame(2, (int)$annBaseRows[1]['Page']); // Text on page 2

		// Verify drawing row in DrawAnnotations
		$drawRow = $msDb->query('SELECT * FROM DrawAnnotations WHERE BaseId = ' . (int)$annBaseRows[0]['Id'])->fetch(PDO::FETCH_ASSOC);
		self::assertNotEmpty($drawRow);
		self::assertSame(-16776961, (int)$drawRow['LineColor']); // Blue

		// Verify text row in TextboxAnnotations
		$textRow = $msDb->query('SELECT * FROM TextboxAnnotations WHERE BaseId = ' . (int)$annBaseRows[1]['Id'])->fetch(PDO::FETCH_ASSOC);
		self::assertNotEmpty($textRow);
		self::assertSame('Crescendo', $textRow['Text']);
		self::assertSame(-65536, (int)$textRow['TextColor']); // Red
	}
}
