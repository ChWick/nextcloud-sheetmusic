<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Listener;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Listener\SheetMusicFileEventListener;
use OCA\SheetMusic\Service\LibraryReconciler;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SheetMusicFileEventListenerTest extends TestCase {
	private LibraryMapper $libraryMapper;
	private InternalSongMapper $songMapper;
	private InternalSongFileMapper $songFileMapper;
	private LibraryReconciler $reconciler;
	private IRootFolder $rootFolder;
	private LoggerInterface $logger;
	private SheetMusicFileEventListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->libraryMapper = $this->createMock(LibraryMapper::class);
		$this->songMapper = $this->createMock(InternalSongMapper::class);
		$this->songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$this->reconciler = $this->createMock(LibraryReconciler::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->listener = new SheetMusicFileEventListener(
			$this->libraryMapper,
			$this->songMapper,
			$this->songFileMapper,
			$this->reconciler,
			$this->rootFolder,
			$this->logger,
		);
	}

	public function testOnNodeCreatedIndexesNewFileInLibrary(): void {
		$library = new InternalLibrary(id: 1, userId: 'admin', path: 'Noten');

		$this->libraryMapper->method('findAllForUser')->with('admin')->willReturn([
			['id' => 1, 'path' => 'Noten'],
		]);
		$this->libraryMapper->method('findModelById')->with(1)->willReturn($library);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(100);
		$file->method('getName')->willReturn('Chopin - Nocturne.pdf');
		$file->method('getPath')->willReturn('/admin/files/Noten/Chopin - Nocturne.pdf');

		$this->reconciler->method('isIgnoredFilename')->with('Chopin - Nocturne.pdf')->willReturn(false);
		$this->songFileMapper->method('findByFileId')->with(100, false)->willReturn([]);

		$this->reconciler->expects(self::once())
			->method('indexFile')
			->with($library, $file);

		$this->libraryMapper->expects(self::once())
			->method('touch')
			->with(1);

		$event = new NodeCreatedEvent($file);
		$this->listener->handle($event);
	}

	public function testOnNodeCreatedIgnoresDbFiles(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('mobilesheets.db');

		$this->reconciler->method('isIgnoredFilename')->with('mobilesheets.db')->willReturn(true);

		$this->reconciler->expects(self::never())->method('indexFile');

		$event = new NodeCreatedEvent($file);
		$this->listener->handle($event);
	}

	public function testOnNodeDeletedPrunesFile(): void {
		$library = new InternalLibrary(id: 1, userId: 'admin', path: 'Noten');

		$this->libraryMapper->method('findAllForUser')->with('admin')->willReturn([
			['id' => 1, 'path' => 'Noten'],
		]);
		$this->libraryMapper->method('findModelById')->with(1)->willReturn($library);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(100);
		$file->method('getPath')->willReturn('/admin/files/Noten/Chopin - Nocturne.pdf');

		$songFile = new InternalSongFile(id: 5, uuid: 'sf-uuid', songId: 10, fileId: 100);
		$this->songFileMapper->method('findByFileId')->with(100, false)->willReturn([$songFile]);

		$this->songFileMapper->expects(self::once())
			->method('delete')
			->with(5, false);

		$this->libraryMapper->expects(self::once())
			->method('touch')
			->with(1);

		$event = new NodeDeletedEvent($file);
		$this->listener->handle($event);
	}

	public function testOnNodeRenamedOutsideLibraryDeletesFile(): void {
		$library = new InternalLibrary(id: 1, userId: 'admin', path: 'Noten');

		// Source in Noten, target in Documents
		$source = $this->createMock(File::class);
		$source->method('getPath')->willReturn('/admin/files/Noten/Chopin.pdf');

		$target = $this->createMock(File::class);
		$target->method('getId')->willReturn(100);
		$target->method('getPath')->willReturn('/admin/files/Documents/Chopin.pdf');

		$this->libraryMapper->method('findAllForUser')->with('admin')->willReturn([
			['id' => 1, 'path' => 'Noten'],
		]);
		$this->libraryMapper->method('findModelById')->with(1)->willReturn($library);

		$songFile = new InternalSongFile(id: 5, uuid: 'sf-uuid', songId: 10, fileId: 100);
		$this->songFileMapper->method('findByFileId')->with(100, false)->willReturn([$songFile]);

		$this->songFileMapper->expects(self::once())
			->method('delete')
			->with(5, false);

		$this->libraryMapper->expects(self::once())
			->method('touch')
			->with(1);

		$event = new NodeRenamedEvent($source, $target);
		$this->listener->handle($event);
	}
}

