<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Tests\Unit\Service;

use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\LibraryOrphanScanner;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsComposer;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFile;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsKey;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsYear;
use PHPUnit\Framework\TestCase;
use TestSupport\RealMobileSheetsTestTrait;

final class LibraryOrphanScannerTest extends TestCase {
	use RealMobileSheetsTestTrait;

	private LibraryOrphanScanner $scanner;
	private FileManager $fileManager;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpRealMobileSheetsEnvironment();
		$logger = new \Psr\Log\NullLogger();
		$libraryService = new \OCA\SheetMusic\Service\LibraryService($this->libraryMapper, $this->rootFolder, $logger, exporter: $this->exporter);
		$this->fileManager = new FileManager(
			$this->libraryMapper,
			$this->rootFolder,
			$this->pathResolver,
			$logger,
			$libraryService,
			$this->songFileMapper,
			$this->songMapper,
		);
		$this->scanner = new LibraryOrphanScanner(
			$this->fileManager,
			$this->rootFolder,
			$this->pathResolver,
		);
	}

	protected function tearDown(): void {
		$this->tearDownRealMobileSheetsEnvironment();
		parent::tearDown();
	}

	public function testStringMatchesPath(): void {
		self::assertTrue($this->scanner->stringMatchesPath('Sonata', 'Brosig/Sonata_in_G.pdf'));
		self::assertTrue($this->scanner->stringMatchesPath('Sonata in G', 'Brosig/Sonata_in_G.pdf'));
		self::assertTrue($this->scanner->stringMatchesPath('Sonata_in_G', 'Brosig/Sonata in G.pdf'));
		self::assertFalse($this->scanner->stringMatchesPath('Prelude', 'Brosig/Sonata_in_G.pdf'));
		self::assertFalse($this->scanner->stringMatchesPath('', 'Brosig/Sonata_in_G.pdf'));
	}

	public function testFindOrphanFileCandidateSongMatchesTier1MissingFile(): void {
		$songA = new MobileSheetsSong(
			id: 1,
			title: 'Nocturne in C minor',
			files: [new MobileSheetsFile(id: 10, path: '/remote.php/dav/files/admin/Chopin/missing.pdf')],
		);
		$songB = new MobileSheetsSong(
			id: 2,
			title: 'Waltz in A minor',
			files: [new MobileSheetsFile(id: 20, path: '/remote.php/dav/files/admin/Chopin/waltz.pdf')],
		);

		// Song 1 has missing file ID 1
		$candidate = $this->scanner->findOrphanFileCandidateSong(
			'Chopin/Nocturne_in_C_minor.pdf',
			[$songA, $songB],
			[1 => true],
		);

		self::assertNotNull($candidate);
		self::assertSame(1, $candidate->id);
		self::assertSame('Nocturne in C minor', $candidate->title);
	}

	public function testFindOrphanFileCandidateSongMatchesTier2NoFiles(): void {
		$songWithNoFiles = new MobileSheetsSong(
			id: 5,
			title: 'Etude Op 10 No 1',
			files: [],
		);

		$candidate = $this->scanner->findOrphanFileCandidateSong(
			'Chopin/Etude_Op_10_No_1.pdf',
			[$songWithNoFiles],
			[],
		);

		self::assertNotNull($candidate);
		self::assertSame(5, $candidate->id);
	}

	public function testFindOrphanFileCandidateSongFiltersByComposerOnDisambiguation(): void {
		$songBach = new MobileSheetsSong(
			id: 10,
			title: 'Invention No 1',
			files: [],
			composers: [new MobileSheetsComposer(id: 1, name: 'Bach')],
		);
		$songMozart = new MobileSheetsSong(
			id: 11,
			title: 'Invention No 1',
			files: [],
			composers: [new MobileSheetsComposer(id: 2, name: 'Mozart')],
		);

		$candidate = $this->scanner->findOrphanFileCandidateSong(
			'Bach/Invention_No_1.pdf',
			[$songBach, $songMozart],
			[],
		);

		self::assertNotNull($candidate);
		self::assertSame(10, $candidate->id);
	}

	public function testFindOrphanFileCandidateSongDisambiguatesByKey(): void {
		$songD = new MobileSheetsSong(
			id: 20,
			title: 'Sonata',
			files: [],
			composers: [new MobileSheetsComposer(id: 1, name: 'Beethoven')],
			keys: [new MobileSheetsKey(id: 1, name: 'D')],
		);
		$songF = new MobileSheetsSong(
			id: 21,
			title: 'Sonata',
			files: [],
			composers: [new MobileSheetsComposer(id: 1, name: 'Beethoven')],
			keys: [new MobileSheetsKey(id: 2, name: 'F')],
		);

		$candidate = $this->scanner->findOrphanFileCandidateSong(
			'Beethoven/Beethoven - Sonata in D.pdf',
			[$songD, $songF],
			[],
		);

		self::assertNotNull($candidate);
		self::assertSame(20, $candidate->id);
	}

	public function testFindOrphanFileCandidateSongDisambiguatesByOpus(): void {
		$songOp10 = new MobileSheetsSong(
			id: 30,
			title: 'Sonata',
			opus: 'Op. 10 No. 3',
			files: [],
			composers: [new MobileSheetsComposer(id: 1, name: 'Beethoven')],
		);
		$songOp13 = new MobileSheetsSong(
			id: 31,
			title: 'Sonata',
			opus: 'Op. 13',
			files: [],
			composers: [new MobileSheetsComposer(id: 1, name: 'Beethoven')],
		);

		$candidate = $this->scanner->findOrphanFileCandidateSong(
			'Beethoven/Beethoven - Sonata, Op. 10 No. 3.pdf',
			[$songOp10, $songOp13],
			[],
		);

		self::assertNotNull($candidate);
		self::assertSame(30, $candidate->id);
	}

	public function testFindOrphanFileCandidateSongDisambiguatesByCatalogNumber(): void {
		$songKV545 = new MobileSheetsSong(
			id: 40,
			title: 'Sonata',
			catalogNumber: 'KV 545',
			files: [],
			composers: [new MobileSheetsComposer(id: 2, name: 'Mozart')],
		);
		$songKV331 = new MobileSheetsSong(
			id: 41,
			title: 'Sonata',
			catalogNumber: 'KV 331',
			files: [],
			composers: [new MobileSheetsComposer(id: 2, name: 'Mozart')],
		);

		$candidate = $this->scanner->findOrphanFileCandidateSong(
			'Mozart/Mozart - Sonata, KV 545.pdf',
			[$songKV545, $songKV331],
			[],
		);

		self::assertNotNull($candidate);
		self::assertSame(40, $candidate->id);
	}

	public function testFindOrphanFileCandidateSongDisambiguatesByYear(): void {
		$song1958 = new MobileSheetsSong(
			id: 50,
			title: 'Autumn Leaves',
			files: [],
			composers: [new MobileSheetsComposer(id: 3, name: 'Kosma')],
			years: [new MobileSheetsYear(id: 1, year: 1958)],
		);
		$song1980 = new MobileSheetsSong(
			id: 51,
			title: 'Autumn Leaves',
			files: [],
			composers: [new MobileSheetsComposer(id: 3, name: 'Kosma')],
			years: [new MobileSheetsYear(id: 2, year: 1980)],
		);

		$candidate = $this->scanner->findOrphanFileCandidateSong(
			'Kosma/Kosma - Autumn Leaves (1958).pdf',
			[$song1958, $song1980],
			[],
		);

		self::assertNotNull($candidate);
		self::assertSame(50, $candidate->id);
	}
}
