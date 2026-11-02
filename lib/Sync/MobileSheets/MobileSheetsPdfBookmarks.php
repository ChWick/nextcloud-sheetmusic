<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

/**
 * Extracts bookmarks (document outlines) from PDF files.
 *
 * In PDF files, bookmarks are organized in an outline tree (/Outlines in the
 * document catalog). Each outline item defines a display title and a destination
 * (/Dest or GoTo action /A) pointing to a target page.
 */
class MobileSheetsPdfBookmarks {
	private function __construct() {
	}

	/**
	 * Extract bookmarks from raw PDF content.
	 *
	 * @return list<array{name: string, pageNum: int, endPage: ?int}>
	 */
	public static function extractBookmarks(string $content): array {
		if ($content === '') {
			return [];
		}

		$searchable = $content . "\n" . self::inflatedStreams($content);

		// 1. Find Root catalog object
		$rootRef = self::findRootRef($content);
		if ($rootRef === null) {
			return [];
		}

		// 2. Parse Catalog body
		$catalogBody = self::findObjectBody($searchable, $rootRef[0], $rootRef[1]);
		if ($catalogBody === null) {
			return [];
		}

		// 3. Find Outlines reference from Catalog
		if (preg_match('#/Outlines\s+(\d+)\s+(\d+)\s+R#', $catalogBody, $outlinesMatch) !== 1) {
			return [];
		}

		$outlinesObj = (int)$outlinesMatch[1];
		$outlinesGen = (int)$outlinesMatch[2];
		$outlinesBody = self::findObjectBody($searchable, $outlinesObj, $outlinesGen);
		if ($outlinesBody === null) {
			return [];
		}

		// 4. Build page object -> page number map
		$pageMap = self::buildPageMap($searchable, $catalogBody);
		$totalPageCount = MobileSheetsPdf::pageCount($content);

		// 5. Named destinations map (if any)
		$destsMap = self::buildNamedDestsMap($searchable, $catalogBody);

		// 6. Find First outline item
		if (preg_match('#/First\s+(\d+)\s+(\d+)\s+R#', $outlinesBody, $firstMatch) !== 1) {
			return [];
		}

		$rawBookmarks = [];
		$visited = [];
		self::traverseOutline(
			$searchable,
			(int)$firstMatch[1],
			(int)$firstMatch[2],
			$pageMap,
			$destsMap,
			$rawBookmarks,
			$visited
		);

		if (empty($rawBookmarks)) {
			return [];
		}

		// 7. Calculate endPage for bookmarks
		$count = count($rawBookmarks);
		$bookmarks = [];
		for ($i = 0; $i < $count; $i++) {
			$cur = $rawBookmarks[$i];
			$curPage = $cur['pageNum'];
			$endPage = null;

			if ($i + 1 < $count) {
				$nextPage = $rawBookmarks[$i + 1]['pageNum'];
				if ($nextPage > $curPage) {
					$endPage = $nextPage - 1;
				} else {
					$endPage = $curPage;
				}
			} elseif ($totalPageCount >= $curPage) {
				$endPage = $totalPageCount;
			}

			$bookmarks[] = [
				'name' => $cur['name'],
				'pageNum' => $curPage,
				'endPage' => $endPage,
			];
		}

		return $bookmarks;
	}

	/**
	 * @return array{0: int, 1: int}|null
	 */
	private static function findRootRef(string $content): ?array {
		if (preg_match_all('#startxref\s+(\d+)#', $content, $matches) === false || empty($matches[1])) {
			if (preg_match('#trailer\s*<<[^>]*?/Root\s+(\d+)\s+(\d+)\s+R#s', $content, $root)) {
				return [(int)$root[1], (int)$root[2]];
			}
			return null;
		}

		$xrefOffset = (int)end($matches[1]);
		$region = substr($content, $xrefOffset);
		if (preg_match('#/Root\s+(\d+)\s+(\d+)\s+R#', $region, $root) === 1) {
			return [(int)$root[1], (int)$root[2]];
		}

		if (preg_match('#/Root\s+(\d+)\s+(\d+)\s+R#', $content, $root) === 1) {
			return [(int)$root[1], (int)$root[2]];
		}

		return null;
	}

	private static function findObjectBody(string $searchable, int $num, int $gen): ?string {
		$pattern = '#(?<![0-9])' . $num . '\s+' . $gen . '\s+obj\s*(.*?)\s*endobj#s';
		if (preg_match_all($pattern, $searchable, $matches, PREG_SET_ORDER) !== false && !empty($matches)) {
			$last = end($matches);
			$raw = trim($last[1]);
			if (preg_match('#^(.*?)\s*stream\b#s', $raw, $streamMatch) === 1) {
				$raw = trim($streamMatch[1]);
			}
			return $raw;
		}

		return null;
	}

