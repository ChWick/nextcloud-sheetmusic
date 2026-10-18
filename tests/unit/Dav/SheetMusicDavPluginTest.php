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
use Psr\Log\NullLogger;
use Sabre\DAV\Server;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use TestSupport\FakeUserSession;
use TestSupport\RealMobileSheetsTestTrait;

final class SheetMusicDavPluginTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private LoggerInterface $logger;
	private FakeUserSession $userSession;
	private SheetMusicDavPlugin $plugin;
	private InternalLibrary $library;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$user = $this->userManager->get('admin');
		self::assertNotNull($user);
		$this->userSession = new FakeUserSession($user);

		$this->library = $this->createTestLibrary('/My Noten', 'admin');
		$this->dbConn->executeStatement('UPDATE *PREFIX*sheetmusic_libraries SET updated_at = 2000 WHERE id = ?', [$this->library->id]);
		$lib = $this->libraryMapper->findModelById((int)$this->library->id);
		self::assertNotNull($lib);
		$this->library = $lib;

		$this->exporter = $this->createMock(MobileSheetsSqliteExporter::class);
		$this->importer = $this->createMock(MobileSheetsSqliteImporter::class);
		$this->logger = new NullLogger();

		$this->exporter->method('exportToFile')->willReturnCallback(
			function ($library, string $target): void {
				file_put_contents($target, 'SQLite format 3' . "\0" . str_repeat('x', 84));
			},
		);
		$this->exporter->method('generateHashcodes')->willReturn("path.pdf\n123\n1000\n500");

		$this->plugin = new SheetMusicDavPlugin(
			$this->libraryMapper,
			$this->exporter,
			$this->importer,
			$this->logger,
			$this->userSession,
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	private function attachServer(string $baseUri = '/remote.php/dav/'): Server {
		$server = new Server();
		$server->setBaseUri($baseUri);
		$server->sapi = new RecordingSapi();
		RecordingSapi::$sent = [];
		$this->plugin->initialize($server);
		return $server;
	}

	public function testMatchVirtualPathReturnsLibraryAndFilename(): void {
		$match = $this->plugin->matchVirtualPath('files/admin/My Noten/mobilesheets.db');
		self::assertNotNull($match);
		self::assertSame($this->library->id, $match['library']->id);
		self::assertSame('mobilesheets.db', $match['filename']);

		$hashMatch = $this->plugin->matchVirtualPath('files/admin/My Noten/mobilesheets_hashcodes.txt');
		self::assertNotNull($hashMatch);
		self::assertSame('mobilesheets_hashcodes.txt', $hashMatch['filename']);

		self::assertNull($this->plugin->matchVirtualPath('files/admin/My Noten/song.pdf'));
		self::assertNull($this->plugin->matchVirtualPath('files/admin/Other/mobilesheets.db'));
		self::assertNull($this->plugin->matchVirtualPath('files/admin/My Noten/sub/mobilesheets.db'));
	}

	public function testMatchVirtualPathRejectsOtherUsersLibraries(): void {
		// Logged in as "admin", but asking for the files of "other".
		self::assertNull($this->plugin->matchVirtualPath('files/other/My Noten/mobilesheets.db'));
	}

	public function testLegacyWebdavPathsResolveToSessionUser(): void {
		$this->attachServer('/remote.php/webdav/');

		$match = $this->plugin->matchVirtualPath('My Noten/mobilesheets.db');
		self::assertNotNull($match);
		self::assertSame($this->library->id, $match['library']->id);
		self::assertNull($this->plugin->matchVirtualPath('files/admin/My Noten/mobilesheets.db'));
	}

	public function testHandleGetDatabaseReturns304WhenEtagMatches(): void {
		$etag = '"sm-db-' . $this->library->id . '-2000"';
		$request = new Request('GET', '/files/admin/My%20Noten/mobilesheets.db', ['If-None-Match' => $etag]);
		$response = new Response();

		self::assertFalse($this->plugin->beforeMethod($request, $response));
		self::assertSame(304, $response->getStatus());
	}

	public function testHandleGetDatabaseStreamsExport(): void {
		$request = new Request('GET', '/files/admin/My%20Noten/mobilesheets.db');
		$response = new Response();

		self::assertFalse($this->plugin->beforeMethod($request, $response));
		self::assertSame(200, $response->getStatus());
		self::assertSame('application/x-sqlite3', $response->getHeader('Content-Type'));
		self::assertSame('100', $response->getHeader('Content-Length'));
		self::assertStringStartsWith('SQLite format 3', $response->getBodyAsString());
	}

	public function testHandleGetHashcodesReturnsTextContent(): void {
		$request = new Request('GET', '/files/admin/My%20Noten/mobilesheets_hashcodes.txt');
		$response = new Response();

		self::assertFalse($this->plugin->beforeMethod($request, $response));
		self::assertSame(200, $response->getStatus());
		self::assertSame('text/plain; charset=utf-8', $response->getHeader('Content-Type'));
		self::assertSame("path.pdf\n123\n1000\n500", $response->getBodyAsString());
	}

	public function testHandlePutDatabaseImportsAndReturns204(): void {
		$this->importer->expects(self::once())
			->method('importFromFile')
			->with($this->library, self::isType('string'))
			->willReturn(['songsUpdated' => 1]);

		$request = new Request('PUT', '/files/admin/My%20Noten/mobilesheets.db', [], 'fake-db-binary');
		$response = new Response();

		self::assertFalse($this->plugin->beforeMethod($request, $response));
		self::assertSame(204, $response->getStatus());
		self::assertNotNull($response->getHeader('ETag'));
	}

	public function testOtherPathsArePassedThrough(): void {
		$request = new Request('GET', '/files/admin/My%20Noten/song.pdf');
		self::assertTrue($this->plugin->beforeMethod($request, new Response()));
	}

	public function testPropfindOnVirtualFileReturnsRealSize(): void {
		$this->attachServer();
		$request = new Request('PROPFIND', '/remote.php/dav/files/admin/My%20Noten/mobilesheets.db', ['Depth' => '0']);
		$request->setBaseUrl('/remote.php/dav/');
		$response = new Response();

		self::assertFalse($this->plugin->beforeMethod($request, $response));
		self::assertSame(207, $response->getStatus());
		// Sabre does not send responses of requests stopped in beforeMethod.
		self::assertSame([$response], RecordingSapi::$sent);
		$body = $response->getBodyAsString();
		self::assertStringContainsString('/remote.php/dav/files/admin/My%20Noten/mobilesheets.db', $body);
		self::assertStringContainsString('<d:getcontentlength>100</d:getcontentlength>', $body);
		self::assertStringContainsString('application/x-sqlite3', $body);
	}

	public function testAfterPropfindInjectsVirtualFilesIntoMultiStatusResponse(): void {
		$this->attachServer();
		$propfindBody = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getcontentlength/><d:getetag/><d:quota-used-bytes/></d:prop></d:propfind>';
		$request = new Request('PROPFIND', '/remote.php/dav/files/admin/My%20Noten/', ['Depth' => '1'], $propfindBody);
		$request->setBaseUrl('/remote.php/dav/');

		// The body is captured before Sabre consumes it.
		self::assertTrue($this->plugin->beforeMethod($request, new Response()));
		self::assertSame($propfindBody, $request->getBodyAsString());

		$response = new Response();
		$response->setStatus(207);
		$response->setBody('<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/dav/files/admin/My%20Noten/</d:href></d:response></d:multistatus>');

		$this->plugin->afterPropfind($request, $response);

		$document = new \DOMDocument();
		self::assertTrue($document->loadXML($response->getBodyAsString()));
		$hrefs = array_map(
			static fn (\DOMNode $n) => $n->textContent,
			iterator_to_array($document->getElementsByTagNameNS('DAV:', 'href')),
		);
		self::assertSame([
			'/remote.php/dav/files/admin/My%20Noten/',
			'/remote.php/dav/files/admin/My%20Noten/mobilesheets.db',
			'/remote.php/dav/files/admin/My%20Noten/mobilesheets_hashcodes.txt',
		], $hrefs);

		$lengths = array_map(
			static fn (\DOMNode $n) => $n->textContent,
			iterator_to_array($document->getElementsByTagNameNS('DAV:', 'getcontentlength')),
		);
		self::assertSame(['100', (string)strlen("path.pdf\n123\n1000\n500")], $lengths);
		// Only the requested properties are returned; unknown ones are reported as missing.
		self::assertSame(0, $document->getElementsByTagNameNS('DAV:', 'getcontenttype')->length);
		self::assertSame(2, $document->getElementsByTagNameNS('DAV:', 'quota-used-bytes')->length);
	}

	public function testAfterPropfindKeepsRealFiles(): void {
		$this->attachServer();
		$request = new Request('PROPFIND', '/remote.php/dav/files/admin/My%20Noten', ['Depth' => '1']);
		$request->setBaseUrl('/remote.php/dav/');

		$response = new Response();
		$response->setStatus(207);
		$response->setBody('<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:">'
			. '<d:response><d:href>/remote.php/dav/files/admin/My%20Noten/</d:href></d:response>'
			. '<d:response><d:href>/remote.php/dav/files/admin/My%20Noten/mobilesheets.db</d:href></d:response>'
			. '</d:multistatus>');

		$this->plugin->afterPropfind($request, $response);

		$body = $response->getBodyAsString();
		self::assertSame(1, substr_count($body, 'mobilesheets.db<'));
		self::assertStringContainsString('mobilesheets_hashcodes.txt', $body);
	}

	public function testAfterPropfindPreservesUtf8CharactersWithoutHtmlEntities(): void {
		$this->attachServer();
		$request = new Request('PROPFIND', '/remote.php/dav/files/admin/My%20Noten', ['Depth' => '1']);
		$request->setBaseUrl('/remote.php/dav/');

		$response = new Response();
		$response->setStatus(207);
		$response->setBody('<?xml version="1.0"?>'
			. '<d:multistatus xmlns:d="DAV:">'
			. '<d:response><d:href>/remote.php/dav/files/admin/My%20Noten/Louis%20James%20Alfred%20Lef%C3%A9bure-Wely/</d:href>'
			. '<d:propstat><d:prop><d:displayname>Louis James Alfred Lefébure-Wely</d:displayname></d:prop></d:propstat>'
			. '</d:response>'
			. '</d:multistatus>');

		$this->plugin->afterPropfind($request, $response);

		$body = $response->getBodyAsString();
		self::assertStringContainsString('Louis James Alfred Lefébure-Wely', $body);
		self::assertStringNotContainsString('&#xE9;', $body);
		self::assertStringNotContainsString('&#xe9;', $body);
	}

	public function testAfterPropfindInjectsIntoStreamedResponse(): void {
		$this->attachServer('/remote.php/webdav/');
		$request = new Request('PROPFIND', '/remote.php/webdav/My%20Noten', ['Depth' => '1']);
		$request->setBaseUrl('/remote.php/webdav/');

		$calls = 0;
		$response = new Response();
		$response->setStatus(207);
		$response->setBody(function () use (&$calls): void {
			$calls++;
			echo '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/webdav/My%20Noten/</d:href></d:response></d:multistatus>';
		});

		$this->plugin->afterPropfind($request, $response);

		self::assertIsCallable($response->getBody());
		$body = $response->getBodyAsString();
		self::assertSame(1, $calls);
		self::assertStringContainsString('<d:href>/remote.php/webdav/My%20Noten/mobilesheets.db</d:href>', $body);
		self::assertStringContainsString('<d:href>/remote.php/webdav/My%20Noten/mobilesheets_hashcodes.txt</d:href>', $body);
	}

	public function testLibraryPathWithDbFilenameMatchesParentFolder(): void {
		$dbLib = $this->createTestLibrary('/tmp/SheetMusic/mobilesheets.db', 'admin');
		$dbLibId = (int)$dbLib->id;

		$plugin = new SheetMusicDavPlugin(
			$this->libraryMapper,
			$this->exporter,
			$this->importer,
			$this->logger,
			$this->userSession,
		);

		$match = $plugin->matchVirtualPath('files/admin/tmp/SheetMusic/mobilesheets.db');
		self::assertNotNull($match);
		self::assertSame($dbLibId, $match['library']->id);
		self::assertSame('mobilesheets.db', $match['filename']);

		$folderLib = $plugin->findLibraryByFolderPath('files/admin/tmp/SheetMusic');
		self::assertNotNull($folderLib);
		self::assertSame($dbLibId, $folderLib->id);
	}
}

/**
 * Records responses instead of writing them to the output.
 */
final class RecordingSapi extends \Sabre\HTTP\Sapi {
	/** @var list<\Sabre\HTTP\ResponseInterface> */
	public static array $sent = [];

	public static function sendResponse(\Sabre\HTTP\ResponseInterface $response): void {
		self::$sent[] = $response;
	}
}
