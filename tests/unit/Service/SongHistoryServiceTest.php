<?php

declare(strict_types=1);

namespace Service;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongHistory;
use OCA\SheetMusic\Db\InternalSongHistoryMapper;
use OCA\SheetMusic\Service\SongHistoryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\RealMobileSheetsTestTrait;

final class SongHistoryServiceTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private int $libraryId;
	private SongHistoryService $historyService;
	private InternalSongHistoryMapper $historyMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$library = $this->createTestLibrary('SongHistory Test Lib');
		$this->libraryId = (int)$library->id;

		$this->historyMapper = new InternalSongHistoryMapper($this->dbConn);
		$this->historyService = new SongHistoryService(
			$this->historyMapper,
			$this->songMapper,
			$this->entityMapper,
			$this->songFileMapper,
			$this->libraryMapper,
			$this->dbConn,
			new NullLogger(),
			$this->rootFolder,
			$this->attributeMapper,
		);
	}

	protected function tearDown(): void {
		$this->cleanupLibraryData($this->libraryId);
		$this->tearDownRealMobileSheetsEnvironment();
		\OC::$server->get(\OCP\IUserSession::class)->setUser(null);
		parent::tearDown();
	}

	public function testRecordSnapshotAndGetTimeline(): void {
		$song = $this->songMapper->insert(new InternalSong(
			uuid: 'hist-song-1',
			libraryId: $this->libraryId,
			title: 'Original Title',
			rating: 3,
		));

		$snap = $this->historyService->recordSnapshot($song, InternalSongHistory::ACTION_CREATED, 'admin');
		self::assertNotNull($snap);
		self::assertSame('Song created', $snap->summary);

		$timeline = $this->historyService->getTimelineForSong((int)$song->id);
		self::assertCount(1, $timeline);
		self::assertSame('Original Title', $timeline[0]['snapshot']['title']);
		self::assertSame(3, $timeline[0]['snapshot']['rating']);
		self::assertSame('created', $timeline[0]['action']);
	}

	public function testRevertOnlyAffectsTargetSong(): void {
		// 1. Create Song 1 and Song 2
		$song1 = $this->songMapper->insert(new InternalSong(
			uuid: 'hist-song-iso-1',
			libraryId: $this->libraryId,
			title: 'Song One V1',
			rating: 1,
		));
		$this->historyService->recordSnapshot($song1, InternalSongHistory::ACTION_CREATED, 'admin');

		$song2 = $this->songMapper->insert(new InternalSong(
			uuid: 'hist-song-iso-2',
			libraryId: $this->libraryId,
			title: 'Song Two V1',
			rating: 2,
		));
		$this->historyService->recordSnapshot($song2, InternalSongHistory::ACTION_CREATED, 'admin');

		// Initial history ID for Song 1
		$historySong1V1 = $this->historyService->getTimelineForSong((int)$song1->id)[0]['id'];

		// 2. Modify Song 1 -> V2
		$song1->title = 'Song One V2';
		$song1->rating = 5;
		$this->songMapper->update($song1);
		$this->historyService->recordSnapshot($song1, InternalSongHistory::ACTION_UPDATED, 'admin');

		// 3. Modify Song 2 -> V2
		$song2->title = 'Song Two V2';
		$song2->rating = 4;
		$this->songMapper->update($song2);
		$this->historyService->recordSnapshot($song2, InternalSongHistory::ACTION_UPDATED, 'admin');

		// Verify both are currently at V2
		$reloaded1 = $this->songMapper->findById((int)$song1->id);
		$reloaded2 = $this->songMapper->findById((int)$song2->id);
		self::assertSame('Song One V2', $reloaded1?->title);
		self::assertSame('Song Two V2', $reloaded2?->title);

		// 4. Revert ONLY Song 1 back to V1
		$this->historyService->revertSongToHistory((int)$song1->id, (int)$historySong1V1, 'admin');

		// 5. Assert: Song 1 reverted, Song 2 remains at V2!
		$final1 = $this->songMapper->findById((int)$song1->id);
		$final2 = $this->songMapper->findById((int)$song2->id);

		self::assertNotNull($final1);
		self::assertSame('Song One V1', $final1->title);
		self::assertSame(1, $final1->rating);

		self::assertNotNull($final2);
		self::assertSame('Song Two V2', $final2->title);
		self::assertSame(4, $final2->rating);
	}

	public function testRevertRestoresEntityRelationsAndFiles(): void {
		$composer1 = $this->entityMapper->insert(new InternalEntity(
			uuid: 'comp-1',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Beethoven',
		));
		$composer2 = $this->entityMapper->insert(new InternalEntity(
			uuid: 'comp-2',
			libraryId: $this->libraryId,
			type: 'composer',
			name: 'Mozart',
		));

		$song = $this->songMapper->insert(new InternalSong(
			uuid: 'hist-song-ents',
			libraryId: $this->libraryId,
			title: 'Classical Piece',
		));
		$this->entityMapper->linkSongEntity((int)$song->id, (int)$composer1->id);

		$file = $this->songFileMapper->insert(new InternalSongFile(
			uuid: 'file-hist-1',
			songId: (int)$song->id,
			fileId: 9991,
			pageOrder: '1-4',
			pageCount: 4,
		));

		// Record Revision 1
		$snap1 = $this->historyService->recordSnapshot($song, InternalSongHistory::ACTION_CREATED, 'admin');
		$rev1Id = (int)$snap1->id;

		// Revision 2: Switch to Mozart, update pageOrder to 1-2,4
		$this->entityMapper->unlinkSongEntity((int)$song->id, (int)$composer1->id);
		$this->entityMapper->linkSongEntity((int)$song->id, (int)$composer2->id);
		$file->pageOrder = '1-2,4';
		$this->songFileMapper->update($file);
		$song->title = 'Modified Classical Piece';
		$this->songMapper->update($song);
		$this->historyService->recordSnapshot($song, InternalSongHistory::ACTION_UPDATED, 'admin');

		// Check current composers
		$currentComposers = $this->entityMapper->findEntitiesForSong((int)$song->id, 'composer');
		self::assertCount(1, $currentComposers);
		self::assertSame('Mozart', $currentComposers[0]->name);

		// Revert to Revision 1
		$this->historyService->revertSongToHistory((int)$song->id, $rev1Id, 'admin');

		// Verify title reverted
		$reloadedSong = $this->songMapper->findById((int)$song->id);
		self::assertSame('Classical Piece', $reloadedSong?->title);

		// Verify composer reverted to Beethoven
		$reloadedComposers = $this->entityMapper->findEntitiesForSong((int)$song->id, 'composer');
		self::assertCount(1, $reloadedComposers);
		self::assertSame('Beethoven', $reloadedComposers[0]->name);

		// Verify pageOrder reverted
		$reloadedFile = $this->songFileMapper->findById((int)$file->id);
		self::assertSame('1-4', $reloadedFile?->pageOrder);
	}

	public function testDeletedSongCanBeRestored(): void {
		$song = $this->songMapper->insert(new InternalSong(
			uuid: 'hist-song-del',
			libraryId: $this->libraryId,
			title: 'To Be Deleted',
		));
		$this->historyService->recordSnapshot($song, InternalSongHistory::ACTION_CREATED, 'admin');

		// Soft delete
		$song->deletedAt = time();
		$this->songMapper->update($song);
		$this->historyService->recordSnapshot($song, InternalSongHistory::ACTION_DELETED, 'admin');

		// Find in deleted songs list
		$deleted = $this->historyService->getDeletedSongs($this->libraryId);
		self::assertCount(1, $deleted);
		self::assertSame('To Be Deleted', $deleted[0]['title']);

		// Restore
		$restored = $this->historyService->restoreDeletedSong((int)$song->id, 'admin');
		self::assertNull($restored->deletedAt);

		// Verify no longer in deleted list
		$deletedAfter = $this->historyService->getDeletedSongs($this->libraryId);
		self::assertCount(0, $deletedAfter);

		// Verify active song
		$active = $this->songMapper->findById((int)$song->id);
		self::assertNotNull($active);
		self::assertNull($active->deletedAt);
	}

	public function testHasHistory(): void {
		$song = $this->songMapper->insert(new InternalSong(
			uuid: 'hist-song-check',
			libraryId: $this->libraryId,
			title: 'History Check',
		));

		self::assertFalse($this->historyService->hasHistory((int)$song->id));

		$this->historyService->recordSnapshot($song, InternalSongHistory::ACTION_CREATED, 'admin');

		self::assertTrue($this->historyService->hasHistory((int)$song->id));
	}

	public function testRestorePhysicalFileFromTrashHandlesMissingGracefully(): void {
		// When ocFileId is <= 0 or user is null, it should return false cleanly without errors
		self::assertFalse($this->historyService->restorePhysicalFileFromTrashIfNeeded(0, 'admin'));
		self::assertFalse($this->historyService->restorePhysicalFileFromTrashIfNeeded(1234, null));
	}

	public function testRevertSongRestoresPhysicalFileVersion(): void {
		$user = $this->userManager->get('admin');
		\OC::$server->get(\OCP\IUserSession::class)->setUser($user);
		$userFolder = $this->rootFolder->getUserFolder('admin');

		$libraryFolder = $userFolder->newFolder('SongHist_Lib_' . bin2hex(random_bytes(3)));
		$this->createdUserNodes[] = $libraryFolder;

		$initialContent = '%PDF-1.4 INITIAL_UNROTATED_SCORE_12345';
		$fileNode = $libraryFolder->newFile('score_revert_test.pdf');
		$fileNode->putContent($initialContent);
		$origFileId = (int)$fileNode->getId();

		$song = $this->songMapper->insert(new InternalSong(
			uuid: 'hist-song-file-revert',
			libraryId: $this->libraryId,
			title: 'Song with Versioned File',
		));

		$fileRecord = $this->songFileMapper->insert(new InternalSongFile(
			uuid: 'file-hist-revert-1',
			songId: (int)$song->id,
			fileId: $origFileId,
			pageOrder: '1',
			pageCount: 1,
			fileSize: strlen($initialContent),
		));

		// Revision 1: initial state with initial file content
		$snap1 = $this->historyService->recordSnapshot($song, InternalSongHistory::ACTION_CREATED, 'admin');

		// Ensure files_versions folder and missing parent directories exist
		$userRoot = $userFolder->getParent();
		if (!$userRoot->nodeExists('files_versions')) {
			$userRoot->newFolder('files_versions');
		}
		$relPath = $userFolder->getRelativePath($fileNode->getPath());
		\OCA\Files_Versions\Storage::createMissingDirectories($relPath, new \OC\Files\View('/admin'));

		// Modify file content (simulating rotation or edit) and create version
		$vm = \OC::$server->get(\OCA\Files_Versions\Versions\IVersionManager::class);
		$vm->createVersion($user, $fileNode);
		$modifiedContent = '%PDF-1.4 MODIFIED_ROTATED_SCORE_67890';
		$fileNode->putContent($modifiedContent);

		$fileRecord->fileSize = strlen($modifiedContent);
		$this->songFileMapper->update($fileRecord);
		$song->title = 'Song with Versioned File (Modified)';
		$this->songMapper->update($song);

		// Revision 2: modified state
		$this->historyService->recordSnapshot($song, InternalSongHistory::ACTION_UPDATED, 'admin');

		// Verify current content on disk is modified
		$currentOnDisk = $libraryFolder->get('score_revert_test.pdf');
		self::assertSame($modifiedContent, $currentOnDisk->getContent());

		// Revert song back to Revision 1 via SongHistoryService
		$this->historyService->revertSongToHistory((int)$song->id, (int)$snap1->id, 'admin');

		// Verify that physical file on disk was reverted to initial content
		$revertedOnDisk = $libraryFolder->get('score_revert_test.pdf');
		self::assertSame($initialContent, $revertedOnDisk->getContent(), 'Physical file on disk should be restored to Revision 1 content');

		// Verify song title is reverted
		$reloadedSong = $this->songMapper->findById((int)$song->id);
		self::assertSame('Song with Versioned File', $reloadedSong?->title);
	}

	public function testDynamicPropertiesAndCustomAttributesCapturedAndReverted(): void {
		$song = $this->songMapper->insert(new InternalSong(
			uuid: 'hist-song-dynamic',
			libraryId: $this->libraryId,
			title: 'Song With Attributes V1',
			rating: 4,
			difficulty: 2,
			opus: 'Op. 27 No. 2',
			catalogNumber: 'BWV 1001',
		));

		// Set initial custom attributes
		$this->attributeMapper->setAttribute((int)$song->id, 'tempo', '120');
		$this->attributeMapper->setAttribute((int)$song->id, 'keySignature', 'C# Minor');

		// Revision 1
		$snap1 = $this->historyService->recordSnapshot($song, InternalSongHistory::ACTION_CREATED, 'admin');
		self::assertNotNull($snap1);
		$snap1Arr = $snap1->getSnapshotArray();
		self::assertSame('Song With Attributes V1', $snap1Arr['title']);
		self::assertSame('120', $snap1Arr['attributes']['tempo'] ?? null);
		self::assertSame('C# Minor', $snap1Arr['attributes']['keySignature'] ?? null);

		// Modify song details and attributes
		$song->title = 'Song With Attributes V2';
		$song->rating = 5;
		$this->songMapper->update($song);
		$this->attributeMapper->setAttribute((int)$song->id, 'tempo', '144');
		$this->attributeMapper->removeAttribute((int)$song->id, 'keySignature');
		$this->attributeMapper->setAttribute((int)$song->id, 'copyright', 'Public Domain');

		// Revision 2
		$snap2 = $this->historyService->recordSnapshot($song, InternalSongHistory::ACTION_UPDATED, 'admin');
		self::assertNotNull($snap2);
		self::assertStringContainsString('Tempo updated', (string)$snap2->summary);

		// Verify modified attributes in database
		self::assertSame('144', $this->attributeMapper->findForSong((int)$song->id, 'tempo'));
		self::assertNull($this->attributeMapper->findForSong((int)$song->id, 'keySignature'));
		self::assertSame('Public Domain', $this->attributeMapper->findForSong((int)$song->id, 'copyright'));

		// Revert to Revision 1
		$this->historyService->revertSongToHistory((int)$song->id, (int)$snap1->id, 'admin');

		// Verify reverted song attributes and fields
		$reloaded = $this->songMapper->findById((int)$song->id);
		self::assertSame('Song With Attributes V1', $reloaded?->title);
		self::assertSame(4, $reloaded?->rating);
		self::assertSame('120', $this->attributeMapper->findForSong((int)$song->id, 'tempo'));
		self::assertSame('C# Minor', $this->attributeMapper->findForSong((int)$song->id, 'keySignature'));
		self::assertNull($this->attributeMapper->findForSong((int)$song->id, 'copyright'));
	}

	public function testBackwardCompatibilityRevertingLegacySnapshotWithoutNewFields(): void {
		$song = $this->songMapper->insert(new InternalSong(
			uuid: 'hist-song-legacy',
			libraryId: $this->libraryId,
			title: 'Current Modern Song',
			rating: 5,
			difficulty: 3,
			opus: 'Op. 99',
			catalogNumber: 'Cat 42',
			songNumber: 'Song 7',
		));

		// Manually record a legacy snapshot that is missing newer fields (e.g. opus, catalogNumber, songNumber)
		$legacySnapshot = [
			'title' => 'Ancient Legacy Song',
			'rating' => 2,
			'difficulty' => 1,
			// opus, catalogNumber, songNumber are omitted as if recorded before those fields existed
		];

		$snap = $this->historyService->recordSnapshot(
			$song,
			InternalSongHistory::ACTION_CREATED,
			'admin',
			'Legacy snapshot',
			$legacySnapshot
		);
		self::assertNotNull($snap);

		// Revert to legacy snapshot
		$reverted = $this->historyService->revertSongToHistory((int)$song->id, (int)$snap->id, 'admin');

		// Verify core fields reverted and omitted fields safely reset to their defaults (null)
		self::assertSame('Ancient Legacy Song', $reverted->title);
		self::assertSame(2, $reverted->rating);
		self::assertSame(1, $reverted->difficulty);
		self::assertNull($reverted->opus);
		self::assertNull($reverted->catalogNumber);
		self::assertNull($reverted->songNumber);
	}
}
