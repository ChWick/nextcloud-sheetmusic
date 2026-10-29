import { translate as t } from '@nextcloud/l10n'
import type { EntityType, FilterEntity, Song, SongColumn } from '../../types/song'
import { formatDifficulty, formatStars, type SortKey } from '../../utils/song-formatter'

export type InlineEditableField =
	| 'title'
	| 'opus'
	| 'catalogNumber'
	| 'songNumber'
	| 'stars'
	| 'difficulty'
	| 'keywords'
	| 'custom'
	| 'custom2'

export interface TableColumnDef {
	col: SongColumn
	sortKey: SortKey
	label: () => string
}

export interface EntityColumnDef {
	col: SongColumn
	entityType: EntityType
	cardLabel: () => string
	getItems: (song: Song) => FilterEntity[]
	getItemLabel: (item: FilterEntity) => string
	getFilterTitle: (label: string) => string
}

export interface TextEditableColumnDef {
	col: SongColumn
	field: InlineEditableField
	ariaLabel: () => string
	editTitle: () => string
}

export const TABLE_COLUMNS: TableColumnDef[] = [
	{ col: 'title', sortKey: 'title', label: () => t('sheetmusic', 'Title') },
	{ col: 'composer', sortKey: 'composer', label: () => t('sheetmusic', 'Composer') },
	{ col: 'book', sortKey: 'book', label: () => t('sheetmusic', 'Book') },
	{ col: 'collection', sortKey: 'collection', label: () => t('sheetmusic', 'Collection') },
	{ col: 'artist', sortKey: 'artist', label: () => t('sheetmusic', 'Artist') },
	{ col: 'genre', sortKey: 'genre', label: () => t('sheetmusic', 'Genre') },
	{ col: 'key', sortKey: 'key', label: () => t('sheetmusic', 'Key') },
	{ col: 'customGroup', sortKey: 'customGroup', label: () => t('sheetmusic', 'Custom Group') },
	{ col: 'year', sortKey: 'year', label: () => t('sheetmusic', 'Year') },
	{ col: 'signature', sortKey: 'signature', label: () => t('sheetmusic', 'Time Signature') },
	{ col: 'sourceType', sortKey: 'sourceType', label: () => t('sheetmusic', 'Source Type') },
	{ col: 'instrument', sortKey: 'instrument', label: () => t('sheetmusic', 'Instrument') },
	{ col: 'opus', sortKey: 'opus', label: () => t('sheetmusic', 'Opus') },
	{ col: 'catalogNumber', sortKey: 'catalogNumber', label: () => t('sheetmusic', 'Catalog No.') },
	{ col: 'songNumber', sortKey: 'songNumber', label: () => t('sheetmusic', 'Song No.') },
	{ col: 'stars', sortKey: 'stars', label: () => t('sheetmusic', 'Rating') },
	{ col: 'difficulty', sortKey: 'difficulty', label: () => t('sheetmusic', 'Difficulty') },
	{ col: 'duration', sortKey: 'duration', label: () => t('sheetmusic', 'Duration') },
	{ col: 'keywords', sortKey: 'keywords', label: () => t('sheetmusic', 'Keywords') },
	{ col: 'custom', sortKey: 'custom', label: () => t('sheetmusic', 'Custom') },
	{ col: 'custom2', sortKey: 'custom2', label: () => t('sheetmusic', 'Custom 2') },
	{ col: 'lastModified', sortKey: 'lastModified', label: () => t('sheetmusic', 'Last modified') },
	{ col: 'dateCreated', sortKey: 'dateCreated', label: () => t('sheetmusic', 'Created') },
]

