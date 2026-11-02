<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'ocs' => [
		[
			'name' => 'Api#getSongs',
			'url' => '/api/songs',
			'verb' => 'GET',
		],
		[
			'name' => 'Api#createSong',
			'url' => '/api/songs',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#updateSong',
			'url' => '/api/songs/{id}',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#deleteSong',
			'url' => '/api/songs/{id}',
			'verb' => 'DELETE',
		],
		[
			'name' => 'Api#getSongHistory',
			'url' => '/api/songs/{id}/history',
			'verb' => 'GET',
		],
		[
			'name' => 'Api#revertSongHistory',
			'url' => '/api/songs/{id}/revert/{historyId}',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#getDeletedSongs',
			'url' => '/api/libraries/{libraryId}/deleted-songs',
			'verb' => 'GET',
		],
		[
			'name' => 'Api#restoreDeletedSong',
			'url' => '/api/songs/{id}/restore',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#uploadSongFile',
			'url' => '/api/libraries/{libraryId}/songs/{id}/file/upload',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#updateSongFile',
			'url' => '/api/libraries/{libraryId}/songs/{id}/file/{fileId}/update',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#getSongFileVersions',
			'url' => '/api/libraries/{libraryId}/songs/{id}/file/{fileId}/versions',
			'verb' => 'GET',
		],
		[
			'name' => 'Api#revertSongFileVersion',
			'url' => '/api/libraries/{libraryId}/songs/{id}/file/{fileId}/versions/{revision}/revert',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#attachNextcloudFile',
			'url' => '/api/songs/{id}/file/attach',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#deleteSongFile',
			'url' => '/api/songs/{id}/file/{fileId}/delete',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#detachSongFile',
			'url' => '/api/songs/{id}/file/{fileId}/detach',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#updateSongFilePageOrder',
			'url' => '/api/songs/{id}/file/{fileId}/page-order',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#createComposer',
			'url' => '/api/composers',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#createBook',
			'url' => '/api/books',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#createCollection',
			'url' => '/api/collections',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#createArtist',
			'url' => '/api/artists',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#createGenre',
			'url' => '/api/genres',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#createKey',
			'url' => '/api/keys',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#createCustomGroup',
			'url' => '/api/customgroups',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#createYear',
			'url' => '/api/years',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#createInstrument',
			'url' => '/api/instruments',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#deleteComposer',
			'url' => '/api/composers/{id}',
			'verb' => 'DELETE',
		],
		[
			'name' => 'Api#deleteBook',
			'url' => '/api/books/{id}',
			'verb' => 'DELETE',
		],
		[
			'name' => 'Api#deleteCollection',
			'url' => '/api/collections/{id}',
			'verb' => 'DELETE',
		],
		[
			'name' => 'Api#deleteArtist',
			'url' => '/api/artists/{id}',
			'verb' => 'DELETE',
		],
		[
			'name' => 'Api#deleteGenre',
			'url' => '/api/genres/{id}',
			'verb' => 'DELETE',
		],
		[
			'name' => 'Api#deleteKey',
			'url' => '/api/keys/{id}',
			'verb' => 'DELETE',
		],
		[
			'name' => 'Api#deleteCustomGroup',
			'url' => '/api/customgroups/{id}',
			'verb' => 'DELETE',
		],
		[
			'name' => 'Api#deleteYear',
			'url' => '/api/years/{id}',
			'verb' => 'DELETE',
		],
		[
			'name' => 'Api#deleteInstrument',
			'url' => '/api/instruments/{id}',
			'verb' => 'DELETE',
		],
		[
			'name' => 'Api#deleteLibrary',
			'url' => '/api/libraries/{id}',
			'verb' => 'DELETE',
		],
		[
			'name' => 'Api#getProgress',
			'url' => '/api/libraries/progress/{progressId}',
			'verb' => 'GET',
		],
		[
			'name' => 'Api#fixIntegrityIssue',
			'url' => '/api/libraries/{id}/integrity/fix',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#fixAllIntegrityIssues',
			'url' => '/api/libraries/{id}/integrity/fix-all',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#createSignature',
			'url' => '/api/signatures',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#deleteSignature',
			'url' => '/api/signatures/{id}',
			'verb' => 'DELETE',
		],
		[
			'name' => 'Api#createSourceType',
			'url' => '/api/sourcetypes',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#deleteSourceType',
			'url' => '/api/sourcetypes/{id}',
			'verb' => 'DELETE',
		],
		[
			'name' => 'Api#mergeEntities',
			'url' => '/api/entities/merge',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#getSetlists',
			'url' => '/api/libraries/{libraryId}/setlists',
			'verb' => 'GET',
		],
		[
			'name' => 'Api#getSetlist',
			'url' => '/api/libraries/{libraryId}/setlists/{id}',
			'verb' => 'GET',
		],
		[
			'name' => 'Api#createSetlist',
			'url' => '/api/setlists',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#updateSetlist',
			'url' => '/api/setlists/{id}',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#deleteSetlist',
			'url' => '/api/setlists/{id}',
			'verb' => 'DELETE',
		],
		[
			'name' => 'Api#addSetlistItem',
			'url' => '/api/setlists/{id}/items',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#updateSetlistItem',
			'url' => '/api/setlists/{id}/items/{itemId}',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#removeSetlistItem',
			'url' => '/api/setlists/{id}/items/{itemId}',
			'verb' => 'DELETE',
		],
		[
			'name' => 'Api#reorderSetlistItems',
			'url' => '/api/setlists/{id}/reorder',
			'verb' => 'POST',
		],
	],
];
