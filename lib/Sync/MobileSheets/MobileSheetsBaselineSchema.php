<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

/**
 * Baseline MobileSheets SQLite database schema extracted from reference MobileSheets database fixture.
 */
final class MobileSheetsBaselineSchema {
	public const DEFAULT_USER_VERSION = 63;
	public const DEFAULT_PAGE_SIZE = 1024;

	/**
	 * Recognized baseline columns in MobileSheets Files table.
	 * Any columns outside this set sent by clients will trigger an unknown column warning.
	 *
	 * @var list<string>
	 */
	public const KNOWN_FILE_COLUMNS = [
		'id',
		'songid',
		'path',
		'pageorder',
		'filesize',
		'lastmodified',
		'source',
		'type',
		'password',
		'sourcefilepagecount',
		'filehash',
		'width',
		'height',
	];

	/**
	 * Core MobileSheets table creation statements.
	 * @var array<string, string>
	 */
	public const TABLES = [
		'AbcSettings' => 'CREATE TABLE AbcSettings(Id INTEGER PRIMARY KEY,FileId INTEGER,SongId INTEGER,TransposeLetter INTEGER,TransposeAccidentals INTEGER,Letter INTEGER,Accidentals INTEGER,PageScale FLOAT,LeftMargin INTEGER,TopMargin INTEGER,RightMargin INTEGER,BottomMargin INTEGER,Version INTEGER,TitleFont VARCHAR(255) DEFAULT \'\',LyricsFont VARCHAR(255) DEFAULT \'\',MusicFont VARCHAR(255) DEFAULT \'\',ChordsFont VARCHAR(255) DEFAULT \'\',NumberOfSteps INTEGER DEFAULT 0)',
		'AnnotationPoints' => 'CREATE TABLE AnnotationPoints(Id INTEGER PRIMARY KEY,AnnotationId INTEGER,Points BLOB,Count INTEGER)',
		'AnnotationsBase' => 'CREATE TABLE AnnotationsBase(Id INTEGER PRIMARY KEY,SongId INTEGER,Page INTEGER,Type INTEGER,Opacity INTEGER,Zoom FLOAT,ZoomY FLOAT,Version INTEGER,SourcePageWidth FLOAT,SourcePageHeight FLOAT,Layer INTEGER)',
		'Artists' => 'CREATE TABLE Artists(Id INTEGER PRIMARY KEY,Name VARCHAR(255),SortBy INTEGER DEFAULT 1,Ascending INTEGER DEFAULT 1,DateCreated INTEGER DEFAULT 0,LastModified INTEGER DEFAULT 0)',
		'ArtistsSongs' => 'CREATE TABLE ArtistsSongs(Id INTEGER PRIMARY KEY,ArtistId INTEGER,SongId INTEGER)',
		'AudioFiles' => 'CREATE TABLE AudioFiles(Id INTEGER PRIMARY KEY,SongId INTEGER,Title VARCHAR(255),File VARCHAR(255),FileSource INTEGER,StartPos INTEGER,EndPos INTEGER,FileSize INTEGER,LastModified INTEGER,APosition INTEGER DEFAULT -1,BPosition INTEGER DEFAULT -1,ABEnabled INTEGER DEFAULT 0,FullDuration INTEGER DEFAULT 0,Volume FLOAT DEFAULT 0.75,Artist VARCHAR(255) DEFAULT \'\',PitchShift INTEGER DEFAULT 0,TempoSpeed FLOAT DEFAULT 1.0,AudioFileHash INTEGER DEFAULT 0)',
		'AutoScroll' => 'CREATE TABLE AutoScroll(Id INTEGER PRIMARY KEY,SongId INTEGER,Behavior INTEGER,PauseDuration INTEGER,Speed INTEGER,FixedDuration INTEGER,ScrollPercent INTEGER,ScrollOnLoad INTEGER,TimeBeforeScroll INTEGER)',
		'BatchMIDI' => 'CREATE TABLE BatchMIDI(Id INTEGER PRIMARY KEY,ParentId INTEGER,SongId INTEGER,CommandType INTEGER,Cable INTEGER,Channel INTEGER,MSB INTEGER,LSB INTEGER,Value INTEGER,CustomField INTEGER,SendMSB INTEGER DEFAULT 1,SendLSB INTEGER DEFAULT 1,SendValue INTEGER DEFAULT 1,Label VARCHAR(255) DEFAULT \'\')',
		'BatchMidiAction' => 'CREATE TABLE BatchMidiAction(Id INTEGER PRIMARY KEY,ParentId INTEGER,CommandType INTEGER,Cable INTEGER,Channel INTEGER,MSB INTEGER,LSB INTEGER,Value INTEGER,CustomField INTEGER,SendMSB INTEGER DEFAULT 1,SendLSB INTEGER DEFAULT 1,SendValue INTEGER DEFAULT 1,InputPort VARCHAR(255) DEFAULT \'\',Label VARCHAR(255) DEFAULT \'\')',
		'BatchMidiSysex' => 'CREATE TABLE BatchMidiSysex(Id INTEGER PRIMARY KEY,MidiId INTEGER,SongId INTEGER,SysexBytes BLOB)',
		'BookSongs' => 'CREATE TABLE BookSongs(Id INTEGER PRIMARY KEY,BookId INTEGER,SongId INTEGER)',
		'Bookmarks' => 'CREATE TABLE Bookmarks(Id INTEGER PRIMARY KEY,SongId INTEGER,Name VARCHAR(255),PageNum INTEGER,ShowInLibrary INTEGER,DateCreated INTEGER DEFAULT 0,LastModified INTEGER DEFAULT 0)',
		'Books' => 'CREATE TABLE Books(Id INTEGER PRIMARY KEY,Title VARCHAR(255),SortBy INTEGER DEFAULT 1,Ascending INTEGER DEFAULT 1,DateCreated INTEGER DEFAULT 0,LastModified INTEGER DEFAULT 0)',
		'CollectionSong' => 'CREATE TABLE CollectionSong(Id INTEGER PRIMARY KEY,CollectionId INTEGER,SongId INTEGER)',
		'Collections' => 'CREATE TABLE Collections(Id INTEGER PRIMARY KEY,Name VARCHAR(255),SortBy INTEGER DEFAULT 1,Ascending INTEGER DEFAULT 1,DateCreated INTEGER DEFAULT 0,LastModified INTEGER DEFAULT 0)',
		'Composer' => 'CREATE TABLE Composer(Id INTEGER PRIMARY KEY,Name VARCHAR(255),SortBy INTEGER DEFAULT 1,Ascending INTEGER DEFAULT 1,DateCreated INTEGER DEFAULT 0,LastModified INTEGER DEFAULT 0)',
		'ComposerSongs' => 'CREATE TABLE ComposerSongs(Id INTEGER PRIMARY KEY,ComposerId INTEGER,SongId INTEGER)',
		'Crop' => 'CREATE TABLE Crop(Id INTEGER PRIMARY KEY,SongId INTEGER,Page INTEGER,Left INTEGER,Top INTEGER,Right INTEGER,Bottom INTEGER,Rotation INTEGER)',
		'CustomGroup' => 'CREATE TABLE CustomGroup(Id INTEGER PRIMARY KEY,Name VARCHAR(255),SortBy INTEGER DEFAULT 1,Ascending INTEGER DEFAULT 1,DateCreated INTEGER DEFAULT 0,LastModified INTEGER DEFAULT 0)',
		'CustomGroupSongs' => 'CREATE TABLE CustomGroupSongs(Id INTEGER PRIMARY KEY,GroupId INTEGER,SongId INTEGER)',
		'Defaults' => 'CREATE TABLE Defaults(Id INTEGER PRIMARY KEY,Initialized INTEGER)',
		'DrawAnnotations' => 'CREATE TABLE DrawAnnotations(Id INTEGER PRIMARY KEY,BaseId INTEGER,LineColor INTEGER,FillColor INTEGER,LineWidth FLOAT,DrawMode INTEGER,PenMode INTEGER,SmoothMode INTEGER)',
		'Files' => 'CREATE TABLE Files(Id INTEGER PRIMARY KEY,SongId INTEGER,Path VARCHAR(255),PageOrder VARCHAR(255),FileSize INTEGER,LastModified INTEGER,Source INTEGER,Type INTEGER,Password VARCHAR(255) DEFAULT \'\',SourceFilePageCount INTEGER,FileHash INTEGER,Width INTEGER,Height INTEGER)',
		'Genres' => 'CREATE TABLE Genres(Id INTEGER PRIMARY KEY,Type VARCHAR(255),SortBy INTEGER DEFAULT 1,Ascending INTEGER DEFAULT 1,DateCreated INTEGER DEFAULT 0,LastModified INTEGER DEFAULT 0)',
		'GenresSongs' => 'CREATE TABLE GenresSongs(Id INTEGER PRIMARY KEY,GenreId INTEGER,SongId INTEGER)',
		'Goals' => 'CREATE TABLE Goals(Id INTEGER PRIMARY KEY,Duration INTEGER,Views INTEGER,Type INTEGER,EntryId INTEGER)',
		'HalfPagePos' => 'CREATE TABLE HalfPagePos(Id INTEGER PRIMARY KEY,SongId INTEGER,Page INTEGER,Position FLOAT)',
		'Key' => 'CREATE TABLE Key(Id INTEGER PRIMARY KEY,Name VARCHAR(255),SortBy INTEGER DEFAULT 1,Ascending INTEGER DEFAULT 1,DateCreated INTEGER DEFAULT 0,LastModified INTEGER DEFAULT 0)',
		'KeySongs' => 'CREATE TABLE KeySongs(Id INTEGER PRIMARY KEY,KeyId INTEGER,SongId INTEGER)',
		'Layers' => 'CREATE TABLE Layers(Id INTEGER PRIMARY KEY,SongId INTEGER,Page INTEGER,LayerIndex INTEGER,Name INTEGER,Visible INTEGER)',
		'Links' => 'CREATE TABLE Links(Id INTEGER PRIMARY KEY,SongId INTEGER,StartPointX FLOAT,StartPointY FLOAT,EndPointX FLOAT,EndPointY FLOAT,StartPage INTEGER,EndPage INTEGER,ZoomXStart FLOAT,ZoomYStart FLOAT,ZoomXEnd FLOAT,ZoomYEnd FLOAT,Radius INTEGER,Version INTEGER)',
		'MIDI' => 'CREATE TABLE MIDI(Id INTEGER PRIMARY KEY,SongId INTEGER,CommandType INTEGER,Cable INTEGER,Channel INTEGER,MSB INTEGER,LSB INTEGER,Value INTEGER,CustomField INTEGER,SendOnLoad INTEGER,LoadOnRecv INTEGER,SendMSB INTEGER DEFAULT 1,SendLSB INTEGER DEFAULT 1,SendValue INTEGER DEFAULT 1,InputPort VARCHAR(255) DEFAULT \'\',OutputPort VARCHAR(255) DEFAULT \'\',Label VARCHAR(255) DEFAULT \'\')',
		'Metrics' => 'CREATE TABLE Metrics(Id INTEGER PRIMARY KEY,Type INTEGER,EntryId INTEGER,Date INTEGER,TimeVisible INTEGER)',
		'MetronomeBeatsPerPage' => 'CREATE TABLE MetronomeBeatsPerPage(Id INTEGER PRIMARY KEY,SongId INTEGER,Page INTEGER,BeatsPerPage INTEGER)',
		'MetronomeSettings' => 'CREATE TABLE MetronomeSettings(Id INTEGER PRIMARY KEY,SongId INTEGER,Sig1 INTEGER,Sig2 INTEGER,Subdivision INTEGER,SoundFX INTEGER,AccentFirst INTEGER,AutoStart INTEGER DEFAULT 0,CountIn INTEGER DEFAULT 0,NumberCount INTEGER DEFAULT 0,AutoTurn INTEGER DEFAULT 0)',
		'MidiAction' => 'CREATE TABLE MidiAction(Id INTEGER PRIMARY KEY,Action VARCHAR(255))',
		'MidiActionSysex' => 'CREATE TABLE MidiActionSysex(Id INTEGER PRIMARY KEY,MidiId INTEGER,SysexBytes BLOB)',
		'MidiSysex' => 'CREATE TABLE MidiSysex(Id INTEGER PRIMARY KEY,MidiId INTEGER,SongId INTEGER,SysexBytes BLOB)',
		'Recent' => 'CREATE TABLE Recent(Id INTEGER PRIMARY KEY,Song INTEGER,RecentType INTEGER DEFAULT 0)',
		'SetlistSong' => 'CREATE TABLE SetlistSong(Id INTEGER PRIMARY KEY,SetlistId INTEGER,SongId INTEGER)',
		'SetlistSongNotes' => 'CREATE TABLE SetlistSongNotes(Id INTEGER PRIMARY KEY,SetlistId INTEGER,SongId INTEGER,ShowNotesOnLoad INTEGER,DisplayTime INTEGER,Notes VARCHAR(1024),TextSize INTEGER DEFAULT 24,Alignment INTEGER DEFAULT 0)',
		'Setlists' => 'CREATE TABLE Setlists(Id INTEGER PRIMARY KEY,Name VARCHAR(255),LastPage INTEGER,LastIndex INTEGER,SortBy INTEGER,Ascending INTEGER DEFAULT 1,DateCreated INTEGER DEFAULT 0,LastModified INTEGER DEFAULT 0)',
		'Signature' => 'CREATE TABLE Signature(Id INTEGER PRIMARY KEY,Name VARCHAR(255),SortBy INTEGER DEFAULT 1,Ascending INTEGER DEFAULT 1,DateCreated INTEGER DEFAULT 0,LastModified INTEGER DEFAULT 0)',
		'SignatureSongs' => 'CREATE TABLE SignatureSongs(Id INTEGER PRIMARY KEY,SignatureId INTEGER,SongId INTEGER)',
		'SmartButtonMIDI' => 'CREATE TABLE SmartButtonMIDI(Id INTEGER PRIMARY KEY,ButtonId INTEGER,SongId INTEGER,CommandType INTEGER,Cable INTEGER,Channel INTEGER,MSB INTEGER,LSB INTEGER,Value INTEGER,CustomField INTEGER,SendMSB INTEGER DEFAULT 1,SendLSB INTEGER DEFAULT 1,SendValue INTEGER DEFAULT 1,OutputPort VARCHAR(255) DEFAULT \'\',Label VARCHAR(255) DEFAULT \'\')',
		'SmartButtons' => 'CREATE TABLE SmartButtons(Id INTEGER PRIMARY KEY,SongId INTEGER,Label VARCHAR(255),Page INTEGER,Action INTEGER,Value INTEGER,Value2 INTEGER,XPos FLOAT,YPos FLOAT,ZoomX FLOAT,ZoomY FLOAT,File VARCHAR(255),Size INTEGER,Version INTEGER)',
		'SmartMidiSysex' => 'CREATE TABLE SmartMidiSysex(Id INTEGER PRIMARY KEY,MidiId INTEGER,SongId INTEGER,SysexBytes BLOB)',
		'SongDisplaySettings' => 'CREATE TABLE SongDisplaySettings(Id INTEGER PRIMARY KEY,SongId INTEGER,UseDefaultAdapter INTEGER,PortraitAdapterType INTEGER,LandscapeAdapterType INTEGER,UseDefaultScaleMode INTEGER,PortraitScaleMode INTEGER,LandscapeScaleMode INTEGER)',
		'SongNotes' => 'CREATE TABLE SongNotes(Id INTEGER PRIMARY KEY,SongId INTEGER,ShowNotesOnLoad INTEGER,DisplayTime INTEGER,Notes VARCHAR(1024),TextSize INTEGER DEFAULT 24,Alignment INTEGER DEFAULT 0)',
		'Songs' => 'CREATE TABLE Songs(Id INTEGER PRIMARY KEY,Title VARCHAR(255),Difficulty INTEGER,Custom VARCHAR(255) DEFAULT \'\',Custom2 VARCHAR(255) DEFAULT \'\',LastPage INTEGER,OrientationLock INTEGER,Duration INTEGER,Stars INTEGER DEFAULT 0,VerticalZoom FLOAT DEFAULT 1,SortTitle VARCHAR(255) DEFAULT \'\',Sharpen INTEGER DEFAULT 0,SharpenLevel INTEGER DEFAULT 4,CreationDate INTEGER DEFAULT 0,LastModified INTEGER DEFAULT 0,Keywords VARCHAR(255) DEFAULT \'\',AutoStartAudio INTEGER,SongId INTEGER)',
		'SourceType' => 'CREATE TABLE SourceType(Id INTEGER PRIMARY KEY,Type VARCHAR(255),SortBy INTEGER DEFAULT 1,Ascending INTEGER DEFAULT 1,DateCreated INTEGER DEFAULT 0,LastModified INTEGER DEFAULT 0)',
		'SourceTypeSongs' => 'CREATE TABLE SourceTypeSongs(Id INTEGER PRIMARY KEY,SourceTypeId INTEGER,SongId INTEGER)',
		'StampAnnotations' => 'CREATE TABLE StampAnnotations(Id INTEGER PRIMARY KEY,BaseId INTEGER,Type INTEGER,Size FLOAT,FilePath VARCHAR(255),Color INTEGER,Font INTEGER,Symbol VARCHAR(255))',
		'Tempos' => 'CREATE TABLE Tempos(Id INTEGER PRIMARY KEY,SongId INTEGER,Tempo INTEGER,TempoIndex INTEGER)',
		'TextDisplaySettings' => 'CREATE TABLE TextDisplaySettings(Id INTEGER PRIMARY KEY,FileId INTEGER,SongId INTEGER,FontFamily INTEGER,DefaultFont VARCHAR(255),TitleSize INTEGER,TitleFont VARCHAR(255),TitleBold INTEGER,TitleItalic INTEGER,TitleColor INTEGER,ShowTitle INTEGER,SubtitleSize INTEGER,SubtitleFont VARCHAR(255),SubtitleBold INTEGER,SubtitleItalic INTEGER,SubtitleColor INTEGER,ShowSubtitle INTEGER,MetaSize INTEGER,MetaFont VARCHAR(255),MetaBold INTEGER,MetaItalic INTEGER,MetaColor INTEGER,ShowMeta INTEGER,LyricsSize INTEGER,LyricsFont VARCHAR(255),LyricsBold INTEGER,LyricsItalic INTEGER,LyricsColor INTEGER,ShowLyrics INTEGER,ChordsSize INTEGER,ChordsFont VARCHAR(255),ChordStyle INTEGER,ChordColor INTEGER,ChordHighlight INTEGER,ShowChords INTEGER,ChorusSize INTEGER,ChorusFont VARCHAR(255),ChorusBold INTEGER,ChorusItalic INTEGER,ChorusColor INTEGER,TabSize INTEGER,TabFont VARCHAR(255),TabBold INTEGER,TabItalic INTEGER,TabColor INTEGER,ShowTabs INTEGER,GridTextSize INTEGER,GridChordFont VARCHAR(255),GridChordBold INTEGER,GridChordItalic INTEGER,GridChordColor INTEGER,ShowGrids INTEGER,GridCommentsSize INTEGER,GridCommentsFont VARCHAR(255),GridCommentsBold INTEGER,GridCommentsItalic INTEGER,GridCommentsColor INTEGER,GridCommentsHighlight INTEGER,CommentsSize INTEGER,CommentsFont VARCHAR(255),CommentsBold INTEGER,CommentsItalic INTEGER,CommentsColor INTEGER,ShowComments INTEGER,CommentsItalicSize INTEGER,CommentsItalicFont VARCHAR(255),CommentsItalicBold INTEGER,CommentsItalicItalic INTEGER,CommentsItalicColor INTEGER,CommentsItalicHighlight INTEGER,ShowCommentsItalic INTEGER,CommentBoxSize INTEGER,CommentBoxFont VARCHAR(255),CommentBoxBold INTEGER,CommentBoxItalic INTEGER,CommentBoxColor INTEGER,CommentBoxHighlight INTEGER,ShowCommentBox INTEGER,FooterSize INTEGER,FooterFont VARCHAR(255),FooterBold INTEGER,FooterItalic INTEGER,FooterColor INTEGER,ShowFooter INTEGER,LabelsSize INTEGER,LabelsFont VARCHAR(255),LabelsBold INTEGER,LabelsItalic INTEGER,LabelsColor INTEGER,LabelsUnderline INTEGER,ShowLabels INTEGER,DiagramTextSize INTEGER,DiagramTextFont VARCHAR(255),DiagramTextBold INTEGER,DiagramTextItalic INTEGER,DiagramTextColor INTEGER,LineSpacing FLOAT,ChordLineSpacing FLOAT,UseImprovedSpacing INTEGER,GridLineColor INTEGER,GridLineThickness INTEGER,ShowDiagrams INTEGER,ShowChorusLabel INTEGER,SectionIndent INTEGER,DiagramScale FLOAT,Instrument VARCHAR(255),Transpose INTEGER,TransposeKey INTEGER,Capo INTEGER,NumberChords INTEGER,EnableTranpose INTEGER,EnableCapo INTEGER,Structure VARCHAR(255),Key INTEGER,Encoding INTEGER,RTL INTEGER,UseSharps INTEGER DEFAULT 0,NumberOfSteps INTEGER DEFAULT 0)',
		'TextboxAnnotations' => 'CREATE TABLE TextboxAnnotations(Id INTEGER PRIMARY KEY,BaseId INTEGER,TextColor INTEGER,Text VARCHAR(255),FontFamily INTEGER,FontSize FLOAT,FontStyle INTEGER,FillColor INTEGER,BorderColor INTEGER,TextAlign INTEGER,HasBorder INTEGER,BorderWidth FLOAT,AutoSize INTEGER,LineSpacing FLOAT)',
		'Years' => 'CREATE TABLE Years(Id INTEGER PRIMARY KEY,Name VARCHAR(255),SortBy INTEGER DEFAULT 1,Ascending INTEGER DEFAULT 1,DateCreated INTEGER DEFAULT 0,LastModified INTEGER DEFAULT 0)',
		'YearsSongs' => 'CREATE TABLE YearsSongs(Id INTEGER PRIMARY KEY,YearId INTEGER,SongId INTEGER)',
		'ZoomPerPage' => 'CREATE TABLE ZoomPerPage(Id INTEGER PRIMARY KEY,SongId INTEGER,Page INTEGER,Zoom FLOAT,PortPanX FLOAT,PortPanY FLOAT,LandZoom FLOAT,LandPanX FLOAT,LandPanY FLOAT,FirstHalfY INTEGER,SecondHalfY INTEGER)',
		'android_metadata' => 'CREATE TABLE android_metadata (locale TEXT)',
	];

