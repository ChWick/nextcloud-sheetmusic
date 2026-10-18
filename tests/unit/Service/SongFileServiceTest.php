<?php

declare(strict_types=1);

namespace Service;

use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Service\FileAlreadyExistsException;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Service\SongFileService;
use OCP\Files\Folder;
use OCP\Files\IUserFolder;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\RealMobileSheetsTestTrait;

final class SongFileServiceTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private IUser $user;
	private IUserFolder $userFolder;
	private Folder $libraryFolder;
	private int $libraryId;
	private SongFileService $service;
	private LibraryService $libraryService;
	private FileManager $fileManager;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();

		$this->user = $this->userManager->get('admin');
		$this->userFolder = $this->rootFolder->getUserFolder('admin');

		$libraryDirName = 'Noten_' . bin2hex(random_bytes(4));
		$this->libraryFolder = $this->userFolder->newFolder($libraryDirName);
		$this->createdUserNodes[] = $this->libraryFolder;

		$library = $this->createTestLibrary('/' . $libraryDirName . '/library.db', 'admin');
		$this->libraryId = (int)$library->id;

		$logger = new NullLogger();
		$this->libraryService = new LibraryService($this->libraryMapper, $this->rootFolder, $logger, exporter: $this->exporter);
		$this->fileManager = new FileManager(
			$this->libraryMapper,
			$this->rootFolder,
			$this->pathResolver,
			$logger,
			$this->libraryService,
			$this->songFileMapper,
			$this->songMapper,
		);
		$this->service = new SongFileService(
			$this->libraryService,
			$this->fileManager,
			$this->rootFolder,
			$logger,
			$this->songFileMapper,
			$this->songMapper,
			$this->libraryMapper,
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testAttachNextcloudFileCopiesWithoutOverwritingExistingFile(): void {
		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-attach-1',
			libraryId: $this->libraryId,
			title: 'Song One',
		));

		// Existing file at destination in library
		$existingDest = $this->libraryFolder->newFile('score.pdf');
		$existingDest->putContent('EXISTING_DESTINATION');
		$this->createdUserNodes[] = $existingDest;

		// Source folder outside library
		$uploadsDir = $this->userFolder->newFolder('Uploads_' . bin2hex(random_bytes(4)));
		$this->createdUserNodes[] = $uploadsDir;
		$sourceFile = $uploadsDir->newFile('score.pdf');
		$sourceFile->putContent('NEW_ATTACHMENT');
		$this->createdUserNodes[] = $sourceFile;

		$result = $this->service->attachNextcloudFile(
			$this->user,
			$this->libraryId,
			(int)$song->id,
			$uploadsDir->getName() . '/score.pdf',
			'copy',
		);

		self::assertNotNull($result);
		self::assertTrue($this->libraryFolder->nodeExists('score.pdf'));
		self::assertSame('EXISTING_DESTINATION', $this->libraryFolder->get('score.pdf')->getContent(), 'Existing destination file must NOT be overwritten');

		self::assertTrue($this->libraryFolder->nodeExists('score (2).pdf'));
		self::assertSame('NEW_ATTACHMENT', $this->libraryFolder->get('score (2).pdf')->getContent(), 'New attachment must be copied to non-conflicting unique name');
	}

	public function testAttachNextcloudFileMovesWithoutOverwritingExistingFile(): void {
		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-attach-2',
			libraryId: $this->libraryId,
			title: 'Song Two',
		));

		// Existing file at destination in library
		$existingDest = $this->libraryFolder->newFile('score.pdf');
		$existingDest->putContent('EXISTING_DESTINATION');
		$this->createdUserNodes[] = $existingDest;

		// Source folder outside library
		$uploadsDir = $this->userFolder->newFolder('Uploads_' . bin2hex(random_bytes(4)));
		$this->createdUserNodes[] = $uploadsDir;
		$sourceFile = $uploadsDir->newFile('score.pdf');
		$sourceFile->putContent('NEW_ATTACHMENT');
		$this->createdUserNodes[] = $sourceFile;

		$result = $this->service->attachNextcloudFile(
			$this->user,
			$this->libraryId,
			(int)$song->id,
			$uploadsDir->getName() . '/score.pdf',
			'move',
		);

		self::assertNotNull($result);
		self::assertTrue($this->libraryFolder->nodeExists('score.pdf'));
		self::assertSame('EXISTING_DESTINATION', $this->libraryFolder->get('score.pdf')->getContent(), 'Existing destination file must NOT be overwritten');

		self::assertTrue($this->libraryFolder->nodeExists('score (2).pdf'));
		self::assertSame('NEW_ATTACHMENT', $this->libraryFolder->get('score (2).pdf')->getContent(), 'New attachment must be moved to non-conflicting unique name');

		self::assertFalse($uploadsDir->nodeExists('score.pdf'), 'Source file must be moved');
	}

	public function testUploadSongFileWithoutOverwritingExistingFile(): void {
		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-upload-1',
			libraryId: $this->libraryId,
			title: 'Song Three',
		));

		// Existing file at destination in library
		$existingFile = $this->libraryFolder->newFile('uploaded.pdf');
		$existingFile->putContent('EXISTING_FILE');
		$this->createdUserNodes[] = $existingFile;

		$tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_');
		file_put_contents($tmpFile, 'UPLOADED_CONTENT');

		$fileInfo = [
			'tmp_name' => $tmpFile,
			'name' => 'uploaded.pdf',
		];

		try {
			$result = $this->service->uploadSongFile(
				$this->user,
				$this->libraryId,
				(int)$song->id,
				$fileInfo,
			);
			self::assertNotNull($result);
		} finally {
			unlink($tmpFile);
		}

		self::assertTrue($this->libraryFolder->nodeExists('uploaded.pdf'));
		self::assertSame('EXISTING_FILE', $this->libraryFolder->get('uploaded.pdf')->getContent(), 'Existing file must NOT be overwritten');

		self::assertTrue($this->libraryFolder->nodeExists('uploaded (2).pdf'));
		self::assertSame('UPLOADED_CONTENT', $this->libraryFolder->get('uploaded (2).pdf')->getContent());
	}

	public function testDetachSongFileKeepsThePhysicalFile(): void {
		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-detach-1',
			libraryId: $this->libraryId,
			title: 'Song Detach',
		));

		$fileNode = $this->libraryFolder->newFile('score_detach.pdf');
		$fileNode->putContent('DETACHED_CONTENT');
		$this->createdUserNodes[] = $fileNode;

		$fileRecord = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'sf-detach-1',
			songId: (int)$song->id,
			fileId: (int)$fileNode->getId(),
		));

		$result = $this->service->detachSongFile($this->user, $this->libraryId, (int)$song->id, (int)$fileRecord->id);
		self::assertNotNull($result);

		// Record in database is soft-deleted
		$deletedRecord = $this->songFileMapper->findById((int)$fileRecord->id);
		self::assertNotNull($deletedRecord);
		self::assertNotNull($deletedRecord->deletedAt);
		self::assertEmpty($this->songFileMapper->findAllForSong((int)$song->id));

		// Physical file still exists on disk
		self::assertTrue($this->libraryFolder->nodeExists('score_detach.pdf'));
		self::assertSame('DETACHED_CONTENT', $this->libraryFolder->get('score_detach.pdf')->getContent());
	}

	public function testUploadSongFileFailsWhenAutoPathsDisabledAndFileExists(): void {
		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-upload-fail',
			libraryId: $this->libraryId,
			title: 'Song Fail',
		));

		$this->libraryMapper->setSettingsForUser('admin', $this->libraryId, autoPaths: false);

		$existingFile = $this->libraryFolder->newFile('existing.pdf');
		$existingFile->putContent('%PDF-1.4 existing');
		$this->createdUserNodes[] = $existingFile;

		$tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_');
		file_put_contents($tmpFile, '%PDF-1.4 new');

		try {
			$this->expectException(FileAlreadyExistsException::class);
			$this->service->uploadSongFile($this->user, $this->libraryId, (int)$song->id, [
				'tmp_name' => $tmpFile,
				'name' => 'existing.pdf',
			]);
		} finally {
			unlink($tmpFile);
		}
	}

	public function testUploadSongFileSucceedsWithoutRelocationWhenAutoPathsDisabled(): void {
		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-upload-no-reloc',
			libraryId: $this->libraryId,
			title: 'Song No Reloc',
		));

		$this->libraryMapper->setSettingsForUser('admin', $this->libraryId, autoPaths: false);

		$tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_');
		file_put_contents($tmpFile, '%PDF-1.4 content');

		try {
			$result = $this->service->uploadSongFile($this->user, $this->libraryId, (int)$song->id, [
				'tmp_name' => $tmpFile,
				'name' => 'new_no_reloc.pdf',
			]);
			self::assertNotNull($result);
		} finally {
			unlink($tmpFile);
		}

		self::assertTrue($this->libraryFolder->nodeExists('new_no_reloc.pdf'));
	}

	public function testSyncSongFileMetadataSkippedWhenAutoPdfMetadataDisabled(): void {
		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-sync-meta-disabled',
			libraryId: $this->libraryId,
			title: 'Song Meta Disabled',
		));

		$this->libraryMapper->setSettingsForUser('admin', $this->libraryId, autoPdfMetadata: false);

		// Must not throw or crash even if file does not exist
		$this->service->syncSongFileMetadata($this->user, '', (int)$song->id, $this->libraryId);

		self::assertFalse($this->libraryService->isAutoPdfMetadataEnabled('admin', $this->libraryId));
	}
}
