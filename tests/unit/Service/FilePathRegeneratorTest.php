<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Tests\Unit\Service;

use OCA\SheetMusic\Service\FilePathRegenerator;
use OCA\SheetMusic\Service\SyncException;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFile;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TestSupport\RealMobileSheetsTestTrait;

final class FilePathRegeneratorTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private FilePathRegenerator $regenerator;
	private Folder $testFolder;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();

		$this->regenerator = new FilePathRegenerator(
			$this->rootFolder,
			$this->pathResolver,
			new NullLogger(),
		);

		$userFolder = $this->rootFolder->getUserFolder('admin');
		if ($userFolder->nodeExists('TestRegenDir')) {
			$userFolder->get('TestRegenDir')->delete();
		}
		$this->testFolder = $userFolder->newFolder('TestRegenDir');
		$this->createdUserNodes[] = $this->testFolder;
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testUniqueRelativePathReturnsOriginalWhenNotExists(): void {
		$result = $this->regenerator->uniqueRelativePath($this->testFolder, 'Noten/My Song.pdf');
		self::assertSame('Noten/My Song.pdf', $result);
	}

	public function testUniqueRelativePathIncrementsCounterWhenTaken(): void {
		$this->testFolder->newFolder('Noten');
		$this->testFolder->newFile('Noten/My Song.pdf', 'dummy content');

		$result = $this->regenerator->uniqueRelativePath($this->testFolder, 'Noten/My Song.pdf');
		self::assertSame('Noten/My Song (2).pdf', $result);
	}

	public function testUniqueRelativePathRespectsKeepRelative(): void {
		$this->testFolder->newFolder('Noten');
		$this->testFolder->newFile('Noten/My Song.pdf', 'dummy content');

		$result = $this->regenerator->uniqueRelativePath($this->testFolder, 'Noten/My Song.pdf', 'Noten/My Song.pdf');
		self::assertSame('Noten/My Song.pdf', $result);
	}

	public function testEnsureParentFolderCreatesMissingSegments(): void {
		$result = $this->regenerator->ensureParentFolder($this->testFolder, 'Noten/Jazz/Miles.pdf');
		self::assertSame(['Noten', 'Noten/Jazz'], $result);
		self::assertTrue($this->testFolder->nodeExists('Noten'));
		self::assertTrue($this->testFolder->nodeExists('Noten/Jazz'));
	}

	public function testEnsureParentFolderReturnsEmptyForRoot(): void {
		$result = $this->regenerator->ensureParentFolder($this->testFolder, 'rootfile.pdf');
		self::assertSame([], $result);
	}

	public function testNodeIdOfStoredFileReturnsNullWhenCannotResolve(): void {
		$user = $this->userManager->get('admin');
		self::assertNotNull($user);

		$id = $this->regenerator->nodeIdOfStoredFile('/some/invalid/path.pdf', $user);
		self::assertNull($id);
	}

	public function testNodeIdOfStoredFileReturnsIdWhenNodeExists(): void {
		$user = $this->userManager->get('admin');
		self::assertNotNull($user);

		$file = $this->testFolder->newFile('song.pdf', "%PDF-1.4\n%%EOF");
		$storedPath = '/remote.php/dav/files/admin/TestRegenDir/song.pdf';

		$id = $this->regenerator->nodeIdOfStoredFile($storedPath, $user);
		self::assertSame($file->getId(), $id);
	}

	public function testRegenerateFilePathThrowsWhenStoredOutsideLibrary(): void {
		$user = $this->userManager->get('admin');
		self::assertNotNull($user);

		$song = new MobileSheetsSong(1, 'Test Song');
		$file = new MobileSheetsFile(1, '/invalid/outside.pdf');

		$this->expectException(SyncException::class);
		$this->regenerator->regenerateFilePath($song, $file, $user, $this->testFolder, '/db.db');
	}
}
