<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Listener;

use OCA\SheetMusic\Listener\SheetMusicFileEventListener;
use OCA\SheetMusic\Service\LibraryReconciler;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\RealMobileSheetsTestTrait;

final class SheetMusicFileEventListenerTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private LibraryReconciler $reconciler;
	private SheetMusicFileEventListener $listener;

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

		$this->listener = new SheetMusicFileEventListener(
			$this->libraryMapper,
			$this->songMapper,
			$this->songFileMapper,
			$this->reconciler,
			$this->rootFolder,
			new NullLogger(),
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testOnNodeCreatedIndexesNewFileInLibrary(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		if (!$userFolder->nodeExists('Noten')) {
			$this->createdUserNodes[] = $userFolder->newFolder('Noten');
		}
		$library = $this->createTestLibrary('Noten', 'admin');

		$file = $userFolder->newFile('Noten/Chopin - Nocturne.pdf');
		$file->putContent("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n");
		$this->createdUserNodes[] = $file;

		$event = new NodeCreatedEvent($file);
		$this->listener->handle($event);

		$song = $this->songMapper->findByTitle((int)$library->id, 'Chopin - Nocturne');
		self::assertNotNull($song);
		self::assertSame('Chopin - Nocturne', $song->title);

		$songFiles = $this->songFileMapper->findByFileId($file->getId(), false);
		self::assertCount(1, $songFiles);
		self::assertSame($song->id, $songFiles[0]->songId);
	}

	public function testOnNodeCreatedIgnoresDbFiles(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		if (!$userFolder->nodeExists('Noten')) {
			$this->createdUserNodes[] = $userFolder->newFolder('Noten');
		}
		$this->createTestLibrary('Noten', 'admin');

		if ($userFolder->nodeExists('Noten/mobilesheets.db')) {
			$userFolder->get('Noten/mobilesheets.db')->delete();
		}
		$file = $userFolder->newFile('Noten/mobilesheets.db');
		$file->putContent('sqlite');
		$this->createdUserNodes[] = $file;

		$event = new NodeCreatedEvent($file);
		$this->listener->handle($event);

		$songFiles = $this->songFileMapper->findByFileId($file->getId(), false);
		self::assertEmpty($songFiles);
	}

	public function testOnNodeCreatedIgnoresEmptyFiles(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		if (!$userFolder->nodeExists('Noten')) {
			$this->createdUserNodes[] = $userFolder->newFolder('Noten');
		}
		$this->createTestLibrary('Noten', 'admin');

		$file = $userFolder->newFile('Noten/Empty.pdf');
		$this->createdUserNodes[] = $file;

		$event = new NodeCreatedEvent($file);
		$this->listener->handle($event);

		$songFiles = $this->songFileMapper->findByFileId($file->getId(), false);
		self::assertEmpty($songFiles);
	}

	public function testOnNodeDeletedDoesNotDeleteSongFile(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		if (!$userFolder->nodeExists('Noten')) {
			$this->createdUserNodes[] = $userFolder->newFolder('Noten');
		}
		$library = $this->createTestLibrary('Noten', 'admin');

		$file = $userFolder->newFile('Noten/Chopin - Nocturne.pdf');
		$file->putContent("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n");
		$this->createdUserNodes[] = $file;

		$this->reconciler->indexFile($library, $file);
		self::assertCount(1, $this->songFileMapper->findByFileId($file->getId(), false));

		$event = new NodeDeletedEvent($file);
		$this->listener->handle($event);

		// Song file attachment is preserved so it shows as a missing file instead of a song without files
		self::assertCount(1, $this->songFileMapper->findByFileId($file->getId(), false));
	}

	public function testOnNodeRenamedOutsideLibraryDoesNotDeleteSongFile(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		if (!$userFolder->nodeExists('Noten')) {
			$this->createdUserNodes[] = $userFolder->newFolder('Noten');
		}
		if (!$userFolder->nodeExists('Documents')) {
			$this->createdUserNodes[] = $userFolder->newFolder('Documents');
		}
		$library = $this->createTestLibrary('Noten', 'admin');

		$source = $userFolder->newFile('Noten/Chopin.pdf');
		$source->putContent("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n");
		$this->createdUserNodes[] = $source;

		$this->reconciler->indexFile($library, $source);
		self::assertCount(1, $this->songFileMapper->findByFileId($source->getId(), false));

		$target = $userFolder->newFile('Documents/Chopin.pdf');
		$target->putContent("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n");
		$this->createdUserNodes[] = $target;

		$this->dbConn->executeStatement(
			'UPDATE *PREFIX*sheetmusic_song_files SET file_id = ? WHERE file_id = ?',
			[$target->getId(), $source->getId()]
		);

		$event = new NodeRenamedEvent($source, $target);
		$this->listener->handle($event);

		// Song file attachment is preserved
		self::assertCount(1, $this->songFileMapper->findByFileId($target->getId(), false));
	}
}
