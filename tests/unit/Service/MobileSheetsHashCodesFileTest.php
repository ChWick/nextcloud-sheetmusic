<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\MobileSheetsManager\Service\MobileSheetsHashCodesFile;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the MobileSheets hash codes file reader/writer.
 *
 * The file holds four lines per entry (path, hash, modification time, size) and
 * is rewritten in place, so the tests focus on never losing or corrupting data.
 */
final class MobileSheetsHashCodesFileTest extends TestCase {
	/** @var list<string> */
	private array $temporaryFiles = [];

	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	protected function tearDown(): void {
		foreach ($this->temporaryFiles as $file) {
			foreach ([$file, $file . '.tmp'] as $candidate) {
				if (is_file($candidate)) {
					unlink($candidate);
				}
			}
		}
		$this->temporaryFiles = [];
	}

	/** Write a file with the exact given contents and return its path. */
	private function writeFile(string $contents): string {
		$path = tempnam(sys_get_temp_dir(), 'msm-hash-');
		self::assertNotFalse($path);
		file_put_contents($path, $contents);
		$this->temporaryFiles[] = $path;

		return $path;
	}

	/**
	 * Build the file contents for a list of entries, in MobileSheets' format.
	 *
	 * @param list<array{0: string, 1: string, 2: string, 3: string}> $entries
	 */
	private function contentsFor(array $entries, string $ending = "\n"): string {
		$lines = [];
		foreach ($entries as $entry) {
			foreach ($entry as $line) {
				$lines[] = $line;
			}
		}

		// No trailing newline, mirroring what MobileSheets writes.
		return implode($ending, $lines);
	}

	public function testDerivesThePathFromTheDatabasePath(): void {
		self::assertSame(
			'/x/Noten/mobilesheets_hashcodes.txt',
			MobileSheetsHashCodesFile::pathForDatabase('/x/Noten/mobilesheets.db'),
		);
		self::assertSame(
			'/a/b_hashcodes.txt',
			MobileSheetsHashCodesFile::pathForDatabase('/a/b.db'),
		);
		self::assertSame(
			'/no-extension_hashcodes.txt',
			MobileSheetsHashCodesFile::pathForDatabase('/no-extension'),
		);
	}

	public function testParsesEntriesInGroupsOfFour(): void {
		$path = $this->writeFile($this->contentsFor([
			['/dav/a.pdf', '561292453', '1789312275000', '839797'],
			['/dav/b.pdf', '-205429089', '1789249491000', '809049'],
		]));

		$entries = MobileSheetsHashCodesFile::parse($path, $this->logger)->entries();

		self::assertCount(2, $entries);
		self::assertSame('/dav/a.pdf', $entries[0]->path);
		self::assertSame('561292453', $entries[0]->hashCode);
		self::assertSame('1789312275000', $entries[0]->modifiedAt);
		self::assertSame('839797', $entries[0]->size);
		// Negative hashes must survive verbatim.
		self::assertSame('-205429089', $entries[1]->hashCode);
	}

	public function testParsesAFinalLineWithoutTrailingNewline(): void {
		$path = $this->writeFile($this->contentsFor([
			['/dav/a.pdf', '1', '2', '3'],
		]));

		self::assertCount(1, MobileSheetsHashCodesFile::parse($path, $this->logger)->entries());
	}

	public function testParsesATrailingNewlineWithoutAddingAnEntry(): void {
		$contents = $this->contentsFor([['/dav/a.pdf', '1', '2', '3']]) . "\n";
		$path = $this->writeFile($contents);

		self::assertCount(1, MobileSheetsHashCodesFile::parse($path, $this->logger)->entries());
	}

	public function testParsesWindowsLineEndings(): void {
		$path = $this->writeFile($this->contentsFor([
			['/dav/a.pdf', '1', '2', '3'],
			['/dav/b.pdf', '4', '5', '6'],
		], "\r\n"));

		$entries = MobileSheetsHashCodesFile::parse($path, $this->logger)->entries();

		self::assertCount(2, $entries);
		self::assertSame('/dav/a.pdf', $entries[0]->path);
		self::assertSame('/dav/b.pdf', $entries[1]->path);
	}

	public function testParsesAnEmptyFileAsNoEntries(): void {
		$path = $this->writeFile('');

		$file = MobileSheetsHashCodesFile::parse($path, $this->logger);

		self::assertSame([], $file->entries());
		self::assertFalse($file->isDirty());
	}