	/**
	 * Core MobileSheets indexes.
	 * @var list<string>
	 */
	public const INDEXES = [
		'CREATE INDEX abc_id_idx ON AbcSettings(SongId)',
		'CREATE INDEX ann_ann_id_idx ON AnnotationPoints(AnnotationId)',
		'CREATE INDEX ann_song_id_idx ON AnnotationsBase(SongId)',
		'CREATE INDEX artist_artist_id_idx ON ArtistsSongs(ArtistId)',
		'CREATE INDEX artist_song_id_idx ON ArtistsSongs(SongId)',
		'CREATE INDEX audio_song_id_idx ON AudioFiles(SongId)',
		'CREATE INDEX auto_scroll_song_id_idx ON AutoScroll(SongId)',
		'CREATE INDEX batch_midi_parent_id_idx ON BatchMIDI(ParentId)',
		'CREATE INDEX batch_midi_song_id_idx ON BatchMIDI(SongId)',
		'CREATE INDEX batch_midi_sysex_midi_id_idx ON BatchMidiSysex(MidiId)',
		'CREATE INDEX book_book_id_idx ON BookSongs(BookId)',
		'CREATE INDEX book_song_id_idx ON BookSongs(SongId)',
		'CREATE INDEX bookmark_song_id_idx ON Bookmarks(SongId)',
		'CREATE INDEX bpp_page_idx ON MetronomeBeatsPerPage(Page)',
		'CREATE INDEX bpp_song_id_idx ON MetronomeBeatsPerPage(SongId)',
		'CREATE INDEX cgroup_group_id_idx ON CustomGroupSongs(GroupId)',
		'CREATE INDEX cgroup_song_id_idx ON CustomGroupSongs(SongId)',
		'CREATE INDEX col_col_id_idx ON CollectionSong(CollectionId)',
		'CREATE INDEX col_song_id_idx ON CollectionSong(SongId)',
		'CREATE INDEX composer_composer_id_idx ON ComposerSongs(ComposerId)',
		'CREATE INDEX crop_song_id_idx ON Crop(SongId)',
		'CREATE INDEX draw_ann_id_idx ON DrawAnnotations(BaseId)',
		'CREATE INDEX files_song_id_idx ON Files(SongId)',
		'CREATE INDEX genre_genre_id_idx ON GenresSongs(GenreId)',
		'CREATE INDEX genre_song_id_idx ON GenresSongs(SongId)',
		'CREATE INDEX goals_id_idx ON Goals(EntryId)',
		'CREATE INDEX half_page_song_id_idx ON HalfPagePos(SongId)',
		'CREATE INDEX key_key_id_idx ON KeySongs(KeyId)',
		'CREATE INDEX key_song_id_idx ON KeySongs(SongId)',
		'CREATE INDEX layers_song_id_idx ON Layers(SongId)',
		'CREATE INDEX links_song_id_idx ON Links(SongId)',
		'CREATE INDEX metrics_id_idx ON Metrics(EntryId)',
		'CREATE INDEX metronome_song_id_idx ON MetronomeSettings(SongId)',
		'CREATE INDEX midi_action_commands_parent_id_idx ON BatchMidiAction(ParentId)',
		'CREATE INDEX midi_action_sysex_midi_id_idx ON MidiActionSysex(MidiId)',
		'CREATE INDEX midi_song_id_idx ON MIDI(SongId)',
		'CREATE INDEX midi_sysex_midi_id_idx ON MidiSysex(MidiId)',
		'CREATE INDEX scrtype_type_id_idx ON SourceTypeSongs(SourceTypeId)',
		'CREATE INDEX setlist_notes_id_idx ON SetlistSongNotes(SetlistId)',
		'CREATE INDEX setlist_notes_song_id_idx ON SetlistSongNotes(SongId)',
		'CREATE INDEX setlist_setlist_id_idx ON SetlistSong(SetlistId)',
		'CREATE INDEX setlist_song_id_idx ON SetlistSong(SongId)',
		'CREATE INDEX sig_sig_id_idx ON SignatureSongs(SignatureId)',
		'CREATE INDEX sig_song_id_idx ON SignatureSongs(SongId)',
		'CREATE INDEX smart_buttons_song_id_idx ON SmartButtons(SongId)',
		'CREATE INDEX smart_midi_button_id_idx ON SmartButtonMIDI(ButtonId)',
		'CREATE INDEX smart_midi_sysex_midi_id_idx ON SmartMidiSysex(MidiId)',
		'CREATE INDEX song_ds_id_idx ON SongDisplaySettings(SongId)',
		'CREATE INDEX song_notes_id_idx ON SongNotes(SongId)',
		'CREATE INDEX srctype_song_id_idx ON SourceTypeSongs(SongId)',
		'CREATE INDEX stamp_ann_id_idx ON StampAnnotations(BaseId)',
		'CREATE INDEX tb_ann_id_idx ON TextboxAnnotations(BaseId)',
		'CREATE INDEX tempo_song_id_idx ON Tempos(SongId)',
		'CREATE INDEX text_display_id_idx ON TextDisplaySettings(SongId)',
		'CREATE INDEX year_song_id_idx ON YearsSongs(SongId)',
		'CREATE INDEX year_year_id_idx ON YearsSongs(YearId)',
		'CREATE INDEX zoom_song_id_idx ON ZoomPerPage(SongId)',
	];