export const ENTITY_COLUMNS: EntityColumnDef[] = [
	{
		col: 'composer',
		entityType: 'composers',
		cardLabel: () => t('sheetmusic', 'Composer:'),
		getItems: (s) => s.composers ?? [],
		getItemLabel: (item) => ('name' in item ? item.name : item.title),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for composer {name}', { name }),
	},
	{
		col: 'book',
		entityType: 'books',
		cardLabel: () => t('sheetmusic', 'Book:'),
		getItems: (s) => s.books ?? [],
		getItemLabel: (item) => ('title' in item ? item.title : item.name),
		getFilterTitle: (title) => t('sheetmusic', 'Toggle filter for book {title}', { title }),
	},
	{
		col: 'collection',
		entityType: 'collections',
		cardLabel: () => t('sheetmusic', 'Collection:'),
		getItems: (s) => s.collections ?? [],
		getItemLabel: (item) => ('name' in item ? item.name : item.title),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for collection {name}', { name }),
	},
	{
		col: 'artist',
		entityType: 'artists',
		cardLabel: () => t('sheetmusic', 'Artist:'),
		getItems: (s) => s.artists ?? [],
		getItemLabel: (item) => ('name' in item ? item.name : item.title),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for artist {name}', { name }),
	},
	{
		col: 'genre',
		entityType: 'genres',
		cardLabel: () => t('sheetmusic', 'Genre:'),
		getItems: (s) => s.genres ?? [],
		getItemLabel: (item) => ('name' in item ? item.name : item.title),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for genre {name}', { name }),
	},
	{
		col: 'key',
		entityType: 'keys',
		cardLabel: () => t('sheetmusic', 'Key:'),
		getItems: (s) => s.keys ?? [],
		getItemLabel: (item) => ('name' in item ? item.name : item.title),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for key {name}', { name }),
	},
	{
		col: 'customGroup',
		entityType: 'customgroups',
		cardLabel: () => t('sheetmusic', 'Custom Group:'),
		getItems: (s) => s.customGroups ?? [],
		getItemLabel: (item) => ('name' in item ? item.name : item.title),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for custom group {name}', { name }),
	},
	{
		col: 'year',
		entityType: 'years',
		cardLabel: () => t('sheetmusic', 'Year:'),
		getItems: (s) => s.years ?? [],
		getItemLabel: (item) => ('name' in item ? item.name : item.title),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for year {name}', { name }),
	},
	{
		col: 'signature',
		entityType: 'signatures',
		cardLabel: () => t('sheetmusic', 'Time Signature:'),
		getItems: (s) => s.signatures ?? [],
		getItemLabel: (item) => ('name' in item ? item.name : item.title),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for signature {name}', { name }),
	},
	{
		col: 'sourceType',
		entityType: 'sourcetypes',
		cardLabel: () => t('sheetmusic', 'Source Type:'),
		getItems: (s) => s.sourceTypes ?? [],
		getItemLabel: (item) => ('name' in item ? item.name : item.title),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for source type {name}', { name }),
	},
	{
		col: 'instrument',
		entityType: 'instruments',
		cardLabel: () => t('sheetmusic', 'Instrument:'),
		getItems: (s) => s.instruments ?? [],
		getItemLabel: (item) => ('name' in item ? item.name : item.title),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for instrument {name}', { name }),
	},
]

export const IDENTIFIER_TEXT_COLUMNS: TextEditableColumnDef[] = [
	{
		col: 'opus',
		field: 'opus',
		ariaLabel: () => t('sheetmusic', 'Opus'),
		editTitle: () => t('sheetmusic', 'Edit opus inline'),
	},
	{
		col: 'catalogNumber',
		field: 'catalogNumber',
		ariaLabel: () => t('sheetmusic', 'Catalog No.'),
		editTitle: () => t('sheetmusic', 'Edit catalog number inline'),
	},
	{
		col: 'songNumber',
		field: 'songNumber',
		ariaLabel: () => t('sheetmusic', 'Song No.'),
		editTitle: () => t('sheetmusic', 'Edit song number inline'),
	},
]

export const EXTRA_TEXT_COLUMNS: TextEditableColumnDef[] = [
	{
		col: 'keywords',
		field: 'keywords',
		ariaLabel: () => t('sheetmusic', 'Keywords'),
		editTitle: () => t('sheetmusic', 'Edit keywords inline'),
	},
	{
		col: 'custom',
		field: 'custom',
		ariaLabel: () => t('sheetmusic', 'Custom'),
		editTitle: () => t('sheetmusic', 'Edit custom field inline'),
	},
	{
		col: 'custom2',
		field: 'custom2',
		ariaLabel: () => t('sheetmusic', 'Custom 2'),
		editTitle: () => t('sheetmusic', 'Edit custom 2 field inline'),
	},
]

export const STAR_SELECT_OPTIONS = [
	{ value: 0, label: '—' },
	...Array.from({ length: 5 }, (_, i) => ({ value: i + 1, label: formatStars(i + 1) })),
]

export const DIFFICULTY_SELECT_OPTIONS = [
	{ value: 0, label: '—' },
	...Array.from({ length: 5 }, (_, i) => ({ value: i + 1, label: formatDifficulty(i + 1) })),
]
