<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Dav;

use OCA\SheetMusic\Dav\SheetMusicDavPlugin;
use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\SublibraryMapper;
use OCA\SheetMusic\Service\LibraryShareService;
use OCA\SheetMusic\Service\SublibraryService;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteExporter;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteImporter;
use OCA\SheetMusic\Sync\SyncClientRegistry;
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
	private ?LibraryShareService $shareService = null;
	private ?\OCP\Share\IManager $shareManager = null;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$user = $this->userManager->get('admin');
		self::assertNotNull($user);
		$this->userSession = new FakeUserSession($user);

		$this->library = $this->createTestLibrary('/My Noten', 'admin');
		$this->dbConn->executeStatement('UPDATE *PREFIX*sheetmusic_libraries SET updated_at = 2000, mobilesheets_sync_enabled = 1 WHERE id = ?', [$this->library->id]);
		$lib = $this->libraryMapper->findModelById((int)$this->library->id);
		self::assertNotNull($lib);
		$this->library = $lib;

		$this->logger = new NullLogger();

		$song = new \OCA\SheetMusic\Db\InternalSong(
			id: null,
			uuid: 'score-song-uuid',
			libraryId: (int)$this->library->id,
			title: 'Score Song',
			clientId: 1,
			createdAt: 2000,
			updatedAt: 2000,
		);
		$createdSong = $this->songMapper->insert($song);
		$this->attachTestFileToSong($createdSong, 'score.pdf', 1);

		$instOrgel = $this->insertTestEntity((int)$this->library->id, 'instrument', 'Orgel');
		$this->entityMapper->linkSongEntity((int)$createdSong->id, (int)$instOrgel->id);

		$this->sublibraryService = new SublibraryService(
			new SublibraryMapper($this->dbConn),
			$this->entityMapper,
			$this->dbConn,
		);

		$this->importer = new MobileSheetsSqliteImporter(
			$this->songMapper,
			$this->entityMapper,
			$this->songFileMapper,
			$this->attributeMapper,
			$this->miscMapper,
			$this->libraryMapper,
			$this->pathResolver,
			$this->rootFolder,
			$this->userManager,
			$this->logger,
			null,
			null,
			$this->setlistItemMapper,
			$this->bookmarkMapper,
			null,
			$this->sublibraryService,
		);

		$this->exporter = new MobileSheetsSqliteExporter(
			$this->songMapper,
			$this->entityMapper,
			$this->songFileMapper,
			$this->attributeMapper,
			$this->miscMapper,
			$this->libraryMapper,
			$this->pathResolver,
			$this->rootFolder,
			$this->userManager,
			$this->logger,
			null,
			$this->bookmarkMapper,
			$this->setlistItemMapper,
			null,
			$this->sublibraryService,
		);

		$this->shareManager = $this->createMock(\OCP\Share\IManager::class);
		$this->shareService = new LibraryShareService(
			$this->libraryMapper,
			$this->rootFolder,
			$this->shareManager,
			$this->userManager,
			null,
			null,
			new SublibraryMapper($this->dbConn),
		);

		$this->plugin = new SheetMusicDavPlugin(
			$this->libraryMapper,
			$this->exporter,
			$this->importer,
			$this->logger,
			$this->userSession,
			null,
			$this->rootFolder,
			$this->sublibraryService,
			$this->shareService,
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
		$match = $this->plugin->matchVirtualPath('files/admin/My Noten/Songs/mobilesheets.db');
		self::assertNotNull($match);
		self::assertSame($this->library->id, $match['library']->id);
		self::assertSame('mobilesheets.db', $match['filename']);

		$hashMatch = $this->plugin->matchVirtualPath('files/admin/My Noten/Songs/mobilesheets_hashcodes.txt');
		self::assertNotNull($hashMatch);
		self::assertSame('mobilesheets_hashcodes.txt', $hashMatch['filename']);

		self::assertNull($this->plugin->matchVirtualPath('files/admin/My Noten/mobilesheets.db'));
		self::assertNull($this->plugin->matchVirtualPath('files/admin/My Noten/song.pdf'));
		self::assertNull($this->plugin->matchVirtualPath('files/admin/Other/Songs/mobilesheets.db'));
		self::assertNull($this->plugin->matchVirtualPath('files/admin/My Noten/Songs/sub/mobilesheets.db'));
	}

	public function testMatchVirtualPathRejectsOtherUsersLibraries(): void {
		// Logged in as "admin", but asking for the files of "other".
		self::assertNull($this->plugin->matchVirtualPath('files/other/My Noten/Songs/mobilesheets.db'));
	}

	public function testLegacyWebdavPathsResolveToSessionUser(): void {
		$this->attachServer('/remote.php/webdav/');

		$match = $this->plugin->matchVirtualPath('My Noten/Songs/mobilesheets.db');
		self::assertNotNull($match);
		self::assertSame($this->library->id, $match['library']->id);
		self::assertNull($this->plugin->matchVirtualPath('files/admin/My Noten/Songs/mobilesheets.db'));
	}

	public function testHandleGetDatabaseReturns304WhenEtagMatches(): void {
		$etag = '"sm-db-' . $this->library->id . '-2000"';
		$request = new Request('GET', '/files/admin/My%20Noten/Songs/mobilesheets.db', ['If-None-Match' => $etag]);
		$response = new Response();

		self::assertFalse($this->plugin->beforeMethod($request, $response));
		self::assertSame(304, $response->getStatus());
	}

	public function testHandleGetDatabaseStreamsExport(): void {
		$request = new Request('GET', '/files/admin/My%20Noten/Songs/mobilesheets.db');
		$response = new Response();

		self::assertFalse($this->plugin->beforeMethod($request, $response));
		self::assertSame(200, $response->getStatus());
		self::assertSame('application/x-sqlite3', $response->getHeader('Content-Type'));
		$contentLength = (int)$response->getHeader('Content-Length');
		self::assertGreaterThan(0, $contentLength);
		self::assertStringStartsWith('SQLite format 3', $response->getBodyAsString());
	}

	public function testHandleGetHashcodesReturnsTextContent(): void {
		$request = new Request('GET', '/files/admin/My%20Noten/Songs/mobilesheets_hashcodes.txt');
		$response = new Response();

		self::assertFalse($this->plugin->beforeMethod($request, $response));
		self::assertSame(200, $response->getStatus());
		self::assertSame('text/plain; charset=utf-8', $response->getHeader('Content-Type'));
		self::assertStringContainsString('score.pdf', $response->getBodyAsString());
	}

	public function testHandlePutDatabaseImportsAndReturns204(): void {
		$tempDb = $this->tempDbPath();
		$this->exporter->exportToFile($this->library, $tempDb);
		$dbBytes = (string)file_get_contents($tempDb);

		$request = new Request('PUT', '/files/admin/My%20Noten/Songs/mobilesheets.db', [], $dbBytes);
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
		$request = new Request('PROPFIND', '/remote.php/dav/files/admin/My%20Noten/Songs/mobilesheets.db', ['Depth' => '0']);
		$request->setBaseUrl('/remote.php/dav/');
		$response = new Response();

		self::assertFalse($this->plugin->beforeMethod($request, $response));
		self::assertSame(207, $response->getStatus());
		// Sabre does not send responses of requests stopped in beforeMethod.
		self::assertSame([$response], RecordingSapi::$sent);
		$body = $response->getBodyAsString();
		self::assertStringContainsString('/remote.php/dav/files/admin/My%20Noten/Songs/mobilesheets.db', $body);
		self::assertMatchesRegularExpression('|<d:getcontentlength>[1-9][0-9]*</d:getcontentlength>|', $body);
		self::assertStringContainsString('application/x-sqlite3', $body);
	}

	public function testAfterPropfindInjectsVirtualFilesIntoMultiStatusResponse(): void {
		$this->attachServer();
		$propfindBody = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getcontentlength/><d:getetag/><d:quota-used-bytes/></d:prop></d:propfind>';
		$request = new Request('PROPFIND', '/remote.php/dav/files/admin/My%20Noten/Songs/', ['Depth' => '1'], $propfindBody);
		$request->setBaseUrl('/remote.php/dav/');

		// The body is captured before Sabre consumes it.
		self::assertTrue($this->plugin->beforeMethod($request, new Response()));
		self::assertSame($propfindBody, $request->getBodyAsString());

		$response = new Response();
		$response->setStatus(207);
		$response->setBody('<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/dav/files/admin/My%20Noten/Songs/</d:href></d:response></d:multistatus>');

		$this->plugin->afterPropfind($request, $response);

		$document = new \DOMDocument();
		self::assertTrue($document->loadXML($response->getBodyAsString()));
		$hrefs = array_map(
			static fn (\DOMNode $n) => $n->textContent,
			iterator_to_array($document->getElementsByTagNameNS('DAV:', 'href')),
		);
		self::assertSame([
			'/remote.php/dav/files/admin/My%20Noten/Songs/',
			'/remote.php/dav/files/admin/My%20Noten/Songs/mobilesheets.db',
			'/remote.php/dav/files/admin/My%20Noten/Songs/mobilesheets_hashcodes.txt',
		], $hrefs);

		$lengths = array_map(
			static fn (\DOMNode $n) => (int)$n->textContent,
			iterator_to_array($document->getElementsByTagNameNS('DAV:', 'getcontentlength')),
		);
		self::assertCount(2, $lengths);
		self::assertGreaterThan(0, $lengths[0]);
		self::assertGreaterThan(0, $lengths[1]);
		// Only the requested properties are returned; unknown ones are reported as missing.
		self::assertSame(0, $document->getElementsByTagNameNS('DAV:', 'getcontenttype')->length);
		self::assertSame(2, $document->getElementsByTagNameNS('DAV:', 'quota-used-bytes')->length);
	}

	public function testAfterPropfindKeepsRealFiles(): void {
		$this->attachServer();
		$request = new Request('PROPFIND', '/remote.php/dav/files/admin/My%20Noten/Songs', ['Depth' => '1']);
		$request->setBaseUrl('/remote.php/dav/');

		$response = new Response();
		$response->setStatus(207);
		$response->setBody('<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:">'
			. '<d:response><d:href>/remote.php/dav/files/admin/My%20Noten/Songs/</d:href></d:response>'
			. '<d:response><d:href>/remote.php/dav/files/admin/My%20Noten/Songs/mobilesheets.db</d:href></d:response>'
			. '</d:multistatus>');

		$this->plugin->afterPropfind($request, $response);

		$body = $response->getBodyAsString();
		self::assertSame(1, substr_count($body, 'mobilesheets.db<'));
		self::assertStringContainsString('mobilesheets_hashcodes.txt', $body);
	}

	public function testAfterPropfindDoesNotInjectVirtualFilesWhenLibraryIsEmpty(): void {
		$emptyLib = $this->createTestLibrary('/EmptyNotenLib', 'admin');
		$this->attachServer();

		$request = new Request('PROPFIND', '/remote.php/dav/files/admin/EmptyNotenLib', ['Depth' => '1']);
		$request->setBaseUrl('/remote.php/dav/');

		$response = new Response();
		$response->setStatus(207);
		$initialBody = '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/dav/files/admin/EmptyNotenLib/</d:href></d:response></d:multistatus>';
		$response->setBody($initialBody);

		$this->plugin->afterPropfind($request, $response);

		self::assertSame($initialBody, $response->getBodyAsString());
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
		$request = new Request('PROPFIND', '/remote.php/webdav/My%20Noten/Songs', ['Depth' => '1']);
		$request->setBaseUrl('/remote.php/webdav/');

		$calls = 0;
		$response = new Response();
		$response->setStatus(207);
		$response->setBody(function () use (&$calls): void {
			$calls++;
			echo '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/webdav/My%20Noten/Songs/</d:href></d:response></d:multistatus>';
		});

		$this->plugin->afterPropfind($request, $response);

		self::assertIsCallable($response->getBody());
		$body = $response->getBodyAsString();
		self::assertSame(1, $calls);
		self::assertStringContainsString('<d:href>/remote.php/webdav/My%20Noten/Songs/mobilesheets.db</d:href>', $body);
		self::assertStringContainsString('<d:href>/remote.php/webdav/My%20Noten/Songs/mobilesheets_hashcodes.txt</d:href>', $body);
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

		$match = $plugin->matchVirtualPath('files/admin/tmp/SheetMusic/Songs/mobilesheets.db');
		self::assertNotNull($match);
		self::assertSame($dbLibId, $match['library']->id);
		self::assertSame('mobilesheets.db', $match['filename']);

		$folderLib = $plugin->findLibraryByFolderPath('files/admin/tmp/SheetMusic');
		self::assertNotNull($folderLib);
		self::assertSame($dbLibId, $folderLib->id);
	}

	public function testPluginWithEmptyRegistryStillRegistersMobileSheetsSyncBackend(): void {
		$emptyRegistry = new SyncClientRegistry([]);
		self::assertEmpty($emptyRegistry->getClients());

		$plugin = new SheetMusicDavPlugin(
			$this->libraryMapper,
			$this->exporter,
			$this->importer,
			$this->logger,
			$this->userSession,
			$emptyRegistry,
		);

		$match = $plugin->matchVirtualPath('files/admin/My Noten/Songs/mobilesheets.db');
		self::assertNotNull($match);
		self::assertSame((int)$this->library->id, $match['library']->id);
		self::assertSame('mobilesheets.db', $match['filename']);

		$hashMatch = $plugin->matchVirtualPath('files/admin/My Noten/Songs/mobilesheets_hashcodes.txt');
		self::assertNotNull($hashMatch);
		self::assertSame('mobilesheets_hashcodes.txt', $hashMatch['filename']);
	}

	public function testServerContainerResolvesPluginWithRegisteredSyncClients(): void {
		$plugin = \OC::$server->get(SheetMusicDavPlugin::class);
		self::assertInstanceOf(SheetMusicDavPlugin::class, $plugin);

		$userSession = \OC::$server->get(\OCP\IUserSession::class);
		$userSession->setUser($this->userManager->get('admin'));

		$match = $plugin->matchVirtualPath('files/admin/My Noten/Songs/mobilesheets.db');
		self::assertNotNull($match);
		self::assertSame((int)$this->library->id, $match['library']->id);
	}

	public function testBeforeMethodPutCreatesParentDirectoriesInLibraryFolder(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		if ($userFolder->nodeExists('My Noten/DavSubFolder')) {
			$userFolder->get('My Noten/DavSubFolder')->delete();
		}
		self::assertFalse($userFolder->nodeExists('My Noten/DavSubFolder'));

		$request = new Request('PUT', '/remote.php/dav/files/admin/My Noten/DavSubFolder/test.pdf');
		$response = new Response();

		$proceed = $this->plugin->beforeMethod($request, $response);
		self::assertTrue($proceed);
		self::assertTrue($userFolder->nodeExists('My Noten/DavSubFolder'));
		$this->createdUserNodes[] = $userFolder->get('My Noten/DavSubFolder');
	}

	public function testBeforeMethodPutDoesNotCreateDirectoriesOutsideLibrary(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		if ($userFolder->nodeExists('OtherFolder/DavSubFolder')) {
			$userFolder->get('OtherFolder/DavSubFolder')->delete();
		}
		self::assertFalse($userFolder->nodeExists('OtherFolder/DavSubFolder'));

		$request = new Request('PUT', '/remote.php/dav/files/admin/OtherFolder/DavSubFolder/test.pdf');
		$response = new Response();

		$proceed = $this->plugin->beforeMethod($request, $response);
		self::assertTrue($proceed);
		self::assertFalse($userFolder->nodeExists('OtherFolder/DavSubFolder'));
	}

	public function testWebDavPropfindAndGetWithRealExporterAndMovementsAndCollections(): void {
		// 1. Create a user collection in Nextcloud
		$collection = new \OCA\SheetMusic\Db\InternalEntity(
			id: null,
			uuid: 'coll-uuid-1',
			libraryId: (int)$this->library->id,
			name: 'Chamber Works',
			type: 'collection',
			clientId: 1,
			createdAt: 2000,
			updatedAt: 2000,
		);
		$createdCollection = $this->entityMapper->insert($collection);

		// 2. Create a song with a file and movements
		$song = new \OCA\SheetMusic\Db\InternalSong(
			id: null,
			uuid: 'song-uuid-1',
			libraryId: (int)$this->library->id,
			title: 'String Quartet No. 14',
			clientId: 1,
			createdAt: 2000,
			updatedAt: 2000,
		);
		$createdSong = $this->songMapper->insert($song);
		$this->attachTestFileToSong($createdSong, 'quartet.pdf', 1);

		$this->bookmarkMapper->setBookmarksForSong((int)$createdSong->id, [
			['name' => 'I. Adagio', 'pageNum' => 1, 'endPage' => 2],
			['name' => 'II. Vivace', 'pageNum' => 3, 'endPage' => 4],
		]);

		// 3. Plugin with REAL MobileSheetsSqliteExporter
		$realExporter = new MobileSheetsSqliteExporter(
			$this->songMapper,
			$this->entityMapper,
			$this->songFileMapper,
			$this->attributeMapper,
			$this->miscMapper,
			$this->libraryMapper,
			$this->pathResolver,
			$this->rootFolder,
			$this->userManager,
			$this->logger,
			null,
			$this->bookmarkMapper,
		);

		$plugin = new SheetMusicDavPlugin(
			$this->libraryMapper,
			$realExporter,
			$this->importer,
			$this->logger,
			$this->userSession,
			null,
			$this->rootFolder,
		);

		$server = new Server();
		$server->setBaseUri('/remote.php/dav/');
		$server->sapi = new RecordingSapi();
		RecordingSapi::$sent = [];
		$plugin->initialize($server);

		// 4. Test WebDAV PROPFIND on mobilesheets.db
		$propfindReq = new Request('PROPFIND', '/remote.php/dav/files/admin/My%20Noten/Songs/mobilesheets.db', ['Depth' => '0']);
		$propfindReq->setBaseUrl('/remote.php/dav/');
		$propfindResp = new Response();

		self::assertFalse($plugin->beforeMethod($propfindReq, $propfindResp));
		self::assertSame(207, $propfindResp->getStatus());
		$propfindXml = $propfindResp->getBodyAsString();

		// Extract getcontentlength from XML
		$dom = new \DOMDocument();
		self::assertTrue($dom->loadXML($propfindXml));
		$lengths = $dom->getElementsByTagNameNS('DAV:', 'getcontentlength');
		self::assertSame(1, $lengths->length);
		$lengthValue = (int)$lengths->item(0)->textContent;
		self::assertGreaterThan(0, $lengthValue, 'mobilesheets.db size in PROPFIND must be > 0 bytes');

		// 5. Test WebDAV GET on mobilesheets.db
		$getReq = new Request('GET', '/remote.php/dav/files/admin/My%20Noten/Songs/mobilesheets.db');
		$getReq->setBaseUrl('/remote.php/dav/');
		$getResp = new Response();

		self::assertFalse($plugin->beforeMethod($getReq, $getResp));
		self::assertSame(200, $getResp->getStatus());
		self::assertSame('application/x-sqlite3', $getResp->getHeader('Content-Type'));
		$contentLength = (int)$getResp->getHeader('Content-Length');
		self::assertSame($lengthValue, $contentLength);

		$dbStream = $getResp->getBody();
		$dbContent = is_resource($dbStream) ? (string)stream_get_contents($dbStream) : (string)$dbStream;
		self::assertStringStartsWith("SQLite format 3\0", $dbContent);

		// 6. Verify SQLite database integrity & content
		$tempDb = $this->tempDbPath();
		file_put_contents($tempDb, $dbContent);
		$pdo = new \PDO('sqlite:' . $tempDb);

		// Collections: 1 user collection
		$collRows = $pdo->query('SELECT Id, Name FROM Collections ORDER BY Id ASC')->fetchAll(\PDO::FETCH_ASSOC);
		self::assertCount(1, $collRows);
		self::assertSame('Chamber Works', $collRows[0]['Name']);

		// Songs: 1 master song
		$songRows = $pdo->query('SELECT Id, Title FROM Songs ORDER BY Id ASC')->fetchAll(\PDO::FETCH_ASSOC);
		self::assertCount(1, $songRows);
		self::assertSame('String Quartet No. 14', $songRows[0]['Title']);

		// Bookmarks: 2 bookmarks exported on master song (0-indexed in SQLite)
		$bmRows = $pdo->query('SELECT Name, PageNum, ShowInLibrary FROM Bookmarks ORDER BY PageNum ASC')->fetchAll(\PDO::FETCH_ASSOC);
		self::assertCount(2, $bmRows);
		self::assertSame('I. Adagio', $bmRows[0]['Name']);
		self::assertSame(0, (int)$bmRows[0]['PageNum']);
		self::assertSame('II. Vivace', $bmRows[1]['Name']);
		self::assertSame(2, (int)$bmRows[1]['PageNum']);
		self::assertSame(1, (int)$bmRows[0]['ShowInLibrary']);
	}

	public function testSublibraryVirtualPathAndPropfind(): void {
		$server = $this->attachServer();

		$sub = $this->sublibraryService->createSublibrary((int)$this->library->id, 'Organ Repertoire', 'orgel', [
			'instrument' => ['Orgel'],
		]);

		// 1. matchVirtualPath with sublibrary (SubLibraries and legacy .sublibraries)
		$match = $this->plugin->matchVirtualPath('files/admin/My Noten/SubLibraries/orgel/mobilesheets.db');
		self::assertNotNull($match);
		self::assertSame($this->library->id, $match['library']->id);
		self::assertSame('mobilesheets.db', $match['filename']);
		self::assertNotNull($match['sublibrary']);
		self::assertSame('orgel', $match['sublibrary']->slug);

		$legacyMatch = $this->plugin->matchVirtualPath('files/admin/My Noten/.sublibraries/orgel/mobilesheets.db');
		self::assertNotNull($legacyMatch);
		self::assertSame('orgel', $legacyMatch['sublibrary']->slug);

		// 2. PROPFIND on SubLibraries collection
		$req = new Request('PROPFIND', '/files/admin/My%20Noten/SubLibraries', ['Depth' => '1']);
		$res = new Response();
		self::assertFalse($this->plugin->beforeMethod($req, $res));
		self::assertSame(207, $res->getStatus());
		$body = (string)$res->getBody();
		self::assertStringContainsString('SubLibraries', $body);
		self::assertStringContainsString('orgel', $body);

		// 3. PROPFIND on SubLibraries/orgel collection
		$reqSub = new Request('PROPFIND', '/files/admin/My%20Noten/SubLibraries/orgel', ['Depth' => '1']);
		$resSub = new Response();
		self::assertFalse($this->plugin->beforeMethod($reqSub, $resSub));
		self::assertSame(207, $resSub->getStatus());
		$bodySub = (string)$resSub->getBody();
		self::assertStringContainsString('mobilesheets.db', $bodySub);
		self::assertStringContainsString('mobilesheets_hashcodes.txt', $bodySub);
	}

	public function testSublibraryFileUploadPutRoutesToMasterLibrary(): void {
		$this->attachServer();
		$this->sublibraryService->createSublibrary((int)$this->library->id, 'Organ Repertoire', 'orgel', [
			'instrument' => ['Orgel'],
		]);

		$fileContent = '%PDF-1.4 test score';
		$req = new Request('PUT', '/files/admin/My%20Noten/SubLibraries/orgel/UploadScore.pdf');
		$req->setBody($fileContent);
		$res = new Response();

		self::assertFalse($this->plugin->beforeMethod($req, $res));
		self::assertSame(201, $res->getStatus());

		// Verify physical file was saved in master library directory
		$userFolder = $this->rootFolder->getUserFolder('admin');
		self::assertTrue($userFolder->nodeExists('My Noten/UploadScore.pdf'));
		$savedNode = $userFolder->get('My Noten/UploadScore.pdf');
		self::assertSame($fileContent, $savedNode->getContent());

		// Verify GET on virtual sublibrary file downloads the physical file
		$getReq = new Request('GET', '/files/admin/My%20Noten/SubLibraries/orgel/UploadScore.pdf');
		$getRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($getReq, $getRes));
		self::assertSame(200, $getRes->getStatus());
		self::assertSame($fileContent, stream_get_contents($getRes->getBody()));

		// Verify HEAD on virtual sublibrary file
		$headReq = new Request('HEAD', '/files/admin/My%20Noten/SubLibraries/orgel/UploadScore.pdf');
		$headRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($headReq, $headRes));
		self::assertSame(200, $headRes->getStatus());
		self::assertSame((string)strlen($fileContent), $headRes->getHeader('Content-Length'));

		// Verify PROPFIND on virtual sublibrary file
		$propReq = new Request('PROPFIND', '/files/admin/My%20Noten/SubLibraries/orgel/UploadScore.pdf');
		$propRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($propReq, $propRes));
		self::assertSame(207, $propRes->getStatus());
		self::assertStringContainsString('UploadScore.pdf', $propRes->getBodyAsString());
	}

	public function testPublicSublibraryFileDownloadGet(): void {
		$this->attachServer('/public.php/dav/');
		$sub = $this->sublibraryService->createSublibrary((int)$this->library->id, 'Organ Repertoire', 'orgel', [
			'instrument' => ['Orgel'],
		]);

		$fileContent = '%PDF-1.4 public organ score';
		$adminFolder = $this->rootFolder->getUserFolder('admin');
		$adminFolder->newFile('My Noten/PublicPiece.pdf', $fileContent);

		$folderId = $this->shareService->ensureSublibraryFolderId($sub, $this->library);
		self::assertNotNull($folderId);

		$mockShare = $this->createMock(\OCP\Share\IShare::class);
		$mockFolder = $this->createMock(\OCP\Files\Folder::class);
		$mockFolder->method('getId')->willReturn($folderId);
		$mockShare->method('getNode')->willReturn($mockFolder);
		$mockShare->method('getTarget')->willReturn('/My Noten/SubLibraries/orgel');
		$mockShare->method('getSharedBy')->willReturn('admin');
		$mockShare->method('getToken')->willReturn('pubSubToken');
		$this->shareManager->method('getShareByToken')->with('pubSubToken')->willReturn($mockShare);

		$getReq = new Request('GET', '/public.php/dav/files/pubSubToken/PublicPiece.pdf');
		$getRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($getReq, $getRes));
		self::assertSame(200, $getRes->getStatus());
		self::assertSame($fileContent, stream_get_contents($getRes->getBody()));
	}

	public function testSharedSublibraryFileDownloadGet(): void {
		$this->attachServer();
		$sub = $this->sublibraryService->createSublibrary((int)$this->library->id, 'Organ Repertoire', 'orgel', [
			'instrument' => ['Orgel'],
		]);

		$fileContent = '%PDF-1.4 shared organ score for bob';
		$adminFolder = $this->rootFolder->getUserFolder('admin');
		$adminFolder->newFile('My Noten/BobPiece.pdf', $fileContent);

		$folderId = $this->shareService->ensureSublibraryFolderId($sub, $this->library);
		self::assertNotNull($folderId);

		$bobFolder = $this->rootFolder->getUserFolder('bob');
		if (!$bobFolder->nodeExists('BobOrgan')) {
			$this->createdUserNodes[] = $bobFolder->newFolder('BobOrgan');
		}
		$bobOrganNode = $bobFolder->get('BobOrgan');
		$sub->folderId = $bobOrganNode->getId();
		(new SublibraryMapper($this->dbConn))->updateFolderId($sub->id, $bobOrganNode->getId());

		$bobUser = $this->userManager->get('bob');
		$this->userSession->setUser($bobUser);

		$getReq = new Request('GET', '/files/bob/BobOrgan/BobPiece.pdf');
		$getRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($getReq, $getRes));
		self::assertSame(200, $getRes->getStatus());
		self::assertSame($fileContent, stream_get_contents($getRes->getBody()));
	}

	public function testSublibraryFullWebdavSyncLifecycle(): void {
		$this->attachServer();
		$sub = $this->sublibraryService->createSublibrary((int)$this->library->id, 'Organ Repertoire', 'orgel', [
			'instrument' => ['Orgel'],
		]);

		// Baseline database export (tablet has synced the sublibrary before adding a new song)
		$tempDb = tempnam(sys_get_temp_dir(), 'e2e_ms_') . '.db';
		$this->exporter->exportToFile((int)$this->library->id, $tempDb, null, $sub);

		// Step 1: MobileSheets uploads new score PDF via WebDAV PUT to virtual sublibrary path
		$fileContent = "%PDF-1.4 E2E Test Score\n";
		$putFileReq = new Request('PUT', '/files/admin/My%20Noten/SubLibraries/orgel/OrganChorale.pdf');
		$putFileReq->setBody($fileContent);
		$putFileRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($putFileReq, $putFileRes));
		self::assertSame(201, $putFileRes->getStatus());

		// Verify physical file was placed in master library folder
		$userFolder = $this->rootFolder->getUserFolder('admin');
		self::assertTrue($userFolder->nodeExists('My Noten/OrganChorale.pdf'));
		$fileNode = $userFolder->get('My Noten/OrganChorale.pdf');
		self::assertSame($fileContent, $fileNode->getContent());

		// Step 2: Simulate Nextcloud auto-indexing the newly uploaded physical file
		$stubSong = $this->insertTestSong((int)$this->library->id, 'OrganChorale');
		$stubFile = new \OCA\SheetMusic\Db\InternalSongFile(
			uuid: 'sf_stub_' . bin2hex(random_bytes(4)),
			songId: $stubSong->id,
			fileId: (int)$fileNode->getId(),
			pageOrder: '1-1',
			pageCount: 1,
			fileSize: strlen($fileContent),
			fileHash: 123456,
			type: 1,
			clientId: null,
			createdAt: time(),
			updatedAt: time(),
		);
		$this->songFileMapper->insert($stubFile);

		// Step 3: Client adds Song2 to its database and uploads mobilesheets.db via WebDAV PUT
		$db = new \PDO('sqlite:' . $tempDb);
		$db->exec("INSERT INTO Songs (Title) VALUES ('Song2')");
		$song2ClientId = (int)$db->lastInsertId();

		$storedPath = '/remote.php/dav/files/admin/My Noten/SubLibraries/orgel/OrganChorale.pdf';
		$stmt = $db->prepare("INSERT INTO Files (SongId, Path, PageOrder, SourceFilePageCount, FileSize) VALUES (?, ?, '1-1', 1, 1024)");
		$stmt->execute([$song2ClientId, $storedPath]);

		$putDbReq = new Request('PUT', '/files/admin/My%20Noten/SubLibraries/orgel/mobilesheets.db');
		$putDbReq->setBody(fopen($tempDb, 'rb'));
		$putDbRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($putDbReq, $putDbRes));
		@unlink($tempDb);

		// Step 4: Verify Song2 in Nextcloud has the file attached and stub song is pruned
		$song2 = $this->songMapper->findByClientId((int)$this->library->id, $song2ClientId);
		self::assertNotNull($song2);
		self::assertSame('Song2', $song2->title);

		$attachedFiles = $this->songFileMapper->findAllForSong((int)$song2->id, includeDeleted: false);
		self::assertCount(1, $attachedFiles);
		self::assertSame((int)$fileNode->getId(), $attachedFiles[0]->fileId);

		// Stub song must be deleted
		$stubCheck = $this->songMapper->findById((int)$stubSong->id);
		self::assertNull($stubCheck);

		// Song2 must have received sublibrary auto-tagging
		$instruments = $this->entityMapper->findEntitiesForSong((int)$song2->id, 'instrument');
		self::assertCount(1, $instruments);
		self::assertSame('Orgel', $instruments[0]->name);

		// Step 5: MobileSheets downloads the score file via WebDAV GET
		$getScoreReq = new Request('GET', '/files/admin/My%20Noten/SubLibraries/orgel/OrganChorale.pdf');
		$getScoreRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($getScoreReq, $getScoreRes));
		self::assertSame(200, $getScoreRes->getStatus());
		self::assertSame($fileContent, stream_get_contents($getScoreRes->getBody()));

		// Step 6: MobileSheets downloads database via WebDAV GET
		$getDbReq = new Request('GET', '/files/admin/My%20Noten/SubLibraries/orgel/mobilesheets.db');
		$getDbRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($getDbReq, $getDbRes));
		self::assertSame(200, $getDbRes->getStatus());

		// Step 7: MobileSheets downloads hashcodes via WebDAV GET
		$getHashReq = new Request('GET', '/files/admin/My%20Noten/SubLibraries/orgel/mobilesheets_hashcodes.txt');
		$getHashRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($getHashReq, $getHashRes));
		self::assertSame(200, $getHashRes->getStatus());
		$hashBody = $getHashRes->getBody();
		$hashContent = is_resource($hashBody) ? stream_get_contents($hashBody) : (string)$hashBody;
		self::assertStringContainsString('OrganChorale.pdf', $hashContent);
	}

	public function testPublicLinkWebdavSyncExportsDbAndBlocksPut(): void {
		$folderId = $this->shareService->ensureLibraryFolderId($this->library);
		self::assertNotNull($folderId);

		$mockShare = $this->createMock(\OCP\Share\IShare::class);
		$mockFolder = $this->createMock(\OCP\Files\Folder::class);
		$mockFolder->method('getId')->willReturn($folderId);

		$mockShare->method('getNode')->willReturn($mockFolder);
		$mockShare->method('getTarget')->willReturn('/My Noten');
		$mockShare->method('getSharedBy')->willReturn('admin');
		$mockShare->method('getToken')->willReturn('pubLinkToken123');

		$this->shareManager->method('getShareByToken')->with('pubLinkToken123')->willReturn($mockShare);

		// Match virtual path via legacy public-files
		$match = $this->plugin->matchVirtualPath('public-files/pubLinkToken123/Songs/mobilesheets.db');
		self::assertNotNull($match);
		self::assertSame((int)$this->library->id, (int)$match['library']->id);
		self::assertFalse($match['canWrite']);
		self::assertSame('pubLinkToken123', $match['publicToken']);

		// GET mobilesheets.db via legacy public link returns 200
		$getReq = new Request('GET', '/public-files/pubLinkToken123/Songs/mobilesheets.db');
		$getRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($getReq, $getRes));
		self::assertSame(200, $getRes->getStatus());
		self::assertSame('application/x-sqlite3', $getRes->getHeader('Content-Type'));

		// PUT mobilesheets.db via public link returns 403 Forbidden
		$putReq = new Request('PUT', '/public-files/pubLinkToken123/Songs/mobilesheets.db');
		$putRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($putReq, $putRes));
		self::assertSame(403, $putRes->getStatus());

		// Test public DAV endpoint under /public.php/dav/
		$this->attachServer('/public.php/dav/');
		$pubDavReq = new Request('GET', '/public.php/dav/files/pubLinkToken123/Songs/mobilesheets.db');
		$pubDavReq->setBaseUrl('/public.php/dav/');
		$pubDavRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($pubDavReq, $pubDavRes));
		self::assertSame(200, $pubDavRes->getStatus());
		self::assertSame('application/x-sqlite3', $pubDavRes->getHeader('Content-Type'));

		// PUT under public DAV endpoint returns 403 Forbidden
		$pubDavPutReq = new Request('PUT', '/public.php/dav/files/pubLinkToken123/Songs/mobilesheets.db');
		$pubDavPutReq->setBaseUrl('/public.php/dav/');
		$pubDavPutRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($pubDavPutReq, $pubDavPutRes));
		self::assertSame(403, $pubDavPutRes->getStatus());
	}

	public function testIsSyncClientDetection(): void {
		$desktopReq = new Request('GET', '/', ['User-Agent' => 'Mozilla/5.0 (Windows) mirall/3.14.0 (Nextcloud, windows-10.0.19045 ClientArchitecture: x86_64)']);
		self::assertTrue($this->plugin->isSyncClient($desktopReq));

		$androidReq = new Request('GET', '/', ['User-Agent' => 'Mozilla/5.0 (Android) Nextcloud-android/3.30.0']);
		self::assertTrue($this->plugin->isSyncClient($androidReq));

		$iosReq = new Request('GET', '/', ['User-Agent' => 'Mozilla/5.0 (iOS) Nextcloud-iOS/5.0.0']);
		self::assertTrue($this->plugin->isSyncClient($iosReq));

		$csyncReq = new Request('GET', '/', ['User-Agent' => 'Mozilla/5.0 (Linux) csyncoC/1.0']);
		self::assertTrue($this->plugin->isSyncClient($csyncReq));

		$msReq = new Request('GET', '/', ['User-Agent' => 'MobileSheets/3.8.4']);
		self::assertFalse($this->plugin->isSyncClient($msReq));

		$okhttpReq = new Request('GET', '/', ['User-Agent' => 'okhttp/4.9.2']);
		self::assertFalse($this->plugin->isSyncClient($okhttpReq));

		$browserReq = new Request('GET', '/', ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36']);
		self::assertFalse($this->plugin->isSyncClient($browserReq));

		$emptyReq = new Request('GET', '/');
		self::assertFalse($this->plugin->isSyncClient($emptyReq));
	}

	public function testIsSublibraryContainerPath(): void {
		self::assertTrue($this->plugin->isSublibraryContainerPath('files/admin/My Noten/SubLibraries'));
		self::assertTrue($this->plugin->isSublibraryContainerPath('files/admin/My Noten/SubLibraries/orgel'));
		self::assertTrue($this->plugin->isSublibraryContainerPath('files/admin/My Noten/SubLibraries/orgel/mobilesheets.db'));
		self::assertTrue($this->plugin->isSublibraryContainerPath('files/admin/My Noten/.sublibraries/orgel'));

		self::assertFalse($this->plugin->isSublibraryContainerPath('files/admin/My Noten'));
		self::assertFalse($this->plugin->isSublibraryContainerPath('files/admin/My Noten/mobilesheets.db'));
		self::assertFalse($this->plugin->isSublibraryContainerPath('files/admin/My Noten/score.pdf'));
		self::assertFalse($this->plugin->isSublibraryContainerPath('files/bob/BobOrgan/BobPiece.pdf'));
	}

	public function testSublibrariesNotVisibleToSyncClientsDuringPropfind(): void {
		$this->attachServer();
		$this->sublibraryService->createSublibrary((int)$this->library->id, 'Organ Repertoire', 'orgel', [
			'instrument' => ['Orgel'],
		]);

		$syncClientUa = 'Mozilla/5.0 (Windows) mirall/3.14.0 (Nextcloud, windows-10.0.19045)';

		// 1. Nextcloud sync client PROPFIND on library folder
		$reqSync = new Request('PROPFIND', '/remote.php/dav/files/admin/My%20Noten', [
			'Depth' => '1',
			'User-Agent' => $syncClientUa,
		]);
		$reqSync->setBaseUrl('/remote.php/dav/');

		$resSync = new Response();
		$resSync->setStatus(207);
		$resSync->setBody('<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/dav/files/admin/My%20Noten/</d:href></d:response></d:multistatus>');

		$this->plugin->afterPropfind($reqSync, $resSync);

		$bodySync = $resSync->getBodyAsString();
		// Virtual database files are in Songs/, not in library root
		self::assertStringNotContainsString('mobilesheets.db', $bodySync);
		// And SubLibraries is NOT injected for the sync client
		self::assertStringNotContainsString('SubLibraries', $bodySync);

		// 2. Non-sync client (e.g. MobileSheets) PROPFIND on library folder
		$reqMs = new Request('PROPFIND', '/remote.php/dav/files/admin/My%20Noten', [
			'Depth' => '1',
			'User-Agent' => 'MobileSheets/3.8.4',
		]);
		$reqMs->setBaseUrl('/remote.php/dav/');

		$resMs = new Response();
		$resMs->setStatus(207);
		$resMs->setBody('<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/dav/files/admin/My%20Noten/</d:href></d:response></d:multistatus>');

		$this->plugin->afterPropfind($reqMs, $resMs);

		$bodyMs = $resMs->getBodyAsString();
		self::assertStringNotContainsString('mobilesheets.db', $bodyMs);
		// SubLibraries IS injected for non-sync clients
		self::assertStringContainsString('SubLibraries', $bodyMs);

		// 3. Direct PROPFIND on SubLibraries by sync client is bypassed (returns true so SabreDAV returns 404)
		$directSyncReq = new Request('PROPFIND', '/files/admin/My%20Noten/SubLibraries', [
			'Depth' => '1',
			'User-Agent' => $syncClientUa,
		]);
		$directSyncRes = new Response();
		self::assertTrue($this->plugin->beforeMethod($directSyncReq, $directSyncRes));

		// 4. Direct PROPFIND on SubLibraries by non-sync client is handled
		$directMsReq = new Request('PROPFIND', '/files/admin/My%20Noten/SubLibraries', [
			'Depth' => '1',
			'User-Agent' => 'MobileSheets/3.8.4',
		]);
		$directMsRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($directMsReq, $directMsRes));
		self::assertSame(207, $directMsRes->getStatus());
		self::assertStringContainsString('orgel', (string)$directMsRes->getBody());
	}

	public function testVirtualFilesNotVisibleToSyncClientsDuringPropfindOrDirectAccess(): void {
		$this->attachServer();
		$syncClientUa = 'Mozilla/5.0 (Windows) mirall/3.14.0 (Nextcloud, windows-10.0.19045)';

		// 1. Nextcloud sync client PROPFIND on Songs/ folder does NOT include virtual files
		$reqSync = new Request('PROPFIND', '/remote.php/dav/files/admin/My%20Noten/Songs', [
			'Depth' => '1',
			'User-Agent' => $syncClientUa,
		]);
		$reqSync->setBaseUrl('/remote.php/dav/');

		$resSync = new Response();
		$resSync->setStatus(207);
		$resSync->setBody('<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/dav/files/admin/My%20Noten/Songs/</d:href></d:response></d:multistatus>');

		$this->plugin->afterPropfind($reqSync, $resSync);

		$bodySync = $resSync->getBodyAsString();
		self::assertStringNotContainsString('mobilesheets.db', $bodySync);
		self::assertStringNotContainsString('mobilesheets_hashcodes.txt', $bodySync);

		// 2. Non-sync client (e.g. MobileSheets) PROPFIND on Songs/ folder DOES include virtual files
		$reqMs = new Request('PROPFIND', '/remote.php/dav/files/admin/My%20Noten/Songs', [
			'Depth' => '1',
			'User-Agent' => 'MobileSheets/3.8.4',
		]);
		$reqMs->setBaseUrl('/remote.php/dav/');

		$resMs = new Response();
		$resMs->setStatus(207);
		$resMs->setBody('<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/dav/files/admin/My%20Noten/Songs/</d:href></d:response></d:multistatus>');

		$this->plugin->afterPropfind($reqMs, $resMs);

		$bodyMs = $resMs->getBodyAsString();
		self::assertStringContainsString('mobilesheets.db', $bodyMs);
		self::assertStringContainsString('mobilesheets_hashcodes.txt', $bodyMs);

		// 3. Direct GET / PROPFIND on virtual files by sync client is bypassed (returns true so SabreDAV returns 404)
		foreach (['GET', 'PROPFIND'] as $method) {
			foreach (['mobilesheets.db', 'mobilesheets_hashcodes.txt'] as $filename) {
				$directSyncReq = new Request($method, '/files/admin/My%20Noten/Songs/' . $filename, [
					'User-Agent' => $syncClientUa,
				]);
				$directSyncRes = new Response();
				self::assertTrue($this->plugin->beforeMethod($directSyncReq, $directSyncRes));
			}
		}

		// 4. Direct GET on virtual file by non-sync client is handled
		$directMsReq = new Request('GET', '/files/admin/My%20Noten/Songs/mobilesheets.db', [
			'User-Agent' => 'MobileSheets/3.8.4',
		]);
		$directMsRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($directMsReq, $directMsRes));
		self::assertSame(200, $directMsRes->getStatus());
	}

	public function testVirtualFilesNotExposedWhenMobilesheetsSyncDisabled(): void {
		$this->attachServer();
		$this->dbConn->executeStatement('UPDATE *PREFIX*sheetmusic_libraries SET mobilesheets_sync_enabled = 0 WHERE id = ?', [$this->library->id]);

		// Even MobileSheets client does NOT see virtual files in PROPFIND when flag is disabled
		$reqMs = new Request('PROPFIND', '/remote.php/dav/files/admin/My%20Noten/Songs', [
			'Depth' => '1',
			'User-Agent' => 'MobileSheets/3.8.4',
		]);
		$reqMs->setBaseUrl('/remote.php/dav/');

		$resMs = new Response();
		$resMs->setStatus(207);
		$resMs->setBody('<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/dav/files/admin/My%20Noten/Songs/</d:href></d:response></d:multistatus>');

		$this->plugin->afterPropfind($reqMs, $resMs);

		$bodyMs = $resMs->getBodyAsString();
		self::assertStringNotContainsString('mobilesheets.db', $bodyMs);
		self::assertStringNotContainsString('mobilesheets_hashcodes.txt', $bodyMs);

		// Direct GET / PROPFIND on virtual files is bypassed (returns true so SabreDAV returns 404)
		foreach (['GET', 'PROPFIND'] as $method) {
			foreach (['mobilesheets.db', 'mobilesheets_hashcodes.txt'] as $filename) {
				$req = new Request($method, '/files/admin/My%20Noten/Songs/' . $filename, [
					'User-Agent' => 'MobileSheets/3.8.4',
				]);
				$res = new Response();
				self::assertTrue($this->plugin->beforeMethod($req, $res));
			}
		}
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
