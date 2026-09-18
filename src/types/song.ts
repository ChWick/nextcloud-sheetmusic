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

export interface SongFile {
	id: number
	path: string
}

export interface Song {
	id: number
	title: string
	filePath: string | null
	files?: SongFile[]
	composerIds?: number[]
	bookIds?: number[]
	collectionIds?: number[]
}

export interface LibraryData {
	songs: Song[]
	composers: Composer[]
	books: Book[]
	collections: Collection[]
}
