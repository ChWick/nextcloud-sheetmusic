<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Util;

use OCA\SheetMusic\Util\MobileSheetsTimestampUtil;
use PHPUnit\Framework\TestCase;

final class MobileSheetsTimestampUtilTest extends TestCase {
	public function testMsToSeconds(): void {
		self::assertSame(0, MobileSheetsTimestampUtil::msToSeconds(null));
		self::assertSame(0, MobileSheetsTimestampUtil::msToSeconds(0));
		self::assertSame(0, MobileSheetsTimestampUtil::msToSeconds(-500));
		self::assertSame(1700000000, MobileSheetsTimestampUtil::msToSeconds(1700000000000));
		self::assertSame(1700000000, MobileSheetsTimestampUtil::msToSeconds(1700000000499));
		self::assertSame(1700000001, MobileSheetsTimestampUtil::msToSeconds(1700000000501));
	}

	public function testSecondsToMs(): void {
		self::assertSame(0, MobileSheetsTimestampUtil::secondsToMs(null));
		self::assertSame(0, MobileSheetsTimestampUtil::secondsToMs(0));
		self::assertSame(0, MobileSheetsTimestampUtil::secondsToMs(-10));
		self::assertSame(1700000000000, MobileSheetsTimestampUtil::secondsToMs(1700000000));
	}

	public function testExtractLastModifiedSec(): void {
		self::assertSame(0, MobileSheetsTimestampUtil::extractLastModifiedSec([]));
		self::assertSame(1600000, MobileSheetsTimestampUtil::extractLastModifiedSec(['LastModified' => 1600000000]));
		self::assertSame(1600000, MobileSheetsTimestampUtil::extractLastModifiedSec(['lastmodified' => 1600000000]));
	}

	public function testExtractDateCreatedSec(): void {
		self::assertSame(0, MobileSheetsTimestampUtil::extractDateCreatedSec([]));
		self::assertSame(1500000, MobileSheetsTimestampUtil::extractDateCreatedSec(['DateCreated' => 1500000000]));
		self::assertSame(1500000, MobileSheetsTimestampUtil::extractDateCreatedSec(['CreationDate' => 1500000000]));
		self::assertSame(1500000, MobileSheetsTimestampUtil::extractDateCreatedSec(['datecreated' => 1500000000]));
		self::assertSame(1500000, MobileSheetsTimestampUtil::extractDateCreatedSec(['creationdate' => 1500000000]));
	}
}
