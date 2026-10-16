<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace TestSupport;

use PDO;

final class MobileSheetsDatabasePresets {
	/**
	 * @return array<string, array{string, callable(): string}>
	 */
	public static function allPresets(): array {
		return [
			'Production Fixture' => [
				'Real-world production database with 422 songs and all MobileSheets feature tables',
				static fn (): string => dirname(__DIR__, 1) . '/fixtures/mobilesheets.db',
			],
			'Singular Schema Variant' => [
				'Database using Key and SourceType singular tables and Years with Name column',
				static fn (): string => self::createSingularSchemaVariant(),
			],
			'Display & Text Layout Settings' => [
				'Database covering SongDisplaySettings, TextDisplaySettings, Crop, ZoomPerPage, and HalfPagePos',
				static fn (): string => self::createDisplayAndLayoutPreset(),
			],
			'Annotations & Visual Markup' => [
				'Database covering Layers, AnnotationsBase, DrawAnnotations, AnnotationPoints, StampAnnotations, and TextboxAnnotations',
				static fn (): string => self::createAnnotationsPreset(),
			],
			'Audio, Metronome & Bookmarks' => [
				'Database covering AudioFiles, MetronomeSettings (custom sigs), Tempos, and Bookmarks',
				static fn (): string => self::createAudioAndMetronomePreset(),
			],
			'Setlists, Collections & Ordering' => [
				'Database covering Setlists, SetlistSong, SetlistSongNotes, Collections, Books, and custom SortBy/Ascending',
				static fn (): string => self::createSetlistsAndCollectionsPreset(),
			],
			'MIDI Automation, Links & Smart Buttons' => [
				'Database covering MIDI commands, page-jump Links, and SmartButtons',
				static fn (): string => self::createMidiAndControlsPreset(),
			],
			'Multi-File & Media Types' => [
				'Database with multi-file songs, diverse media types (PDF, Image, Text), and complex page orders',
				static fn (): string => self::createMultiFilePreset(),
			],
			'International Unicode & Complex Relations' => [
				'Database with Japanese, Cyrillic, German umlauts, French accents, emojis, and many-to-many relationships',
				static fn (): string => self::createUnicodeAndComplexPreset(),
			],
			'Minimal Schema' => [
				'Minimal MobileSheets database with only baseline tables and columns',
				static fn (): string => self::createMinimalSchemaPreset(),
			],
			'Empty Library' => [
				'Empty valid MobileSheets database with 0 songs and 0 entities',
				static fn (): string => self::createEmptyLibraryPreset(),
			],
		];
	}

	private static function createPdo(string $path): PDO {
		if (file_exists($path)) {
			@unlink($path);
		}

		return new PDO('sqlite:' . $path, options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
	}

	private static function tempDb(): string {
		$path = tempnam(sys_get_temp_dir(), 'ms_preset_');
		if ($path === false) {
			throw new \RuntimeException('Failed to create temporary SQLite database file.');
		}
		return $path . '.db';
	}

	public static function createSingularSchemaVariant(?string $path = null): string {
		$path ??= self::tempDb();
		$db = self::createPdo($path);

		$db->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), Stars INTEGER DEFAULT 0, Difficulty INTEGER DEFAULT 0, Duration INTEGER DEFAULT 0, Keywords VARCHAR(255) DEFAULT \'\', Custom VARCHAR(255) DEFAULT \'\', Custom2 VARCHAR(255) DEFAULT \'\', SongId INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0, Opus VARCHAR(255) DEFAULT \'\', CatalogNumber VARCHAR(255) DEFAULT \'\', SongNumber VARCHAR(255) DEFAULT \'\')');
		$db->exec('CREATE TABLE SongNotes(Id INTEGER PRIMARY KEY, SongId INTEGER, Note TEXT, Title VARCHAR(255) DEFAULT \'\', DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255), PageOrder VARCHAR(255), FileSize INTEGER, LastModified INTEGER, Source INTEGER, Type INTEGER, Password VARCHAR(255) DEFAULT \'\', SourceFilePageCount INTEGER, FileHash INTEGER, Width INTEGER DEFAULT -1, Height INTEGER DEFAULT -1)');
		$db->exec('CREATE TABLE Composer(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE ComposerSongs(Id INTEGER PRIMARY KEY, ComposerId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE "Key"(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE KeySongs(Id INTEGER PRIMARY KEY, KeyId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE SourceType(Id INTEGER PRIMARY KEY, Type VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE SourceTypeSongs(Id INTEGER PRIMARY KEY, SourceTypeId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE Years(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE YearsSongs(Id INTEGER PRIMARY KEY, YearId INTEGER, SongId INTEGER)');

