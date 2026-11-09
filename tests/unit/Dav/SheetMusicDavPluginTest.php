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
use OCA\SheetMusic\Service\SublibraryService;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteExporter;
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

		$this->sublibraryService = new SublibraryService(
			new SublibraryMapper($this->dbConn),
			$this->entityMapper,
			$this->dbConn,
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
		$contentLength = (int)$response->getHeader('Content-Length');
		self::assertGreaterThan(0, $contentLength);
		self::assertStringStartsWith('SQLite format 3', $response->getBodyAsString());
	}

	public function testHandleGetHashcodesReturnsTextContent(): void {
		$request = new Request('GET', '/files/admin/My%20Noten/mobilesheets_hashcodes.txt');
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

		$request = new Request('PUT', '/files/admin/My%20Noten/mobilesheets.db', [], $dbBytes);
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
		self::assertMatchesRegularExpression('|<d:getcontentlength>[1-9][0-9]*</d:getcontentlength>|', $body);
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

		$match = $plugin->matchVirtualPath('files/admin/My Noten/mobilesheets.db');
		self::assertNotNull($match);
		self::assertSame((int)$this->library->id, $match['library']->id);
		self::assertSame('mobilesheets.db', $match['filename']);

		$hashMatch = $plugin->matchVirtualPath('files/admin/My Noten/mobilesheets_hashcodes.txt');
		self::assertNotNull($hashMatch);
		self::assertSame('mobilesheets_hashcodes.txt', $hashMatch['filename']);
	}

	public function testServerContainerResolvesPluginWithRegisteredSyncClients(): void {
		$plugin = \OC::$server->get(SheetMusicDavPlugin::class);
		self::assertInstanceOf(SheetMusicDavPlugin::class, $plugin);

		$userSession = \OC::$server->get(\OCP\IUserSession::class);
		$userSession->setUser($this->userManager->get('admin'));

		$match = $plugin->matchVirtualPath('files/admin/My Noten/mobilesheets.db');
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
		$propfindReq = new Request('PROPFIND', '/remote.php/dav/files/admin/My%20Noten/mobilesheets.db', ['Depth' => '0']);
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
		$getReq = new Request('GET', '/remote.php/dav/files/admin/My%20Noten/mobilesheets.db');
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

		// 1. matchVirtualPath with sublibrary (_SubLibraries_ and legacy .sublibraries)
		$match = $this->plugin->matchVirtualPath('files/admin/My Noten/_SubLibraries_/orgel/mobilesheets.db');
		self::assertNotNull($match);
		self::assertSame($this->library->id, $match['library']->id);
		self::assertSame('mobilesheets.db', $match['filename']);
		self::assertNotNull($match['sublibrary']);
		self::assertSame('orgel', $match['sublibrary']->slug);

		$legacyMatch = $this->plugin->matchVirtualPath('files/admin/My Noten/.sublibraries/orgel/mobilesheets.db');
		self::assertNotNull($legacyMatch);
		self::assertSame('orgel', $legacyMatch['sublibrary']->slug);

		// 2. PROPFIND on _SubLibraries_ collection
		$req = new Request('PROPFIND', '/files/admin/My%20Noten/_SubLibraries_', ['Depth' => '1']);
		$res = new Response();
		self::assertFalse($this->plugin->beforeMethod($req, $res));
		self::assertSame(207, $res->getStatus());
		$body = (string)$res->getBody();
		self::assertStringContainsString('_SubLibraries_', $body);
		self::assertStringContainsString('orgel', $body);

		// 3. PROPFIND on _SubLibraries_/orgel collection
		$reqSub = new Request('PROPFIND', '/files/admin/My%20Noten/_SubLibraries_/orgel', ['Depth' => '1']);
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
		$req = new Request('PUT', '/files/admin/My%20Noten/_SubLibraries_/orgel/UploadScore.pdf');
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
		$getReq = new Request('GET', '/files/admin/My%20Noten/_SubLibraries_/orgel/UploadScore.pdf');
		$getRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($getReq, $getRes));
		self::assertSame(200, $getRes->getStatus());
		self::assertSame($fileContent, stream_get_contents($getRes->getBody()));

		// Verify HEAD on virtual sublibrary file
		$headReq = new Request('HEAD', '/files/admin/My%20Noten/_SubLibraries_/orgel/UploadScore.pdf');
		$headRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($headReq, $headRes));
		self::assertSame(200, $headRes->getStatus());
		self::assertSame((string)strlen($fileContent), $headRes->getHeader('Content-Length'));

		// Verify PROPFIND on virtual sublibrary file
		$propReq = new Request('PROPFIND', '/files/admin/My%20Noten/_SubLibraries_/orgel/UploadScore.pdf');
		$propRes = new Response();
		self::assertFalse($this->plugin->beforeMethod($propReq, $propRes));
		self::assertSame(207, $propRes->getStatus());
		self::assertStringContainsString('UploadScore.pdf', $propRes->getBodyAsString());
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
