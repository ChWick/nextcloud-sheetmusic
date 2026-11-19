<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Service;

use OCA\SheetMusic\Service\NoNamingRuleException;
use OCA\SheetMusic\Service\SongPathNamingRule;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsArtist;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsBook;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsCollection;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsComposer;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFile;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsKey;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsYear;
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
		self::assertSame('Songs/Joseph Kosma/Joseph Kosma - Real Book/Joseph Kosma - Real Book - Autumn Leaves in G.pdf', $path);
	}

	public function testCreateTargetPathWithArtistOnlyInAuto(): void {
		$song = new MobileSheetsSong(
			id: 11,
			title: 'Wonderwall',
			artists: [new MobileSheetsArtist(1, 'Oasis')],
		);

		$pathAuto = SongPathNamingRule::createTargetPath($song, '.pdf', preset: SongPathNamingRule::PRESET_AUTO);
		self::assertSame('Songs/Oasis/Oasis - Wonderwall.pdf', $pathAuto);

		$pathArtist = SongPathNamingRule::createTargetPath($song, '.pdf', preset: SongPathNamingRule::PRESET_ARTIST);
		self::assertSame('Songs/Oasis/Oasis - Wonderwall.pdf', $pathArtist);

		// In composer preset, falls back to artist
		$pathComposer = SongPathNamingRule::createTargetPath($song, '.pdf', preset: SongPathNamingRule::PRESET_COMPOSER);
		self::assertSame('Songs/Oasis/Oasis - Wonderwall.pdf', $pathComposer);
	}

	public function testCreateTargetPathWithBothModernSongDefaultsToArtistInAuto(): void {
		$song = new MobileSheetsSong(
			id: 12,
			title: 'Come Together',
			composers: [
				new MobileSheetsComposer(1, 'John Lennon'),
				new MobileSheetsComposer(2, 'Paul McCartney'),
			],
			artists: [new MobileSheetsArtist(3, 'The Beatles')],
			books: [new MobileSheetsBook(4, 'Abbey Road')],
		);

		// In Auto mode: modern song (no classical indicators) -> Artist leads!
		$pathAuto = SongPathNamingRule::createTargetPath($song, '.pdf', preset: SongPathNamingRule::PRESET_AUTO);
		self::assertSame('Songs/The Beatles/The Beatles - Abbey Road/The Beatles - Abbey Road - Come Together.pdf', $pathAuto);

		// Multiple valid paths returned: both artist and composer are valid for integrity checking
		$allPaths = SongPathNamingRule::createTargetPaths($song, '.pdf', preset: SongPathNamingRule::PRESET_AUTO);
		self::assertContains('Songs/The Beatles/The Beatles - Abbey Road/The Beatles - Abbey Road - Come Together.pdf', $allPaths);
		self::assertContains('Songs/John Lennon, Paul McCartney/John Lennon, Paul McCartney - Abbey Road/John Lennon, Paul McCartney - Abbey Road - Come Together.pdf', $allPaths);

		// Strict composer preset forces composer
		$pathComposer = SongPathNamingRule::createTargetPath($song, '.pdf', preset: SongPathNamingRule::PRESET_COMPOSER);
		self::assertSame('Songs/John Lennon, Paul McCartney/John Lennon, Paul McCartney - Abbey Road/John Lennon, Paul McCartney - Abbey Road - Come Together.pdf', $pathComposer);
	}

	public function testCreateTargetPathWithBothClassicalSongDefaultsToComposerInAuto(): void {
		$song = new MobileSheetsSong(
			id: 13,
			title: 'Symphony No. 5',
			opus: 'Op. 67',
			composers: [new MobileSheetsComposer(1, 'Ludwig van Beethoven')],
			artists: [new MobileSheetsArtist(2, 'Berliner Philharmoniker')],
		);

		// In Auto mode: has Opus and orchestra in artist -> Composer leads, and Opus is included in filename
		$pathAuto = SongPathNamingRule::createTargetPath($song, '.pdf', preset: SongPathNamingRule::PRESET_AUTO);
		self::assertSame('Songs/Ludwig van Beethoven/Ludwig van Beethoven - Symphony No. 5, Op. 67.pdf', $pathAuto);

		// Strict artist preset forces artist
		$pathArtist = SongPathNamingRule::createTargetPath($song, '.pdf', preset: SongPathNamingRule::PRESET_ARTIST);
		self::assertSame('Songs/Berliner Philharmoniker/Berliner Philharmoniker - Symphony No. 5, Op. 67.pdf', $pathArtist);
	}

	public function testCreateTargetPathWithBookOnlyInAuto(): void {
		$song = new MobileSheetsSong(
			id: 14,
			title: 'All of Me',
			books: [new MobileSheetsBook(1, 'The Real Book')],
		);

		// In Auto mode: no composer/artist, but book exists -> files under Book!
		$path = SongPathNamingRule::createTargetPath($song, '.pdf', preset: SongPathNamingRule::PRESET_AUTO);
		self::assertSame('Songs/The Real Book/The Real Book - All of Me.pdf', $path);
	}

	public function testCreateTargetPathWithCollectionPreset(): void {
		$song = new MobileSheetsSong(
			id: 15,
			title: 'Tune',
			composers: [new MobileSheetsComposer(1, 'Miles Davis')],
			collections: [new MobileSheetsCollection(2, 'Jazz Standards')],
		);

		$path = SongPathNamingRule::createTargetPath($song, '.pdf', preset: SongPathNamingRule::PRESET_COLLECTION);
		self::assertSame('Songs/Jazz Standards/Jazz Standards - Miles Davis/Jazz Standards - Miles Davis - Tune.pdf', $path);
	}

	public function testCreateTargetPathsThrowsWhenPresetIsDisabled(): void {
		$song = new MobileSheetsSong(
			id: 16,
			title: 'Any Song',
			composers: [new MobileSheetsComposer(1, 'Bach')],
		);
		$this->expectException(NoNamingRuleException::class);
		$this->expectExceptionMessage('Naming rule is disabled for this library');
		SongPathNamingRule::createTargetPaths($song, '.pdf', preset: SongPathNamingRule::PRESET_DISABLED);
	}

	public function testCreateTargetPathsThrowsWhenNoNamingEntityExists(): void {
		$song = new MobileSheetsSong(id: 1, title: 'No Composer Song');
		$this->expectException(NoNamingRuleException::class);
		SongPathNamingRule::createTargetPaths($song, '.pdf');
	}

	public function testConflictResolutionViaKeyDAndF(): void {
		$sonataD = new MobileSheetsSong(
			id: 20,
			title: 'Sonata',
			composers: [new MobileSheetsComposer(1, 'Beethoven')],
			keys: [new MobileSheetsKey(1, 'D')],
		);
		$sonataF = new MobileSheetsSong(
			id: 21,
			title: 'Sonata',
			composers: [new MobileSheetsComposer(1, 'Beethoven')],
			keys: [new MobileSheetsKey(2, 'F')],
		);

		$pathD = SongPathNamingRule::createTargetPath($sonataD, '.pdf');
		$pathF = SongPathNamingRule::createTargetPath($sonataF, '.pdf');

		self::assertSame('Songs/Beethoven/Beethoven - Sonata in D.pdf', $pathD);
		self::assertSame('Songs/Beethoven/Beethoven - Sonata in F.pdf', $pathF);
		self::assertNotSame($pathD, $pathF);
	}

	public function testConflictResolutionViaOpusAndCatalogNumber(): void {
		$mozart = new MobileSheetsSong(
			id: 22,
			title: 'Sonata',
			catalogNumber: 'KV 545',
			composers: [new MobileSheetsComposer(1, 'Mozart')],
			keys: [new MobileSheetsKey(1, 'C')],
		);
		$beethoven = new MobileSheetsSong(
			id: 23,
			title: 'Sonata',
			opus: 'Op. 10 No. 3',
			composers: [new MobileSheetsComposer(2, 'Beethoven')],
			keys: [new MobileSheetsKey(2, 'D')],
		);

		$pathMozart = SongPathNamingRule::createTargetPath($mozart, '.pdf');
		$pathBeethoven = SongPathNamingRule::createTargetPath($beethoven, '.pdf');

		self::assertSame('Songs/Mozart/Mozart - Sonata in C, KV 545.pdf', $pathMozart);
		self::assertSame('Songs/Beethoven/Beethoven - Sonata in D, Op. 10 No. 3.pdf', $pathBeethoven);
	}

	public function testConflictResolutionViaYearAndSongNumber(): void {
		$hymn = new MobileSheetsSong(
			id: 24,
			title: 'Amazing Grace',
			songNumber: '142',
			composers: [new MobileSheetsComposer(1, 'John Newton')],
		);
		$songWithYear = new MobileSheetsSong(
			id: 25,
			title: 'Autumn Leaves',
			composers: [new MobileSheetsComposer(2, 'Joseph Kosma')],
			years: [new MobileSheetsYear(1, 1945)],
		);

		$pathHymn = SongPathNamingRule::createTargetPath($hymn, '.pdf');
		$pathYear = SongPathNamingRule::createTargetPath($songWithYear, '.pdf');

		self::assertSame('Songs/John Newton/John Newton - Amazing Grace (No. 142).pdf', $pathHymn);
		self::assertSame('Songs/Joseph Kosma/Joseph Kosma - Autumn Leaves (1945).pdf', $pathYear);
	}

	public function testSmartDeduplicationPreventsRedundancy(): void {
		// When the user already typed "in D" or "KV 545" or "(1958)" into the title
		$song = new MobileSheetsSong(
			id: 26,
			title: 'Sonata in D, KV 545 (1958)',
			catalogNumber: 'KV 545',
			composers: [new MobileSheetsComposer(1, 'Mozart')],
			keys: [new MobileSheetsKey(1, 'D')],
			years: [new MobileSheetsYear(1, 1958)],
		);

		$path = SongPathNamingRule::createTargetPath($song, '.pdf');
		self::assertSame('Songs/Mozart/Mozart - Sonata in D, KV 545 (1958).pdf', $path);
	}

	public function testTitleContainsKeyRecognizesMusicalKeys(): void {
		self::assertTrue(SongPathNamingRule::titleContainsKey('Sonata in D', 'D'));
		self::assertTrue(SongPathNamingRule::titleContainsKey('Sonata in D, Op. 10', 'D'));
		self::assertTrue(SongPathNamingRule::titleContainsKey('Sonata in D major', 'D'));
		self::assertTrue(SongPathNamingRule::titleContainsKey('Sonata in D-Dur', 'D'));
		self::assertTrue(SongPathNamingRule::titleContainsKey('Sonata in Dm', 'Dm'));
		self::assertTrue(SongPathNamingRule::titleContainsKey('Sonata in D minor', 'Dm'));
		self::assertTrue(SongPathNamingRule::titleContainsKey('Sonata in D-Moll', 'Dm'));
		self::assertTrue(SongPathNamingRule::titleContainsKey('Sonata in C# minor', 'C#m'));
		self::assertTrue(SongPathNamingRule::titleContainsKey('Sonata in C♯m', 'C#m'));
		self::assertTrue(SongPathNamingRule::titleContainsKey('Sonata in Bb major', 'Bb'));
		self::assertTrue(SongPathNamingRule::titleContainsKey('Sonata (D major)', 'D'));
		self::assertTrue(SongPathNamingRule::titleContainsKey('Sonata (D)', 'D'));
		self::assertTrue(SongPathNamingRule::titleContainsKey('Sonata [D]', 'D'));
		self::assertTrue(SongPathNamingRule::titleContainsKey('Sonata - D Major', 'D'));
		self::assertTrue(SongPathNamingRule::titleContainsKey('Sonata D-Dur', 'D'));
		self::assertTrue(SongPathNamingRule::titleContainsKey('Sonata, Dm', 'Dm'));
		self::assertTrue(SongPathNamingRule::titleContainsKey('Sonata (Dm)', 'Dm'));
	}

	public function testTitleContainsKeyDoesNotMatchOrdinaryWords(): void {
		self::assertFalse(SongPathNamingRule::titleContainsKey('Night and Day', 'D'));
		self::assertFalse(SongPathNamingRule::titleContainsKey('Song For You', 'F'));
		self::assertFalse(SongPathNamingRule::titleContainsKey('Beauty and the Beast', 'A'));
		self::assertFalse(SongPathNamingRule::titleContainsKey('Come Together', 'C'));
		self::assertFalse(SongPathNamingRule::titleContainsKey('Stand by Me', 'B'));
		self::assertFalse(SongPathNamingRule::titleContainsKey('Take It Easy', 'E'));
		self::assertFalse(SongPathNamingRule::titleContainsKey('My Girl', 'G'));
		self::assertFalse(SongPathNamingRule::titleContainsKey('Once in a Lifetime', 'A'));
		self::assertFalse(SongPathNamingRule::titleContainsKey('Dancing in December', 'D'));
		self::assertFalse(SongPathNamingRule::titleContainsKey('Lost in France', 'F'));
		self::assertFalse(SongPathNamingRule::titleContainsKey('Walking in Darkness', 'D'));
		self::assertFalse(SongPathNamingRule::titleContainsKey('Here I Am', 'Am'));
		self::assertFalse(SongPathNamingRule::titleContainsKey('American Pie', 'Am'));
		self::assertFalse(SongPathNamingRule::titleContainsKey('Air on the G String', 'G'));
	}

	public function testCreateTargetPathRetainsKeyWhenTitleContainsWordStartingWithKeyLetter(): void {
		$songDay = new MobileSheetsSong(
			id: 30,
			title: 'Night and Day',
			composers: [new MobileSheetsComposer(1, 'Cole Porter')],
			keys: [new MobileSheetsKey(1, 'D')],
		);
		$songForYou = new MobileSheetsSong(
			id: 31,
			title: 'Song For You',
			artists: [new MobileSheetsArtist(1, 'Leon Russell')],
			keys: [new MobileSheetsKey(2, 'F')],
		);
		$songDecember = new MobileSheetsSong(
			id: 32,
			title: 'Dancing in December',
			artists: [new MobileSheetsArtist(2, 'Taylor')],
			keys: [new MobileSheetsKey(3, 'D')],
		);
		$songLifetime = new MobileSheetsSong(
			id: 33,
			title: 'Once in a Lifetime',
			artists: [new MobileSheetsArtist(3, 'Talking Heads')],
			keys: [new MobileSheetsKey(4, 'A')],
		);

		self::assertSame('Songs/Cole Porter/Cole Porter - Night and Day in D.pdf', SongPathNamingRule::createTargetPath($songDay, '.pdf'));
		self::assertSame('Songs/Leon Russell/Leon Russell - Song For You in F.pdf', SongPathNamingRule::createTargetPath($songForYou, '.pdf'));
		self::assertSame('Songs/Taylor/Taylor - Dancing in December in D.pdf', SongPathNamingRule::createTargetPath($songDecember, '.pdf'));
		self::assertSame('Songs/Talking Heads/Talking Heads - Once in a Lifetime in A.pdf', SongPathNamingRule::createTargetPath($songLifetime, '.pdf'));
	}

	public function testIsDisabledPreset(): void {
		self::assertTrue(SongPathNamingRule::isDisabledPreset(SongPathNamingRule::PRESET_DISABLED));
		self::assertTrue(SongPathNamingRule::isDisabledPreset('none'));
		self::assertFalse(SongPathNamingRule::isDisabledPreset(SongPathNamingRule::PRESET_AUTO));
		self::assertFalse(SongPathNamingRule::isDisabledPreset(SongPathNamingRule::PRESET_COMPOSER));
		self::assertFalse(SongPathNamingRule::isDisabledPreset(SongPathNamingRule::PRESET_ARTIST));
		self::assertFalse(SongPathNamingRule::isDisabledPreset(SongPathNamingRule::PRESET_COLLECTION));
	}
}
