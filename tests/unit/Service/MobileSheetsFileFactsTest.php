<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Service\MobileSheetsFileFacts;
use OCP\Files\File;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;

use TestSupport\RealMobileSheetsTestTrait;

final class MobileSheetsFileFactsTest extends TestCase {
	use RealMobileSheetsTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testFromNodeDerivesTheFactsFromTheFile(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		$file = $userFolder->newFile('facts_test.txt');
		$file->putContent('123456789');
		$file->touch(1789222407);
		$this->createdUserNodes[] = $file;

		$facts = MobileSheetsFileFacts::fromNode($file);

		self::assertSame(-873187034, $facts->fileHash);
		self::assertSame(9, $facts->fileSize);
		self::assertSame(1789222407000, $facts->lastModified);
		self::assertSame(1, $facts->sourceFilePageCount);
		self::assertSame('1-1', $facts->pageOrder);
	}

	public function testFromNodeReadsThePageCountFromAPdf(): void {
		$pdf = "%PDF-1.4\n"
			. "<< /Type /Pages /Count 3 /Kids [] >>\n"
			. "1 0 obj << /Type /Page >> endobj\n"
			. "2 0 obj << /Type /Page >> endobj\n"
			. "3 0 obj << /Type /Page >> endobj\n";
		$userFolder = $this->rootFolder->getUserFolder('admin');
		$file = $userFolder->newFile('facts_test.pdf');
		$file->putContent($pdf);
		$file->touch(1789222408);
		$this->createdUserNodes[] = $file;

		$facts = MobileSheetsFileFacts::fromNode($file);

		self::assertSame(3, $facts->sourceFilePageCount);
		self::assertSame('1-3', $facts->pageOrder);
		self::assertSame(strlen($pdf), $facts->fileSize);
		self::assertSame(1789222408000, $facts->lastModified);
	}

	/** A plain Node has no readable content: the derived fields fall back safely. */
	public function testFromNodeToleratesANodeWithoutContent(): void {
		$userFolder = $this->rootFolder->getUserFolder('admin');
		$folder = $userFolder->newFolder('facts_test_dir');
		$folder->touch(1789222409);
		$this->createdUserNodes[] = $folder;

		$facts = MobileSheetsFileFacts::fromNode($folder);

		self::assertSame(0, $facts->fileHash);
		self::assertSame(0, $facts->fileSize);
		self::assertSame(1789222409000, $facts->lastModified);
		self::assertSame(1, $facts->sourceFilePageCount);
		self::assertSame('1-1', $facts->pageOrder);
	}
}
