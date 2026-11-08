<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Db\InternalSongAnnotation;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsAnnotationAdapter;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsBaselineSchema;
use PDO;
use PHPUnit\Framework\TestCase;

final class MobileSheetsAnnotationAdapterTest extends TestCase {
	private MobileSheetsAnnotationAdapter $adapter;

	protected function setUp(): void {
		parent::setUp();
		$this->adapter = new MobileSheetsAnnotationAdapter();
	}

	public function testColorConversionRoundtrip(): void {
		// Pure Red
		$redHex = '#ff0000';
		$redArgb = MobileSheetsAnnotationAdapter::hexToArgb($redHex);
		self::assertSame(-65536, $redArgb);
		self::assertSame($redHex, MobileSheetsAnnotationAdapter::argbToHex($redArgb));

		// Pure Blue
		$blueHex = '#0000ff';
		$blueArgb = MobileSheetsAnnotationAdapter::hexToArgb($blueHex);
		self::assertSame(-16776961, $blueArgb);
		self::assertSame($blueHex, MobileSheetsAnnotationAdapter::argbToHex($blueArgb));

		// Semi-transparent Green (alpha = 128 / 0x80)
		$greenHex = '#00ff0080';
		$greenArgb = MobileSheetsAnnotationAdapter::hexToArgb($greenHex);
		self::assertSame($greenHex, MobileSheetsAnnotationAdapter::argbToHex($greenArgb));
	}

	public function testPackAndUnpackPointsBlob(): void {
		$points = [
			['x' => 0.12345, 'y' => 0.23456],
			['x' => 0.34567, 'y' => 0.45678],
			['x' => 0.56789, 'y' => 0.67890],
		];

		$sourceW = 600.0;
		$sourceH = 800.0;

		[$blob, $count] = MobileSheetsAnnotationAdapter::packPointsBlob($points, $sourceW, $sourceH);
		self::assertNotEmpty($blob);
		self::assertGreaterThan(0, $count);

		$unpacked = MobileSheetsAnnotationAdapter::unpackPointsBlob($blob, $sourceW, $sourceH);
		self::assertCount(3, $unpacked);
		self::assertEqualsWithDelta(0.12345, $unpacked[0]['x'], 0.0001);
		self::assertEqualsWithDelta(0.23456, $unpacked[0]['y'], 0.0001);
		self::assertEqualsWithDelta(0.56789, $unpacked[2]['x'], 0.0001);
		self::assertEqualsWithDelta(0.67890, $unpacked[2]['y'], 0.0001);
	}

	public function testImportFromRealMobileSheetsFixtureDatabase(): void {
		$dbPath = __DIR__ . '/../../../../tests/fixtures/mobilesheets.db';
		self::assertFileExists($dbPath);

		$db = new PDO('sqlite:' . $dbPath);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// Song 87 has 11 real annotations in the fixture
		$annotations = $this->adapter->importAnnotations($db, songId: 10, fileId: 20, clientSongId: 87);

		self::assertCount(11, $annotations);
		foreach ($annotations as $ann) {
			self::assertInstanceOf(InternalSongAnnotation::class, $ann);
			self::assertSame(10, $ann->songId);
			self::assertSame(20, $ann->fileId);
			self::assertGreaterThanOrEqual(0, $ann->pageNumber);
			self::assertNotEmpty($ann->uuid);
			self::assertNotEmpty($ann->data['path']);
			self::assertNotEmpty($ann->data['points']);
			self::assertNotEmpty($ann->style['stroke']);
			self::assertGreaterThan(0.0, $ann->style['strokeWidth']);
		}

		// First annotation on page 0
		$first = $annotations[0];
		self::assertSame(0, $first->pageNumber);
		self::assertSame('#ff0000', $first->style['stroke']); // red
		self::assertSame('drawing', $first->type);
	}

	public function testExportToMobileSheetsDatabase(): void {
		$db = new PDO('sqlite::memory:');
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// Create baseline tables
		foreach (['AnnotationsBase', 'DrawAnnotations', 'AnnotationPoints', 'TextboxAnnotations'] as $t) {
			$db->exec(MobileSheetsBaselineSchema::TABLES[$t]);
		}

		$canonical = [
			new InternalSongAnnotation(
				id: 1,
				uuid: 'test-drawing-uuid',
				songId: 10,
				fileId: 20,
				pageNumber: 2,
				layerIndex: 0,
				type: 'drawing',
				x: 0.1,
				y: 0.2,
				width: 0.3,
				height: 0.4,
				style: ['stroke' => '#0000ff', 'strokeWidth' => 3.5, 'opacity' => 1.0],
				data: [
					'points' => [
						['x' => 0.1, 'y' => 0.2],
						['x' => 0.4, 'y' => 0.6],
					],
				],
			),
			new InternalSongAnnotation(
				id: 2,
				uuid: 'test-text-uuid',
				songId: 10,
				fileId: 20,
				pageNumber: 3,
				layerIndex: 1,
				type: 'text',
				x: 0.2,
				y: 0.3,
				width: 0.2,
				height: 0.05,
				style: ['color' => '#ff0000'],
				data: ['text' => 'Allegro con brio', 'fontSize' => 16.0],
			),
		];

		$this->adapter->exportAnnotations($db, clientSongId: 99, annotations: $canonical);

		// Verify AnnotationsBase
		$annRows = $db->query('SELECT * FROM AnnotationsBase ORDER BY Id ASC')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(2, $annRows);
		self::assertSame(99, (int)$annRows[0]['SongId']);
		self::assertSame(2, (int)$annRows[0]['Page']);
		self::assertSame(3, (int)$annRows[1]['Page']);

		// Verify DrawAnnotations
		$drawRows = $db->query('SELECT * FROM DrawAnnotations')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(1, $drawRows);
		self::assertSame(-16776961, (int)$drawRows[0]['LineColor']); // pure blue

		// Verify AnnotationPoints (both drawing and textbox have points in MobileSheets)
		$pointRows = $db->query('SELECT * FROM AnnotationPoints')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(2, $pointRows);
		self::assertGreaterThan(0, (int)$pointRows[0]['Count']);

		// Verify TextboxAnnotations
		$textRows = $db->query('SELECT * FROM TextboxAnnotations')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(1, $textRows);
		self::assertSame('Allegro con brio', $textRows[0]['Text']);
		self::assertSame(-65536, (int)$textRows[0]['TextColor']); // pure red
	}

