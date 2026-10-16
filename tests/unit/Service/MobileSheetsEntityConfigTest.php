<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Service\MobileSheetsEntityConfig;
use PHPUnit\Framework\TestCase;

final class MobileSheetsEntityConfigTest extends TestCase {
	public function testConfigMappings(): void {
		self::assertArrayHasKey('composer', MobileSheetsEntityConfig::BY_TYPE);
		self::assertArrayHasKey('collection', MobileSheetsEntityConfig::BY_TYPE);
		self::assertArrayHasKey('book', MobileSheetsEntityConfig::BY_TYPE);

		$composer = MobileSheetsEntityConfig::BY_TYPE['composer'];
		self::assertSame('Composer', $composer['table']);
		self::assertSame('Name', $composer['nameCol']);
		self::assertSame('ComposerSongs', $composer['linkTable']);
		self::assertSame('ComposerId', $composer['linkCol']);
		self::assertTrue($composer['hasSort']);

		self::assertArrayHasKey('Composer', MobileSheetsEntityConfig::BY_TABLE);
		self::assertArrayHasKey('Collections', MobileSheetsEntityConfig::BY_TABLE);
		self::assertArrayHasKey('Keys', MobileSheetsEntityConfig::BY_TABLE);
		self::assertArrayHasKey('Key', MobileSheetsEntityConfig::BY_TABLE);
		self::assertArrayHasKey('SourceType', MobileSheetsEntityConfig::BY_TABLE);
		self::assertArrayHasKey('SourceTypes', MobileSheetsEntityConfig::BY_TABLE);
	}

	public function testIsCoreManaged(): void {
		self::assertTrue(MobileSheetsEntityConfig::isCoreManaged('songs'));
		self::assertTrue(MobileSheetsEntityConfig::isCoreManaged('Songs'));
		self::assertTrue(MobileSheetsEntityConfig::isCoreManaged('SONGS'));
		self::assertTrue(MobileSheetsEntityConfig::isCoreManaged('Composer'));
		self::assertTrue(MobileSheetsEntityConfig::isCoreManaged('CollectionSong'));
		self::assertTrue(MobileSheetsEntityConfig::isCoreManaged('_nc_sync_metadata'));

		self::assertFalse(MobileSheetsEntityConfig::isCoreManaged('custom_user_table'));
		self::assertFalse(MobileSheetsEntityConfig::isCoreManaged('bookmarks'));
	}
}

