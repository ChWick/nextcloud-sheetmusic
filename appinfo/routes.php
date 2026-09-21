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
			'name' => 'Api#updateSong',
			'url' => '/api/songs/{id}',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#uploadSongFile',
			'url' => '/api/songs/{id}/file/upload',
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
			'name' => 'Api#regenerateFilePaths',
			'url' => '/api/regenerate-file-paths',
			'verb' => 'POST',
		],
	],
];
