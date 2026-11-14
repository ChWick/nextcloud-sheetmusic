<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

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
	 * @throws \RuntimeException when the file is not a PDF whose trailer can be
	 *                           read or whose protection cannot be handled
	 */
	public static function withInfo(string $content, array $info): string {
		$trailer = self::trailer($content);
		$security = $trailer['security'];

		// In an encrypted file every string is encrypted with the key of the
		// object it lives in, so the update redefines the file's own Info
		// object: the stamp entries copied over keep their key and stay
		// readable, where a fresh object number would leave them encrypted with
		// the wrong key. An unencrypted file gets a fresh Info object at the
		// trailer's /Size, which never collides with one it already defines.
		$redefinesInfo = $security !== null && $trailer['infoNumber'] !== null;
		$objectNumber = $redefinesInfo ? (int)$trailer['infoNumber'] : $trailer['size'];
		$objectGeneration = $redefinesInfo ? $trailer['infoGeneration'] : 0;

		$body = self::mergedBody(self::infoBody($content, $trailer), $info, $security, $objectNumber, $objectGeneration);

		$objectOffset = strlen($content);
		$object = $objectNumber . ' ' . $objectGeneration . " obj\n<<\n" . $body . ">>\nendobj\n";

		$trailerOffset = $objectOffset + strlen($object);
		$entry = sprintf('%010d %05d n ', $objectOffset, $objectGeneration) . "\n";
		$dict = '<< /Size ' . ($redefinesInfo ? $trailer['size'] : $objectNumber + 1)
			. ' /Root ' . $trailer['root']
			. ' /Info ' . $objectNumber . ' ' . $objectGeneration . ' R'
			. ($trailer['id'] !== null ? ' /ID ' . $trailer['id'] : '')
			. ($trailer['encrypt'] !== null ? ' /Encrypt ' . $trailer['encrypt'] : '')
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
		$fastNeedle = $trailer['infoNumber'] . ' ' . $trailer['infoGeneration'] . ' obj';
		$lastFastPos = strrpos($content, $fastNeedle);
		if ($lastFastPos !== false
			&& ($lastFastPos === 0 || !ctype_digit($content[$lastFastPos - 1]))
			&& !ctype_alnum($content[$lastFastPos + strlen($fastNeedle)] ?? '')
			&& preg_match($pattern, $content, $unused, 0, $lastFastPos + 1) !== 1
		) {
			$offset = $lastFastPos;
		} else {
			if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE) === false || $matches[0] === []) {
				return null;
			}
			$offset = (int)end($matches[0])[1];
		}

		if (preg_match('#\d+\s+\d+\s+obj\s*<<(.*?)>>#s', substr($content, $offset), $dict) !== 1) {
			return null;
		}

		return $dict[1];
	}

	/**
	 * The Info dictionary body to write: our fields, keeping the stamp entries
	 * the file already had so an edit does not erase them. In an encrypted file
	 * the values are encrypted for the object the body ends up in.
	 *
	 * @param array<string, string> $info
	 */
	private static function mergedBody(?string $existing, array $info, ?MobileSheetsPdfSecurity $security, int $objectNumber, int $objectGeneration): string {
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
			$bytes = self::pdfTextBytes((string)$value);
			if ($security !== null) {
				$bytes = $security->encryptBytes($bytes, $objectNumber, $objectGeneration);
			}
			$written .= '/' . $key . ' ' . self::pdfStringToken($bytes) . "\n";
		}

		return $kept . $written;
	}

	/**
	 * The bytes of a PDF string for arbitrary text: UTF-16BE with a byte order
	 * mark, the encoding a PDF reader must accept for non-ASCII document
	 * properties.
	 */
	private static function pdfTextBytes(string $text): string {
		return "\xFE\xFF" . mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
	}

	/** The PDF string token for given bytes: a hex string. */
	private static function pdfStringToken(string $bytes): string {
		return '<' . strtoupper(bin2hex($bytes)) . '>';
	}

	/**
	 * The trailer the file currently ends on, which tells us where to append the
	 * incremental update and which entries to carry over.
	 *
	 * @return array{xrefOffset: int, size: int, root: string, info: ?string, infoNumber: ?int, infoGeneration: int, id: ?string, encrypt: ?string, security: ?MobileSheetsPdfSecurity}
	 * @throws \RuntimeException when the file is not a PDF whose metadata can be
	 *                           read and written
	 */
	private static function trailer(string $content): array {
		$lastStartxref = strrpos($content, 'startxref');
		if ($lastStartxref !== false && preg_match('#^startxref\s+(\d+)#', substr($content, $lastStartxref), $lastMatch) === 1) {
			$xrefOffset = (int)$lastMatch[1];
		} elseif (preg_match_all('#startxref\s+(\d+)#', $content, $matches) !== false && $matches[1] !== []) {
			// The last startxref points at the cross-reference section in force.
			$xrefOffset = (int)end($matches[1]);
		} else {
			throw new \RuntimeException('Not a PDF: no startxref');
		}
		$region = substr($content, $xrefOffset);

		if (preg_match('#/Size\s+(\d+)#', $region, $size) !== 1) {
			throw new \RuntimeException('Not a readable PDF: no trailer /Size');
		}
		if (preg_match('#/Root\s+(\d+)\s+(\d+)\s+R#', $region, $root) !== 1) {
			throw new \RuntimeException('Not a readable PDF: no trailer /Root');
		}

		// An encrypted file is handled as far as its protection allows: the
		// security either yields the key to read and write its strings with, or
		// throws - a protection we cannot handle keeps being refused instead of
		// producing a corrupt file.
		$encrypt = null;
		if (preg_match('#/Encrypt\s+(\d+)\s+(\d+)\s+R#', $region, $encryptMatch) === 1) {
			$encrypt = $encryptMatch[1] . ' ' . $encryptMatch[2] . ' R';
		}
		$security = MobileSheetsPdfSecurity::forTrailer($content, $region);

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
			'encrypt' => $encrypt,
			'security' => $security,
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
	 * Whether the PDF already carries exactly the document properties of $song.
	 *
	 * Both sides are normalized the way a write/read round trip normalizes them -
	 * values joined with "; ", then split, trimmed and deduplicated - so a file
	 * that was written from this song before compares equal and is not written
	 * again. That keeps repeated sync runs from piling up incremental revisions
	 * in a file that is already up to date.
	 *
	 * @throws \RuntimeException when the file is not a readable PDF
	 */
	public static function matchesSong(string $content, MobileSheetsSong $song): bool {
		return self::prefillFor($content) === [
			'title' => $song->title,
			'composers' => self::normalizedNames($song->composers, 'name'),
			'books' => self::normalizedNames($song->books, 'title'),
			'collections' => self::normalizedNames($song->collections, 'name'),
			'artists' => self::normalizedNames($song->artists, 'name'),
		];
	}

	/**
	 * Info dictionary fields that differ from the exact values this app writes
	 * for a song. Alternate import spellings such as Album are reported because
	 * they are not part of the generated metadata.
	 *
	 * @return list<string>
	 * @throws \RuntimeException when the file is not a readable PDF
	 */
	public static function mismatchedFields(string $content, MobileSheetsSong $song): array {
		$actual = self::readFields($content);
		$expected = self::fieldsFor($song);
		$keys = array_unique([...array_keys($expected), ...array_keys($actual)]);

		return array_values(array_filter(
			$keys,
			static fn (string $key): bool => ($actual[$key] ?? null) !== ($expected[$key] ?? null),
		));
	}

	/**
	 * The names of the song's entities, normalized like a value that travels
	 * through the document properties: joined, then split, trimmed and deduped.
	 *
	 * @param list<object> $entities the song's composers, books, collections or artists
	 * @param string $property the property holding the name: "name" or "title"
	 *
	 * @return list<string>
	 */
	private static function normalizedNames(array $entities, string $property): array {
		$values = array_map(
			static fn (object $entity): string => (string)$entity->$property,
			$entities,
		);

		return self::splitNames(self::join($values));
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
		$trailer = self::trailer($content);
		$body = self::infoBody($content, $trailer);
		if ($body === null || $trailer['infoNumber'] === null) {
			return [];
		}

		$security = $trailer['security'];
		$fields = [];
		foreach (self::READ_KEYS as $key) {
			// The values we are after are strings; anything else is left out.
			$pattern = '#/' . preg_quote($key, '#') . '\s*(\((?:[^()\\\\]|\\\\.)*\)|<[^>]*>)#';
			if (preg_match($pattern, $body, $match) === 1) {
				$bytes = self::pdfStringBytes($match[1]);
				if ($security !== null) {
					$bytes = $security->decryptBytes($bytes, $trailer['infoNumber'], $trailer['infoGeneration']);
				}
				$fields[$key] = self::pdfBytesText($bytes);
			}
		}

		return $fields;
	}

	/** The bytes of a PDF string, whether it is written as a hex string or a literal one. */
	private static function pdfStringBytes(string $raw): string {
		if (str_starts_with($raw, '<')) {
			$hex = preg_replace('/\s+/', '', substr($raw, 1, -1)) ?? '';
			$bytes = hex2bin($hex);

			return $bytes === false ? '' : $bytes;
		}

		return self::unescapeLiteral(substr($raw, 1, -1));
	}

	/**
	 * Text of the bytes of a PDF string, whether they are UTF-16 with a byte
	 * order mark or plain bytes.
	 */
	private static function pdfBytesText(string $bytes): string {
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
