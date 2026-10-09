<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Dav;

use OCA\SheetMusic\Dav\SheetMusicDavPlugin;
use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Service\MobileSheetsSqliteExporter;
use OCA\SheetMusic\Service\MobileSheetsSqliteImporter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Server;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

final class SheetMusicDavPluginTest extends TestCase {
	private LibraryMapper $libraryMapper;
	private MobileSheetsSqliteExporter $exporter;
	private MobileSheetsSqliteImporter $importer;
	private LoggerInterface $logger;
	private SheetMusicDavPlugin $plugin;

	protected function setUp(): void {
		parent::setUp();
		$this->libraryMapper = $this->createMock(LibraryMapper::class);
		$this->exporter = $this->createMock(MobileSheetsSqliteExporter::class);
		$this->importer = $this->createMock(MobileSheetsSqliteImporter::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->plugin = new SheetMusicDavPlugin(
			$this->libraryMapper,
			$this->exporter,
			$this->importer,
			$this->logger,
		);
	}

	public function testMatchVirtualPathReturnsLibraryAndFilename(): void {
		$library = new InternalLibrary(id: 1, userId: 'admin', path: 'Noten', updatedAt: 2000);

		$this->libraryMapper->method('findAllForUser')->willReturnCallback(function ($user) {
			return $user === 'admin' ? [['id' => 1, 'path' => 'Noten']] : [];
		});
		$this->libraryMapper->method('findModelById')->with(1)->willReturn($library);

		$match = $this->plugin->matchVirtualPath('files/admin/Noten/mobilesheets.db');
		self::assertNotNull($match);
		self::assertSame(1, $match['library']->id);
		self::assertSame('mobilesheets.db', $match['filename']);

		$hashMatch = $this->plugin->matchVirtualPath('files/admin/Noten/mobilesheets_hashcodes.txt');
		self::assertNotNull($hashMatch);
		self::assertSame('mobilesheets_hashcodes.txt', $hashMatch['filename']);

		// Non-matching path
		self::assertNull($this->plugin->matchVirtualPath('files/admin/Noten/song.pdf'));
		self::assertNull($this->plugin->matchVirtualPath('files/other/Noten/mobilesheets.db'));
	}

	public function testHandleGetDatabaseReturns304WhenEtagMatches(): void {
		$library = new InternalLibrary(id: 1, userId: 'admin', path: 'Noten', updatedAt: 2000);
		$etag = '"sm-db-1-2000"';

		$this->libraryMapper->method('findAllForUser')->with('admin')->willReturn([
			['id' => 1, 'path' => 'Noten'],
		]);
		$this->libraryMapper->method('findModelById')->with(1)->willReturn($library);

		$request = new Request('GET', 'files/admin/Noten/mobilesheets.db', ['If-None-Match' => $etag]);
		$response = new Response();

		$result = $this->plugin->beforeMethod($request, $response);

		self::assertFalse($result); // false stops Sabre processing
		self::assertSame(304, $response->getStatus());
	}

	public function testHandleGetHashcodesReturnsTextContent(): void {
		$library = new InternalLibrary(id: 1, userId: 'admin', path: 'Noten', updatedAt: 2000);

		$this->libraryMapper->method('findAllForUser')->with('admin')->willReturn([
			['id' => 1, 'path' => 'Noten'],
		]);
		$this->libraryMapper->method('findModelById')->with(1)->willReturn($library);

		$this->exporter->method('generateHashcodes')->with($library)->willReturn("path.pdf\n123\n1000\n500");

		$request = new Request('GET', 'files/admin/Noten/mobilesheets_hashcodes.txt');
		$response = new Response();

		$result = $this->plugin->beforeMethod($request, $response);

		self::assertFalse($result);
		self::assertSame(200, $response->getStatus());
		self::assertSame('text/plain; charset=utf-8', $response->getHeader('Content-Type'));
		self::assertSame("path.pdf\n123\n1000\n500", $response->getBodyAsString());
	}

	public function testHandlePutDatabaseImportsAndReturns204(): void {
		$library = new InternalLibrary(id: 1, userId: 'admin', path: 'Noten', updatedAt: 2000);

		$this->libraryMapper->method('findAllForUser')->with('admin')->willReturn([
			['id' => 1, 'path' => 'Noten'],
		]);
		$this->libraryMapper->method('findModelById')->with(1)->willReturn($library);

		$this->importer->expects(self::once())
			->method('importFromFile')
			->with($library, self::isType('string'))
			->willReturn(['songsUpdated' => 1]);

		$request = new Request('PUT', 'files/admin/Noten/mobilesheets.db', [], 'fake-db-binary');
		$response = new Response();

		$result = $this->plugin->beforeMethod($request, $response);

		self::assertFalse($result);
		self::assertSame(204, $response->getStatus());
		self::assertNotNull($response->getHeader('ETag'));
	}

	public function testAfterPropfindInjectsVirtualFilesIntoMultiStatusResponse(): void {
		$library = new InternalLibrary(id: 1, userId: 'admin', path: 'Noten', updatedAt: 2000);

		$this->libraryMapper->method('findAllForUser')->with('admin')->willReturn([
			['id' => 1, 'path' => 'Noten'],
		]);
		$this->libraryMapper->method('findModelById')->with(1)->willReturn($library);

		$request = new Request('PROPFIND', 'files/admin/Noten', ['Depth' => '1']);
		$response = new Response();
		$response->setStatus(207);
		$response->setBody('<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/files/admin/Noten/</d:href></d:response></d:multistatus>');

		$this->plugin->afterPropfind($request, $response);

		$body = $response->getBodyAsString();
		self::assertStringContainsString('mobilesheets.db', $body);
		self::assertStringContainsString('mobilesheets_hashcodes.txt', $body);
		self::assertStringContainsString('application/x-sqlite3', $body);
		self::assertStringContainsString('text/plain; charset=utf-8', $body);
	}
}
