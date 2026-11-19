<?php

declare(strict_types=1);

namespace Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongHistoryMapper;
use OCA\SheetMusic\Service\FileAlreadyExistsException;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Service\SongFileService;
use OCA\SheetMusic\Service\SongHistoryService;
use OCA\SheetMusic\Service\SongPathNamingRule;
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
	private SongHistoryService $historyService;
	private LibraryService $libraryService;
	private FileManager $fileManager;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();

		$this->user = $this->userManager->get('admin');
		\OC::$server->get(\OCP\IUserSession::class)->setUser($this->user);
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
		$historyMapper = new InternalSongHistoryMapper($this->dbConn);
		$this->historyService = new SongHistoryService(
			$historyMapper,
			$this->songMapper,
			$this->entityMapper,
			$this->songFileMapper,
			$this->libraryMapper,
			$this->dbConn,
			$logger,
			$this->rootFolder,
		);
		$this->service = new SongFileService(
			$this->libraryService,
			$this->fileManager,
			$this->rootFolder,
			$logger,
			$this->songFileMapper,
			$this->songMapper,
			$this->libraryMapper,
			$this->historyService,
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		\OC::$server->get(\OCP\IUserSession::class)->setUser(null);
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

		self::assertTrue($this->libraryFolder->nodeExists('Songs/score (2).pdf'));
		self::assertSame('NEW_ATTACHMENT', $this->libraryFolder->get('Songs/score (2).pdf')->getContent(), 'New attachment must be copied to non-conflicting unique name under Songs/');
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

		self::assertTrue($this->libraryFolder->nodeExists('Songs/score (2).pdf'));
		self::assertSame('NEW_ATTACHMENT', $this->libraryFolder->get('Songs/score (2).pdf')->getContent(), 'New attachment must be moved to non-conflicting unique name under Songs/');

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

		self::assertTrue($this->libraryFolder->nodeExists('Songs/uploaded (2).pdf'));
		self::assertSame('UPLOADED_CONTENT', $this->libraryFolder->get('Songs/uploaded (2).pdf')->getContent());
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

	public function testUploadSongFileStoresExplicitPageCountAndOrder(): void {
		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-upload-explicit-pages',
			libraryId: $this->libraryId,
			title: 'Song Explicit Pages',
		));

		$tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_pages_');
		file_put_contents($tmpFile, '%PDF-1.4 split score content');

		try {
			$result = $this->service->uploadSongFile(
				$this->user,
				$this->libraryId,
				(int)$song->id,
				[
					'tmp_name' => $tmpFile,
					'name' => 'split_part.pdf',
				],
				explicitPageCount: 5,
				explicitPageOrder: '1-5',
			);
			self::assertNotNull($result);

			$files = $this->songFileMapper->findAllForSong((int)$song->id);
			self::assertCount(1, $files);
			self::assertSame(5, $files[0]->pageCount);
			self::assertSame('1-5', $files[0]->pageOrder);
		} finally {
			unlink($tmpFile);
		}
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

	public function testUpdateSongFileOverwritesInPlaceAndPreservesFileId(): void {
		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-update-1',
			libraryId: $this->libraryId,
			title: 'Song Update In Place',
		));

		$fileNode = $this->libraryFolder->newFile('original_score.pdf');
		$fileNode->putContent('%PDF-1.4 ORIGINAL_CONTENT');
		$this->createdUserNodes[] = $fileNode;
		$origFileId = (int)$fileNode->getId();

		$fileRecord = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'sf-update-1',
			songId: (int)$song->id,
			fileId: $origFileId,
			type: InternalSongFile::FILE_TYPE_PDF,
		));

		$tmpFile = tempnam(sys_get_temp_dir(), 'test_update_');
		file_put_contents($tmpFile, '%PDF-1.4 UPDATED_ENHANCED_CONTENT');

		try {
			$result = $this->service->updateSongFile(
				$this->user,
				$this->libraryId,
				(int)$song->id,
				(int)$fileRecord->id,
				[
					'tmp_name' => $tmpFile,
					'name' => 'original_score.pdf',
				],
			);
			self::assertNotNull($result);
		} finally {
			unlink($tmpFile);
		}

		// Verify file node ID is identical (not deleted and recreated)
		self::assertTrue($this->libraryFolder->nodeExists('original_score.pdf'));
		$reloadedFile = $this->libraryFolder->get('original_score.pdf');
		self::assertSame($origFileId, (int)$reloadedFile->getId(), 'File ID must be preserved for Nextcloud version history');
		self::assertSame('%PDF-1.4 UPDATED_ENHANCED_CONTENT', $reloadedFile->getContent());
	}

	public function testUpdateSongFileThrowsOnInvalidFile(): void {
		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-update-fail',
			libraryId: $this->libraryId,
			title: 'Song Update Fail',
		));

		$this->expectException(\InvalidArgumentException::class);
		$this->service->updateSongFile($this->user, $this->libraryId, (int)$song->id, 9999, null);
	}

	public function testGetSongFileVersionsForNonexistentFileReturnsEmpty(): void {
		$versions = $this->service->getSongFileVersions($this->user, 99999);
		self::assertSame([], $versions);
	}

	public function testUpdateSongFileCapturesVersionInFilesVersionsHistory(): void {
		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-version-test-1',
			libraryId: $this->libraryId,
			title: 'Song Version Capture',
		));

		$initialContent = '%PDF-1.4 INITIAL_SCORE_CONTENT_FOR_VERSIONING';
		$fileNode = $this->libraryFolder->newFile('score_versioned.pdf');
		$fileNode->putContent($initialContent);
		$this->createdUserNodes[] = $fileNode;
		$origFileId = (int)$fileNode->getId();

		$fileRecord = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'sf-version-test-1',
			songId: (int)$song->id,
			fileId: $origFileId,
			type: InternalSongFile::FILE_TYPE_PDF,
		));

		// Before update, no versions exist yet
		$versionsBefore = $this->service->getSongFileVersions($this->user, (int)$fileRecord->id);
		self::assertEmpty($versionsBefore);

		$tmpFile = tempnam(sys_get_temp_dir(), 'test_update_version_');
		$updatedContent = '%PDF-1.4 UPDATED_ENHANCED_SCORE_CONTENT';
		file_put_contents($tmpFile, $updatedContent);

		try {
			$this->service->updateSongFile(
				$this->user,
				$this->libraryId,
				(int)$song->id,
				(int)$fileRecord->id,
				[
					'tmp_name' => $tmpFile,
					'name' => 'score_versioned.pdf',
				],
			);
		} finally {
			unlink($tmpFile);
		}

		// Content was updated in place
		$reloadedFile = $this->libraryFolder->get('score_versioned.pdf');
		self::assertSame($updatedContent, $reloadedFile->getContent());

		// Nextcloud files_versions captured the previous version
		$versionsAfter = $this->service->getSongFileVersions($this->user, (int)$fileRecord->id);
		self::assertNotEmpty($versionsAfter, 'Expected a version to be captured in files_versions');

		$firstVersion = $versionsAfter[0];
		self::assertArrayHasKey('revision', $firstVersion);
		self::assertArrayHasKey('version', $firstVersion);
		self::assertArrayHasKey('timestamp', $firstVersion);
		self::assertArrayHasKey('size', $firstVersion);
		self::assertArrayHasKey('name', $firstVersion);
		self::assertGreaterThan(0, $firstVersion['revision']);
		self::assertSame((string)$firstVersion['revision'], $firstVersion['version']);
		self::assertSame(strlen($initialContent), $firstVersion['size']);
		self::assertSame('score_versioned.pdf', $firstVersion['name']);

		// Reverting to the earlier version rolls back file content on disk
		$this->service->revertSongFileVersion(
			$this->user,
			$this->libraryId,
			(int)$song->id,
			(int)$fileRecord->id,
			$firstVersion['revision'],
		);

		$restoredFile = $this->libraryFolder->get('score_versioned.pdf');
		self::assertSame($initialContent, $restoredFile->getContent(), 'File content should be restored to initial version');

		// Verify timeline captured update and revert
		$timeline = $this->historyService->getTimelineForSong((int)$song->id);
		self::assertGreaterThanOrEqual(2, count($timeline));
		self::assertSame('reverted', $timeline[0]['action']);
		self::assertStringContainsString('Reverted file', $timeline[0]['summary']);
	}

	public function testUploadSongFileAppliesSelectedNamingRulePreset(): void {
		$this->libraryMapper->setSettingsForUser('admin', $this->libraryId, autoPaths: true, namingRulePreset: SongPathNamingRule::PRESET_COMPOSER);

		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-upload-rule-1',
			libraryId: $this->libraryId,
			title: 'Moonlight Sonata',
		));
		$composer = $this->entityMapper->insert(new InternalEntity(
			id: null,
			uuid: 'e-composer-beethoven',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Beethoven',
		));
		$artist = $this->entityMapper->insert(new InternalEntity(
			id: null,
			uuid: 'e-artist-band',
			libraryId: $this->libraryId,
			type: 'artist',
			name: 'Some Orchestra',
		));
		$this->entityMapper->setSongEntitiesForType((int)$song->id, 'composer', [(int)$composer->id]);
		$this->entityMapper->setSongEntitiesForType((int)$song->id, 'artist', [(int)$artist->id]);

		$tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_rule_');
		file_put_contents($tmpFile, 'MOONLIGHT_PDF');

		try {
			$result = $this->service->uploadSongFile($this->user, $this->libraryId, (int)$song->id, [
				'tmp_name' => $tmpFile,
				'name' => 'raw_scan.pdf',
			]);
			self::assertNotNull($result);
		} finally {
			unlink($tmpFile);
		}

		self::assertFalse($this->libraryFolder->nodeExists('raw_scan.pdf'));
		self::assertTrue($this->libraryFolder->nodeExists('Songs/Beethoven/Beethoven - Moonlight Sonata.pdf'));
		self::assertSame('MOONLIGHT_PDF', $this->libraryFolder->get('Songs/Beethoven/Beethoven - Moonlight Sonata.pdf')->getContent());
	}

	public function testAttachNextcloudFileAppliesSelectedNamingRulePreset(): void {
		$this->libraryMapper->setSettingsForUser('admin', $this->libraryId, autoPaths: true, namingRulePreset: SongPathNamingRule::PRESET_ARTIST);

		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-attach-rule-1',
			libraryId: $this->libraryId,
			title: 'Yesterday',
		));
		$composer = $this->entityMapper->insert(new InternalEntity(
			id: null,
			uuid: 'e-composer-lennon',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Lennon',
		));
		$artist = $this->entityMapper->insert(new InternalEntity(
			id: null,
			uuid: 'e-artist-beatles',
			libraryId: $this->libraryId,
			type: 'artist',
			name: 'The Beatles',
		));
		$this->entityMapper->setSongEntitiesForType((int)$song->id, 'composer', [(int)$composer->id]);
		$this->entityMapper->setSongEntitiesForType((int)$song->id, 'artist', [(int)$artist->id]);

		$uploadsDir = $this->userFolder->newFolder('Uploads_rule_' . bin2hex(random_bytes(4)));
		$this->createdUserNodes[] = $uploadsDir;
		$sourceFile = $uploadsDir->newFile('scan_123.pdf');
		$sourceFile->putContent('YESTERDAY_PDF');
		$this->createdUserNodes[] = $sourceFile;

		$result = $this->service->attachNextcloudFile(
			$this->user,
			$this->libraryId,
			(int)$song->id,
			$uploadsDir->getName() . '/scan_123.pdf',
			'copy',
		);

		self::assertNotNull($result);
		self::assertFalse($this->libraryFolder->nodeExists('scan_123.pdf'));
		self::assertTrue($this->libraryFolder->nodeExists('Songs/The Beatles/The Beatles - Yesterday.pdf'));
		self::assertSame('YESTERDAY_PDF', $this->libraryFolder->get('Songs/The Beatles/The Beatles - Yesterday.pdf')->getContent());
	}

	public function testUploadSongFileStaysInLibraryRootWhenNamingRulePresetIsDisabled(): void {
		$this->libraryMapper->setSettingsForUser('admin', $this->libraryId, autoPaths: true, namingRulePreset: SongPathNamingRule::PRESET_DISABLED);

		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-upload-disabled-rule',
			libraryId: $this->libraryId,
			title: 'Flat Song',
		));
		$composer = $this->entityMapper->insert(new InternalEntity(
			id: null,
			uuid: 'e-composer-flat',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Bach',
		));
		$this->entityMapper->setSongEntitiesForType((int)$song->id, 'composer', [(int)$composer->id]);

		$tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_flat_');
		file_put_contents($tmpFile, 'FLAT_PDF');

		try {
			$result = $this->service->uploadSongFile($this->user, $this->libraryId, (int)$song->id, [
				'tmp_name' => $tmpFile,
				'name' => 'keep_in_root.pdf',
			]);
			self::assertNotNull($result);
		} finally {
			unlink($tmpFile);
		}

		self::assertTrue($this->libraryFolder->nodeExists('keep_in_root.pdf'));
		self::assertFalse($this->libraryFolder->nodeExists('Songs'));
	}

	public function testDeleteSongFileRegeneratesRemainingFilePath(): void {
		$this->libraryMapper->setSettingsForUser('admin', $this->libraryId, autoPaths: true, namingRulePreset: SongPathNamingRule::PRESET_COMPOSER);

		$song = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 's-delete-regen',
			libraryId: $this->libraryId,
			title: 'Prelude',
		));
		$composer = $this->entityMapper->insert(new InternalEntity(
			id: null,
			uuid: 'e-composer-chopin',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Chopin',
		));
		$this->entityMapper->setSongEntitiesForType((int)$song->id, 'composer', [(int)$composer->id]);

		$tmp1 = tempnam(sys_get_temp_dir(), 'test_del_1_');
		$tmp2 = tempnam(sys_get_temp_dir(), 'test_del_2_');
		file_put_contents($tmp1, 'PART_1');
		file_put_contents($tmp2, 'PART_2');

		try {
			$this->service->uploadSongFile($this->user, $this->libraryId, (int)$song->id, ['tmp_name' => $tmp1, 'name' => 'part1.pdf']);
			$this->service->uploadSongFile($this->user, $this->libraryId, (int)$song->id, ['tmp_name' => $tmp2, 'name' => 'part2.pdf']);
		} finally {
			unlink($tmp1);
			unlink($tmp2);
		}

		$files = $this->songFileMapper->findAllForSong((int)$song->id);
		self::assertCount(2, $files);
		self::assertTrue($this->libraryFolder->nodeExists('Songs/Chopin/001 Chopin - Prelude.pdf'));
		self::assertTrue($this->libraryFolder->nodeExists('Songs/Chopin/002 Chopin - Prelude.pdf'));

		// Delete second file; remaining file should lose the 001 prefix
		$this->service->deleteSongFile($this->user, $this->libraryId, (int)$song->id, (int)$files[1]->id);

		self::assertFalse($this->libraryFolder->nodeExists('Songs/Chopin/002 Chopin - Prelude.pdf'));
		self::assertFalse($this->libraryFolder->nodeExists('Songs/Chopin/001 Chopin - Prelude.pdf'));
		self::assertTrue($this->libraryFolder->nodeExists('Songs/Chopin/Chopin - Prelude.pdf'));
	}
}
