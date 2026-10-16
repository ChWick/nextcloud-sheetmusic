<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service;

/**
 * Configuration and mappings between Nextcloud internal entity types
 * and MobileSheets SQLite tables and columns.
 */
final class MobileSheetsEntityConfig {
	/**
	 * Mapping of internal entity types to MobileSheets table specifications.
	 *
	 * @var array<string, array{table: string, nameCol: string, linkTable: string, linkCol: string, hasSort: bool}>
	 */
	public const BY_TYPE = [
		'composer' => [
			'table' => 'Composer',
			'nameCol' => 'Name',
			'linkTable' => 'ComposerSongs',
			'linkCol' => 'ComposerId',
			'hasSort' => true,
		],
		'artist' => [
			'table' => 'Artists',
			'nameCol' => 'Name',
			'linkTable' => 'ArtistsSongs',
			'linkCol' => 'ArtistId',
			'hasSort' => false,
		],
		'book' => [
			'table' => 'Books',
			'nameCol' => 'Title',
			'linkTable' => 'BookSongs',
			'linkCol' => 'BookId',
			'hasSort' => true,
		],
		'collection' => [
			'table' => 'Collections',
			'nameCol' => 'Name',
			'linkTable' => 'CollectionSong',
			'linkCol' => 'CollectionId',
			'hasSort' => true,
		],
		'genre' => [
			'table' => 'Genres',
			'nameCol' => 'Type',
			'linkTable' => 'GenresSongs',
			'linkCol' => 'GenreId',
			'hasSort' => false,
		],
		'key' => [
			'table' => 'Key',
			'nameCol' => 'Name',
			'linkTable' => 'KeySongs',
			'linkCol' => 'KeyId',
			'hasSort' => false,
		],
		'customgroup' => [
			'table' => 'CustomGroup',
			'nameCol' => 'Name',
			'linkTable' => 'CustomGroupSongs',
			'linkCol' => 'GroupId',
			'hasSort' => false,
		],
		'year' => [
			'table' => 'Years',
			'nameCol' => 'Year',
			'linkTable' => 'YearsSongs',
			'linkCol' => 'YearId',
			'hasSort' => false,
		],
		'signature' => [
			'table' => 'Signature',
			'nameCol' => 'Name',
			'linkTable' => 'SignatureSongs',
			'linkCol' => 'SignatureId',
			'hasSort' => false,
		],
		'sourcetype' => [
			'table' => 'SourceType',
			'nameCol' => 'Type',
			'linkTable' => 'SourceTypeSongs',
			'linkCol' => 'SourceTypeId',
			'hasSort' => false,
		],
		'instrument' => [
			'table' => 'CustomGroup',
			'nameCol' => 'Name',
			'linkTable' => 'CustomGroupSongs',
			'linkCol' => 'GroupId',
			'hasSort' => false,
		],
	];

	/**
	 * Mapping of MobileSheets SQLite table names to internal entity types and configuration.
	 *
	 * @var array<string, array{type: string, nameCol: string, linkTable: string, linkCol: string}>
	 */
	public const BY_TABLE = [
		'Composer' => [
			'type' => 'composer',
			'nameCol' => 'Name',
			'linkTable' => 'ComposerSongs',
			'linkCol' => 'ComposerId',
		],
		'Artists' => [
			'type' => 'artist',
			'nameCol' => 'Name',
			'linkTable' => 'ArtistsSongs',
			'linkCol' => 'ArtistId',
		],
		'Books' => [
			'type' => 'book',
			'nameCol' => 'Title',
			'linkTable' => 'BookSongs',
			'linkCol' => 'BookId',
		],
		'Collections' => [
			'type' => 'collection',
			'nameCol' => 'Name',
			'linkTable' => 'CollectionSong',
			'linkCol' => 'CollectionId',
		],
		'Genres' => [
			'type' => 'genre',
			'nameCol' => 'Type',
			'linkTable' => 'GenresSongs',
			'linkCol' => 'GenreId',
		],
		'Keys' => [
			'type' => 'key',
			'nameCol' => 'Name',
			'linkTable' => 'KeySongs',
			'linkCol' => 'KeyId',
		],
		'Key' => [
			'type' => 'key',
			'nameCol' => 'Name',
			'linkTable' => 'KeySongs',
			'linkCol' => 'KeyId',
		],
		'CustomGroup' => [
			'type' => 'customgroup',
			'nameCol' => 'Name',
			'linkTable' => 'CustomGroupSongs',
			'linkCol' => 'GroupId',
		],
		'Years' => [
			'type' => 'year',
			'nameCol' => 'Year',
			'linkTable' => 'YearsSongs',
			'linkCol' => 'YearId',
		],
		'Signature' => [
			'type' => 'signature',
			'nameCol' => 'Name',
			'linkTable' => 'SignatureSongs',
			'linkCol' => 'SignatureId',
		],
		'SourceTypes' => [
			'type' => 'sourcetype',
			'nameCol' => 'Type',
			'linkTable' => 'SourceTypeSongs',
			'linkCol' => 'SourceTypeId',
		],
		'SourceType' => [
			'type' => 'sourcetype',
			'nameCol' => 'Type',
			'linkTable' => 'SourceTypeSongs',
			'linkCol' => 'SourceTypeId',
		],
	];

	/**
	 * Standard tables natively managed by Sheet Music core.
	 *
	 * @var list<string>
	 */
	public const CORE_MANAGED_TABLES = [
		'songs',
		'files',
		'composer',
		'composersongs',
		'artists',
		'artistssongs',
		'books',
		'booksongs',
		'collections',
		'collectionsong',
		'genres',
		'genressongs',
		'keys',
		'key',
		'keysongs',
		'customgroup',
		'customgroupsongs',
		'years',
		'yearssongs',
		'signature',
		'signaturesongs',
		'sourcetypes',
		'sourcetype',
		'sourcetypesongs',
		'songnotes',
		'_nc_sync_metadata',
	];

	/**
	 * Check if a table is managed by Sheet Music core.
	 */
	public static function isCoreManaged(string $tableName): bool {
		return in_array(strtolower($tableName), self::CORE_MANAGED_TABLES, true);
	}
}