	public function testImportAndExportStampAnnotations(): void {
		$db = new PDO('sqlite::memory:');
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		foreach (['AnnotationsBase', 'StampAnnotations', 'AnnotationPoints'] as $table) {
			$db->exec(MobileSheetsBaselineSchema::TABLES[$table]);
		}

		$canonical = [
			new InternalSongAnnotation(
				id: 3,
				uuid: 'test-stamp-uuid',
				songId: 10,
				fileId: 20,
				pageNumber: 1,
				layerIndex: 0,
				type: 'stamp',
				x: 0.25,
				y: 0.35,
				width: 0.08,
				height: 0.08,
				style: ['color' => '#000000', 'fontSize' => 48],
				data: ['symbol' => 'q', 'font' => 1, 'size' => 48.0],
			),
		];

		$this->adapter->exportAnnotations($db, clientSongId: 42, annotations: $canonical);

		$baseRows = $db->query('SELECT * FROM AnnotationsBase')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(1, $baseRows);
		self::assertSame(3, (int)$baseRows[0]['Type']); // Type 3 for Stamp

		$stampRows = $db->query('SELECT * FROM StampAnnotations')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(1, $stampRows);
		self::assertSame('q', $stampRows[0]['Symbol']);
		self::assertEqualsWithDelta(48.0, (float)$stampRows[0]['Size'], 0.01);

		// Now test importing it back
		$imported = $this->adapter->importAnnotations($db, songId: 10, fileId: 20, clientSongId: 42);
		self::assertCount(1, $imported);
		self::assertSame('stamp', $imported[0]->type);
		self::assertSame('q', $imported[0]->data['symbol']);
		self::assertSame('♩', $imported[0]->data['displaySymbol']);
		self::assertEqualsWithDelta(0.25, $imported[0]->x, 0.01);
		self::assertEqualsWithDelta(0.35, $imported[0]->y, 0.01);
	}

	public function testDashedAndDottedLineStyleImportExport(): void {
		$db = new PDO('sqlite::memory:');
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		foreach (['AnnotationsBase', 'DrawAnnotations', 'AnnotationPoints'] as $table) {
			$db->exec(MobileSheetsBaselineSchema::TABLES[$table]);
		}

		$canonical = [
			new InternalSongAnnotation(
				id: 1,
				uuid: 'test-dashed-uuid',
				songId: 10,
				fileId: 20,
				pageNumber: 0,
				layerIndex: 0,
				type: 'drawing',
				x: 0.1,
				y: 0.1,
				width: 0.5,
				height: 0.5,
				style: [
					'stroke' => '#e91e63',
					'strokeWidth' => 4.0,
					'opacity' => 1.0,
					'lineStyle' => 'dashed',
					'strokeDashArray' => '16, 12',
				],
				data: [
					'points' => [['x' => 0.1, 'y' => 0.1], ['x' => 0.6, 'y' => 0.6]],
				],
			),
			new InternalSongAnnotation(
				id: 2,
				uuid: 'test-dotted-uuid',
				songId: 10,
				fileId: 20,
				pageNumber: 0,
				layerIndex: 0,
				type: 'drawing',
				x: 0.2,
				y: 0.2,
				width: 0.4,
				height: 0.4,
				style: [
					'stroke' => '#00ff00',
					'strokeWidth' => 2.0,
					'opacity' => 1.0,
					'lineStyle' => 'dotted',
				],
				data: [
					'points' => [['x' => 0.2, 'y' => 0.2], ['x' => 0.6, 'y' => 0.6]],
				],
			),
		];

		$this->adapter->exportAnnotations($db, clientSongId: 10, annotations: $canonical);

		$drawRows = $db->query('SELECT * FROM DrawAnnotations ORDER BY Id ASC')->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(2, $drawRows);
		self::assertSame(3, (int)$drawRows[0]['PenMode'], 'Dashed stroke must export with PenMode = 3');
		self::assertSame(2, (int)$drawRows[1]['PenMode'], 'Dotted stroke must export with PenMode = 2');

		// Import back and verify lineStyle and strokeDashArray are reconstructed
		$imported = $this->adapter->importAnnotations($db, songId: 10, fileId: 20, clientSongId: 10);
		self::assertCount(2, $imported);
		self::assertSame('dashed', $imported[0]->style['lineStyle']);
		self::assertSame('36, 20', $imported[0]->style['strokeDashArray']);
		self::assertSame(3, $imported[0]->data['penMode']);

		self::assertSame('dotted', $imported[1]->style['lineStyle']);
		self::assertSame('4, 12', $imported[1]->style['strokeDashArray']);
		self::assertSame(2, $imported[1]->data['penMode']);
	}

