<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Tests\Unit\Service;

use OCA\SheetMusic\BackgroundJob\ComputePdfCropBoxesJob;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Service\PdfCropService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PdfCropServiceTest extends TestCase {
	public function testSanitizeCropBoxesFiltersInvalidEntries(): void {
		$sanitized = PdfCropService::sanitizeCropBoxes([
			'1' => ['left' => 0.1, 'top' => 0.15, 'right' => 0.9, 'bottom' => 0.85],
			'2' => ['left' => 0.8, 'top' => 0.2, 'right' => 0.2, 'bottom' => 0.8], // inverted
			'0' => ['left' => 0.1, 'top' => 0.1, 'right' => 0.9, 'bottom' => 0.9], // invalid page 0
			'bad' => ['left' => 0.1, 'top' => 0.1, 'right' => 0.9, 'bottom' => 0.9],
		]);

		self::assertSame([
			'1' => ['left' => 0.1, 'top' => 0.15, 'right' => 0.9, 'bottom' => 0.85],
		], $sanitized);
	}

	public function testDetectInkBoundsFromGdImage(): void {
		if (!function_exists('imagecreatetruecolor')) {
			self::markTestSkipped('GD extension not available');
		}

		// Blank white page -> full page
		$blank = imagecreatetruecolor(100, 140);
		$white = imagecolorallocate($blank, 255, 255, 255);
		imagefilledrectangle($blank, 0, 0, 99, 139, $white);
		self::assertSame(PdfCropService::FULL_PAGE_CROP, PdfCropService::detectInkBoundsFromGdImage($blank));

		// Notation inside [20..79] x [28..111] -> raw [0.2..0.8] x [0.2..0.8] + 0.015 margin
		$score = imagecreatetruecolor(100, 140);
		$w = imagecolorallocate($score, 255, 255, 255);
		$black = imagecolorallocate($score, 10, 10, 10);
		imagefilledrectangle($score, 0, 0, 99, 139, $w);
		imagefilledrectangle($score, 20, 28, 79, 111, $black);

		$box = PdfCropService::detectInkBoundsFromGdImage($score);

		self::assertEqualsWithDelta(0.185, $box['left'], 0.001);
		self::assertEqualsWithDelta(0.185, $box['top'], 0.001);
		self::assertEqualsWithDelta(0.815, $box['right'], 0.001);
		self::assertEqualsWithDelta(0.815, $box['bottom'], 0.001);
	}

	public function testEnqueueCropJobAddsQueuedJobForFile(): void {
		$mapper = $this->createMock(InternalSongFileMapper::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$jobList = $this->createMock(IJobList::class);
		$logger = $this->createMock(LoggerInterface::class);

		$jobList->expects(self::once())
			->method('add')
			->with(ComputePdfCropBoxesJob::class, ['userId' => 'alice', 'fileId' => 42]);

		$service = new PdfCropService($mapper, $rootFolder, $jobList, $logger);
		$service->enqueueCropJob('alice', 42);
	}

	public function testSaveCropBoxesForFileMergesWithExistingAndPersistsHash(): void {
		$existingRecord = new InternalSongFile(
			id: 10,
			uuid: 'f-uuid',
			songId: 1,
			fileId: 42,
			pageCount: 3,
			fileSize: 1000,
			fileHash: 999888,
			type: InternalSongFile::FILE_TYPE_PDF,
			cropBoxes: json_encode(['1' => ['left' => 0.1, 'top' => 0.1, 'right' => 0.9, 'bottom' => 0.9]]),
			cropBoxesHash: 999888,
		);

		$mapper = $this->createMock(InternalSongFileMapper::class);
		$mapper->method('findByFileId')->with(42, false)->willReturn([$existingRecord]);
		$mapper->expects(self::once())
			->method('updateCropBoxesByFileId')
			->with(
				42,
				self::callback(function (string $json): bool {
					$decoded = json_decode($json, true);
					return isset($decoded['1'], $decoded['2'])
						&& $decoded['2']['left'] === 0.2;
				}),
				999888,
			);

		$file = $this->createMock(File::class);
		$folder = $this->createMock(IUserFolder::class);
		$folder->method('getById')->with(42)->willReturn([$file]);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('alice')->willReturn($folder);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$service = new PdfCropService(
			$mapper,
			$rootFolder,
			$this->createMock(IJobList::class),
			$this->createMock(LoggerInterface::class),
		);

		$saved = $service->saveCropBoxesForFile($user, 42, [
			'2' => ['left' => 0.2, 'top' => 0.2, 'right' => 0.8, 'bottom' => 0.8],
		]);

		self::assertNotNull($saved);
		self::assertArrayHasKey('1', $saved);
		self::assertArrayHasKey('2', $saved);
	}

	public function testStreamNodeToTempFileUsesStreamWithoutLoadingWholeFile(): void {
		$stream = fopen('php://memory', 'r+b');
		self::assertIsResource($stream);
		fwrite($stream, '%PDF-1.4 streamed-test-payload');
		rewind($stream);

		$file = $this->createMock(File::class);
		$file->expects(self::once())->method('fopen')->with('rb')->willReturn($stream);
		$file->expects(self::never())->method('getContent');

		$tmpPath = sys_get_temp_dir() . '/sheetmusic-stream-test-' . bin2hex(random_bytes(6)) . '.pdf';
		try {
			self::assertTrue(PdfCropService::streamNodeToTempFile($file, $tmpPath));
			self::assertSame('%PDF-1.4 streamed-test-payload', file_get_contents($tmpPath));
		} finally {
			@unlink($tmpPath);
		}
	}

	public function testEnqueueUncroppedPdfBatchEnqueuesEachCandidateWhenRasterToolAvailable(): void {
		$mapper = $this->createMock(InternalSongFileMapper::class);
		$mapper->expects(self::once())
			->method('findUncroppedPdfFiles')
			->with(2)
			->willReturn([
				['userId' => 'alice', 'fileId' => 101],
				['userId' => 'bob', 'fileId' => 202],
			]);

		$jobList = $this->createMock(IJobList::class);
		$enqueued = [];
		$jobList->expects(self::exactly(2))
			->method('add')
			->willReturnCallback(function (string $class, array $args) use (&$enqueued): void {
				$enqueued[] = [$class, $args];
			});

		$service = new class (
			$mapper,
			$this->createMock(IRootFolder::class),
			$jobList,
			$this->createMock(LoggerInterface::class),
		) extends PdfCropService {
			public function hasServerRasterTool(): bool {
				return true;
			}
		};

		self::assertSame(2, $service->enqueueUncroppedPdfBatch(2));
		self::assertSame([
			[ComputePdfCropBoxesJob::class, ['userId' => 'alice', 'fileId' => 101]],
			[ComputePdfCropBoxesJob::class, ['userId' => 'bob', 'fileId' => 202]],
		], $enqueued);
	}
}
