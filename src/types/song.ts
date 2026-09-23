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
