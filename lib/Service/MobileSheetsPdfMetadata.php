<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Service;

use OCA\MobileSheetsManager\Db\MobileSheetsSong;

/**
 * Writes the metadata MobileSheets reads on import into a PDF.
 *
 * When a PDF is imported, MobileSheets builds the new song from the file's
 * document properties (its Info dictionary) and its XMP packet. The mapping is
 * straightforward for the standard fields - Title -> Title, Author -> Composer,
 * Subject -> Genre - while the remaining fields (Composer, Artist, Books,
 * Collection, ...) travel as custom entries that MobileSheets reads back by
 * name. Writing them here means a file this manager has touched can be handed
 * to MobileSheets again and yields the same song.
 *
 * The metadata is added as an incremental update: a new revision carrying a
 * fresh Info dictionary is appended and the trailer points at it. The existing
 * pages, objects and unrelated document properties therefore stay byte for
 * byte untouched, which is the only safe way to change metadata without a full
 * PDF rewrite.
 */
class MobileSheetsPdfMetadata {
	/**
	 * Info dictionary entries that are not ours but are kept, so a song edit
	 * does not throw away the tool and date stamps a PDF already carried.
	 */
	private const PRESERVED_INFO_KEYS = ['Creator', 'Producer', 'CreationDate', 'ModDate'];

	/**
	 * Info dictionary keys read back for a new song: the names fieldsFor()
	 * writes, plus "Album" - the field MobileSheets maps "Books" to on import,
	 * so a file that came back out of MobileSheets is read just as well.
	 */
	private const READ_KEYS = ['Title', 'Author', 'Composer', 'Artist', 'Books', 'Album', 'Collection'];

	private function __construct() {
	}

	/**
	 * Info dictionary entries describing a song, in the field names MobileSheets
	 * looks for. Values are joined with "; " when a song has several composers,
	 * books, artists or collections, because the document properties hold one
	 * value per field.
	 *
	 * Author and Composer both carry the composers: Author is the standardized
	 * spelling (mapped to Composer on import) while Composer is the custom one,
	 * and either is read back depending on how the file was written.
	 *
	 * @return array<string, string> Info dictionary key => value
	 */
	public static function fieldsFor(MobileSheetsSong $song): array {
		$fields = ['Title' => $song->title];

		$composers = self::join(array_map(static fn ($composer): string => $composer->name, $song->composers));
		if ($composers !== '') {
			$fields['Author'] = $composers;
			$fields['Composer'] = $composers;
		}

		$artists = self::join(array_map(static fn ($artist): string => $artist->name, $song->artists));
		if ($artists !== '') {
			$fields['Artist'] = $artists;
		}

		$books = self::join(array_map(static fn ($book): string => $book->title, $song->books));
		if ($books !== '') {
			// "Books" is the name the field travels under; MobileSheets maps it
			// to the song's album on import.
			$fields['Books'] = $books;
		}

		$collections = self::join(array_map(static fn ($collection): string => $collection->name, $song->collections));
		if ($collections !== '') {
			$fields['Collection'] = $collections;
		}

		return $fields;
	}

	/**
	 * Join the entity names, skipping the empty ones.
	 *
	 * @param list<string> $values
	 */
	private static function join(array $values): string {
		return implode('; ', array_values(array_filter($values, static fn (string $value): bool => $value !== '')));
	}

	/**
	 * The PDF with $info written into its Info dictionary.
	 *
	 * @param array<string, string> $info Info dictionary key => value
	 * @throws \RuntimeException when the file is not an unencrypted PDF whose
	 *                          trailer can be read
	 */
	public static function withInfo(string $content, array $info): string {
		$trailer = self::trailer($content);
		$body = self::mergedBody(self::infoBody($content, $trailer), $info);

		// The first free object number is the trailer's /Size, so a fresh Info
		// object never collides with one the file already defines.
		$objectNumber = $trailer['size'];
		$objectOffset = strlen($content);
		$object = $objectNumber . " 0 obj\n<<\n" . $body . ">>\nendobj\n";

		$trailerOffset = $objectOffset + strlen($object);
		$entry = sprintf('%010d 00000 n ', $objectOffset) . "\n";
		$dict = '<< /Size ' . ($objectNumber + 1)
			. ' /Root ' . $trailer['root']
			. ' /Info ' . $objectNumber . ' 0 R'
			. ($trailer['id'] !== null ? ' /ID ' . $trailer['id'] : '')
			. ' /Prev ' . $trailer['xrefOffset']
			. ' >>';

		$update = "xref\n{$objectNumber} 1\n{$entry}trailer\n{$dict}\n"
			. "startxref\n{$trailerOffset}\n%%EOF\n";

		return $content . $object . $update;
	}

