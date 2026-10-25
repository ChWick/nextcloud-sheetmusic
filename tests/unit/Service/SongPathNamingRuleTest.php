<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Service\NoNamingRuleException;
use OCA\SheetMusic\Service\SongPathNamingRule;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsBook;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsComposer;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFile;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsKey;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
use PHPUnit\Framework\TestCase;

final class SongPathNamingRuleTest extends TestCase {
	public function testNormalizeGeneratedPathSegmentSanitizesSpecialCharacters(): void {
		self::assertSame('Song - Name', SongPathNamingRule::normalizeGeneratedPathSegment('Song / Name'));
		self::assertSame('untitled', SongPathNamingRule::normalizeGeneratedPathSegment(''));
		self::assertSame('untitled', SongPathNamingRule::normalizeGeneratedPathSegment('.'));
		self::assertSame('_CON.pdf', SongPathNamingRule::normalizeGeneratedPathSegment('CON.pdf'));
		self::assertSame('score-part', SongPathNamingRule::normalizeGeneratedPathSegment('score.part'));
	}

	public function testNormalizeKeyNameForGeneratedPathReplacesSharpsAndFlats(): void {
		self::assertSame('C♯', SongPathNamingRule::normalizeKeyNameForGeneratedPath('C#'));
		self::assertSame('B♭', SongPathNamingRule::normalizeKeyNameForGeneratedPath('Bb'));
		self::assertSame('F♯m', SongPathNamingRule::normalizeKeyNameForGeneratedPath('F#m'));
	}

	public function testFileIndexPrefixAndCount(): void {
		$song = new MobileSheetsSong(
			id: 1,
			title: 'Multi File Song',
			files: [
				new MobileSheetsFile(1, 'score_1.pdf'),
				new MobileSheetsFile(2, 'score_2.pdf'),
				new MobileSheetsFile(3, 'audio.mp3'),
			],
		);

		self::assertSame(2, SongPathNamingRule::fileCountForExtension($song, $song->files[0]));
		self::assertSame(1, SongPathNamingRule::fileCountForExtension($song, $song->files[2]));
		self::assertSame(1, SongPathNamingRule::fileIndexOf($song, $song->files[0]));
		self::assertSame(2, SongPathNamingRule::fileIndexOf($song, $song->files[1]));
		self::assertSame('001 ', SongPathNamingRule::fileIndexPrefix(1, 2));
		self::assertSame('', SongPathNamingRule::fileIndexPrefix(1, 1));
	}

	public function testCreateTargetPathWithComposerBookAndKey(): void {
		$song = new MobileSheetsSong(
			id: 10,
			title: 'Autumn Leaves',
			composers: [new MobileSheetsComposer(1, 'Joseph Kosma')],
			books: [new MobileSheetsBook(2, 'Real Book')],
			keys: [new MobileSheetsKey(3, 'G')],
		);

		$path = SongPathNamingRule::createTargetPath($song, '.pdf');
		self::assertSame('Joseph Kosma/Joseph Kosma - Real Book/Joseph Kosma - Real Book - Autumn Leaves in G.pdf', $path);
	}

	public function testCreateTargetPathsThrowsWhenNoNamingEntityExists(): void {
		$song = new MobileSheetsSong(id: 1, title: 'No Composer Song');
		$this->expectException(NoNamingRuleException::class);
		SongPathNamingRule::createTargetPaths($song, '.pdf');
	}
}
