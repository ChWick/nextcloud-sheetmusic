<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Dav\SheetMusicDavPlugin;
use OCA\SheetMusic\Db\InternalSongHistoryMapper;
use OCA\SheetMusic\Db\SublibraryMapper;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Service\LibraryShareService;
use OCA\SheetMusic\Service\SongFileService;
use OCA\SheetMusic\Service\SongHistoryService;
use OCA\SheetMusic\Service\SongService;
use OCA\SheetMusic\Service\SublibraryService;
use OCP\IUser;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sabre\DAV\Server;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use TestSupport\FakeUserSession;
use TestSupport\RealMobileSheetsTestTrait;
use TestSupport\RecordingSapi;

final class SharedLibraryEndToEndTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private IManager $shareManager;
	private SublibraryService $sublibraryService;
	private LibraryShareService $shareService;
	private LibraryService $libraryService;
	private SongService $songService;
	private IUser $adminUser;
	private IUser $bobUser;
	private IUser $fooUser;
	private IUser $aliceUser;

	/** @var list<string> */
	private array $createdShareIds = [];

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();

		$this->shareManager = \OC::$server->get(IManager::class);
		$logger = new NullLogger();

		$subMapper = new SublibraryMapper($this->dbConn);
		$this->sublibraryService = new SublibraryService($subMapper, $this->entityMapper, $this->dbConn);

		$this->shareService = new LibraryShareService(
			libraryMapper: $this->libraryMapper,
			rootFolder: $this->rootFolder,
			shareManager: $this->shareManager,
			userManager: $this->userManager,
			logger: $logger,
			sublibraryMapper: $subMapper,
		);

		$this->libraryService = new LibraryService(
			$this->libraryMapper,
			$this->rootFolder,
			$logger,
			exporter: $this->exporter,
			shareService: $this->shareService,
		);

		$fileManager = new FileManager(
			$this->libraryMapper,
			$this->rootFolder,
			$this->pathResolver,
			$logger,
			$this->libraryService,
			$this->songFileMapper,
			$this->songMapper,
		);

		$songFileService = new SongFileService(
			$this->libraryService,
			$fileManager,
			$this->rootFolder,
			$logger,
			$this->songFileMapper,
			$this->songMapper,
			$this->libraryMapper,
			sublibraryService: $this->sublibraryService,
		);

		$historyService = new SongHistoryService(
			new InternalSongHistoryMapper($this->dbConn),
			$this->songMapper,
			$this->entityMapper,
			$this->songFileMapper,
			$this->libraryMapper,
			$this->dbConn,
			$logger,
		);

		$this->songService = new SongService(
			$this->libraryService,
			$songFileService,
			$fileManager,
			$logger,
			$this->songMapper,
			$this->entityMapper,
			$this->songFileMapper,
			$this->libraryMapper,
			$this->rootFolder,
			$this->createLibraryIntegrityService($this->libraryService),
			$historyService,
			$this->bookmarkMapper,
			sublibraryService: $this->sublibraryService,
		);

		$admin = $this->userManager->get('admin');
		$bob = $this->userManager->get('bob');
		$foo = $this->userManager->get('foo');
		$alice = $this->userManager->get('alice');
		self::assertNotNull($admin);
		self::assertNotNull($bob);
		self::assertNotNull($foo);
		self::assertNotNull($alice);

		$this->adminUser = $admin;
		$this->bobUser = $bob;
		$this->fooUser = $foo;
		$this->aliceUser = $alice;
		$this->createdShareIds = [];
	}

	protected function tearDown(): void {
		foreach ($this->createdShareIds as $shareId) {
			try {
				$fullId = str_contains($shareId, ':') ? $shareId : 'ocinternal:' . $shareId;
				$share = $this->shareManager->getShareById($fullId);
				$this->shareManager->deleteShare($share);
			} catch (\Throwable) {
				// Ignore cleanup failures
			}
		}
		$this->createdShareIds = [];
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testFullLibrarySharedReadWriteEndToEnd(): void {
		$lib = $this->createTestLibrary('/AdminChoirLib', 'admin');
		$libId = (int)$lib->id;

		$adminFolder = $this->rootFolder->getUserFolder('admin');
		if (!$adminFolder->nodeExists('AdminChoirLib')) {
			$this->createdUserNodes[] = $adminFolder->newFolder('AdminChoirLib');
		}

		// Insert song and attach real file
		$song = $this->insertTestSong($libId, 'Hallelujah Chorus');
		$this->attachTestFileToSong($song, 'hallelujah.pdf');

		// Admin shares full library with bob (read & write)
		$shareData = $this->shareService->createShare(
			$libId,
			'admin',
			IShare::TYPE_USER,
			'bob',
			'write',
		);
		self::assertNotNull($shareData['id']);
		$this->createdShareIds[] = (string)$shareData['id'];

		// Bob retrieves songs from shared library
		$bobData = $this->songService->getSongs($this->bobUser, $libId);
		self::assertNotNull($bobData);
		self::assertCount(1, $bobData->songs);
		self::assertSame('Hallelujah Chorus', $bobData->songs[0]->title);

		// Verify bob receives valid WebDAV path
		self::assertNotEmpty($bobData->songs[0]->files);
		$davPath = $bobData->songs[0]->files[0]->path;
		self::assertStringContainsString('/remote.php/dav/files/bob/', $davPath);
		self::assertStringContainsString('hallelujah.pdf', $davPath);

		// Bob updates song title inline
		$updatedData = $this->songService->updateSong(
			$this->bobUser,
			(int)$song->id,
			$libId,
			'Hallelujah (Handel)',
		);
		self::assertNotNull($updatedData);
		self::assertSame('Hallelujah (Handel)', $updatedData->songs[0]->title);

		// Verify change is persisted in admin's library
		$adminData = $this->songService->getSongs($this->adminUser, $libId);
		self::assertNotNull($adminData);
		self::assertSame('Hallelujah (Handel)', $adminData->songs[0]->title);
	}

	public function testSublibrarySharedReadWriteEndToEnd(): void {
		$lib = $this->createTestLibrary('/OrchestraLib', 'admin');
		$libId = (int)$lib->id;

		$adminFolder = $this->rootFolder->getUserFolder('admin');
		if (!$adminFolder->nodeExists('OrchestraLib')) {
			$this->createdUserNodes[] = $adminFolder->newFolder('OrchestraLib');
		}

		// Song 1: Organ piece
		$song1 = $this->insertTestSong($libId, 'Toccata and Fugue');
		$instOrgan = $this->insertTestEntity($libId, 'instrument', 'Orgel');
		$this->entityMapper->linkSongEntity((int)$song1->id, (int)$instOrgan->id);
		$this->attachTestFileToSong($song1, 'toccata.pdf');

		// Song 2: Violin piece (should not appear in organ sublibrary)
		$song2 = $this->insertTestSong($libId, 'Violin Concerto');
		$instViolin = $this->insertTestEntity($libId, 'instrument', 'Violine');
		$this->entityMapper->linkSongEntity((int)$song2->id, (int)$instViolin->id);
		$this->attachTestFileToSong($song2, 'violin.pdf');

		// Create organ sublibrary
		$sub = $this->sublibraryService->createSublibrary($libId, 'Organ Section', 'organ', [
			'instrument' => ['Orgel'],
		]);
		self::assertNotNull($sub->id);
		$subId = (int)$sub->id;
		$syntheticId = LibraryShareService::SUBLIBRARY_ID_OFFSET + $subId;

		// Admin shares sublibrary with foo (read & write)
		$shareData = $this->shareService->createSublibraryShare(
			$libId,
			$subId,
			'admin',
			IShare::TYPE_USER,
			'foo',
			'write',
		);
		self::assertNotNull($shareData['id']);
		$this->createdShareIds[] = (string)$shareData['id'];

		// Foo retrieves songs: should only see organ song, not violin
		$fooData = $this->songService->getSongs($this->fooUser, $syntheticId);
		self::assertNotNull($fooData);
		self::assertCount(1, $fooData->songs);
		self::assertSame('Toccata and Fugue', $fooData->songs[0]->title);

		// Verify virtual WebDAV file path for foo
		self::assertNotEmpty($fooData->songs[0]->files);
		$davPath = $fooData->songs[0]->files[0]->path;
		self::assertStringContainsString('/remote.php/dav/files/foo/', $davPath);
		self::assertStringContainsString('toccata.pdf', $davPath);

		// Verify foo can download the PDF via SheetMusicDavPlugin
		$userSession = new FakeUserSession($this->fooUser);
		$plugin = new SheetMusicDavPlugin(
			$this->libraryMapper,
			$this->exporter,
			$this->importer,
			new NullLogger(),
			$userSession,
			null,
			$this->rootFolder,
			$this->sublibraryService,
			$this->shareService,
		);
		$server = new Server();
		$server->setBaseUri('/remote.php/dav/');
		$server->sapi = new RecordingSapi();
		RecordingSapi::$sent = [];
		$plugin->initialize($server);

		$davRelPath = preg_replace('#^/remote\.php/dav/#', '/', $davPath);
		$getReq = new Request('GET', $davRelPath);
		$getRes = new Response();
		self::assertFalse($plugin->beforeMethod($getReq, $getRes));
		self::assertSame(200, $getRes->getStatus());
		self::assertStringStartsWith('%PDF-1.4', stream_get_contents($getRes->getBody()));

		// Foo updates the song title inline
		$updatedData = $this->songService->updateSong(
			$this->fooUser,
			(int)$song1->id,
			$syntheticId,
			'Toccata and Fugue in D minor',
			instrumentIds: [(int)$instOrgan->id],
		);
		self::assertNotNull($updatedData);
		self::assertSame('Toccata and Fugue in D minor', $updatedData->songs[0]->title);

		// Verify master library reflects the updated title
		$adminData = $this->songService->getSongs($this->adminUser, $libId);
		self::assertNotNull($adminData);
		$found = false;
		foreach ($adminData->songs as $s) {
			if ($s->id === $song1->id) {
				self::assertSame('Toccata and Fugue in D minor', $s->title);
				$found = true;
			}
		}
		self::assertTrue($found);
	}

	public function testSublibrarySharedReadOnlyPreventsMutation(): void {
		$lib = $this->createTestLibrary('/ReadOnlySublibTest', 'admin');
		$libId = (int)$lib->id;

		$adminFolder = $this->rootFolder->getUserFolder('admin');
		if (!$adminFolder->nodeExists('ReadOnlySublibTest')) {
			$this->createdUserNodes[] = $adminFolder->newFolder('ReadOnlySublibTest');
		}

		$song = $this->insertTestSong($libId, 'Chorale Prelude');
		$inst = $this->insertTestEntity($libId, 'instrument', 'Orgel');
		$this->entityMapper->linkSongEntity((int)$song->id, (int)$inst->id);
		$this->attachTestFileToSong($song, 'chorale.pdf');

		$sub = $this->sublibraryService->createSublibrary($libId, 'Organ Ro', 'organ_ro', [
			'instrument' => ['Orgel'],
		]);
		$subId = (int)$sub->id;
		$syntheticId = LibraryShareService::SUBLIBRARY_ID_OFFSET + $subId;

		// Admin shares read-only with alice
		$shareData = $this->shareService->createSublibraryShare(
			$libId,
			$subId,
			'admin',
			IShare::TYPE_USER,
			'alice',
			'read',
		);
		self::assertNotNull($shareData['id']);
		$this->createdShareIds[] = (string)$shareData['id'];

		// Alice can read the song
		$aliceData = $this->songService->getSongs($this->aliceUser, $syntheticId);
		self::assertNotNull($aliceData);
		self::assertCount(1, $aliceData->songs);
		self::assertSame('Chorale Prelude', $aliceData->songs[0]->title);

		// Alice can download the PDF
		$userSession = new FakeUserSession($this->aliceUser);
		$plugin = new SheetMusicDavPlugin(
			$this->libraryMapper,
			$this->exporter,
			$this->importer,
			new NullLogger(),
			$userSession,
			null,
			$this->rootFolder,
			$this->sublibraryService,
			$this->shareService,
		);
		$server = new Server();
		$server->setBaseUri('/remote.php/dav/');
		$server->sapi = new RecordingSapi();
		RecordingSapi::$sent = [];
		$plugin->initialize($server);

		$davPath = $aliceData->songs[0]->files[0]->path;
		$davRelPath = preg_replace('#^/remote\.php/dav/#', '/', $davPath);
		$getReq = new Request('GET', $davRelPath);
		$getRes = new Response();
		self::assertFalse($plugin->beforeMethod($getReq, $getRes));
		self::assertSame(200, $getRes->getStatus());
		self::assertStringStartsWith('%PDF-1.4', stream_get_contents($getRes->getBody()));

		// Alice attempts to modify the song -> must be rejected
		$updateResult = $this->songService->updateSong(
			$this->aliceUser,
			(int)$song->id,
			$syntheticId,
			'Unauthorized Alice Modification',
		);
		self::assertNull($updateResult);

		// Alice attempts to delete the song -> must be rejected
		$deleteResult = $this->songService->deleteSong(
			$this->aliceUser,
			(int)$song->id,
			$syntheticId,
		);
		self::assertNull($deleteResult);

		// Verify title is untouched
		$verifyData = $this->songService->getSongs($this->adminUser, $libId);
		self::assertNotNull($verifyData);
		self::assertSame('Chorale Prelude', $verifyData->songs[0]->title);
	}

	public function testSharedSublibraryMobileSheetsExportAndHashcodes(): void {
		$lib = $this->createTestLibrary('/ExportSublibTest', 'admin');
		$libId = (int)$lib->id;

		$adminFolder = $this->rootFolder->getUserFolder('admin');
		if (!$adminFolder->nodeExists('ExportSublibTest')) {
			$this->createdUserNodes[] = $adminFolder->newFolder('ExportSublibTest');
		}

		$song1 = $this->insertTestSong($libId, 'Organ Fugue');
		$instOrgan = $this->insertTestEntity($libId, 'instrument', 'Orgel');
		$this->entityMapper->linkSongEntity((int)$song1->id, (int)$instOrgan->id);
		$this->attachTestFileToSong($song1, 'organ_fugue.pdf');

		$song2 = $this->insertTestSong($libId, 'Trumpet Tune');
		$instTrumpet = $this->insertTestEntity($libId, 'instrument', 'Trompete');
		$this->entityMapper->linkSongEntity((int)$song2->id, (int)$instTrumpet->id);
		$this->attachTestFileToSong($song2, 'trumpet_tune.pdf');

		$sub = $this->sublibraryService->createSublibrary($libId, 'Organ Only', 'organ_export', [
			'instrument' => ['Orgel'],
		]);

		// Export sublibrary to a real SQLite file
		$tempDb = tempnam(sys_get_temp_dir(), 'sublib_export_') . '.db';
		$this->tempFiles[] = $tempDb;

		$this->exporter->exportToFile($libId, $tempDb, null, $sub);
		self::assertFileExists($tempDb);

		// Validate SQLite schema and contents
		$pdo = new PDO('sqlite:' . $tempDb);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// Only 1 song should be in exported database
		$stmt = $pdo->query('SELECT count(*) FROM Songs');
		self::assertSame(1, (int)$stmt->fetchColumn());

		$stmt = $pdo->query('SELECT Title FROM Songs');
		self::assertSame('Organ Fugue', $stmt->fetchColumn());

		// Only 1 instrument ('Orgel') should be in CustomGroup table
		$stmt = $pdo->query('SELECT count(*) FROM CustomGroup');
		self::assertSame(1, (int)$stmt->fetchColumn());
		$stmt = $pdo->query('SELECT Name FROM CustomGroup');
		self::assertSame('Orgel', $stmt->fetchColumn());

		// Verify hashcodes generation for the sublibrary
		$hashcodes = $this->exporter->generateHashcodes($libId, $sub);
		self::assertStringContainsString('organ_fugue.pdf', $hashcodes);
		self::assertStringNotContainsString('trumpet_tune.pdf', $hashcodes);
	}
}