	public function testCurveSmoothingWithQuadraticBezier(): void {
		$points = [
			['x' => 0.1, 'y' => 0.1],
			['x' => 0.2, 'y' => 0.4],
			['x' => 0.5, 'y' => 0.3],
			['x' => 0.8, 'y' => 0.7],
		];

		$smoothed = MobileSheetsAnnotationAdapter::generateSvgPath($points, smooth: true);
		self::assertStringStartsWith('M 100 100', $smoothed);
		self::assertStringContainsString('Q', $smoothed, '3+ points must be smoothed with quadratic Bezier curves');

		$linear = MobileSheetsAnnotationAdapter::generateSvgPath($points, smooth: false);
		self::assertStringStartsWith('M 100 100', $linear);
		self::assertStringContainsString('L', $linear);
		self::assertStringNotContainsString('Q', $linear);

		// Two points should always be linear
		$twoPoints = [
			['x' => 0.1, 'y' => 0.1],
			['x' => 0.9, 'y' => 0.9],
		];
		$twoPath = MobileSheetsAnnotationAdapter::generateSvgPath($twoPoints, smooth: true);
		self::assertSame('M 100 100 L 900 900', $twoPath);
	}

	public function testCrescendoHairpinImportAndExport(): void {
		$db = new PDO('sqlite::memory:');
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		foreach (['AnnotationsBase', 'DrawAnnotations', 'AnnotationPoints', 'TextboxAnnotations'] as $t) {
			$db->exec(MobileSheetsBaselineSchema::TABLES[$t]);
		}

		$canonical = [
			new InternalSongAnnotation(
				id: 1,
				uuid: 'test-crescendo-uuid',
				songId: 10,
				fileId: 20,
				pageNumber: 0,
				layerIndex: 0,
				type: 'shape',
				x: 0.1,
				y: 0.5,
				width: 0.2,
				height: 0.03,
				style: [
					'stroke' => '#000000',
					'strokeWidth' => 2.0,
				],
				data: [
					'drawMode' => 4,
					'isHairpin' => true,
					'aperture' => 4.0,
					'points' => [['x' => 0.1, 'y' => 0.5], ['x' => 0.3, 'y' => 0.5]],
				],
			),
		];

		$this->adapter->exportAnnotations($db, clientSongId: 10, annotations: $canonical);

		$imported = $this->adapter->importAnnotations($db, songId: 10, fileId: 20, clientSongId: 10);
		self::assertCount(1, $imported);
		self::assertTrue($imported[0]->data['isHairpin']);
		self::assertSame(4, $imported[0]->data['drawMode']);
		self::assertStringStartsWith('M 300', $imported[0]->data['path']);
		self::assertStringContainsString('L 100 500', $imported[0]->data['path']);
	}

	public function testTextboxFontFamilyAndFontSizeScaling(): void {
		$db = new PDO('sqlite::memory:');
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		foreach (['AnnotationsBase', 'DrawAnnotations', 'AnnotationPoints', 'TextboxAnnotations'] as $t) {
			$db->exec(MobileSheetsBaselineSchema::TABLES[$t]);
		}

		$db->exec('INSERT INTO AnnotationsBase (Id, SongId, Page, Type, Opacity, Zoom, ZoomY, Version, SourcePageWidth, SourcePageHeight, Layer)
			VALUES (1, 10, 0, 0, 100, 1.0, 1.0, 3, 500.0, 800.0, 0)');
		$db->exec("INSERT INTO TextboxAnnotations (Id, BaseId, TextColor, Text, FontFamily, FontSize, FontStyle, FillColor, BorderColor, TextAlign, HasBorder, BorderWidth, AutoSize, LineSpacing)
			VALUES (1, 1, -16777216, 'Allegro', 0, 25.0, 0, 0, -16777216, 0, 0, 0.5, 1, 1.0)");

		$imported = $this->adapter->importAnnotations($db, songId: 10, fileId: 20, clientSongId: 10);
		self::assertCount(1, $imported);
		self::assertSame('Allegro', $imported[0]->data['text']);
		self::assertSame('sans-serif', $imported[0]->data['fontFamily']);
		self::assertSame('sans-serif', $imported[0]->style['fontFamily']);
		self::assertEquals(50.0, $imported[0]->style['fontSize']); // 25 / 500 * 1000 = 50.0
	}
}
