<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync;

use OCA\SheetMusic\Db\InternalLibrary;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Interface for sheet music client synchronization backends (e.g. MobileSheets, ForScore, SongBook).
 */
interface SyncClientInterface {
	/**
	 * Unique identifier for the client type, e.g. 'mobilesheets'.
	 */
	public function getClientType(): string;

	/**
	 * List of virtual filenames exposed in the library folder for this client.
	 *
	 * @return list<string>
	 */
	public function getVirtualFileNames(): array;

	/**
	 * Check if a filename is handled by this sync client.
	 */
	public function handlesVirtualFile(string $filename): bool;

	/**
	 * Export the library data for this client to a destination file path.
	 */
	public function exportLibrary(int $libraryId, string $destinationPath, string $userId): void;

	/**
	 * Import client data from a source file path into the internal database.
	 */
	public function importLibrary(int $libraryId, string $sourcePath, string $userId): void;

	/**
	 * Handle a WebDAV GET or HEAD request for a virtual file.
	 *
	 * @return bool True if the request was handled, false otherwise.
	 */
	public function handleGet(
		RequestInterface $request,
		ResponseInterface $response,
		InternalLibrary $library,
		string $filename,
		bool $isHead,
	): bool;

	/**
	 * Handle a WebDAV PUT request for a virtual file.
	 *
	 * @return bool True if the request was handled, false otherwise.
	 */
	public function handlePut(
		RequestInterface $request,
		ResponseInterface $response,
		InternalLibrary $library,
		string $filename,
	): bool;

	/**
	 * Return properties for PROPFIND (content type, file size, last modified timestamp).
	 *
	 * @return array{size: int, contentType: string, lastModified: int}|null
	 */
	public function getVirtualFileProperties(InternalLibrary $library, string $filename, string $userId): ?array;
}