	/**
	 * The Info dictionary a PDF already carries, or null when it has none that
	 * can be read (missing, or hidden in a compressed object stream).
	 *
	 * @param array{infoNumber: ?int, infoGeneration: int} $trailer
	 */
	private static function infoBody(string $content, array $trailer): ?string {
		if ($trailer['infoNumber'] === null) {
			return null;
		}

		// The object may have been redefined by an earlier incremental update,
		// in which case the last definition is the one in force.
		$pattern = '#(?<![0-9])' . $trailer['infoNumber'] . '\s+' . $trailer['infoGeneration'] . '\s+obj\b#';
		if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE) === false || $matches[0] === []) {
			return null;
		}

		$offset = (int)end($matches[0])[1];
		if (preg_match('#\d+\s+\d+\s+obj\s*<<(.*?)>>#s', substr($content, $offset), $dict) !== 1) {
			return null;
		}

		return $dict[1];
	}

	/**
	 * The Info dictionary body to write: our fields, keeping the stamp entries
	 * the file already had so an edit does not erase them.
	 *
	 * @param array<string, string> $info
	 */
	private static function mergedBody(?string $existing, array $info): string {
		$kept = '';
		if ($existing !== null) {
			foreach (self::PRESERVED_INFO_KEYS as $key) {
				if (array_key_exists($key, $info)) {
					continue;
				}
				$pattern = '#/' . preg_quote($key, '#') . '\s*(\((?:[^()\\\\]|\\\\.)*\)|<[^>]*>)#';
				if (preg_match($pattern, $existing, $match) === 1) {
					$kept .= '/' . $key . ' ' . $match[1] . "\n";
				}
			}
		}

		$written = '';
		foreach ($info as $key => $value) {
			$written .= '/' . $key . ' ' . self::pdfText((string)$value) . "\n";
		}

		return $kept . $written;
	}

	/**
	 * A PDF string for arbitrary text: UTF-16BE with a byte order mark, the
	 * encoding a PDF reader must accept for non-ASCII document properties.
	 */
	private static function pdfText(string $text): string {
		$utf16 = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');

		return '<FEFF' . strtoupper(bin2hex($utf16)) . '>';
	}

	/**
	 * The trailer the file currently ends on, which tells us where to append the
	 * incremental update and which entries to carry over.
	 *
	 * @return array{xrefOffset: int, size: int, root: string, info: ?string, infoNumber: ?int, infoGeneration: int, id: ?string}
	 * @throws \RuntimeException when the file is not an unencrypted, readable PDF
	 */
	private static function trailer(string $content): array {
		if (preg_match_all('#startxref\s+(\d+)#', $content, $matches) === false || $matches[1] === []) {
			throw new \RuntimeException('Not a PDF: no startxref');
		}
		// The last startxref points at the cross-reference section in force.
		$xrefOffset = (int)end($matches[1]);
		$region = substr($content, $xrefOffset);

		if (preg_match('#/Size\s+(\d+)#', $region, $size) !== 1) {
			throw new \RuntimeException('Not a readable PDF: no trailer /Size');
		}
		if (preg_match('#/Root\s+(\d+)\s+(\d+)\s+R#', $region, $root) !== 1) {
			throw new \RuntimeException('Not a readable PDF: no trailer /Root');
		}
		if (preg_match('#/Encrypt\b#', $region) === 1) {
			throw new \RuntimeException('Encrypted PDF: metadata cannot be updated');
		}

		$info = null;
		$infoNumber = null;
		$infoGeneration = 0;
		if (preg_match('#/Info\s+(\d+)\s+(\d+)\s+R#', $region, $infoMatch) === 1) {
			$info = $infoMatch[1] . ' ' . $infoMatch[2] . ' R';
			$infoNumber = (int)$infoMatch[1];
			$infoGeneration = (int)$infoMatch[2];
		}

		$id = null;
		if (preg_match('#/ID\s*(\[[^\]]*\])#s', $region, $idMatch) === 1) {
			$id = $idMatch[1];
		}

		return [
			'xrefOffset' => $xrefOffset,
			'size' => (int)$size[1],
			'root' => $root[1] . ' ' . $root[2] . ' R',
			'info' => $info,
			'infoNumber' => $infoNumber,
			'infoGeneration' => $infoGeneration,
			'id' => $id,
		];
	}

	/**
	 * The information a new song can be prefilled with, read from a PDF's
	 * document properties. The values a song holds several of - composers,
	 * books, collections, artists - come back as one entry per name, because
	 * that is how the editor turns them into entities.
	 *
	 * @return array{title: string, composers: list<string>, books: list<string>, collections: list<string>, artists: list<string>}
	 * @throws \RuntimeException when the file is not a readable PDF
	 */
	public static function prefillFor(string $content): array {
		$fields = self::readFields($content);

		// Author and Composer both spell the composers; a file carries one or
		// the other depending on who wrote it, so both are read back together.
		$composers = self::splitNames(($fields['Composer'] ?? '') . ';' . ($fields['Author'] ?? ''));

		return [
			'title' => $fields['Title'] ?? '',
			'composers' => $composers,
			// "Books" travels to the album on import, so either name is a book.
			'books' => self::splitNames($fields['Books'] ?? $fields['Album'] ?? ''),
			'collections' => self::splitNames($fields['Collection'] ?? ''),
			'artists' => self::splitNames($fields['Artist'] ?? ''),
		];
	}

	/**
	 * Split a document property holding several names into the individual ones,
	 * dropping blanks and repeats.
	 *
	 * @return list<string>
	 */
	private static function splitNames(string $value): array {
		$names = [];
		foreach (preg_split('/\s*;\s*/', $value) ?: [] as $part) {
			$part = trim($part);
			if ($part !== '' && !in_array($part, $names, true)) {
				$names[] = $part;
			}
		}

		return $names;
	}

	/**
	 * The Info dictionary entries of a PDF, decoded to text.
	 *
	 * @return array<string, string> Info dictionary key => value
	 */
	private static function readFields(string $content): array {
		$body = self::infoBody($content, self::trailer($content));
		if ($body === null) {
			return [];
		}

		$fields = [];
		foreach (self::READ_KEYS as $key) {
			// The values we are after are strings; anything else is left out.
			$pattern = '#/' . preg_quote($key, '#') . '\s*(\((?:[^()\\\\]|\\\\.)*\)|<[^>]*>)#';
			if (preg_match($pattern, $body, $match) === 1) {
				$fields[$key] = self::pdfTextDecode($match[1]);
			}
		}

		return $fields;
	}

	/**
	 * Text of a PDF string, whether it is written as a hex string or a literal
	 * one, and whether it is UTF-16 with a byte order mark or plain bytes.
	 */
	private static function pdfTextDecode(string $raw): string {
		if (str_starts_with($raw, '<')) {
			$hex = preg_replace('/\s+/', '', substr($raw, 1, -1)) ?? '';
			$bytes = hex2bin($hex);
			if ($bytes === false) {
				return '';
			}
		} else {
			$bytes = self::unescapeLiteral(substr($raw, 1, -1));
		}

		if (str_starts_with($bytes, "\xFE\xFF")) {
			return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
		}

		return mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
	}

	/** Body of a PDF literal string with its backslash escapes resolved. */
	private static function unescapeLiteral(string $body): string {
		return preg_replace_callback(
			'/\\\\([nrtbf()\\\\]|[0-7]{1,3})/',
			static function (array $match): string {
				return match ($match[1]) {
					'n' => "\n",
					'r' => "\r",
					't' => "\t",
					'b' => "\x08",
					'f' => "\x0C",
					'(' => '(',
					')' => ')',
					'\\' => '\\',
					default => chr(octdec($match[1])),
				};
			},
			$body,
		) ?? $body;
	}
}