export interface Composer {
	id: number
	name: string
}

export interface Book {
	id: number
	title: string
}

export interface Collection {
	id: number
	name: string
}

export interface Artist {
	id: number
	name: string
}

export interface SongFile {
	id: number
	path: string
}

/** The four entity kinds a song can carry, i.e. the four filters of the song view. */
export type EntityType = 'composers' | 'artists' | 'books' | 'collections'

/** Any of the entities a song can carry. Books are titled, everything else named. */
export type FilterEntity = Composer | Artist | Book | Collection

export interface Song {
	id: number
	title: string
	files?: SongFile[]
	composers?: Composer[]
	books?: Book[]
	collections?: Collection[]
	artists?: Artist[]
}

export interface LibraryData {
	songs: Song[]
	composers: Composer[]
	books: Book[]
	collections: Collection[]
	artists: Artist[]
}

/** Library data the API returns after a song was created, plus the id of that song. */
export interface CreatedSongData extends LibraryData {
	createdSongId: number
}

/** Severity of a log line, so the user can filter the log down. */
export type FileLogLevel = 'info' | 'warning' | 'error'

/** One line of the log a bulk run produces: what happened to one file of a song. */
export interface FileLogEntry {
	songId: number
	songTitle: string
	filePath: string
	message: string
	level: FileLogLevel
}

/**
 * Metadata read from an imported PDF: the information a new song is prefilled
 * with. The entity fields hold names, which the editor turns into entities -
 * existing ones when a name matches, new ones otherwise.
 */
export interface SongPrefill {
	title: string
	composers: string[]
	books: string[]
	collections: string[]
	artists: string[]
}
