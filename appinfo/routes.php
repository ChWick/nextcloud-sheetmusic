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
			'name' => 'Api#uploadSongFile',
			'url' => '/api/libraries/{libraryId}/songs/{id}/file/upload',
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
	],
];
