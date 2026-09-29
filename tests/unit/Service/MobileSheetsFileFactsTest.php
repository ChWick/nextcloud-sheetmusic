<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\MobileSheetsManager\Service\MobileSheetsFileFacts;
use OCP\Files\File;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;

final class MobileSheetsFileFactsTest extends TestCase {
	public function testFromNodeDerivesTheFactsFromTheFile(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('123456789');
		$file->method('getMTime')->willReturn(1789222407);

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
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($pdf);
		$file->method('getMTime')->willReturn(1789222408);

		$facts = MobileSheetsFileFacts::fromNode($file);

		self::assertSame(3, $facts->sourceFilePageCount);
		self::assertSame('1-3', $facts->pageOrder);
		self::assertSame(strlen($pdf), $facts->fileSize);
		self::assertSame(1789222408000, $facts->lastModified);
	}

	/** A plain Node has no readable content: the derived fields fall back safely. */
	public function testFromNodeToleratesANodeWithoutContent(): void {
		$node = $this->createMock(Node::class);
		$node->method('getMTime')->willReturn(1789222409);

		$facts = MobileSheetsFileFacts::fromNode($node);

		self::assertSame(0, $facts->fileHash);
		self::assertSame(0, $facts->fileSize);
		self::assertSame(1789222409000, $facts->lastModified);
		self::assertSame(1, $facts->sourceFilePageCount);
		self::assertSame('1-1', $facts->pageOrder);
	}
}
