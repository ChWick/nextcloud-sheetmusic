<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryData;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\LibraryOrphanScanner;
use OCA\SheetMusic\Service\LibraryService;
use OCA\SheetMusic\Service\OrphanFileLinker;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPathResolver;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OrphanFileLinkerTest extends TestCase {
	public function testReturnsFailureWhenOrphanFileNotFound(): void {
		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('get')->willThrowException(new \RuntimeException('Not found'));

		$linker = new OrphanFileLinker(
			$this->createMock(LibraryService::class),
			$this->createMock(FileManager::class),
			$this->createMock(MobileSheetsPathResolver::class),
			new NullLogger(),
			$this->createMock(InternalSongMapper::class),
			$this->createMock(InternalSongFileMapper::class),
			$this->createMock(LibraryMapper::class),
			$this->createMock(LibraryOrphanScanner::class),
		);

		$res = $linker->linkOrphanFile(1, 'admin', $this->createMock(IUser::class), '', $targetFolder, 'Missing.pdf');
		self::assertFalse($res['success']);
		self::assertSame(0, $res['fixedCount']);
		self::assertStringContainsString('Missing.pdf', $res['message']);
	}

	public function testLinksOrphanFileToMatchedCandidateSong(): void {
		$orphanNode = $this->createMock(Node::class);
		$orphanNode->method('getId')->willReturn(555);
		$orphanNode->method('getName')->willReturn('Sonata.pdf');
		$orphanNode->method('getSize')->willReturn(1024);

		$targetFolder = $this->createMock(Folder::class);
		$targetFolder->method('get')->with('Sonata.pdf')->willReturn($orphanNode);
		$targetFolder->method('getPath')->willReturn('/admin/files/Library');

		$user = $this->createMock(IUser::class);
		$candidate = new MobileSheetsSong(id: 42, title: 'Sonata');

		$songMapper = $this->createMock(InternalSongMapper::class);
		$songMapper->method('findLibraryData')->willReturn(new LibraryData(songs: [$candidate]));

		$orphanScanner = $this->createMock(LibraryOrphanScanner::class);
		$orphanScanner->method('findMissingSongIds')->willReturn([42]);
		$orphanScanner->method('findOrphanFileCandidateSong')->willReturn($candidate);

		$songFileMapper = $this->createMock(InternalSongFileMapper::class);
		$songFileMapper->method('findAllForSong')->with(42)->willReturn([]);
		$songFileMapper->expects(self::once())->method('insert');

		$libraryService = $this->createMock(LibraryService::class);
		$libraryService->method('isAutoPathsEnabled')->willReturn(false);

		$linker = new OrphanFileLinker(
			$libraryService,
			$this->createMock(FileManager::class),
			$this->createMock(MobileSheetsPathResolver::class),
			new NullLogger(),
			$songMapper,
			$songFileMapper,
			$this->createMock(LibraryMapper::class),
			$orphanScanner,
		);

		$res = $linker->linkOrphanFile(1, 'admin', $user, '', $targetFolder, 'Sonata.pdf');
		self::assertTrue($res['success']);
		self::assertSame(1, $res['fixedCount']);
		self::assertStringContainsString('Sonata', $res['message']);
	}
}