	/**
	 * @return array<int, int> objNum => pageNum (1-based)
	 */
	private static function buildPageMap(string $searchable, string $catalogBody): array {
		$pageMap = [];
		if (preg_match('#/Pages\s+(\d+)\s+(\d+)\s+R#', $catalogBody, $pagesMatch) === 1) {
			$pagesObj = (int)$pagesMatch[1];
			$pagesGen = (int)$pagesMatch[2];
			$pageNumber = 1;
			$visited = [];
			self::traversePages($searchable, $pagesObj, $pagesGen, $pageNumber, $pageMap, $visited);
		}

		if (empty($pageMap)) {
			$pattern = '#(?<![0-9])(\d+)\s+(\d+)\s+obj\s*<<[^>]*?/Type\s*/Page(?![A-Za-z])#';
			if (preg_match_all($pattern, $searchable, $matches, PREG_SET_ORDER) !== false) {
				$p = 1;
				foreach ($matches as $m) {
					$pageMap[(int)$m[1]] = $p++;
				}
			}
		}

		return $pageMap;
	}

	/**
	 * @param array<int, int> $pageMap
	 * @param array<string, bool> $visited
	 */
	private static function traversePages(
		string $searchable,
		int $num,
		int $gen,
		int &$pageNumber,
		array &$pageMap,
		array &$visited,
	): void {
		$key = "$num:$gen";
		if (isset($visited[$key])) {
			return;
		}
		$visited[$key] = true;

		$body = self::findObjectBody($searchable, $num, $gen);
		if ($body === null) {
			return;
		}

		if (preg_match('#/Type\s*/Page(?![A-Za-z])#', $body) === 1 && preg_match('#/Kids\b#', $body) !== 1) {
			$pageMap[$num] = $pageNumber++;
			return;
		}

		if (preg_match('#/Kids\s*\[([^\]]*)\]#s', $body, $kidsMatch) === 1) {
			if (preg_match_all('#(\d+)\s+(\d+)\s+R#', $kidsMatch[1], $kidRefs, PREG_SET_ORDER) !== false) {
				foreach ($kidRefs as $kidRef) {
					self::traversePages($searchable, (int)$kidRef[1], (int)$kidRef[2], $pageNumber, $pageMap, $visited);
				}
			}
		}
	}

	/**
	 * @return array<string, string>
	 */
	private static function buildNamedDestsMap(string $searchable, string $catalogBody): array {
		$dests = [];

		if (preg_match('#/Dests\s+(\d+)\s+(\d+)\s+R#', $catalogBody, $destsMatch) === 1) {
			$destsBody = self::findObjectBody($searchable, (int)$destsMatch[1], (int)$destsMatch[2]);
			if ($destsBody !== null) {
				self::parseDestsDictionary($destsBody, $dests);
			}
		} elseif (preg_match('#/Dests\s*<<(.*?)>>#s', $catalogBody, $destsMatch) === 1) {
			self::parseDestsDictionary($destsMatch[1], $dests);
		}

		if (preg_match('#/Names\s+(\d+)\s+(\d+)\s+R#', $catalogBody, $namesMatch) === 1) {
			$namesBody = self::findObjectBody($searchable, (int)$namesMatch[1], (int)$namesMatch[2]);
			if ($namesBody !== null && preg_match('#/Dests\s+(\d+)\s+(\d+)\s+R#', $namesBody, $namesDestsMatch) === 1) {
				$namesDestsBody = self::findObjectBody($searchable, (int)$namesDestsMatch[1], (int)$namesDestsMatch[2]);
				if ($namesDestsBody !== null) {
					self::parseNameTree($namesDestsBody, $dests);
				}
			}
		}

		return $dests;
	}

	/**
	 * @param array<string, string> $dests
	 */
	private static function parseDestsDictionary(string $body, array &$dests): void {
		if (preg_match_all('#/([A-Za-z0-9_\-\.]+)\s*\[([^\]]*)\]#s', $body, $matches, PREG_SET_ORDER) !== false) {
			foreach ($matches as $m) {
				$dests[$m[1]] = $m[2];
			}
		}
	}

	/**
	 * @param array<string, string> $dests
	 */
	private static function parseNameTree(string $body, array &$dests): void {
		if (preg_match('#/Names\s*\[(.*?)\]#s', $body, $namesMatch) === 1) {
			if (preg_match_all('#\(([^()]*)\)\s*\[([^\]]*)\]#s', $namesMatch[1], $items, PREG_SET_ORDER) !== false) {
				foreach ($items as $item) {
					$dests[$item[1]] = $item[2];
				}
			}
		}
	}

