<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsArtist;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsBook;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsCollection;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsComposer;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdfMetadata;
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

	public function testWithInfoRejectsAnEncryptedPdfItCannotHandle(): void {
		// The Encrypt dictionary is missing entirely, so no key can be derived.
		$this->expectException(\RuntimeException::class);

		MobileSheetsPdfMetadata::withInfo(self::minimalPdf(' /Encrypt 9 0 R'), ['Title' => 'X']);
	}

	public function testWithInfoWritesTheFieldsIntoAnEncryptedPdf(): void {
		$pdf = self::encryptedPdf();
		$updated = MobileSheetsPdfMetadata::withInfo($pdf, ['Title' => 'New title', 'Artist' => 'Miles Davis']);

		// The original revision is preserved byte for byte ...
		self::assertStringStartsWith($pdf, $updated);
		// ... and the read-back proves the values were encrypted for the object
		// they were written into.
		self::assertSame([
			'title' => 'New title',
			'composers' => [],
			'books' => [],
			'collections' => [],
			'artists' => ['Miles Davis'],
		], MobileSheetsPdfMetadata::prefillFor($updated));
	}

	public function testWithInfoKeepsTheStampEntriesOfAnEncryptedPdf(): void {
		$pdf = self::encryptedPdf();
		$appended = substr(MobileSheetsPdfMetadata::withInfo($pdf, ['Title' => 'New']), strlen($pdf));

		// The creator the file already had survives verbatim - the update
		// redefines the very Info object its strings were encrypted for, so
		// their key does not change - while the title is written anew. Both
		// spellings come from the test's own key derivation, so they cross-check
		// the keys the implementation derives.
		self::assertStringContainsString('/Creator ' . self::fixtureString('Unit test', 2, false), $appended);
		self::assertStringContainsString('/Title ' . self::fixtureString('New', 2, false), $appended);
	}

	public function testPrefillForReadsTheFieldsOfAnEncryptedPdf(): void {
		$pdf = self::encryptedPdf([
			'Title' => 'Imported',
			'Composer' => 'A; B',
			'Album' => 'Real Book',
			'Artist' => 'Solo',
		]);

		self::assertSame([
			'title' => 'Imported',
			'composers' => ['A', 'B'],
			'books' => ['Real Book'],
			'collections' => [],
			'artists' => ['Solo'],
		], MobileSheetsPdfMetadata::prefillFor($pdf));
	}

	public function testWithInfoRejectsAnEncryptedPdfWithAUserPassword(): void {
		// A /U the empty user password does not compute to: the file needs a
		// password we do not have, so its metadata must not be touched.
		$this->expectException(\RuntimeException::class);

		MobileSheetsPdfMetadata::withInfo(self::encryptedPdf(['Title' => 'X'], 2, false, str_repeat("\xFF", 32)), ['Title' => 'Y']);
	}

	public function testWithInfoWritesTheFieldsIntoAnAesEncryptedPdf(): void {
		$pdf = self::encryptedPdf(['Title' => 'Old title'], 4, true);
		$updated = MobileSheetsPdfMetadata::withInfo($pdf, ['Title' => 'New title']);

		self::assertSame('New title', MobileSheetsPdfMetadata::prefillFor($updated)['title']);
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

	/** A file written from a song compares equal to that song afterwards. */
	public function testMatchesSongForAFileWrittenFromTheSong(): void {
		$song = new MobileSheetsSong(
			42,
			'Blue Bossa',
			[],
			[new MobileSheetsComposer(1, 'Joe Henderson')],
			[new MobileSheetsBook(2, 'Real Book')],
			[new MobileSheetsCollection(3, 'Jazz')],
			[new MobileSheetsArtist(4, 'Miles Davis')],
		);
		$content = MobileSheetsPdfMetadata::withInfo(
			self::minimalPdf(),
			MobileSheetsPdfMetadata::fieldsFor($song),
		);

		self::assertTrue(MobileSheetsPdfMetadata::matchesSong($content, $song));
	}

	/**
	 * The comparison normalizes both sides like the round trip does, so names
	 * that repeat in the song match the deduplicated document properties.
	 */
	public function testMatchesSongNormalizesNamesLikeTheRoundTrip(): void {
		$song = new MobileSheetsSong(
			42,
			'Air on the G String',
			[],
			[new MobileSheetsComposer(1, 'J.S. Bach'), new MobileSheetsComposer(2, 'J.S. Bach')],
		);
		$content = MobileSheetsPdfMetadata::withInfo(
			self::minimalPdf(),
			MobileSheetsPdfMetadata::fieldsFor($song),
		);

		self::assertTrue(MobileSheetsPdfMetadata::matchesSong($content, $song));
	}

	/** The old properties of the fixture are not the song's, so this is false. */
	public function testMatchesSongIsFalseWhenTheFileCarriesOtherProperties(): void {
		$song = new MobileSheetsSong(42, 'Blue Bossa', []);

		self::assertFalse(MobileSheetsPdfMetadata::matchesSong(self::minimalPdf(), $song));
	}

	public function testMismatchedFieldsComparesAgainstTheExactGeneratedInfoFields(): void {
		$song = new MobileSheetsSong(
			42,
			'Blue Bossa',
			[],
			[new MobileSheetsComposer(1, 'Joe Henderson')],
		);
		$expected = MobileSheetsPdfMetadata::withInfo(
			self::minimalPdf(),
			MobileSheetsPdfMetadata::fieldsFor($song),
		);
		$alternate = MobileSheetsPdfMetadata::withInfo(
			self::minimalPdf(),
			['Title' => 'Blue Bossa', 'Author' => 'Joe Henderson'],
		);

		self::assertSame([], MobileSheetsPdfMetadata::mismatchedFields($expected, $song));
		self::assertSame(['Composer'], MobileSheetsPdfMetadata::mismatchedFields($alternate, $song));
	}

	public function testMatchesSongRejectsAFileThatIsNotAPdf(): void {
		$this->expectException(\RuntimeException::class);

		MobileSheetsPdfMetadata::matchesSong('just some text', new MobileSheetsSong(42, 'Blue Bossa', []));
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

	/** The password padding string of the PDF specification, as hex. */
	private const FIXTURE_PADDING = '28BF4E5E4E758A4164004E56FFFA01082E2E00B6D0683E802F0CA9FE6453697A';

	/** The permissions fixture files state, like the real ones: -44. */
	private const FIXTURE_PERMISSIONS = 196;

	/**
	 * A tiny encrypted PDF in the style of the "restrictions" protection of
	 * published files: the minimal PDF plus an Encrypt dictionary, with the
	 * Info strings encrypted for the object they live in. Revision 2 gives RC4
	 * 40 bit, revision 4 with $aes gives AES-128 - both with the empty user
	 * password, unless $userKey states a /U the empty password does not
	 * compute to.
	 *
	 * @param array<string, string> $info the plaintext Info entries to write
	 */
	private static function encryptedPdf(
		array $info = ['Title' => 'Old title', 'Creator' => 'Unit test'],
		int $revision = 2,
		bool $aes = false,
		?string $userKey = null,
	): string {
		$userKey ??= self::fixtureUserKey(self::fixtureKey($revision, $aes), $revision);

		$body = '';
		foreach ($info as $entry => $value) {
			$body .= '/' . $entry . ' ' . self::fixtureString($value, $revision, $aes) . ' ';
		}

		$encryption = '<</Filter/Standard/R ' . $revision
			. '/V ' . ($revision === 2 ? 1 : 2)
			. '/O<' . strtoupper(bin2hex(self::fixtureOwnerKey())) . '>'
			. '/U<' . strtoupper(bin2hex($userKey)) . '>'
			. '/P ' . self::FIXTURE_PERMISSIONS
			. ($revision === 2
				? ''
				: '/Length 128 /CF<</StdCF<</Type/Crypt/CFM/' . ($aes ? 'AESV2' : 'V2') . '>>>> /StmF /StdCF /StrF /StdCF')
			. '>>';

		$objects = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Count 1 /Kids [3 0 R] >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
			. '4 0 obj << ' . $body . ">> endobj\n"
			. '5 0 obj ' . $encryption . " endobj\n";

		$xrefOffset = strlen("%PDF-1.4\n" . $objects);

		return "%PDF-1.4\n"
			. $objects
			. "xref\n0 6\n0000000000 65535 f \n"
			. 'trailer << /Size 6 /Root 1 0 R /Info 4 0 R'
			. ' /ID[<' . strtoupper(bin2hex(self::fixtureId())) . '><' . strtoupper(bin2hex(self::fixtureId())) . '>]'
			. " /Encrypt 5 0 R >>\n"
			. "startxref\n{$xrefOffset}\n%%EOF\n";
	}

	/** The owner key fixtures state; arbitrary, but fixed for determinism. */
	private static function fixtureOwnerKey(): string {
		return str_repeat("\x0B", 32);
	}

	/** The first element of the /ID array fixtures state. */
	private static function fixtureId(): string {
		return str_repeat("\x0C", 16);
	}

	/**
	 * The file key the fixture derives from its empty user password - Algorithm
	 * 2 of the specification, spelled out here on purpose, so the tests check
	 * the implementation against the specification instead of against itself.
	 */
	private static function fixtureKey(int $revision, bool $aes): string {
		$keyLength = $revision === 2 ? 5 : 16;
		$key = substr(md5(
			hex2bin(self::FIXTURE_PADDING) . self::fixtureOwnerKey()
			. pack('V', self::FIXTURE_PERMISSIONS) . self::fixtureId(),
			true,
		), 0, $keyLength);
		if ($revision >= 3) {
			for ($i = 0; $i < 50; $i++) {
				$key = substr(md5($key, true), 0, $keyLength);
			}
		}

		return $key;
	}

	/** The /U entry the empty user password computes to - Algorithm 5. */
	private static function fixtureUserKey(string $key, int $revision): string {
		$padding = hex2bin(self::FIXTURE_PADDING);
		if ($revision === 2) {
			return self::fixtureRc4($key, $padding);
		}

		$userKey = self::fixtureRc4($key, md5($padding . self::fixtureId(), true));
		for ($i = 1; $i <= 19; $i++) {
			$userKey = self::fixtureRc4($key ^ str_repeat(chr($i), strlen($key)), $userKey);
		}

		return $userKey;
	}

	/** The key of fixture object 4 - Algorithm 3.1. */
	private static function fixtureObjectKey(string $key, bool $aes): string {
		$hash = md5($key . substr(pack('V', 4), 0, 3) . "\x00\x00" . ($aes ? "\x73\x41\x6C\x54" : ''), true);

		return substr($hash, 0, min(strlen($key) + 5, 16));
	}

	/** The encrypted PDF string spelling of $text for fixture object 4. */
	private static function fixtureString(string $text, int $revision, bool $aes): string {
		$key = self::fixtureObjectKey(self::fixtureKey($revision, $aes), $aes);
		$bytes = "\xFE\xFF" . mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
		if ($aes) {
			// A fixed initialization vector keeps the fixtures deterministic.
			$iv = str_repeat("\x0D", 16);
			$bytes = $iv . openssl_encrypt($bytes, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
		} else {
			$bytes = self::fixtureRc4($key, $bytes);
		}

		return '<' . strtoupper(bin2hex($bytes)) . '>';
	}

	/** RC4, the cipher of the fixtures. */
	private static function fixtureRc4(string $key, string $data): string {
		$schedule = range(0, 255);
		$keyBytes = array_values(unpack('C*', $key));
		$j = 0;
		foreach ($schedule as $i => $unused) {
			$j = ($j + $schedule[$i] + $keyBytes[$i % count($keyBytes)]) & 0xFF;
			[$schedule[$i], $schedule[$j]] = [$schedule[$j], $schedule[$i]];
		}

		$i = 0;
		$j = 0;
		$out = '';
		foreach (unpack('C*', $data) as $byte) {
			$i = ($i + 1) & 0xFF;
			$j = ($j + $schedule[$i]) & 0xFF;
			[$schedule[$i], $schedule[$j]] = [$schedule[$j], $schedule[$i]];
			$out .= chr($byte ^ $schedule[($schedule[$i] + $schedule[$j]) & 0xFF]);
		}

		return $out;
	}
}