		$db->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, Keywords, Custom, Custom2, SongId, DateCreated, LastModified, Opus, CatalogNumber, SongNumber) VALUES
			(1, 'Sonata No. 1', 'Sonata No. 1', 5, 4, 360, 'classical, piano', 'Studio A', 'Urtext', 1, 1000000, 1700000000000, 'Op. 10', 'Hob. XVI:1', '1'),
			(2, 'Prelude in C', 'Prelude in C', 3, 2, 140, 'baroque', 'Studio B', 'Peters', 2, 1000000, 1700000000000, 'BWV 846', '', '2')");

		$db->exec("INSERT INTO SongNotes (Id, SongId, Note, Title, DateCreated, LastModified) VALUES
			(1, 1, 'Watch tempo at measure 32', 'Performance Note', 1000000, 1700000000000),
			(2, 2, 'Light pedal only', '', 1000000, 1700000000000)");

		$db->exec("INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Source, Type, Password, SourceFilePageCount, FileHash, Width, Height) VALUES
			(1, 1, '/scores/sonata1.pdf', '1-8', 1048576, 1700000000000, 1, 1, '', 8, 123456, 1200, 1600),
			(2, 2, '/scores/prelude_c.pdf', '1-2', 524288, 1700000000000, 1, 1, '', 2, 789012, 1200, 1600)");

		$db->exec("INSERT INTO Composer (Id, Name, SortBy, Ascending, DateCreated, LastModified) VALUES
			(10, 'Haydn, Joseph', 1, 1, 1000000, 1700000000000),
			(20, 'Bach, J.S.', 1, 1, 1000000, 1700000000000)");
		$db->exec('INSERT INTO ComposerSongs (Id, ComposerId, SongId) VALUES (1, 10, 1), (2, 20, 2)');

		$db->exec("INSERT INTO \"Key\" (Id, Name, SortBy, Ascending, DateCreated, LastModified) VALUES
			(30, 'C Major', 1, 1, 1000000, 1700000000000),
			(40, 'F Minor', 1, 1, 1000000, 1700000000000)");
		$db->exec('INSERT INTO KeySongs (Id, KeyId, SongId) VALUES (1, 40, 1), (2, 30, 2)');

		$db->exec("INSERT INTO SourceType (Id, Type, SortBy, Ascending, DateCreated, LastModified) VALUES
			(50, 'Urtext Manuscript', 1, 1, 1000000, 1700000000000),
			(60, 'Sheet Music Book', 1, 1, 1000000, 1700000000000)");
		$db->exec('INSERT INTO SourceTypeSongs (Id, SourceTypeId, SongId) VALUES (1, 50, 1), (2, 60, 2)');

		$db->exec("INSERT INTO Years (Id, Name, SortBy, Ascending, DateCreated, LastModified) VALUES
			(70, '1760', 1, 1, 1000000, 1700000000000),
			(80, '1722', 1, 1, 1000000, 1700000000000)");
		$db->exec('INSERT INTO YearsSongs (Id, YearId, SongId) VALUES (1, 70, 1), (2, 80, 2)');

		return $path;
	}

	public static function createDisplayAndLayoutPreset(?string $path = null): string {
		$path ??= self::tempDb();
		$db = self::createPdo($path);

		$db->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), Stars INTEGER DEFAULT 0, Difficulty INTEGER DEFAULT 0, Duration INTEGER DEFAULT 0, Keywords VARCHAR(255) DEFAULT \'\', Custom VARCHAR(255) DEFAULT \'\', Custom2 VARCHAR(255) DEFAULT \'\', SongId INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0, Opus VARCHAR(255) DEFAULT \'\', CatalogNumber VARCHAR(255) DEFAULT \'\', SongNumber VARCHAR(255) DEFAULT \'\')');
		$db->exec('CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255), PageOrder VARCHAR(255), FileSize INTEGER, LastModified INTEGER, Source INTEGER, Type INTEGER, Password VARCHAR(255) DEFAULT \'\', SourceFilePageCount INTEGER, FileHash INTEGER, Width INTEGER DEFAULT -1, Height INTEGER DEFAULT -1)');
		$db->exec('CREATE TABLE SongDisplaySettings(Id INTEGER PRIMARY KEY, SongId INTEGER, UseDefaultAdapter INTEGER, PortraitAdapterType INTEGER, LandscapeAdapterType INTEGER, UseDefaultScaleMode INTEGER, PortraitScaleMode INTEGER, LandscapeScaleMode INTEGER)');
		$db->exec('CREATE TABLE TextDisplaySettings(Id INTEGER PRIMARY KEY, FileId INTEGER, SongId INTEGER, FontFamily INTEGER, DefaultFont VARCHAR(255), TitleSize INTEGER, TitleFont VARCHAR(255), TitleBold INTEGER, TitleItalic INTEGER, TitleColor INTEGER, ShowTitle INTEGER, SubtitleSize INTEGER, SubtitleFont VARCHAR(255), SubtitleBold INTEGER, SubtitleItalic INTEGER, SubtitleColor INTEGER, ShowSubtitle INTEGER, MetaSize INTEGER, MetaFont VARCHAR(255), MetaBold INTEGER, MetaItalic INTEGER, MetaColor INTEGER, ShowMeta INTEGER, LyricsSize INTEGER, LyricsFont VARCHAR(255), LyricsBold INTEGER, LyricsItalic INTEGER, LyricsColor INTEGER, ShowLyrics INTEGER, ChordsSize INTEGER, ChordsFont VARCHAR(255), ChordStyle INTEGER, ChordColor INTEGER, ChordHighlight INTEGER, ShowChords INTEGER, ChorusSize INTEGER, ChorusFont VARCHAR(255), ChorusBold INTEGER, ChorusItalic INTEGER, ChorusColor INTEGER, TabSize INTEGER, TabFont VARCHAR(255), TabBold INTEGER, TabItalic INTEGER, TabColor INTEGER, ShowTabs INTEGER, GridTextSize INTEGER, GridChordFont VARCHAR(255), GridChordBold INTEGER, GridChordItalic INTEGER, GridChordColor INTEGER, ShowGrids INTEGER, GridCommentsSize INTEGER, GridCommentsFont VARCHAR(255), GridCommentsBold INTEGER, GridCommentsItalic INTEGER, GridCommentsColor INTEGER, GridCommentsHighlight INTEGER, CommentsSize INTEGER, CommentsFont VARCHAR(255), CommentsBold INTEGER, CommentsItalic INTEGER, CommentsColor INTEGER, ShowComments INTEGER, CommentsItalicSize INTEGER, CommentsItalicFont VARCHAR(255), CommentsItalicBold INTEGER, CommentsItalicItalic INTEGER, CommentsItalicColor INTEGER, CommentsItalicHighlight INTEGER, ShowCommentsItalic INTEGER, CommentBoxSize INTEGER, CommentBoxFont VARCHAR(255), CommentBoxBold INTEGER, CommentBoxItalic INTEGER, CommentBoxColor INTEGER, CommentBoxHighlight INTEGER, ShowCommentBox INTEGER, FooterSize INTEGER, FooterFont VARCHAR(255), FooterBold INTEGER, FooterItalic INTEGER, FooterColor INTEGER, ShowFooter INTEGER, LabelsSize INTEGER, LabelsFont VARCHAR(255), LabelsBold INTEGER, LabelsItalic INTEGER, LabelsColor INTEGER, LabelsUnderline INTEGER, ShowLabels INTEGER, DiagramTextSize INTEGER, DiagramTextFont VARCHAR(255), DiagramTextBold INTEGER, DiagramTextItalic INTEGER, DiagramTextColor INTEGER, LineSpacing FLOAT, ChordLineSpacing FLOAT, UseImprovedSpacing INTEGER, GridLineColor INTEGER, GridLineThickness INTEGER, ShowDiagrams INTEGER, ShowChorusLabel INTEGER, SectionIndent INTEGER, DiagramScale FLOAT, Instrument VARCHAR(255), Transpose INTEGER, TransposeKey INTEGER, Capo INTEGER, NumberChords INTEGER, EnableTranpose INTEGER, EnableCapo INTEGER, Structure VARCHAR(255), Key INTEGER, Encoding INTEGER, RTL INTEGER, UseSharps INTEGER DEFAULT 0, NumberOfSteps INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE Crop(Id INTEGER PRIMARY KEY, SongId INTEGER, Page INTEGER, Left INTEGER, Top INTEGER, Right INTEGER, Bottom INTEGER, Rotation INTEGER)');
		$db->exec('CREATE TABLE ZoomPerPage(Id INTEGER PRIMARY KEY, SongId INTEGER, Page INTEGER, Zoom FLOAT, PanX FLOAT, PanY FLOAT)');
		$db->exec('CREATE TABLE HalfPagePos(Id INTEGER PRIMARY KEY, SongId INTEGER, Page INTEGER, SplitPos FLOAT)');

		$db->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, SongId, DateCreated, LastModified) VALUES
			(1, 'Nocturne in E-Flat', 'Nocturne in E-Flat', 5, 4, 270, 1, 1000000, 1700000000000)");
		$db->exec("INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Source, Type, SourceFilePageCount, FileHash) VALUES
			(1, 1, '/scores/nocturne.pdf', '1-4', 204800, 1700000000000, 1, 1, 4, 112233)");

		$db->exec('INSERT INTO SongDisplaySettings (Id, SongId, UseDefaultAdapter, PortraitAdapterType, LandscapeAdapterType, UseDefaultScaleMode, PortraitScaleMode, LandscapeScaleMode) VALUES
			(1, 1, 0, 1, 2, 0, 1, 1)');

		$db->exec("INSERT INTO TextDisplaySettings (Id, FileId, SongId, FontFamily, DefaultFont, TitleSize, TitleFont, TitleBold, TitleItalic, TitleColor, ShowTitle, SubtitleSize, SubtitleFont, SubtitleBold, SubtitleItalic, SubtitleColor, ShowSubtitle, MetaSize, MetaFont, MetaBold, MetaItalic, MetaColor, ShowMeta, LyricsSize, LyricsFont, LyricsBold, LyricsItalic, LyricsColor, ShowLyrics, ChordsSize, ChordsFont, ChordStyle, ChordColor, ChordHighlight, ShowChords, ChorusSize, ChorusFont, ChorusBold, ChorusItalic, ChorusColor, TabSize, TabFont, TabBold, TabItalic, TabColor, ShowTabs, GridTextSize, GridChordFont, GridChordBold, GridChordItalic, GridChordColor, ShowGrids, GridCommentsSize, GridCommentsFont, GridCommentsBold, GridCommentsItalic, GridCommentsColor, GridCommentsHighlight, CommentsSize, CommentsFont, CommentsBold, CommentsItalic, CommentsColor, ShowComments, CommentsItalicSize, CommentsItalicFont, CommentsItalicBold, CommentsItalicItalic, CommentsItalicColor, CommentsItalicHighlight, ShowCommentsItalic, CommentBoxSize, CommentBoxFont, CommentBoxBold, CommentBoxItalic, CommentBoxColor, CommentBoxHighlight, ShowCommentBox, FooterSize, FooterFont, FooterBold, FooterItalic, FooterColor, ShowFooter, LabelsSize, LabelsFont, LabelsBold, LabelsItalic, LabelsColor, LabelsUnderline, ShowLabels, DiagramTextSize, DiagramTextFont, DiagramTextBold, DiagramTextItalic, DiagramTextColor, LineSpacing, ChordLineSpacing, UseImprovedSpacing, GridLineColor, GridLineThickness, ShowDiagrams, ShowChorusLabel, SectionIndent, DiagramScale, Instrument, Transpose, TransposeKey, Capo, NumberChords, EnableTranpose, EnableCapo, Structure, Key, Encoding, RTL, UseSharps, NumberOfSteps) VALUES
			(1, 1, 1, 1, 'SansSerif', 24, 'SansSerif', 1, 0, -16777216, 1, 18, 'SansSerif', 0, 1, -12303292, 1, 14, 'SansSerif', 0, 0, -7829368, 1, 16, 'SansSerif', 0, 0, -16777216, 1, 18, 'SansSerif', 1, -65536, 0, 1, 16, 'SansSerif', 1, 0, -16777216, 14, 'Monospace', 0, 0, -16777216, 0, 14, 'Monospace', 0, 0, -16777216, 0, 12, 'SansSerif', 0, 0, -7829368, 0, 14, 'SansSerif', 0, 0, -7829368, 1, 14, 'SansSerif', 0, 1, -7829368, 0, 1, 14, 'SansSerif', 0, 0, -16777216, 0, 0, 12, 'SansSerif', 0, 0, -7829368, 1, 14, 'SansSerif', 0, 0, -16777216, 0, 1, 12, 'SansSerif', 0, 0, -16777216, 1.2, 1.0, 1, -7829368, 1, 0, 1, 0, 1.0, 'Piano', 2, 0, 0, 0, 1, 0, 'Intro,Verse,Chorus,Outro', 3, 0, 0, 0, 0)");

		$db->exec('INSERT INTO Crop (Id, SongId, Page, Left, Top, Right, Bottom, Rotation) VALUES
			(1, 1, 1, 40, 50, 1160, 1550, 0),
			(2, 1, 2, 45, 50, 1155, 1550, 0)');

		$db->exec('INSERT INTO ZoomPerPage (Id, SongId, Page, Zoom, PanX, PanY) VALUES
			(1, 1, 1, 1.15, 10.5, 0.0)');

		$db->exec('INSERT INTO HalfPagePos (Id, SongId, Page, SplitPos) VALUES
			(1, 1, 1, 0.52)');

		return $path;
	}

	public static function createAnnotationsPreset(?string $path = null): string {
		$path ??= self::tempDb();
		$db = self::createPdo($path);

		$db->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), Stars INTEGER DEFAULT 0, Difficulty INTEGER DEFAULT 0, Duration INTEGER DEFAULT 0, Keywords VARCHAR(255) DEFAULT \'\', Custom VARCHAR(255) DEFAULT \'\', Custom2 VARCHAR(255) DEFAULT \'\', SongId INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0, Opus VARCHAR(255) DEFAULT \'\', CatalogNumber VARCHAR(255) DEFAULT \'\', SongNumber VARCHAR(255) DEFAULT \'\')');
		$db->exec('CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255), PageOrder VARCHAR(255), FileSize INTEGER, LastModified INTEGER, Source INTEGER, Type INTEGER, Password VARCHAR(255) DEFAULT \'\', SourceFilePageCount INTEGER, FileHash INTEGER, Width INTEGER DEFAULT -1, Height INTEGER DEFAULT -1)');
		$db->exec('CREATE TABLE Layers(Id INTEGER PRIMARY KEY, SongId INTEGER, Page INTEGER, LayerIndex INTEGER, Name INTEGER, Visible INTEGER)');
		$db->exec('CREATE TABLE AnnotationsBase(Id INTEGER PRIMARY KEY, SongId INTEGER, Page INTEGER, GroupId INTEGER, Type INTEGER, LayerId INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE DrawAnnotations(Id INTEGER PRIMARY KEY, BaseId INTEGER, LineColor INTEGER, FillColor INTEGER, LineWidth FLOAT, DrawMode INTEGER, PenMode INTEGER, SmoothMode INTEGER)');
		$db->exec('CREATE TABLE AnnotationPoints(Id INTEGER PRIMARY KEY, AnnotationId INTEGER, Points BLOB, Count INTEGER)');
		$db->exec('CREATE TABLE StampAnnotations(Id INTEGER PRIMARY KEY, BaseId INTEGER, Type INTEGER, Size FLOAT, FilePath VARCHAR(255), Color INTEGER, Font INTEGER, Symbol VARCHAR(255))');
		$db->exec('CREATE TABLE TextboxAnnotations(Id INTEGER PRIMARY KEY, BaseId INTEGER, TextColor INTEGER, Text VARCHAR(255), FontFamily INTEGER, FontSize FLOAT, FontStyle INTEGER, FillColor INTEGER, BorderColor INTEGER, TextAlign INTEGER, HasBorder INTEGER, BorderWidth FLOAT, AutoSize INTEGER, LineSpacing FLOAT)');

		$db->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, SongId, DateCreated, LastModified) VALUES
			(1, 'Annotations Showcase Piece', 'Annotations Showcase Piece', 4, 3, 200, 1, 1000000, 1700000000000)");
		$db->exec("INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Source, Type, SourceFilePageCount, FileHash) VALUES
			(1, 1, '/scores/annotated.pdf', '1-3', 150000, 1700000000000, 1, 1, 3, 987654)");

		$db->exec('INSERT INTO Layers (Id, SongId, Page, LayerIndex, Name, Visible) VALUES
			(1, 1, 1, 0, 1, 1),
			(2, 1, 1, 1, 2, 1)');

		$db->exec('INSERT INTO AnnotationsBase (Id, SongId, Page, GroupId, Type, LayerId, DateCreated, LastModified) VALUES
			(1, 1, 1, 0, 1, 1, 1000000, 1700000000000),
			(2, 1, 1, 0, 2, 1, 1000000, 1700000000000),
			(3, 1, 1, 0, 3, 2, 1000000, 1700000000000)');

		$db->exec('INSERT INTO DrawAnnotations (Id, BaseId, LineColor, FillColor, LineWidth, DrawMode, PenMode, SmoothMode) VALUES
			(1, 1, -65536, 0, 3.5, 0, 1, 1)');

		$db->exec("INSERT INTO AnnotationPoints (Id, AnnotationId, Points, Count) VALUES
			(1, 1, X'0102030405060708', 2)");

		$db->exec("INSERT INTO StampAnnotations (Id, BaseId, Type, Size, FilePath, Color, Font, Symbol) VALUES
			(1, 2, 4, 32.0, '', -16777216, 0, 'fermata')");

		$db->exec("INSERT INTO TextboxAnnotations (Id, BaseId, TextColor, Text, FontFamily, FontSize, FontStyle, FillColor, BorderColor, TextAlign, HasBorder, BorderWidth, AutoSize, LineSpacing) VALUES
			(1, 3, -16777216, 'Ritardando molto', 0, 16.0, 1, -1, -16777216, 0, 1, 1.5, 1, 1.0)");

		return $path;
	}

	public static function createAudioAndMetronomePreset(?string $path = null): string {
		$path ??= self::tempDb();
		$db = self::createPdo($path);

		$db->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), Stars INTEGER DEFAULT 0, Difficulty INTEGER DEFAULT 0, Duration INTEGER DEFAULT 0, Keywords VARCHAR(255) DEFAULT \'\', Custom VARCHAR(255) DEFAULT \'\', Custom2 VARCHAR(255) DEFAULT \'\', SongId INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0, Opus VARCHAR(255) DEFAULT \'\', CatalogNumber VARCHAR(255) DEFAULT \'\', SongNumber VARCHAR(255) DEFAULT \'\')');
		$db->exec('CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255), PageOrder VARCHAR(255), FileSize INTEGER, LastModified INTEGER, Source INTEGER, Type INTEGER, Password VARCHAR(255) DEFAULT \'\', SourceFilePageCount INTEGER, FileHash INTEGER, Width INTEGER DEFAULT -1, Height INTEGER DEFAULT -1)');
		$db->exec('CREATE TABLE AudioFiles(Id INTEGER PRIMARY KEY, SongId INTEGER, Title VARCHAR(255), File VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE MetronomeSettings(Id INTEGER PRIMARY KEY, SongId INTEGER, Bpm INTEGER, Sig1 INTEGER DEFAULT 4, Sig2 INTEGER DEFAULT 4)');
		$db->exec('CREATE TABLE Tempos(Id INTEGER PRIMARY KEY, SongId INTEGER, Tempo INTEGER, TempoIndex INTEGER)');
		$db->exec('CREATE TABLE Bookmarks(Id INTEGER PRIMARY KEY, SongId INTEGER, Name VARCHAR(255), PageNum INTEGER, ShowInLibrary INTEGER DEFAULT 0, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');

		$db->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, SongId, DateCreated, LastModified) VALUES
			(1, 'Audio Metronome Suite', 'Audio Metronome Suite', 5, 4, 320, 1, 1000000, 1700000000000)");
		$db->exec("INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Source, Type, SourceFilePageCount, FileHash) VALUES
			(1, 1, '/scores/suite.pdf', '1-12', 450000, 1700000000000, 1, 1, 12, 445566)");

		$db->exec("INSERT INTO AudioFiles (Id, SongId, Title, File, DateCreated, LastModified) VALUES
			(1, 1, 'Piano Backing Track', '/audio/suite_backing.mp3', 1000000, 1700000000000),
			(2, 1, 'Click Track 120BPM', '/audio/click_120.mp3', 1000000, 1700000000000)");

		$db->exec('INSERT INTO MetronomeSettings (Id, SongId, Bpm, Sig1, Sig2) VALUES
			(1, 1, 128, 6, 8)');

		$db->exec('INSERT INTO Tempos (Id, SongId, Tempo, TempoIndex) VALUES
			(1, 1, 128, 0),
			(2, 1, 96, 1)');

		$db->exec("INSERT INTO Bookmarks (Id, SongId, Name, PageNum, ShowInLibrary, DateCreated, LastModified) VALUES
			(1, 1, 'Movement I: Allegro', 1, 1, 1000000, 1700000000000),
			(2, 1, 'Cadenza', 5, 0, 1000000, 1700000000000),
			(3, 1, 'Movement II: Adagio', 8, 1, 1000000, 1700000000000)");

		return $path;
	}

	public static function createSetlistsAndCollectionsPreset(?string $path = null): string {
		$path ??= self::tempDb();
		$db = self::createPdo($path);

		$db->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), Stars INTEGER DEFAULT 0, Difficulty INTEGER DEFAULT 0, Duration INTEGER DEFAULT 0, Keywords VARCHAR(255) DEFAULT \'\', Custom VARCHAR(255) DEFAULT \'\', Custom2 VARCHAR(255) DEFAULT \'\', SongId INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0, Opus VARCHAR(255) DEFAULT \'\', CatalogNumber VARCHAR(255) DEFAULT \'\', SongNumber VARCHAR(255) DEFAULT \'\')');
		$db->exec('CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255), PageOrder VARCHAR(255), FileSize INTEGER, LastModified INTEGER, Source INTEGER, Type INTEGER, Password VARCHAR(255) DEFAULT \'\', SourceFilePageCount INTEGER, FileHash INTEGER, Width INTEGER DEFAULT -1, Height INTEGER DEFAULT -1)');
		$db->exec('CREATE TABLE Setlists(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE SetlistSong(Id INTEGER PRIMARY KEY, SetlistId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE Collections(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE CollectionSong(Id INTEGER PRIMARY KEY, CollectionId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE Books(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE BookSongs(Id INTEGER PRIMARY KEY, BookId INTEGER, SongId INTEGER)');

		$db->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, SongId, DateCreated, LastModified) VALUES
			(1, 'Gig Song A', 'Gig Song A', 4, 3, 180, 1, 1000000, 1700000000000),
			(2, 'Gig Song B', 'Gig Song B', 5, 4, 240, 2, 1000000, 1700000000000),
			(3, 'Encore Song', 'Encore Song', 3, 2, 150, 3, 1000000, 1700000000000)");

		$db->exec("INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Source, Type, SourceFilePageCount, FileHash) VALUES
			(1, 1, '/scores/song_a.pdf', '1-2', 100000, 1700000000000, 1, 1, 2, 111),
			(2, 2, '/scores/song_b.pdf', '1-3', 150000, 1700000000000, 1, 1, 3, 222),
			(3, 3, '/scores/encore.pdf', '1', 50000, 1700000000000, 1, 1, 1, 333)");

		$db->exec("INSERT INTO Setlists (Id, Name, DateCreated, LastModified) VALUES
			(1, 'Friday Jazz Club', 1000000, 1700000000000),
			(2, 'Festival Highlights', 1000000, 1700000000000)");

		$db->exec('INSERT INTO SetlistSong (Id, SetlistId, SongId) VALUES
			(1, 1, 1),
			(2, 1, 2),
			(3, 1, 3),
			(4, 2, 2),
			(5, 2, 3)');

		$db->exec("INSERT INTO Collections (Id, Name, SortBy, Ascending, DateCreated, LastModified) VALUES
			(10, 'Standard Repertoire', 2, 0, 1000000, 1700000000000),
			(20, 'Encore Pieces', 1, 1, 1000000, 1700000000000)");
		$db->exec('INSERT INTO CollectionSong (Id, CollectionId, SongId) VALUES
			(1, 10, 1),
			(2, 10, 2),
			(3, 20, 3)');

		$db->exec("INSERT INTO Books (Id, Title, SortBy, Ascending, DateCreated, LastModified) VALUES
			(100, 'The Real Book Vol 1', 3, 1, 1000000, 1700000000000)");
		$db->exec('INSERT INTO BookSongs (Id, BookId, SongId) VALUES
			(1, 100, 1),
			(2, 100, 2)');

		return $path;
	}

	public static function createMidiAndControlsPreset(?string $path = null): string {
		$path ??= self::tempDb();
		$db = self::createPdo($path);

		$db->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), Stars INTEGER DEFAULT 0, Difficulty INTEGER DEFAULT 0, Duration INTEGER DEFAULT 0, Keywords VARCHAR(255) DEFAULT \'\', Custom VARCHAR(255) DEFAULT \'\', Custom2 VARCHAR(255) DEFAULT \'\', SongId INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0, Opus VARCHAR(255) DEFAULT \'\', CatalogNumber VARCHAR(255) DEFAULT \'\', SongNumber VARCHAR(255) DEFAULT \'\')');
		$db->exec('CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255), PageOrder VARCHAR(255), FileSize INTEGER, LastModified INTEGER, Source INTEGER, Type INTEGER, Password VARCHAR(255) DEFAULT \'\', SourceFilePageCount INTEGER, FileHash INTEGER, Width INTEGER DEFAULT -1, Height INTEGER DEFAULT -1)');
		$db->exec('CREATE TABLE MIDI(Id INTEGER PRIMARY KEY, SongId INTEGER, CommandType INTEGER, Cable INTEGER, Channel INTEGER, MSB INTEGER, LSB INTEGER, Value INTEGER, CustomField INTEGER, SendOnLoad INTEGER, LoadOnRecv INTEGER, SendMSB INTEGER DEFAULT 1, SendLSB INTEGER DEFAULT 1, SendValue INTEGER DEFAULT 1, InputPort VARCHAR(255) DEFAULT \'\', OutputPort VARCHAR(255) DEFAULT \'\', Label VARCHAR(255) DEFAULT \'\')');
		$db->exec('CREATE TABLE Links(Id INTEGER PRIMARY KEY, SongId INTEGER, StartPointX FLOAT, StartPointY FLOAT, EndPointX FLOAT, EndPointY FLOAT, StartPage INTEGER, EndPage INTEGER, ZoomXStart FLOAT, ZoomYStart FLOAT, ZoomXEnd FLOAT, ZoomYEnd FLOAT, Radius INTEGER, Version INTEGER)');
		$db->exec('CREATE TABLE SmartButtons(Id INTEGER PRIMARY KEY, SongId INTEGER, Label VARCHAR(255), Page INTEGER, Action INTEGER, Value INTEGER, Value2 INTEGER, XPos FLOAT, YPos FLOAT, ZoomX FLOAT, ZoomY FLOAT, File VARCHAR(255), Size INTEGER, Version INTEGER)');

		$db->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, SongId, DateCreated, LastModified) VALUES
			(1, 'Electronic Concerto', 'Electronic Concerto', 5, 5, 480, 1, 1000000, 1700000000000)");
		$db->exec("INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Source, Type, SourceFilePageCount, FileHash) VALUES
			(1, 1, '/scores/elec_concerto.pdf', '1-16', 800000, 1700000000000, 1, 1, 16, 778899)");

		$db->exec("INSERT INTO MIDI (Id, SongId, CommandType, Cable, Channel, MSB, LSB, Value, CustomField, SendOnLoad, LoadOnRecv, SendMSB, SendLSB, SendValue, InputPort, OutputPort, Label) VALUES
			(1, 1, 0, 0, 1, 0, 32, 15, 0, 1, 0, 1, 1, 1, 'USB MIDI In', 'Nord Stage 3', 'Patch: Warm Pad')");

		$db->exec('INSERT INTO Links (Id, SongId, StartPointX, StartPointY, EndPointX, EndPointY, StartPage, EndPage, ZoomXStart, ZoomYStart, ZoomXEnd, ZoomYEnd, Radius, Version) VALUES
			(1, 1, 1050.0, 1400.0, 80.0, 200.0, 4, 2, 1.0, 1.0, 1.0, 1.0, 25, 1)');

		$db->exec("INSERT INTO SmartButtons (Id, SongId, Label, Page, Action, Value, Value2, XPos, YPos, ZoomX, ZoomY, File, Size, Version) VALUES
			(1, 1, 'Start Backing', 1, 3, 1, 0, 100.0, 50.0, 1.0, 1.0, '', 36, 1)");

		return $path;
	}

	public static function createMultiFilePreset(?string $path = null): string {
		$path ??= self::tempDb();
		$db = self::createPdo($path);

		$db->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), Stars INTEGER DEFAULT 0, Difficulty INTEGER DEFAULT 0, Duration INTEGER DEFAULT 0, Keywords VARCHAR(255) DEFAULT \'\', Custom VARCHAR(255) DEFAULT \'\', Custom2 VARCHAR(255) DEFAULT \'\', SongId INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0, Opus VARCHAR(255) DEFAULT \'\', CatalogNumber VARCHAR(255) DEFAULT \'\', SongNumber VARCHAR(255) DEFAULT \'\')');
		$db->exec('CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255), PageOrder VARCHAR(255), FileSize INTEGER, LastModified INTEGER, Source INTEGER, Type INTEGER, Password VARCHAR(255) DEFAULT \'\', SourceFilePageCount INTEGER, FileHash INTEGER, Width INTEGER DEFAULT -1, Height INTEGER DEFAULT -1)');

		$db->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, SongId, DateCreated, LastModified) VALUES
			(1, 'Multi-File Chamber Suite', 'Multi-File Chamber Suite', 4, 3, 300, 1, 1000000, 1700000000000),
			(2, 'Single Page Chords', 'Single Page Chords', 2, 1, 120, 2, 1000000, 1700000000000)");

		// Song 1 has 3 files: 1 PDF score (Type 1), 1 Image scan (Type 2), 1 Text file (Type 3)
		$db->exec("INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Source, Type, Password, SourceFilePageCount, FileHash, Width, Height) VALUES
			(1, 1, '/scores/chamber_full_score.pdf', '1,3,5,2-4', 2097152, 1700000000000, 1, 1, 'secret', 5, 111222, 1400, 1980),
			(2, 1, '/scores/chamber_cover.png', '1', 524288, 1700000000000, 1, 2, '', 1, 333444, 1200, 1600),
			(3, 1, '/scores/chamber_lyrics.txt', '1', 4096, 1700000000000, 1, 3, '', 1, 555666, -1, -1),
			(4, 2, '/scores/chords.pro', '1', 2048, 1700000000000, 1, 3, '', 1, 777888, -1, -1)");

		return $path;
	}

	public static function createUnicodeAndComplexPreset(?string $path = null): string {
		$path ??= self::tempDb();
		$db = self::createPdo($path);

		$db->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), Stars INTEGER DEFAULT 0, Difficulty INTEGER DEFAULT 0, Duration INTEGER DEFAULT 0, Keywords VARCHAR(255) DEFAULT \'\', Custom VARCHAR(255) DEFAULT \'\', Custom2 VARCHAR(255) DEFAULT \'\', SongId INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0, Opus VARCHAR(255) DEFAULT \'\', CatalogNumber VARCHAR(255) DEFAULT \'\', SongNumber VARCHAR(255) DEFAULT \'\')');
		$db->exec('CREATE TABLE SongNotes(Id INTEGER PRIMARY KEY, SongId INTEGER, Note TEXT, Title VARCHAR(255) DEFAULT \'\', DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255), PageOrder VARCHAR(255), FileSize INTEGER, LastModified INTEGER, Source INTEGER, Type INTEGER, Password VARCHAR(255) DEFAULT \'\', SourceFilePageCount INTEGER, FileHash INTEGER, Width INTEGER DEFAULT -1, Height INTEGER DEFAULT -1)');
		$db->exec('CREATE TABLE Composer(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE ComposerSongs(Id INTEGER PRIMARY KEY, ComposerId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE Artists(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE ArtistsSongs(Id INTEGER PRIMARY KEY, ArtistId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE Genres(Id INTEGER PRIMARY KEY, Type VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE GenresSongs(Id INTEGER PRIMARY KEY, GenreId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE Keys(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE KeySongs(Id INTEGER PRIMARY KEY, KeyId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE CustomGroup(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE CustomGroupSongs(Id INTEGER PRIMARY KEY, GroupId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE Signature(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE SignatureSongs(Id INTEGER PRIMARY KEY, SignatureId INTEGER, SongId INTEGER)');

		// Unicode titles: Japanese, Russian, German, French, and Emojis
		$db->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, Keywords, Custom, Custom2, SongId, DateCreated, LastModified, Opus, CatalogNumber, SongNumber) VALUES
			(1, '夜に駆ける (Racing into the Night) 🎵', 'Racing into the Night', 5, 4, 260, 'J-Pop, ピアノ, アニメ', 'Vocaloid', 'Special Edition', 1, 1000000, 1700000000000, 'Op. 1', 'JP-2020', '1'),
			(2, 'Лебединое озеро — Танец маленьких лебедей', 'Lebedinoe Ozero', 4, 3, 100, 'балет, классика', 'Большой театр', 'Act II', 2, 1000000, 1700000000000, 'Op. 20', 'TH 12', '4'),
			(3, 'Matthäus-Passion: »Erbarme dich, mein Gott«', 'Matthaeus-Passion', 5, 5, 420, 'Barock, Arie, Alt', 'Thomaskirche Leipzig', 'Bärenreiter', 3, 1000000, 1700000000000, 'BWV 244', 'No. 39', '39')");

		$db->exec("INSERT INTO SongNotes (Id, SongId, Note, Title, DateCreated, LastModified) VALUES
			(1, 1, 'テンポ 130 BPM。スラーとスタッカートの対比に注意。', 'メモ', 1000000, 1700000000000),
			(2, 2, 'Чёткая артикуляция фаготов и гобоев. Легато у скрипок.', 'Заметка дирижёра', 1000000, 1700000000000),
			(3, 3, 'Solo-Violine con sordino. Sehr getragen spielen.', 'Aufführungspraxis', 1000000, 1700000000000)");

		$db->exec("INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Source, Type, SourceFilePageCount, FileHash) VALUES
			(1, 1, '/楽譜/夜に駆ける.pdf', '1-5', 600000, 1700000000000, 1, 1, 5, 121212),
			(2, 2, '/ноты/лебединое_озеро.pdf', '1-2', 300000, 1700000000000, 1, 1, 2, 343434),
			(3, 3, '/noten/matthäus_erbarme_dich.pdf', '1-4', 450000, 1700000000000, 1, 1, 4, 565656)");

		$db->exec("INSERT INTO Composer (Id, Name, SortBy, Ascending, DateCreated, LastModified) VALUES
			(10, 'Ayase / YOASOBI', 1, 1, 1000000, 1700000000000),
			(20, 'Чайковский, Пётр Ильич', 1, 1, 1000000, 1700000000000),
			(30, 'Bach, Johann Sebastian', 1, 1, 1000000, 1700000000000)");
		$db->exec('INSERT INTO ComposerSongs (Id, ComposerId, SongId) VALUES (1, 10, 1), (2, 20, 2), (3, 30, 3)');

		$db->exec("INSERT INTO Artists (Id, Name, SortBy, Ascending, DateCreated, LastModified) VALUES
			(40, '幾田りら (ikura)', 1, 1, 1000000, 1700000000000)");
		$db->exec('INSERT INTO ArtistsSongs (Id, ArtistId, SongId) VALUES (1, 40, 1)');

		$db->exec("INSERT INTO Genres (Id, Type, SortBy, Ascending, DateCreated, LastModified) VALUES
			(50, 'J-Pop / Electronic ⚡', 1, 1, 1000000, 1700000000000),
			(60, 'Ballet / Романтизм', 1, 1, 1000000, 1700000000000),
			(70, 'Geistliche Musik / Sakral', 1, 1, 1000000, 1700000000000)");
		$db->exec('INSERT INTO GenresSongs (Id, GenreId, SongId) VALUES (1, 50, 1), (2, 60, 2), (3, 70, 3)');

		$db->exec("INSERT INTO Keys (Id, Name, SortBy, Ascending, DateCreated, LastModified) VALUES
			(80, 'E-Flat Minor / Es-Moll', 1, 1, 1000000, 1700000000000),
			(90, 'F-Sharp Minor / Fis-Moll', 1, 1, 1000000, 1700000000000),
			(100, 'B Minor / H-Moll', 1, 1, 1000000, 1700000000000)");
		$db->exec('INSERT INTO KeySongs (Id, KeyId, SongId) VALUES (1, 80, 1), (2, 90, 2), (3, 100, 3)');

		$db->exec("INSERT INTO CustomGroup (Id, Name, SortBy, Ascending, DateCreated, LastModified) VALUES
			(110, 'Ensemble: Streichquartett 🎻', 1, 1, 1000000, 1700000000000),
			(120, 'Kammerorchester', 1, 1, 1000000, 1700000000000)");
		$db->exec('INSERT INTO CustomGroupSongs (Id, GroupId, SongId) VALUES (1, 110, 2), (2, 120, 3)');

		$db->exec("INSERT INTO Signature (Id, Name, SortBy, Ascending, DateCreated, LastModified) VALUES
			(130, '4/4', 1, 1, 1000000, 1700000000000),
			(140, '2/4', 1, 1, 1000000, 1700000000000),
			(150, '12/8', 1, 1, 1000000, 1700000000000)");
		$db->exec('INSERT INTO SignatureSongs (Id, SignatureId, SongId) VALUES (1, 130, 1), (2, 140, 2), (3, 150, 3)');

		return $path;
	}

	public static function createMinimalSchemaPreset(?string $path = null): string {
		$path ??= self::tempDb();
		$db = self::createPdo($path);

		// Minimal schema without optional columns in Songs or Files
		$db->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), Stars INTEGER DEFAULT 0, Difficulty INTEGER DEFAULT 0, Duration INTEGER DEFAULT 0, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255), PageOrder VARCHAR(255), FileSize INTEGER, LastModified INTEGER, Source INTEGER, Type INTEGER)');
		$db->exec('CREATE TABLE Composer(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE ComposerSongs(Id INTEGER PRIMARY KEY, ComposerId INTEGER, SongId INTEGER)');

		$db->exec("INSERT INTO Songs (Id, Title, SortTitle, Stars, Difficulty, Duration, DateCreated, LastModified) VALUES
			(1, 'Minimalist Melody', 'Minimalist Melody', 3, 1, 90, 1000000, 1700000000000)");
		$db->exec("INSERT INTO Files (Id, SongId, Path, PageOrder, FileSize, LastModified, Source, Type) VALUES
			(1, 1, '/scores/minimal.pdf', '1', 50000, 1700000000000, 1, 1)");
		$db->exec("INSERT INTO Composer (Id, Name, DateCreated, LastModified) VALUES
			(10, 'Glass, Philip', 1000000, 1700000000000)");
		$db->exec('INSERT INTO ComposerSongs (Id, ComposerId, SongId) VALUES (1, 10, 1)');

		return $path;
	}

	public static function createEmptyLibraryPreset(?string $path = null): string {
		$path ??= self::tempDb();
		$db = self::createPdo($path);

		$db->exec('CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), Stars INTEGER DEFAULT 0, Difficulty INTEGER DEFAULT 0, Duration INTEGER DEFAULT 0, Keywords VARCHAR(255) DEFAULT \'\', Custom VARCHAR(255) DEFAULT \'\', Custom2 VARCHAR(255) DEFAULT \'\', SongId INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0, Opus VARCHAR(255) DEFAULT \'\', CatalogNumber VARCHAR(255) DEFAULT \'\', SongNumber VARCHAR(255) DEFAULT \'\')');
		$db->exec('CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255), PageOrder VARCHAR(255), FileSize INTEGER, LastModified INTEGER, Source INTEGER, Type INTEGER, Password VARCHAR(255) DEFAULT \'\', SourceFilePageCount INTEGER, FileHash INTEGER, Width INTEGER DEFAULT -1, Height INTEGER DEFAULT -1)');
		$db->exec('CREATE TABLE Composer(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE ComposerSongs(Id INTEGER PRIMARY KEY, ComposerId INTEGER, SongId INTEGER)');
		$db->exec('CREATE TABLE Collections(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)');
		$db->exec('CREATE TABLE CollectionSong(Id INTEGER PRIMARY KEY, CollectionId INTEGER, SongId INTEGER)');

		return $path;
	}
}
