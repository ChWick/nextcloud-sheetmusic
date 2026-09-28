<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\MobileSheetsManager\Db\MobileSheetsArtist;
use OCA\MobileSheetsManager\Db\MobileSheetsBook;
use OCA\MobileSheetsManager\Db\MobileSheetsCollection;
use OCA\MobileSheetsManager\Db\MobileSheetsComposer;
use OCA\MobileSheetsManager\Db\MobileSheetsSong;
use OCA\MobileSheetsManager\Service\MobileSheetsPdf;
use OCA\MobileSheetsManager\Service\MobileSheetsPdfMetadata;
use PHPUnit\Framework\TestCase;

final class MobileSheetsPdfMetadataTest extends TestCase {
	public function testFieldsForMapsTheSongToTheInfoFieldNames(): void {
		$song = new MobileSheetsSong(
			42,
			'Blue Bossa',
			[],
			[new MobileSheetsComposer(1, 'Joe Henderson')],
			[new MobileSheetsBook(2, 'Real Book')],
			[new MobileSheetsCollection(3, 'Jazz')],
			[new MobileSheetsArtist(4, 'Miles Davis')],
		);

		self::assertSame([
			'Title' => 'Blue Bossa',
			'Author' => 'Joe Henderson',
			'Composer' => 'Joe Henderson',
			'Artist' => 'Miles Davis',
			'Books' => 'Real Book',
			'Collection' => 'Jazz',
		], MobileSheetsPdfMetadata::fieldsFor($song));
	}

	public function testFieldsForJoinsSeveralEntities(): void {
		$song = new MobileSheetsSong(
			1,
			'Duet',
			[],
			[new MobileSheetsComposer(1, 'A'), new MobileSheetsComposer(2, 'B')],
			[],
			[],
			[],
		);

		self::assertSame('A; B', MobileSheetsPdfMetadata::fieldsFor($song)['Composer']);
		self::assertSame('A; B', MobileSheetsPdfMetadata::fieldsFor($song)['Author']);
	}

	public function testFieldsForLeavesOutTheEntitiesTheSongDoesNotHave(): void {
		$song = new MobileSheetsSong(1, 'Solo', [], [], [], [], []);

		self::assertSame(['Title' => 'Solo'], MobileSheetsPdfMetadata::fieldsFor($song));
	}

	public function testWithInfoWritesTheFieldsIntoANewInfoDictionary(): void {
		$pdf = self::minimalPdf();
		$updated = MobileSheetsPdfMetadata::withInfo($pdf, ['Title' => 'Blue Bossa', 'Artist' => 'Miles Davis']);

		// The original revision is preserved byte for byte and the update is
		// appended, closing on a fresh %%EOF.
		self::assertStringStartsWith($pdf, $updated);
		self::assertStringEndsWith("%%EOF\n", $updated);
		self::assertStringContainsString('/Title ' . self::pdfText('Blue Bossa'), $updated);
		self::assertStringContainsString('/Artist ' . self::pdfText('Miles Davis'), $updated);
	}

	public function testWithInfoKeepsTheStampEntriesTheFileAlreadyHad(): void {
		$pdf = self::minimalPdf();
		$appended = substr(MobileSheetsPdfMetadata::withInfo($pdf, ['Title' => 'New']), strlen($pdf));

		// The new Info dictionary carries the creator the file already had ...
		self::assertStringContainsString('/Creator (Unit test)', $appended);
		// ... and its own title instead of the stale one.
		self::assertStringContainsString('/Title ' . self::pdfText('New'), $appended);
	}

	public function testWithInfoLeavesTheExistingPagesIntact(): void {
		$pdf = self::minimalPdf();
		$updated = MobileSheetsPdfMetadata::withInfo($pdf, ['Title' => 'Whatever']);

		self::assertSame(MobileSheetsPdf::pageCount($pdf), MobileSheetsPdf::pageCount($updated));
		self::assertGreaterThan(0, MobileSheetsPdf::pageCount($updated));
	}

	public function testWithInfoRejectsAFileThatIsNotAPdf(): void {
		$this->expectException(\RuntimeException::class);

		MobileSheetsPdfMetadata::withInfo('just some text', ['Title' => 'X']);
	}

	public function testWithInfoRejectsAnEncryptedPdf(): void {
		$this->expectException(\RuntimeException::class);

		MobileSheetsPdfMetadata::withInfo(self::minimalPdf(' /Encrypt 9 0 R'), ['Title' => 'X']);
	}

	public function testPrefillForReadsBackWhatWithInfoWrote(): void {
		$song = new MobileSheetsSong(
			42,
			'Blue Bossa',
			[],
			[new MobileSheetsComposer(1, 'Joe Henderson')],
			[new MobileSheetsBook(2, 'Real Book')],
			[new MobileSheetsCollection(3, 'Jazz')],
			[new MobileSheetsArtist(4, 'Miles Davis')],
		);

		$updated = MobileSheetsPdfMetadata::withInfo(self::minimalPdf(), MobileSheetsPdfMetadata::fieldsFor($song));

		self::assertSame([
			'title' => 'Blue Bossa',
			'composers' => ['Joe Henderson'],
			'books' => ['Real Book'],
			'collections' => ['Jazz'],
			'artists' => ['Miles Davis'],
		], MobileSheetsPdfMetadata::prefillFor($updated));
	}

	public function testPrefillForReadsTheStandardSpellingsAndSplitsTheNames(): void {
		// A file written by another tool: plain literal strings, "Author" for the
		// composers and "Album" for what MobileSheets calls a book.
		$pdf = self::minimalPdf('', '/Title (Imported) /Author (A; B) /Album (Real Book) /Artist (Solo) /Collection (Jazz)');

		self::assertSame([
			'title' => 'Imported',
			'composers' => ['A', 'B'],
			'books' => ['Real Book'],
			'collections' => ['Jazz'],
			'artists' => ['Solo'],
		], MobileSheetsPdfMetadata::prefillFor($pdf));
	}

	public function testPrefillForLeavesOutTheFieldsTheFileDoesNotCarry(): void {
		self::assertSame([
			'title' => 'Solo',
			'composers' => [],
			'books' => [],
			'collections' => [],
			'artists' => [],
		], MobileSheetsPdfMetadata::prefillFor(self::minimalPdf('', '/Title (Solo)')));
	}

	public function testPrefillForRejectsAFileThatIsNotAPdf(): void {
		$this->expectException(\RuntimeException::class);

		MobileSheetsPdfMetadata::prefillFor('just some text');
	}

	/**
	 * A tiny but structurally valid PDF: catalog, one page, a trailer and a startxref.
	 * Object 4 is its Info dictionary, whose entries $infoBody supplies.
	 */
	private static function minimalPdf(
		string $trailerExtra = '',
		string $infoBody = '/Title (Old title) /Creator (Unit test)',
	): string {
		$objects = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Count 1 /Kids [3 0 R] >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. '4 0 obj << ' . $infoBody . " >> endobj\n";

		$xrefOffset = strlen("%PDF-1.4\n" . $objects);

		return "%PDF-1.4\n"
			. $objects
			. "xref\n0 5\n0000000000 65535 f \n"
			. 'trailer << /Size 5 /Root 1 0 R /Info 4 0 R' . $trailerExtra . " >>\n"
			. "startxref\n{$xrefOffset}\n%%EOF\n";
	}

	/** The PDF string spelling of $text: UTF-16BE with a byte order mark. */
	private static function pdfText(string $text): string {
		return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'))) . '>';
	}
}