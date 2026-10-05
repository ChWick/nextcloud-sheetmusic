<?php

declare(strict_types=1);

namespace TestSupport;

final class MobileSheetsTestDatabase {
	/**
	 * @return array{path: string, connection: \PDO}
	 */
	public static function create(): array {
		$path = tempnam(sys_get_temp_dir(), 'mobilesheetsmanager-test-');
		if ($path === false) {
			throw new \RuntimeException('Could not create a temporary MobileSheets test database.');
		}

		$connection = new \PDO('sqlite:' . $path);
		foreach (self::schema() as $statement) {
			$connection->exec($statement);
		}

		return ['path' => $path, 'connection' => $connection];
	}

	/**
	 * @return list<string>
	 */
	private static function schema(): array {
		return [
			'CREATE TABLE Songs(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortTitle VARCHAR(255), Stars INTEGER DEFAULT 0, Difficulty INTEGER DEFAULT 0, Duration INTEGER DEFAULT 0, Keywords VARCHAR(255) DEFAULT \'\', Custom VARCHAR(255) DEFAULT \'\', Custom2 VARCHAR(255) DEFAULT \'\', SongId INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'CREATE TABLE Files(Id INTEGER PRIMARY KEY, SongId INTEGER, Path VARCHAR(255), PageOrder VARCHAR(255), FileSize INTEGER, LastModified INTEGER, Source INTEGER, Type INTEGER, Password VARCHAR(255) DEFAULT \'\', SourceFilePageCount INTEGER, FileHash INTEGER, Width INTEGER, Height INTEGER)',
			'CREATE TABLE Collections(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'CREATE TABLE CollectionSong(Id INTEGER PRIMARY KEY, CollectionId INTEGER, SongId INTEGER)',
			'CREATE TABLE Composer(Id INTEGER PRIMARY KEY, Name VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'CREATE TABLE ComposerSongs(Id INTEGER PRIMARY KEY, ComposerId INTEGER, SongId INTEGER)',
			'CREATE TABLE Books(Id INTEGER PRIMARY KEY, Title VARCHAR(255), SortBy INTEGER DEFAULT 1, Ascending INTEGER DEFAULT 1, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'CREATE TABLE BookSongs(Id INTEGER PRIMARY KEY, BookId INTEGER, SongId INTEGER)',
			'CREATE TABLE Artists(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'CREATE TABLE ArtistsSongs(Id INTEGER PRIMARY KEY, ArtistId INTEGER, SongId INTEGER)',
			'CREATE TABLE Genres(Id INTEGER PRIMARY KEY, Type VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'CREATE TABLE GenresSongs(Id INTEGER PRIMARY KEY, GenreId INTEGER, SongId INTEGER)',
			'CREATE TABLE Keys(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'CREATE TABLE KeySongs(Id INTEGER PRIMARY KEY, KeyId INTEGER, SongId INTEGER)',
			'CREATE TABLE CustomGroup(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'CREATE TABLE CustomGroupSongs(Id INTEGER PRIMARY KEY, GroupId INTEGER, SongId INTEGER)',
			'CREATE TABLE Years(Id INTEGER PRIMARY KEY, Year INTEGER, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'CREATE TABLE YearsSongs(Id INTEGER PRIMARY KEY, YearId INTEGER, SongId INTEGER)',
			'CREATE TABLE Signature(Id INTEGER PRIMARY KEY, Name VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'CREATE TABLE SignatureSongs(Id INTEGER PRIMARY KEY, SignatureId INTEGER, SongId INTEGER)',
			'CREATE TABLE SourceTypes(Id INTEGER PRIMARY KEY, Type VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'CREATE TABLE SourceTypeSongs(Id INTEGER PRIMARY KEY, SourceTypeId INTEGER, SongId INTEGER)',
			'CREATE TABLE Bookmarks(Id INTEGER PRIMARY KEY, SongId INTEGER, Name VARCHAR(255), PageNum INTEGER, ShowInLibrary INTEGER DEFAULT 0, DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'CREATE TABLE AudioFiles(Id INTEGER PRIMARY KEY, SongId INTEGER, Title VARCHAR(255), File VARCHAR(255), DateCreated INTEGER DEFAULT 0, LastModified INTEGER DEFAULT 0)',
			'CREATE TABLE MetronomeSettings(Id INTEGER PRIMARY KEY, SongId INTEGER, Bpm INTEGER)',
		];
	}
}
