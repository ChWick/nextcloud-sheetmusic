<?php

declare(strict_types=1);

namespace Controller;

use OCA\SheetMusic\Controller\SongFileController;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\LibraryData;
use OCA\SheetMusic\Db\SongFileMapper;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Service\SongFileService;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\FakeRequest;
use TestSupport\FakeUserSession;
use TestSupport\RealMobileSheetsTestTrait;

final class SongFileControllerTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private int $libraryId;
	private IUser $user;
	private FakeUserSession $userSession;
	private FakeRequest $request;
	private SongFileService $songFileService;
	private SongFileController $controller;
	private Folder $libFolder;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$library = $this->createTestLibrary('SongFileController Test Lib');
		$this->libraryId = (int)$library->id;
		$this->user = $this->userManager->get($library->userId);

		$userFolder = $this->rootFolder->getUserFolder($this->user->getUID());
		if (!$userFolder->nodeExists('SongFileController Test Lib')) {
			$this->libFolder = $userFolder->newFolder('SongFileController Test Lib');
			$this->createdUserNodes[] = $this->libFolder;
		} else {
			$this->libFolder = $userFolder->get('SongFileController Test Lib');
		}

		$logger = new NullLogger();
		$libraryService = new LibraryService($this->libraryMapper, $this->rootFolder, $logger, exporter: $this->exporter);
		$fileManager = new FileManager(
			$this->libraryMapper,
			$this->rootFolder,
			$this->pathResolver,
			$logger,
			$libraryService,
			$this->songFileMapper,
			$this->songMapper,
		);
		$this->songFileService = new SongFileService(
			$libraryService,
			$fileManager,
			$this->rootFolder,
			$logger,
			$this->songFileMapper,
			$this->songMapper,
			$this->libraryMapper,
		);

		$this->userSession = new FakeUserSession($this->user);
		$this->request = new FakeRequest();

		$this->controller = new SongFileController(
			'sheetmusic',
			$this->request,
			$this->songFileService,
			$this->userSession,
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testImportSongMetadataHandlesUnauthenticated(): void {
		$this->userSession->setUser(null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->importSongMetadata()->getStatus());
	}

	public function testUploadSongFileUsesLibraryIdFromLibraryScopedRoute(): void {
		$song = $this->songMapper->insert(new InternalSong(
			uuid: 'upload-route-uuid',
			libraryId: $this->libraryId,
			title: 'Upload Test Song',
		));

		$tmpFile = tempnam(sys_get_temp_dir(), 'song-upload-controller-test-');
		self::assertNotFalse($tmpFile);
		file_put_contents($tmpFile, '%PDF-1.4 minimal test pdf content');
		$fileInfo = ['tmp_name' => $tmpFile, 'name' => 'score.pdf'];
		$this->request->setUploadedFile('file', $fileInfo);

		try {
			$response = $this->controller->uploadSongFile($song->id, $this->libraryId);

			self::assertSame(Http::STATUS_OK, $response->getStatus());
			$data = $response->getData();
			self::assertInstanceOf(LibraryData::class, $data);
			self::assertNotEmpty($data->songs);
		} finally {
			if (is_file($tmpFile)) {
				unlink($tmpFile);
			}
		}
	}

	public function testUploadSongFileReportsPhpUploadSizeLimit(): void {
		$this->request->setParam('libraryId', (string)$this->libraryId);
		$this->request->setUploadedFile('file', [
			'tmp_name' => '',
			'error' => UPLOAD_ERR_INI_SIZE,
		]);

		$response = $this->controller->uploadSongFile(42, $this->libraryId);

		self::assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());
		self::assertStringContainsString('upload_max_filesize', $response->getData()['message']);
	}

	public function testUploadSongFileDetectsPhpPostMaxSizeWhenFilesAreMissing(): void {
		$this->request->setParam('libraryId', (string)$this->libraryId);
		$this->request->setUploadedFile('file', null);

		$previousContentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
		$_SERVER['CONTENT_LENGTH'] = '999999999999';
		try {
			$response = $this->controller->uploadSongFile(42, $this->libraryId);

			self::assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());
			self::assertStringContainsString('post_max_size', $response->getData()['message']);
		} finally {
			if ($previousContentLength === null) {
				unset($_SERVER['CONTENT_LENGTH']);
			} else {
				$_SERVER['CONTENT_LENGTH'] = $previousContentLength;
			}
		}
	}

	public function testAttachNextcloudFileValidatesParameters(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->attachNextcloudFile(42, null, '/a.pdf')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->attachNextcloudFile(42, $this->libraryId, '')->getStatus());
	}

	public function testDeleteSongFile(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->deleteSongFile(42, 2, null)->getStatus());

		$song = $this->songMapper->insert(new InternalSong(
			uuid: 'del-file-song-uuid',
			libraryId: $this->libraryId,
			title: 'Song with File',
		));

		$fileNode = $this->libFolder->newFile('delete-me.pdf');
		$fileNode->putContent('%PDF-1.4 delete test');
		$this->createdUserNodes[] = $fileNode;

		$songFile = $this->songFileMapper->insert(new InternalSongFile(
			songId: (int)$song->id,
			fileId: $fileNode->getId(),
			type: SongFileMapper::FILE_TYPE_PDF,
		));

		$res = $this->controller->deleteSongFile($song->id, $songFile->id, $this->libraryId);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());

		$files = $this->songFileMapper->findAllForSong($song->id);
		$this->assertEmpty($files);
	}

	public function testDetachSongFile(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->detachSongFile(42, 2, null)->getStatus());

		$song = $this->songMapper->insert(new InternalSong(
			uuid: 'detach-file-song-uuid',
			libraryId: $this->libraryId,
			title: 'Song to Detach',
		));

		$fileNode = $this->libFolder->newFile('detach-me.pdf');
		$fileNode->putContent('%PDF-1.4 detach test');
		$this->createdUserNodes[] = $fileNode;

		$songFile = $this->songFileMapper->insert(new InternalSongFile(
			songId: (int)$song->id,
			fileId: $fileNode->getId(),
			type: SongFileMapper::FILE_TYPE_PDF,
		));

		$res = $this->controller->detachSongFile($song->id, $songFile->id, $this->libraryId);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());

		$files = $this->songFileMapper->findAllForSong($song->id);
		$this->assertEmpty($files);
	}

	public function testUpdateSongFile(): void {
		$song = $this->songMapper->insert(new InternalSong(
			uuid: 'update-file-song-uuid',
			libraryId: $this->libraryId,
			title: 'Song to Update File',
		));

		$fileNode = $this->libFolder->newFile('update-me.pdf');
		$fileNode->putContent('%PDF-1.4 initial content');
		$this->createdUserNodes[] = $fileNode;

		$songFile = $this->songFileMapper->insert(new InternalSongFile(
			songId: (int)$song->id,
			fileId: $fileNode->getId(),
			type: SongFileMapper::FILE_TYPE_PDF,
		));

		$tmp = tempnam(sys_get_temp_dir(), 'test_update_');
		file_put_contents($tmp, '%PDF-1.4 updated content');

		$this->request->setUploadedFile('file', [
			'tmp_name' => $tmp,
			'name' => 'update-me.pdf',
			'error' => UPLOAD_ERR_OK,
		]);

		try {
			$res = $this->controller->updateSongFile($song->id, $songFile->id, $this->libraryId);
			$this->assertSame(Http::STATUS_OK, $res->getStatus());
		} finally {
			unlink($tmp);
		}

		$reloaded = $this->libFolder->get('update-me.pdf');
		$this->assertSame('%PDF-1.4 updated content', $reloaded->getContent());
	}

	public function testGetSongFileVersions(): void {
		$res = $this->controller->getSongFileVersions(1, 99999, $this->libraryId);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame([], $res->getData());
	}
}
