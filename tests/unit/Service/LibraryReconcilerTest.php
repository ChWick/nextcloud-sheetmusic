<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Service\LibraryReconciler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\RealMobileSheetsTestTrait;

final class LibraryReconcilerTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private LibraryReconciler $reconciler;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$this->reconciler = new LibraryReconciler(
			$this->songMapper,
			$this->songFileMapper,
			$this->libraryMapper,
			$this->rootFolder,
			$this->userManager,
			new NullLogger(),
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testReconcileIndexesNewFilesAndPreservesMissingTrackedFiles(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		$folderName = 'Reconcile_' . bin2hex(random_bytes(4));
		$libFolder = $userFolder->newFolder($folderName);
		$this->createdUserNodes[] = $libFolder;

		$library = $this->createTestLibrary('/' . $folderName . '/lib.db', 'admin');

		// 1. Create a physical untracked file on disk
		$physicalFile = $libFolder->newFile('001 New Score.pdf');
		$physicalFile->putContent("%PDF-1.4 mock content\n");
		$this->createdUserNodes[] = $physicalFile;

		// 2. Create an existing tracked file in database with non-existent fileId 999999
		$staleSong = $this->songMapper->insert(new InternalSong(
			id: null,
			uuid: 'stale-song',
			libraryId: (int)$library->id,
			title: 'Stale Song',
		));
		$staleFile = $this->songFileMapper->insert(new InternalSongFile(
			id: null,
			uuid: 'stale-file',
			songId: (int)$staleSong->id,
			fileId: 999999,
		));

		// Run reconciliation
		$result = $this->reconciler->reconcile($library);

		self::assertSame(1, $result['indexed']);
		self::assertSame(0, $result['pruned']);

		// The new score should now be indexed as a song and file in database
		$indexedSong = $this->songMapper->findByTitle((int)$library->id, 'New Score');
		self::assertNotNull($indexedSong);
		self::assertSame('New Score', $indexedSong->title);

		$indexedFiles = $this->songFileMapper->findAllForSong((int)$indexedSong->id);
		self::assertCount(1, $indexedFiles);
		self::assertSame((int)$physicalFile->getId(), $indexedFiles[0]->fileId);

		// The stale file should NOT be pruned (its attachment is preserved for missing file detection)
		$reloadedStale = $this->songFileMapper->findById((int)$staleFile->id);
		self::assertNotNull($reloadedStale);
		self::assertNull($reloadedStale->deletedAt);
	}

	public function testExtractTitleFromFilenameRemovesNumericPrefixes(): void {
		self::assertSame('Jazz - Take Five', $this->reconciler->extractTitleFromFilename('001 Jazz - Take Five.pdf'));
		self::assertSame('Moonlight Sonata', $this->reconciler->extractTitleFromFilename('Moonlight_Sonata.pdf'));
		self::assertSame('Concerto in G', $this->reconciler->extractTitleFromFilename('Concerto in G.pdf'));
	}

	public function testIsIgnoredFilename(): void {
		self::assertTrue($this->reconciler->isIgnoredFilename('mobilesheets.db'));
		self::assertTrue($this->reconciler->isIgnoredFilename('mobilesheets.db-wal'));
		self::assertTrue($this->reconciler->isIgnoredFilename('my_library.db'));
		self::assertTrue($this->reconciler->isIgnoredFilename('mobilesheets_hashcodes.txt'));
		self::assertFalse($this->reconciler->isIgnoredFilename('Sonata.pdf'));
	}
}