	public function testUpdatesAPathKeepingPositionAndValues(): void {
		$path = $this->writeFile($this->contentsFor([
			['/dav/keep.pdf', '1', '2', '3'],
			['/dav/old.pdf', '561292453', '1789312275000', '839797'],
			['/dav/other.pdf', '7', '8', '9'],
		]));
		$file = MobileSheetsHashCodesFile::parse($path, $this->logger);

		self::assertTrue($file->updatePath('/dav/old.pdf', '/dav/new.pdf'));

		self::assertTrue($file->isDirty());
		self::assertFalse($file->hasPath('/dav/old.pdf'));
		self::assertTrue($file->hasPath('/dav/new.pdf'));

		$entries = $file->entries();
		self::assertCount(3, $entries, 'no entry may be added or lost');
		self::assertSame('/dav/keep.pdf', $entries[0]->path);
		self::assertSame('/dav/new.pdf', $entries[1]->path, 'the entry must keep its position');
		self::assertSame('561292453', $entries[1]->hashCode);
		self::assertSame('1789312275000', $entries[1]->modifiedAt);
		self::assertSame('839797', $entries[1]->size);
		self::assertSame('/dav/other.pdf', $entries[2]->path);
	}

	public function testUpdatingAnUnknownPathChangesNothing(): void {
		$contents = $this->contentsFor([['/dav/a.pdf', '1', '2', '3']]);
		$path = $this->writeFile($contents);
		$file = MobileSheetsHashCodesFile::parse($path, $this->logger);

		self::assertFalse($file->updatePath('/dav/missing.pdf', '/dav/new.pdf'));
		self::assertFalse($file->isDirty());
		self::assertSame($contents, file_get_contents($path), 'the file must be unchanged');
	}

	public function testUpdatingAPathToItselfChangesNothing(): void {
		$path = $this->writeFile($this->contentsFor([['/dav/a.pdf', '1', '2', '3']]));
		$file = MobileSheetsHashCodesFile::parse($path, $this->logger);

		self::assertFalse($file->updatePath('/dav/a.pdf', '/dav/a.pdf'));
		self::assertFalse($file->isDirty());
	}

	public function testDropsAStaleEntryThatAlreadyClaimsTheTargetPath(): void {
		$path = $this->writeFile($this->contentsFor([
			['/dav/old.pdf', '111', '1', '1'],
			['/dav/new.pdf', '999', '9', '9'],
		]));
		$file = MobileSheetsHashCodesFile::parse($path, $this->logger);

		self::assertTrue($file->updatePath('/dav/old.pdf', '/dav/new.pdf'));

		$entries = $file->entries();
		self::assertCount(1, $entries, 'exactly one entry must remain per path');
		self::assertSame('/dav/new.pdf', $entries[0]->path);
		self::assertSame('111', $entries[0]->hashCode, 'the moved entry wins, not the stale one');
	}

	public function testRemovesAnEntry(): void {
		$path = $this->writeFile($this->contentsFor([
			['/dav/a.pdf', '1', '2', '3'],
			['/dav/b.pdf', '4', '5', '6'],
		]));
		$file = MobileSheetsHashCodesFile::parse($path, $this->logger);

		self::assertTrue($file->removePath('/dav/a.pdf'));

		$entries = $file->entries();
		self::assertCount(1, $entries);
		self::assertSame('/dav/b.pdf', $entries[0]->path);
		self::assertTrue($file->isDirty());

		self::assertFalse($file->removePath('/dav/missing.pdf'), 'removing an unknown path reports no change');
	}

	public function testSavingAnUnchangedFileDoesNotTouchIt(): void {
		$contents = $this->contentsFor([['/dav/a.pdf', '1', '2', '3']]);
		$path = $this->writeFile($contents);
		$file = MobileSheetsHashCodesFile::parse($path, $this->logger);

		$before = filemtime($path);
		$file->save();

		self::assertSame($contents, file_get_contents($path));
		self::assertFalse(is_file($path . '.tmp'), 'no temporary file may be left behind');
		self::assertSame($before, filemtime($path), 'an unclean save must not rewrite the file');
	}

	public function testSavesInTheMobileSheetsFormat(): void {
		$path = $this->writeFile($this->contentsFor([['/dav/old.pdf', '561292453', '1789312275000', '839797']]));
		$file = MobileSheetsHashCodesFile::parse($path, $this->logger);
		$file->updatePath('/dav/old.pdf', '/dav/new.pdf');
		$file->save();

		$expected = '/dav/new.pdf' . "\n" . '561292453' . "\n" . '1789312275000' . "\n" . '839797';
		self::assertSame($expected, file_get_contents($path));
		self::assertFalse(str_ends_with($expected, "\n"), 'MobileSheets writes no trailing newline');
		self::assertFalse(is_file($path . '.tmp'));
		self::assertFalse($file->isDirty(), 'a save clears the dirty flag');
	}

