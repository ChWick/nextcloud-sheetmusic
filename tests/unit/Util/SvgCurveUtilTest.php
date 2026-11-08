<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Util;

use OCA\SheetMusic\Util\SvgCurveUtil;
use PHPUnit\Framework\TestCase;

final class SvgCurveUtilTest extends TestCase {
	public function testGenerateSvgPathEmpty(): void {
		$this->assertSame('', SvgCurveUtil::generateSvgPath([]));
	}

	public function testGenerateSvgPathSinglePoint(): void {
		$points = [['x' => 0.1, 'y' => 0.2]];
		$this->assertSame('M 100 200', SvgCurveUtil::generateSvgPath($points));
	}

	public function testGenerateSvgPathTwoPoints(): void {
		$points = [
			['x' => 0.1, 'y' => 0.2],
			['x' => 0.3, 'y' => 0.4],
		];
		$this->assertSame('M 100 200 L 300 400', SvgCurveUtil::generateSvgPath($points));
	}

	public function testGenerateSvgPathSmoothing(): void {
		$points = [
			['x' => 0.1, 'y' => 0.1],
			['x' => 0.2, 'y' => 0.2],
			['x' => 0.3, 'y' => 0.25],
		];
		$path = SvgCurveUtil::generateSvgPath($points, smooth: true);
		$this->assertStringContainsString(' Q ', $path);
		$this->assertSame('M 100 100 Q 200 200, 250 225 L 300 250', $path);

		$linearPath = SvgCurveUtil::generateSvgPath($points, smooth: false);
		$this->assertStringNotContainsString(' Q ', $linearPath);
		$this->assertSame('M 100 100 L 200 200 L 300 250', $linearPath);
	}

	public function testCalculateBoundingBox(): void {
		$points = [
			['x' => 0.1, 'y' => 0.2],
			['x' => 0.4, 'y' => 0.7],
		];
		$bbox = SvgCurveUtil::calculateBoundingBox($points);
		$this->assertEquals(0.1, $bbox['x']);
		$this->assertEquals(0.2, $bbox['y']);
		$this->assertEquals(0.3, $bbox['width']);
		$this->assertEquals(0.5, $bbox['height']);
	}

	public function testGenerateHairpinPathCrescendo(): void {
		$p1 = ['x' => 0.1, 'y' => 0.5];
		$p2 = ['x' => 0.3, 'y' => 0.5];
		$path = SvgCurveUtil::generateHairpinPath($p1, $p2, isDecrescendo: false);
		$this->assertStringStartsWith('M 300', $path);
		$this->assertStringContainsString('L 100 500', $path);
		$this->assertStringContainsString('L 300', $path);
	}

	public function testGenerateHairpinPathDecrescendo(): void {
		$p1 = ['x' => 0.1, 'y' => 0.5];
		$p2 = ['x' => 0.3, 'y' => 0.5];
		$path = SvgCurveUtil::generateHairpinPath($p1, $p2, isDecrescendo: true);
		$this->assertStringStartsWith('M 100', $path);
		$this->assertStringContainsString('L 300 500', $path);
		$this->assertStringContainsString('L 100', $path);
	}

	public function testCalculateHairpinBoundingBox(): void {
		$p1 = ['x' => 0.1, 'y' => 0.5];
		$p2 = ['x' => 0.3, 'y' => 0.5];
		$bbox = SvgCurveUtil::calculateHairpinBoundingBox($p1, $p2, isDecrescendo: false);
		$this->assertEquals(0.1, $bbox['x']);
		$this->assertEquals(0.2, $bbox['width']);
		$this->assertGreaterThan(0.01, $bbox['height']);
	}
}