	/**
	 * Standard default source types seeded by MobileSheets when defaults are initialized.
	 *
	 * @var list<array{Id: int, Type: string, SortBy: int, Ascending: int, DateCreated: int, LastModified: int}>
	 */
	public const DEFAULT_SOURCE_TYPES = [
		['Id' => 1, 'Type' => 'Sheet Music', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 2, 'Type' => 'Tab', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 3, 'Type' => 'Lyrics', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 4, 'Type' => 'Chords', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 5, 'Type' => 'Lead Sheet', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 6, 'Type' => 'Charts', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
	];

	/**
	 * Standard default musical keys seeded by MobileSheets when defaults are initialized.
	 *
	 * @var list<array{Id: int, Name: string, SortBy: int, Ascending: int, DateCreated: int, LastModified: int}>
	 */
	public const DEFAULT_KEYS = [
		['Id' => 1, 'Name' => 'C', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 2, 'Name' => 'Cm', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 3, 'Name' => 'C#', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 4, 'Name' => 'C#m', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 5, 'Name' => 'Db', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 6, 'Name' => 'Dbm', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 7, 'Name' => 'D', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 8, 'Name' => 'Dm', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 9, 'Name' => 'D#', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 10, 'Name' => 'D#m', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 11, 'Name' => 'Eb', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 12, 'Name' => 'Ebm', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 13, 'Name' => 'E', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 14, 'Name' => 'Em', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 15, 'Name' => 'F', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 16, 'Name' => 'Fm', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 17, 'Name' => 'F#', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 18, 'Name' => 'F#m', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 19, 'Name' => 'Gb', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 20, 'Name' => 'Gbm', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 21, 'Name' => 'G', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 22, 'Name' => 'Gm', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 23, 'Name' => 'G#', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 24, 'Name' => 'G#m', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 25, 'Name' => 'Ab', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 26, 'Name' => 'Abm', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 27, 'Name' => 'A', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 28, 'Name' => 'Am', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 29, 'Name' => 'A#', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 30, 'Name' => 'A#m', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 31, 'Name' => 'Bb', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 32, 'Name' => 'Bbm', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 33, 'Name' => 'B', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 34, 'Name' => 'Bm', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
	];

	/**
	 * Standard default genres seeded by MobileSheets when defaults are initialized.
	 *
	 * @var list<array{Id: int, Type: string, SortBy: int, Ascending: int, DateCreated: int, LastModified: int}>
	 */
	public const DEFAULT_GENRES = [
		['Id' => 1, 'Type' => 'Acapella', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 2, 'Type' => 'Acoustic', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 3, 'Type' => 'Alternative', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 4, 'Type' => 'Ballad', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 5, 'Type' => 'Big Band', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 6, 'Type' => 'Bluegrass', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 7, 'Type' => 'Blues', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 8, 'Type' => 'Christmas', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 9, 'Type' => 'Classical', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 10, 'Type' => 'Contemporary', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 11, 'Type' => 'Country', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 12, 'Type' => 'Dance', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 13, 'Type' => 'Death Metal', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 14, 'Type' => 'Disco', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 15, 'Type' => 'Folk', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 16, 'Type' => 'Hip Hop', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 17, 'Type' => 'Instrumental', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 18, 'Type' => 'Jazz', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 19, 'Type' => 'Mambo', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 20, 'Type' => 'Metal', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 21, 'Type' => 'Musicals', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 22, 'Type' => 'Oldies', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 23, 'Type' => 'Other', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 24, 'Type' => 'Pop', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 25, 'Type' => 'Punk', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 26, 'Type' => 'Rhythm and Blues', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 27, 'Type' => 'Ragtime', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 28, 'Type' => 'Rap', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 29, 'Type' => 'Rock', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 30, 'Type' => 'Soundtrack', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 31, 'Type' => 'Techno', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 32, 'Type' => 'Vocal', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 33, 'Type' => 'Waltz', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
		['Id' => 34, 'Type' => 'Worship', 'SortBy' => 1, 'Ascending' => 1, 'DateCreated' => 0, 'LastModified' => 0],
	];
}
