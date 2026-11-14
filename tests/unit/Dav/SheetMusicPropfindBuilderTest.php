<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Dav;

use OCA\SheetMusic\Dav\SheetMusicPropfindBuilder;
use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Sync\SyncClientInterface;
use OCA\SheetMusic\Sync\SyncClientRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SheetMusicPropfindBuilderTest extends TestCase {
	public function testListedFilenamesExtractsDecodedBasenames(): void {
		$doc = new \DOMDocument('1.0', 'UTF-8');
		$doc->loadXML(<<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<d:multistatus xmlns:d="DAV:">
  <d:response><d:href>/remote.php/dav/files/admin/Library/</d:href></d:response>
  <d:response><d:href>/remote.php/dav/files/admin/Library/My%20Song.pdf</d:href></d:response>
  <d:response><d:href>/remote.php/dav/files/admin/Library/_SubLibraries_/</d:href></d:response>
</d:multistatus>
XML);

		$builder = new SheetMusicPropfindBuilder(new SyncClientRegistry(), new NullLogger());
		self::assertSame(['Library', 'My Song.pdf', '_SubLibraries_'], $builder->listedFilenames($doc));
	}

	public function testBuildPropfindEntryAndDirectoryEntry(): void {
		$client = $this->createMock(SyncClientInterface::class);
		$client->method('getVirtualFileNames')->willReturn(['mobilesheets.db']);
		$client->method('getVirtualFileProperties')->willReturn([
			'size' => 1234,
			'contentType' => 'application/x-sqlite3',
			'lastModified' => 1700000000,
			'etag' => '"sm-1-1700000000"',
		]);

		$registry = new SyncClientRegistry();
		$registry->registerClient($client);

		$library = new InternalLibrary(id: 1, userId: 'admin', path: '/Library', updatedAt: 1700000000);
		$builder = new SheetMusicPropfindBuilder($registry, new NullLogger());

		$entry = $builder->buildPropfindEntry($library, '/remote.php/dav/files/admin/Library/mobilesheets.db', 'mobilesheets.db', null, $client);
		self::assertNotNull($entry);
		self::assertSame('/remote.php/dav/files/admin/Library/mobilesheets.db', $entry['href']);
		self::assertSame('mobilesheets.db', $entry[200]['{DAV:}displayname']);
		self::assertSame(1234, $entry[200]['{DAV:}getcontentlength']);

		$dirEntry = $builder->buildDirectoryPropfindEntry('/remote.php/dav/files/admin/Library/_SubLibraries_', '_SubLibraries_', null, 1700000000);
		self::assertSame('/remote.php/dav/files/admin/Library/_SubLibraries_', $dirEntry['href']);
		self::assertSame('_SubLibraries_', $dirEntry[200]['{DAV:}displayname']);
	}
}
