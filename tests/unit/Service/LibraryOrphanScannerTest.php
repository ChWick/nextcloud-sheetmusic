<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Tests\Unit\Service;

use OCA\SheetMusic\Db\MobileSheetsComposer;
use OCA\SheetMusic\Db\MobileSheetsFile;
use OCA\SheetMusic\Db\MobileSheetsSong;
use OCA\SheetMusic\Service\FileManager;
use OCA\SheetMusic\Service\LibraryOrphanScanner;
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
}
