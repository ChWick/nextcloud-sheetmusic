import type { components } from './api.generated'

export type Composer = components['schemas']['Composer']
export type Book = components['schemas']['Book']
export type Collection = components['schemas']['Collection']
export type Artist = components['schemas']['Artist']
export type Genre = { id: number; name: string }
export type Key = { id: number; name: string }
export type CustomGroup = { id: number; name: string }
export type Year = { id: number; year: number; name: string }
export type Signature = { id: number; name: string }
export type SourceType = { id: number; type: string; name: string }
export type Bookmark = { id: number; songId: number; name: string; pageNum: number; showInLibrary?: number }
export type AudioFile = { id: number; songId: number; title: string; file: string }
export type SongFile = components['schemas']['SongFile']
export type Song = components['schemas']['Song'] & {
	genres?: Genre[]
	keys?: Key[]
	customGroups?: CustomGroup[]
	years?: Year[]
	signatures?: Signature[]
	sourceTypes?: SourceType[]
	bookmarks?: Bookmark[]
	audioFiles?: AudioFile[]
	stars?: number
	difficulty?: number
	duration?: number
	keywords?: string
	custom?: string
	custom2?: string
	sortTitle?: string
}
export type LibraryData = components['schemas']['LibraryData'] & {
	genres?: Genre[]
	keys?: Key[]
	customGroups?: CustomGroup[]
	years?: Year[]
	signatures?: Signature[]
	sourceTypes?: SourceType[]
	songs: Song[]
}
export type CreatedSongData = components['schemas']['CreatedSongData'] & {
	genres?: Genre[]
	keys?: Key[]
	customGroups?: CustomGroup[]
	years?: Year[]
	signatures?: Signature[]
	sourceTypes?: SourceType[]
	songs: Song[]
}
export type FileLogLevel = components['schemas']['FileLogLevel']
export type FileLogEntry = components['schemas']['FileLogEntry']
export type SongPrefill = components['schemas']['SongPrefill']

/** The entity kinds a song can carry, i.e. the filters of the song view. */
export type EntityType = 'composers' | 'artists' | 'books' | 'collections' | 'genres' | 'keys' | 'customgroups' | 'years' | 'signatures' | 'sourcetypes'

/** Any of the entities a song can carry. Books are titled, everything else named. */
export type FilterEntity = Composer | Artist | Book | Collection | Genre | Key | CustomGroup | Year | Signature | SourceType

/** The columns/fields of a song that can be toggled visible in song views. */
export type SongColumn = 'title' | 'composer' | 'book' | 'collection' | 'artist' | 'genre' | 'key' | 'customGroup' | 'year' | 'signature' | 'sourceType' | 'stars' | 'difficulty' | 'duration' | 'keywords' | 'custom' | 'custom2' | 'lastModified' | 'dateCreated'

export const DEFAULT_VISIBLE_COLUMNS: SongColumn[] = [
	'title',
	'composer',
	'book',
	'collection',
	'artist',
	'lastModified',
	'dateCreated',
]

/** Staged attachment from Nextcloud files before saving */
export interface PendingAttachment {
	key: string
	path: string
	name: string
	action: 'copy' | 'move'
}

/** Staged file upload from local computer before saving */
export interface PendingUpload {
	key: string
	file: File
	name: string
}

/** Display object for staged addition notices in the editor */
export interface PendingAddition {
	key: string
	name: string
	hint: string
	remove: () => void
}

/** A single issue from the library integrity audit. */
export interface IntegrityIssue {
	type: string
	severity: 'error' | 'warning' | 'info'
	message: string
	songId?: number | null
	songTitle?: string | null
	fileId?: number | null
	path?: string | null
	suggestedPath?: string | null
	fixable: boolean
	details?: {
		songIds?: number[]
		exactDuplicate?: boolean
		[key: string]: unknown
	} | null
}

