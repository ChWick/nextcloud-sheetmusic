<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

/**
 * Derives the metadata MobileSheets stores for a PDF file from its content.
 *
 * The values have to match what MobileSheets itself computes, otherwise the app
 * would treat every file touched by this manager as changed and re-import it:
 *
 *  - the content hash is the CRC-32 of the file's bytes, stored as a signed
 *    32-bit integer (the same value the hash codes file keeps),
 *  - the page count is read from the PDF's page tree and the page order covers
 *    the whole file, e.g. "1-3" for a three page PDF.
 */
class MobileSheetsPdf {
	/**
	 * A page object of the page tree. The negative lookahead skips the page
	 * tree's own (/Pages) and any other /Page… node.
	 */
	private const PAGE_OBJECT = '#/Type\s*/Page(?![A-Za-z])#';

	/** A "/Count N" entry; the page tree root holds the total page count. */
	private const PAGE_TREE_COUNT = '#/Count\s+(\d+)#';

	/** A Flate compressed stream body, used to unpack object streams. */
	private const STREAM_BODY = '#(?<!end)stream\r?\n(.*?)\r?\n?endstream#s';

	private function __construct() {
	}

	/**
	 * The content hash MobileSheets stores: CRC-32 of the bytes, as a signed
	 * 32-bit integer.
	 */
	public static function contentHash(string $content): int {
		$unsigned = crc32($content) & 0xFFFFFFFF;

		return $unsigned >= 0x80000000 ? $unsigned - 0x100000000 : $unsigned;
	}

	/**
	 * Number of pages of a PDF.
	 *
	 * The page tree lists one /Type /Page object per page, so counting them is
	 * exact. Objects kept in compressed object streams are unpacked first. When
	 * no page object can be found at all, the largest /Count - which the page
	 * tree root holds in full - is used, and a single page is the last resort.
	 */
	public static function pageCount(string $content): int {
		$searchable = $content . self::inflatedStreams($content);

		$pages = preg_match_all(self::PAGE_OBJECT, $searchable);
		if (is_int($pages) && $pages > 0) {
			return $pages;
		}

		$counts = preg_match_all(self::PAGE_TREE_COUNT, $searchable, $matches);
		if (is_int($counts) && $counts > 0) {
			$largest = max(array_map('intval', $matches[1]));
			if ($largest > 0) {
				return $largest;
			}
		}

		return 1;
	}

	/**
	 * Page order covering the whole file, e.g. "1-3" for a three page PDF.
	 */
	public static function pageOrder(int $pageCount): string {
		return '1-' . max(1, $pageCount);
	}

	/**
	 * Decompressed bodies of the PDF's Flate compressed streams.
	 *
	 * Compressed object streams hide the page objects from a plain text search,
	 * so their unpacked content is searched as well. Streams that are not Flate
	 * compressed simply fail to unpack and are skipped.
	 */
	private static function inflatedStreams(string $content): string {
		// Every stream body of an encrypted document is ciphertext - there is
		// nothing to unpack and trying would only raise errors.
		if (preg_match('#/Encrypt\s+\d+\s+\d+\s+R#', $content) === 1) {
			return '';
		}

		if (preg_match_all(self::STREAM_BODY, $content, $matches) === false) {
			return '';
		}

		$extra = '';
		foreach ($matches[1] as $body) {
			$inflated = self::inflate($body);
			if ($inflated !== '') {
				$extra .= $inflated;
			}
		}

		return $extra;
	}

	/**
	 * The decompressed $body, or '' when it holds no Flate data.
	 *
	 * The zlib functions report undecompressible data through a PHP warning -
	 * an expected outcome here, since every stream body is offered to them. The
	 * warning must not reach the server log, so the calls run under their own
	 * error handler that drops it; the "@" suppression operator is not enough,
	 * an installed error handler still receives the warning.
	 */
	private static function inflate(string $body): string {
		set_error_handler(static fn (): bool => true);
		try {
			$inflated = gzuncompress($body);
			if ($inflated === false) {
				$inflated = gzinflate($body);
			}
		} finally {
			restore_error_handler();
		}

		return $inflated === false ? '' : $inflated;
	}
}
