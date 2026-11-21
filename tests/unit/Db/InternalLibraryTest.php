<?php

declare(strict_types=1);

namespace OCA\SheetMusic\Tests\Unit\Db;

use OCA\SheetMusic\Db\InternalLibrary;
use PHPUnit\Framework\TestCase;

final class InternalLibraryTest extends TestCase {
	public function testNormalizeFolderPathStripsSlashesAndDbFilename(): void {
		self::assertSame('', InternalLibrary::normalizeFolderPath(''));
		self::assertSame('', InternalLibrary::normalizeFolderPath('/'));
		self::assertSame('', InternalLibrary::normalizeFolderPath('.'));
		self::assertSame('', InternalLibrary::normalizeFolderPath('/mobilesheets.db'));
		self::assertSame('', InternalLibrary::normalizeFolderPath('mobilesheets.DB'));
		self::assertSame('SheetMusic', InternalLibrary::normalizeFolderPath('/SheetMusic/'));
		self::assertSame('SheetMusic/Choir', InternalLibrary::normalizeFolderPath('/SheetMusic/Choir/mobilesheets.db'));
		self::assertSame('SheetMusic/Choir', InternalLibrary::normalizeFolderPath('SheetMusic/Choir/custom.DB'));
	}

	public function testFolderPathDelegatesToNormalizeFolderPath(): void {
		$lib = new InternalLibrary(path: '/Music/Scores/mobilesheets.db');
		self::assertSame('Music/Scores', $lib->folderPath());
	}
}