	/**
	 * @param array<int, int> $pageMap
	 * @param array<string, string> $destsMap
	 * @param list<array{name: string, pageNum: int}> $rawBookmarks
	 * @param array<string, bool> $visited
	 */
	private static function traverseOutline(
		string $searchable,
		int $num,
		int $gen,
		array $pageMap,
		array $destsMap,
		array &$rawBookmarks,
		array &$visited,
	): void {
		$currNum = $num;
		$currGen = $gen;

		while ($currNum > 0) {
			$key = "$currNum:$currGen";
			if (isset($visited[$key])) {
				break;
			}
			$visited[$key] = true;

			$itemBody = self::findObjectBody($searchable, $currNum, $currGen);
			if ($itemBody === null) {
				break;
			}

			$title = null;
			if (preg_match('#/Title\s*(\((?:[^()\\\\]|\\\\.)*\)|<[^>]*>)#s', $itemBody, $titleMatch) === 1) {
				$rawBytes = self::pdfStringBytes($titleMatch[1]);
				$title = trim(self::pdfBytesText($rawBytes));
			}

			$pageNum = self::resolveDestPage($searchable, $itemBody, $pageMap, $destsMap);

			if ($title !== null && $title !== '' && $pageNum !== null) {
				$rawBookmarks[] = [
					'name' => $title,
					'pageNum' => $pageNum,
				];
			}

			if (preg_match('#/First\s+(\d+)\s+(\d+)\s+R#', $itemBody, $childMatch) === 1) {
				self::traverseOutline(
					$searchable,
					(int)$childMatch[1],
					(int)$childMatch[2],
					$pageMap,
					$destsMap,
					$rawBookmarks,
					$visited,
				);
			}

			if (preg_match('#/Next\s+(\d+)\s+(\d+)\s+R#', $itemBody, $nextMatch) === 1) {
				$currNum = (int)$nextMatch[1];
				$currGen = (int)$nextMatch[2];
			} else {
				break;
			}
		}
	}

	/**
	 * @param array<int, int> $pageMap
	 * @param array<string, string> $destsMap
	 */
	private static function resolveDestPage(
		string $searchable,
		string $itemBody,
		array $pageMap,
		array $destsMap,
	): ?int {
		$destString = null;

		if (preg_match('#/(?:Dest|D)\s*\[([^\]]*)\]#s', $itemBody, $destMatch) === 1) {
			$destString = $destMatch[1];
		} elseif (preg_match('#/(?:Dest|D)\s*(?:/([A-Za-z0-9_\-\.]+)|(?:\(([^()]*)\)))#', $itemBody, $namedMatch) === 1) {
			$name = !empty($namedMatch[1]) ? $namedMatch[1] : (!empty($namedMatch[2]) ? $namedMatch[2] : '');
			$destString = $destsMap[$name] ?? null;
		} elseif (preg_match('#/(?:Dest|D)\s+(\d+)\s+(\d+)\s+R#', $itemBody, $destRef) === 1) {
			$destBody = self::findObjectBody($searchable, (int)$destRef[1], (int)$destRef[2]);
			if ($destBody !== null) {
				if (preg_match('#\[([^\]]*)\]#s', $destBody, $innerDest) === 1) {
					$destString = $innerDest[1];
				} else {
					$destString = $destBody;
				}
			}
		}

		if ($destString === null) {
			return null;
		}

		if (preg_match('#^\s*(\d+)\s+(\d+)\s+R#', $destString, $pageRefMatch) === 1) {
			$pageObjNum = (int)$pageRefMatch[1];
			return $pageMap[$pageObjNum] ?? null;
		}

		if (preg_match('#^\s*(\d+)#', $destString, $numMatch) === 1) {
			return (int)$numMatch[1] + 1;
		}

		return null;
	}

	private static function pdfStringBytes(string $raw): string {
		if (str_starts_with($raw, '<')) {
			$hex = preg_replace('/\s+/', '', substr($raw, 1, -1)) ?? '';
			$bytes = hex2bin($hex);
			return $bytes === false ? '' : $bytes;
		}

		$inner = substr($raw, 1, -1);
		return stripcslashes($inner);
	}

	private static function pdfBytesText(string $bytes): string {
		if (str_starts_with($bytes, "\xFE\xFF")) {
			return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
		}
		if (str_starts_with($bytes, "\xFF\xFE")) {
			return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
		}
		if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
			return substr($bytes, 3);
		}

		return mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
	}

	private static function inflatedStreams(string $content): string {
		if (preg_match('#/Encrypt\s+\d+\s+\d+\s+R#', $content) === 1) {
			return '';
		}

		if (preg_match_all('#(?<!end)stream\r?\n(.*?)\r?\n?endstream#s', $content, $matches) === false) {
			return '';
		}

		$inflated = '';
		foreach ($matches[1] as $body) {
			$unpacked = @gzuncompress($body);
			if ($unpacked === false) {
				$unpacked = @gzinflate($body);
			}
			if (is_string($unpacked)) {
				$inflated .= $unpacked . "\n";
			}
		}

		return $inflated;
	}
}
