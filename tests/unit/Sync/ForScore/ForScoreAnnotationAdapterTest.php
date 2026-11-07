<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\ForScore;

use OCA\SheetMusic\Db\InternalSongAnnotation;
use OCA\SheetMusic\Sync\ForScore\ForScoreAnnotationAdapter;
use PHPUnit\Framework\TestCase;

final class ForScoreAnnotationAdapterTest extends TestCase {
	private ForScoreAnnotationAdapter $adapter;

	protected function setUp(): void {
		parent::setUp();
		$this->adapter = new ForScoreAnnotationAdapter();
	}

	public function testColorConversion(): void {
		// Pure Red
		$redHex = '#ff0000';
		$redArr = ForScoreAnnotationAdapter::hexToColorArray($redHex);
		self::assertSame([1.0, 0.0, 0.0, 1.0], $redArr);
		self::assertSame($redHex, ForScoreAnnotationAdapter::colorArrayToHex($redArr));

		// Blue with Alpha
		$blueHex = '#0000ff80';
		$blueArr = ForScoreAnnotationAdapter::hexToColorArray($blueHex);
		self::assertEqualsWithDelta(0.502, $blueArr[3], 0.01);
	}

	public function testImportForScoreRecords(): void {
		$forScoreData = [
			[
				'uuid' => 'fs-ann-1',
				'type' => 'drawing',
				'page' => 1,
				'layer' => 0,
				'color' => [1.0, 0.0, 0.0, 1.0],
				'lineWidth' => 3.0,
				'opacity' => 1.0,
				'points' => [
					[100.0, 200.0],
					[150.0, 250.0],
				],
			],
			[
				'uuid' => 'fs-ann-2',
				'type' => 'text',
				'page' => 2,
				'layer' => 1,
				'rect' => [50.0, 60.0, 200.0, 40.0],
				'text' => 'Poco a poco rit.',
				'fontSize' => 18.0,
				'color' => [0.0, 0.0, 1.0, 1.0],
			],
			[
				'uuid' => 'fs-ann-3',
				'type' => 'link',
				'page' => 4,
				'layer' => 0,
				'rect' => [300.0, 400.0, 60.0, 60.0],
				'targetPage' => 1,
			],
		];

		$imported = $this->adapter->importAnnotations($forScoreData, songId: 5, fileId: 8, pdfWidth: 600.0, pdfHeight: 800.0);

		self::assertCount(3, $imported);

		// 1. Drawing
		$drawing = $imported[0];
		self::assertSame('drawing', $drawing->type);
		self::assertSame(1, $drawing->pageNumber);
		self::assertSame('#ff0000', $drawing->style['stroke']);
		self::assertCount(2, $drawing->data['points']);
		self::assertEqualsWithDelta(100.0 / 600.0, $drawing->data['points'][0]['x'], 0.001);
		self::assertEqualsWithDelta(200.0 / 800.0, $drawing->data['points'][0]['y'], 0.001);

		// 2. Text
		$text = $imported[1];
		self::assertSame('text', $text->type);
		self::assertSame(2, $text->pageNumber);
		self::assertSame('Poco a poco rit.', $text->data['text']);
		self::assertSame('#0000ff', $text->style['color']);

		// 3. Link
		$link = $imported[2];
		self::assertSame('link', $link->type);
		self::assertSame(4, $link->pageNumber);
		self::assertSame(1, $link->data['targetPage']);
	}

	public function testExportForScoreRecords(): void {
		$annotations = [
			new InternalSongAnnotation(
				id: 1,
				uuid: 'fs-exp-1',
				songId: 5,
				fileId: 8,
				pageNumber: 0,
				layerIndex: 0,
				type: 'drawing',
				x: 0.1,
				y: 0.2,
				width: 0.3,
				height: 0.4,
				style: ['stroke' => '#00ff00', 'strokeWidth' => 2.0, 'opacity' => 1.0],
				data: [
					'points' => [
						['x' => 0.1, 'y' => 0.2],
						['x' => 0.2, 'y' => 0.4],
					],
				],
			),
			new InternalSongAnnotation(
				id: 2,
				uuid: 'fs-exp-2',
				songId: 5,
				fileId: 8,
				pageNumber: 1,
				layerIndex: 0,
				type: 'text',
				x: 0.25,
				y: 0.5,
				width: 0.3,
				height: 0.05,
				style: ['color' => '#ff0000'],
				data: ['text' => 'Coda', 'fontSize' => 14.0],
			),
		];

		$exported = $this->adapter->exportAnnotations($annotations, pdfWidth: 600.0, pdfHeight: 800.0);

		self::assertCount(2, $exported);
		self::assertSame('drawing', $exported[0]['type']);
		self::assertSame([0.0, 1.0, 0.0, 1.0], $exported[0]['color']);
		self::assertSame(60.0, $exported[0]['points'][0][0]); // 0.1 * 600
		self::assertSame(160.0, $exported[0]['points'][0][1]); // 0.2 * 800

		self::assertSame('text', $exported[1]['type']);
		self::assertSame('Coda', $exported[1]['text']);
		self::assertSame(150.0, $exported[1]['rect'][0]); // 0.25 * 600
	}
}
