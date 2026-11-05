import { translate as t } from '@nextcloud/l10n'
import type { EntityType, FilterEntity, Song, SongColumn } from '../../types/song'
import type { EntityLabelField, SongEntityIdField } from '../song-editor/entity-utils'
import { formatDifficulty, formatDuration, formatStars, type SortKey } from '../../utils/song-formatter'

export type InlineEditableField =
	| 'title'
	| 'composer'
	| 'book'
	| 'collection'
	| 'artist'
	| 'genre'
	| 'key'
	| 'customGroup'
	| 'year'
	| 'signature'
	| 'sourceType'
	| 'instrument'
	| 'arranger'
	| 'opus'
	| 'catalogNumber'
	| 'songNumber'
	| 'stars'
	| 'difficulty'
	| 'duration'
	| 'keywords'
	| 'custom'
	| 'custom2'

export type SongEntityProp =
	| 'composers'
	| 'books'
	| 'collections'
	| 'artists'
	| 'genres'
	| 'keys'
	| 'customGroups'
	| 'years'
	| 'signatures'
	| 'sourceTypes'
	| 'instruments'
	| 'arrangers'

export type SongScalarProp =
	| 'opus'
	| 'catalogNumber'
	| 'songNumber'
	| 'stars'
	| 'difficulty'
	| 'duration'
	| 'keywords'
	| 'custom'
	| 'custom2'

export interface SongScalarFieldDef {
	key: SongScalarProp
	type: 'text' | 'number'
	min?: number
	max?: number
	label: () => string
	inputLabel: () => string
	placeholder: () => string
	formatDisplay: (song?: Song) => string
}

export interface TableColumnDef {
	col: SongColumn
	sortKey: SortKey
	label: () => string
}

