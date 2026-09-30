import type { components } from './api.generated'

export type Composer = components['schemas']['Composer']
export type Book = components['schemas']['Book']
export type Collection = components['schemas']['Collection']
export type Artist = components['schemas']['Artist']
export type SongFile = components['schemas']['SongFile']
export type Song = components['schemas']['Song']
export type LibraryData = components['schemas']['LibraryData']
export type CreatedSongData = components['schemas']['CreatedSongData']
export type FileLogLevel = components['schemas']['FileLogLevel']
export type FileLogEntry = components['schemas']['FileLogEntry']
export type SongPrefill = components['schemas']['SongPrefill']

/** The four entity kinds a song can carry, i.e. the four filters of the song view. */
export type EntityType = 'composers' | 'artists' | 'books' | 'collections'

/** Any of the entities a song can carry. Books are titled, everything else named. */
export type FilterEntity = Composer | Artist | Book | Collection

/** The columns/fields of a song that can be toggled visible in song views. */
export type SongColumn = 'title' | 'composer' | 'book' | 'collection' | 'artist' | 'lastModified' | 'dateCreated'

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