	public function testRoundTripsThroughSaveAndParse(): void {
		$path = $this->writeFile($this->contentsFor([
			['/dav/a.pdf', '561292453', '1789312275000', '839797'],
			['/dav/b.pdf', '-205429089', '1789249491000', '809049'],
			['/dav/c.pdf', '0', '0', '0'],
		]));
		$original = file_get_contents($path);

		$file = MobileSheetsHashCodesFile::parse($path, $this->logger);
		$file->updatePath('/dav/b.pdf', '/dav/moved.pdf');
		$file->save();

		$reparsed = MobileSheetsHashCodesFile::parse($path, $this->logger)->entries();
		self::assertCount(3, $reparsed);
		self::assertSame('/dav/moved.pdf', $reparsed[1]->path);
		self::assertSame('-205429089', $reparsed[1]->hashCode);

		// Everything except the one path must be byte-identical.
		self::assertSame(str_replace('/dav/b.pdf', '/dav/moved.pdf', $original), file_get_contents($path));
	}

	public function testForDatabaseReturnsNullWhenTheFileIsMissing(): void {
		$directory = sys_get_temp_dir() . '/msm-none-' . bin2hex(random_bytes(4));
		mkdir($directory);
		try {
			self::assertNull(
				MobileSheetsHashCodesFile::forDatabase("$directory/library.db", $this->logger),
			);
		} finally {
			rmdir($directory);
		}
	}

	public function testForDatabaseParsesTheFileNextToTheDatabase(): void {
		$directory = sys_get_temp_dir() . '/msm-lib-' . bin2hex(random_bytes(4));
		mkdir($directory);
		$hashFile = "$directory/library_hashcodes.txt";

		try {
			file_put_contents(
				$hashFile,
				$this->contentsFor([['/dav/a.pdf', '1', '2', '3']]),
			);

			$file = MobileSheetsHashCodesFile::forDatabase("$directory/library.db", $this->logger);

			self::assertNotNull($file);
			self::assertCount(1, $file->entries());
		} finally {
			if (is_file($hashFile)) {
				unlink($hashFile);
			}
			rmdir($directory);
		}
	}

	public function testRefusesAMalformedFileInsteadOfLosingData(): void {
		// 5 lines is not a multiple of 4: writing it back would drop the last line.
		$contents = $this->contentsFor([['/dav/a.pdf', '1', '2', '3']]) . "\n/dav/orphan.pdf";
		$path = $this->writeFile($contents);

		try {
			MobileSheetsHashCodesFile::parse($path, $this->logger);
			self::fail('Expected a RuntimeException for a malformed file');
		} catch (\RuntimeException $exception) {
			self::assertStringContainsString('malformed', $exception->getMessage());
		}

		self::assertSame($contents, file_get_contents($path), 'the file must be left untouched');
	}

	public function testUpdateFactsRefreshesTheEntryOfARewrittenFile(): void {
		$path = $this->writeFile($this->contentsFor([
			['/dav/a.pdf', '111', '222', '333'],
			['/dav/b.pdf', '444', '555', '666'],
		]));

		$file = MobileSheetsHashCodesFile::parse($path, $this->logger);
		self::assertTrue($file->updateFacts('/dav/a.pdf', '-1847195323', '1790676483000', '79948'));
		self::assertTrue($file->isDirty());
		$file->save();

		$entries = MobileSheetsHashCodesFile::parse($path, $this->logger)->entries();
		self::assertSame('/dav/a.pdf', $entries[0]->path);
		self::assertSame('-1847195323', $entries[0]->hashCode);
		self::assertSame('1790676483000', $entries[0]->modifiedAt);
		self::assertSame('79948', $entries[0]->size);
		// The entry of the untouched file is kept as it was.
		self::assertSame('444', $entries[1]->hashCode);
		self::assertSame('555', $entries[1]->modifiedAt);
		self::assertSame('666', $entries[1]->size);
	}

	public function testUpdateFactsLeavesTheFileAloneForAnUnknownPath(): void {
		$contents = $this->contentsFor([['/dav/a.pdf', '111', '222', '333']]);
		$path = $this->writeFile($contents);

		$file = MobileSheetsHashCodesFile::parse($path, $this->logger);
		self::assertFalse($file->updateFacts('/dav/other.pdf', '1', '2', '3'));
		self::assertFalse($file->isDirty());

		self::assertSame($contents, file_get_contents($path), 'the file must be left untouched');
	}
}