export interface EntityColumnDef {
	col: SongColumn
	field: InlineEditableField
	songProp: SongEntityProp
	idField: SongEntityIdField
	endpoint: string
	nameKey: EntityLabelField
	entityType: EntityType
	cardLabel: () => string
	ariaLabel: () => string
	singularLabel: () => string
	pluralLabel: () => string
	selectPlaceholder: () => string
	filterPlaceholder: () => string
	editTitle: () => string
	getItems: (song: Song) => FilterEntity[]
	getItemLabel: (item: FilterEntity) => string
	getFilterTitle: (label: string) => string
	formatEditValue: (song: Song) => string
	parseEditValue: (raw: string) => Array<{ id?: number, name?: string, title?: string }>
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
	{ col: 'arranger', sortKey: 'arranger', label: () => t('sheetmusic', 'Arranger') },
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

function parseEntityTokens(raw: string, nameKey: EntityLabelField): Array<{ name?: string, title?: string }> {
	const seen = new Set<string>()
	const result: Array<{ name?: string, title?: string }> = []
	for (const part of raw.split(',')) {
		const trimmed = part.trim()
		if (!trimmed) continue
		const lower = trimmed.toLocaleLowerCase()
		if (seen.has(lower)) continue
		seen.add(lower)
		result.push(nameKey === 'title' ? { title: trimmed } : { name: trimmed })
	}
	return result
}

function makeEntityCol(config: {
	col: SongColumn
	field: InlineEditableField
	songProp: SongEntityProp
	idField: SongEntityIdField
	endpoint: string
	nameKey: EntityLabelField
	entityType: EntityType
	cardLabel: () => string
	ariaLabel: () => string
	singularLabel: () => string
	pluralLabel: () => string
	selectPlaceholder: () => string
	filterPlaceholder: () => string
	editTitle: () => string
	getFilterTitle: (label: string) => string
}): EntityColumnDef {
	const getItems = (s: Song): FilterEntity[] => ((s as any)[config.songProp] ?? []) as FilterEntity[]
	const getItemLabel = (item: FilterEntity): string => ('title' in item ? item.title : item.name)
	return {
		...config,
		getItems,
		getItemLabel,
		formatEditValue: (song: Song) => getItems(song).map(getItemLabel).filter(Boolean).join(', '),
		parseEditValue: (raw: string) => parseEntityTokens(raw, config.nameKey),
	}
}

export const ENTITY_COLUMNS: EntityColumnDef[] = [
	makeEntityCol({
		col: 'composer',
		field: 'composer',
		songProp: 'composers',
		idField: 'composerIds',
		endpoint: '/api/composers',
		nameKey: 'name',
		entityType: 'composers',
		cardLabel: () => t('sheetmusic', 'Composer:'),
		ariaLabel: () => t('sheetmusic', 'Composer'),
		singularLabel: () => t('sheetmusic', 'composer'),
		pluralLabel: () => t('sheetmusic', 'Composers'),
		selectPlaceholder: () => t('sheetmusic', 'Select composers…'),
		filterPlaceholder: () => t('sheetmusic', 'Filter by composer…'),
		editTitle: () => t('sheetmusic', 'Edit composer inline'),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for composer {name}', { name }),
	}),
	makeEntityCol({
		col: 'book',
		field: 'book',
		songProp: 'books',
		idField: 'bookIds',
		endpoint: '/api/books',
		nameKey: 'title',
		entityType: 'books',
		cardLabel: () => t('sheetmusic', 'Book:'),
		ariaLabel: () => t('sheetmusic', 'Book'),
		singularLabel: () => t('sheetmusic', 'book'),
		pluralLabel: () => t('sheetmusic', 'Books'),
		selectPlaceholder: () => t('sheetmusic', 'Select books…'),
		filterPlaceholder: () => t('sheetmusic', 'Filter by book…'),
		editTitle: () => t('sheetmusic', 'Edit book inline'),
		getFilterTitle: (title) => t('sheetmusic', 'Toggle filter for book {title}', { title }),
	}),
	makeEntityCol({
		col: 'collection',
		field: 'collection',
		songProp: 'collections',
		idField: 'collectionIds',
		endpoint: '/api/collections',
		nameKey: 'name',
		entityType: 'collections',
		cardLabel: () => t('sheetmusic', 'Collection:'),
		ariaLabel: () => t('sheetmusic', 'Collection'),
		singularLabel: () => t('sheetmusic', 'collection'),
		pluralLabel: () => t('sheetmusic', 'Collections'),
		selectPlaceholder: () => t('sheetmusic', 'Select collections…'),
		filterPlaceholder: () => t('sheetmusic', 'Filter by collection…'),
		editTitle: () => t('sheetmusic', 'Edit collection inline'),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for collection {name}', { name }),
	}),
	makeEntityCol({
		col: 'artist',
		field: 'artist',
		songProp: 'artists',
		idField: 'artistIds',
		endpoint: '/api/artists',
		nameKey: 'name',
		entityType: 'artists',
		cardLabel: () => t('sheetmusic', 'Artist:'),
		ariaLabel: () => t('sheetmusic', 'Artist'),
		singularLabel: () => t('sheetmusic', 'artist'),
		pluralLabel: () => t('sheetmusic', 'Artists'),
		selectPlaceholder: () => t('sheetmusic', 'Select artists…'),
		filterPlaceholder: () => t('sheetmusic', 'Filter by artist…'),
		editTitle: () => t('sheetmusic', 'Edit artist inline'),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for artist {name}', { name }),
	}),
	makeEntityCol({
		col: 'genre',
		field: 'genre',
		songProp: 'genres',
		idField: 'genreIds',
		endpoint: '/api/genres',
		nameKey: 'name',
		entityType: 'genres',
		cardLabel: () => t('sheetmusic', 'Genre:'),
		ariaLabel: () => t('sheetmusic', 'Genre'),
		singularLabel: () => t('sheetmusic', 'genre'),
		pluralLabel: () => t('sheetmusic', 'Genres'),
		selectPlaceholder: () => t('sheetmusic', 'Select genres…'),
		filterPlaceholder: () => t('sheetmusic', 'Filter by genre…'),
		editTitle: () => t('sheetmusic', 'Edit genre inline'),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for genre {name}', { name }),
	}),
	makeEntityCol({
		col: 'key',
		field: 'key',
		songProp: 'keys',
		idField: 'keyIds',
		endpoint: '/api/keys',
		nameKey: 'name',
		entityType: 'keys',
		cardLabel: () => t('sheetmusic', 'Key:'),
		ariaLabel: () => t('sheetmusic', 'Key'),
		singularLabel: () => t('sheetmusic', 'key'),
		pluralLabel: () => t('sheetmusic', 'Keys'),
		selectPlaceholder: () => t('sheetmusic', 'Select keys…'),
		filterPlaceholder: () => t('sheetmusic', 'Filter by key…'),
		editTitle: () => t('sheetmusic', 'Edit key inline'),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for key {name}', { name }),
	}),
	makeEntityCol({
		col: 'customGroup',
		field: 'customGroup',
		songProp: 'customGroups',
		idField: 'customGroupIds',
		endpoint: '/api/customgroups',
		nameKey: 'name',
		entityType: 'customgroups',
		cardLabel: () => t('sheetmusic', 'Custom Group:'),
		ariaLabel: () => t('sheetmusic', 'Custom Group'),
		singularLabel: () => t('sheetmusic', 'custom group'),
		pluralLabel: () => t('sheetmusic', 'Custom Groups'),
		selectPlaceholder: () => t('sheetmusic', 'Select custom groups…'),
		filterPlaceholder: () => t('sheetmusic', 'Filter by custom group…'),
		editTitle: () => t('sheetmusic', 'Edit custom group inline'),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for custom group {name}', { name }),
	}),
	makeEntityCol({
		col: 'year',
		field: 'year',
		songProp: 'years',
		idField: 'yearIds',
		endpoint: '/api/years',
		nameKey: 'name',
		entityType: 'years',
		cardLabel: () => t('sheetmusic', 'Year:'),
		ariaLabel: () => t('sheetmusic', 'Year'),
		singularLabel: () => t('sheetmusic', 'year'),
		pluralLabel: () => t('sheetmusic', 'Years'),
		selectPlaceholder: () => t('sheetmusic', 'Select years…'),
		filterPlaceholder: () => t('sheetmusic', 'Filter by year…'),
		editTitle: () => t('sheetmusic', 'Edit year inline'),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for year {name}', { name }),
	}),
	makeEntityCol({
		col: 'signature',
		field: 'signature',
		songProp: 'signatures',
		idField: 'signatureIds',
		endpoint: '/api/signatures',
		nameKey: 'name',
		entityType: 'signatures',
		cardLabel: () => t('sheetmusic', 'Time Signature:'),
		ariaLabel: () => t('sheetmusic', 'Time Signature'),
		singularLabel: () => t('sheetmusic', 'time signature'),
		pluralLabel: () => t('sheetmusic', 'Time Signatures'),
		selectPlaceholder: () => t('sheetmusic', 'Select time signatures…'),
		filterPlaceholder: () => t('sheetmusic', 'Filter by time signature…'),
		editTitle: () => t('sheetmusic', 'Edit time signature inline'),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for signature {name}', { name }),
	}),
	makeEntityCol({
		col: 'sourceType',
		field: 'sourceType',
		songProp: 'sourceTypes',
		idField: 'sourceTypeIds',
		endpoint: '/api/sourcetypes',
		nameKey: 'name',
		entityType: 'sourcetypes',
		cardLabel: () => t('sheetmusic', 'Source Type:'),
		ariaLabel: () => t('sheetmusic', 'Source Type'),
		singularLabel: () => t('sheetmusic', 'source type'),
		pluralLabel: () => t('sheetmusic', 'Source Types'),
		selectPlaceholder: () => t('sheetmusic', 'Select source types…'),
		filterPlaceholder: () => t('sheetmusic', 'Filter by source type…'),
		editTitle: () => t('sheetmusic', 'Edit source type inline'),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for source type {name}', { name }),
	}),
	makeEntityCol({
		col: 'instrument',
		field: 'instrument',
		songProp: 'instruments',
		idField: 'instrumentIds',
		endpoint: '/api/instruments',
		nameKey: 'name',
		entityType: 'instruments',
		cardLabel: () => t('sheetmusic', 'Instrument:'),
		ariaLabel: () => t('sheetmusic', 'Instrument'),
		singularLabel: () => t('sheetmusic', 'instrument'),
		pluralLabel: () => t('sheetmusic', 'Instruments'),
		selectPlaceholder: () => t('sheetmusic', 'Select instruments…'),
		filterPlaceholder: () => t('sheetmusic', 'Filter by instrument…'),
		editTitle: () => t('sheetmusic', 'Edit instrument inline'),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for instrument {name}', { name }),
	}),
	makeEntityCol({
		col: 'arranger',
		field: 'arranger',
		songProp: 'arrangers',
		idField: 'arrangerIds',
		endpoint: '/api/arrangers',
		nameKey: 'name',
		entityType: 'arrangers',
		cardLabel: () => t('sheetmusic', 'Arranger:'),
		ariaLabel: () => t('sheetmusic', 'Arranger'),
		singularLabel: () => t('sheetmusic', 'arranger'),
		pluralLabel: () => t('sheetmusic', 'Arrangers'),
		selectPlaceholder: () => t('sheetmusic', 'Select arrangers…'),
		filterPlaceholder: () => t('sheetmusic', 'Filter by arranger…'),
		editTitle: () => t('sheetmusic', 'Edit arranger inline'),
		getFilterTitle: (name) => t('sheetmusic', 'Toggle filter for arranger {name}', { name }),
	}),
]

/**
 * Single source of truth for all scalar metadata fields of a song (excluding title).
 * Used across SongMergeDialog, SongMergeModeView, SongResolveBothModeView, etc.
 */
export const SONG_SCALAR_FIELDS: SongScalarFieldDef[] = [
	{
		key: 'opus',
		type: 'text',
		label: () => t('sheetmusic', 'Opus'),
		inputLabel: () => t('sheetmusic', 'Opus'),
		placeholder: () => t('sheetmusic', 'Opus (e.g. Op. 28 No. 4)…'),
		formatDisplay: (song?: Song) => song?.opus || '-',
	},
	{
		key: 'catalogNumber',
		type: 'text',
		label: () => t('sheetmusic', 'Catalog Number'),
		inputLabel: () => t('sheetmusic', 'Catalog Number'),
		placeholder: () => t('sheetmusic', 'Catalog No. (BWV, KV, etc.)…'),
		formatDisplay: (song?: Song) => song?.catalogNumber || '-',
	},
	{
		key: 'songNumber',
		type: 'text',
		label: () => t('sheetmusic', 'Song Number'),
		inputLabel: () => t('sheetmusic', 'Song Number'),
		placeholder: () => t('sheetmusic', 'Song No. (e.g. 88 or 104/5)…'),
		formatDisplay: (song?: Song) => song?.songNumber || '-',
	},
	{
		key: 'stars',
		type: 'number',
		min: 0,
		max: 5,
		label: () => t('sheetmusic', 'Rating'),
		inputLabel: () => t('sheetmusic', 'Rating (Stars 0-5)'),
		placeholder: () => t('sheetmusic', '0-5'),
		formatDisplay: (song?: Song) => (song?.stars && song.stars > 0) ? `${formatStars(song.stars)} (${song.stars})` : '-',
	},
	{
		key: 'difficulty',
		type: 'number',
		min: 0,
		max: 5,
		label: () => t('sheetmusic', 'Difficulty'),
		inputLabel: () => t('sheetmusic', 'Difficulty (0-5)'),
		placeholder: () => t('sheetmusic', '0-5'),
		formatDisplay: (song?: Song) => (song?.difficulty && song.difficulty > 0) ? `${formatDifficulty(song.difficulty)} (${song.difficulty})` : '-',
	},
	{
		key: 'duration',
		type: 'number',
		min: 0,
		label: () => t('sheetmusic', 'Duration'),
		inputLabel: () => t('sheetmusic', 'Duration (seconds)'),
		placeholder: () => t('sheetmusic', 'Seconds (e.g. 180)'),
		formatDisplay: (song?: Song) => (song?.duration && song.duration > 0) ? `${formatDuration(song.duration, '-')} (${song.duration}s)` : '-',
	},
	{
		key: 'keywords',
		type: 'text',
		label: () => t('sheetmusic', 'Keywords'),
		inputLabel: () => t('sheetmusic', 'Keywords'),
		placeholder: () => t('sheetmusic', 'Keywords / tags…'),
		formatDisplay: (song?: Song) => song?.keywords || '-',
	},
	{
		key: 'custom',
		type: 'text',
		label: () => t('sheetmusic', 'Custom'),
		inputLabel: () => t('sheetmusic', 'Custom'),
		placeholder: () => t('sheetmusic', 'Custom field…'),
		formatDisplay: (song?: Song) => song?.custom || '-',
	},
	{
		key: 'custom2',
		type: 'text',
		label: () => t('sheetmusic', 'Custom 2'),
		inputLabel: () => t('sheetmusic', 'Custom 2'),
		placeholder: () => t('sheetmusic', 'Custom field 2…'),
		formatDisplay: (song?: Song) => song?.custom2 || '-',
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

export function parseDurationInput(raw: string | number): number {
	if (typeof raw === 'number') {
		return Math.max(0, Math.round(raw))
	}
	const trimmed = raw.trim()
	if (!trimmed) return 0
	if (trimmed.includes(':')) {
		const parts = trimmed.split(':').map((p) => Number(p.trim()))
		if (parts.some((n) => Number.isNaN(n) || n < 0)) return 0
		if (parts.length === 2) {
			return Math.round(parts[0] * 60 + parts[1])
		}
		if (parts.length === 3) {
			return Math.round(parts[0] * 3600 + parts[1] * 60 + parts[2])
		}
	}
	const num = Number(trimmed)
	return Number.isFinite(num) && num > 0 ? Math.round(num) : 0
}
