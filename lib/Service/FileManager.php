<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MobileSheetsManager\Service;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\MobileSheetsFile;
use OCA\MobileSheetsManager\Db\MobileSheetsSong;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCP\Files\IRootFolder;
use OCP\IUser;
use Psr\Log\LoggerInterface;

function getFileExtension(string $filename): string {
	$extension = pathinfo($filename, PATHINFO_EXTENSION);

	return $extension !== '' ? '.' . $extension : '';
}

function createWithComposerOrArtist(MobileSheetsSong $song, string $ext): string {
	$composer = $song->composerName ?? '';
	$artist = $song->artistName ?? '';

	if ($composer === '' && $artist === '') {
		throw new \Exception('No composer or artist');
	}

	$compArtist = $composer !== '' ? $composer : $artist;
	$book = $song->bookName ?? '';
	$collection = $song->collectionName ?? '';
	$ds = DIRECTORY_SEPARATOR;
	$newPath = $compArtist;

	if ($book !== '') {
		$newPath .= $ds . "{$compArtist} - {$book}";
		$newPath .= $ds . "{$compArtist} - {$book} - {$song->title}" . $ext;
	} elseif ($collection !== '') {
		$newPath .= $ds . "{$compArtist} - {$collection}";
		$newPath .= $ds . "{$compArtist} - {$collection} - {$song->title}" . $ext;
	} else {
		$newPath .= $ds . "{$compArtist} - {$song->title}" . $ext;
	}

	return $newPath;
}

function createWithCollection(MobileSheetsSong $song, string $ext): string {
	$collection = $song->collectionName ?? '';

	if ($collection === '') {
		throw new \Exception('No collection');
	}

	$ds = DIRECTORY_SEPARATOR;
	$newPath = $collection;
	$newPath .= $ds . "{$collection} - {$song->title}" . $ext;

	return $newPath;
}

function createTargetPath(MobileSheetsSong $song, string $ext): string {
	if (($song->composerName ?? '') !== '' || ($song->artistName ?? '') !== '') {
		return createWithComposerOrArtist($song, $ext);
	}

	if (($song->collectionName ?? '') !== '') {
		return createWithCollection($song, $ext);
	}

	throw new \Exception('No composer, artist, or collection');
}

class FileManager {
	public function __construct(
		private LibraryMapper $libraryMapper,
		private SongMapper $songMapper,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	public function regenerateFilePaths(int $libraryId, IUser $user): void {
		$library = $this->libraryMapper->findById($libraryId);
		if ($library === null) {
			throw new \Exception("Library with ID $libraryId not found");
		}

		$path = $library['path'] ?? '';
		$node = $this->rootFolder->getUserFolder($user->getUID())->get(ltrim($path, '/'));
		$databasePath = $node->getStorage()->getLocalFile($node->getInternalPath());
		if ($databasePath === false) {
			throw new \Exception("Could not retrieve local file path for library with ID $libraryId");
		}

		$songs = $this->songMapper->findSongs($databasePath);

		foreach ($songs as $song) {
			foreach ($song->files as $file) {
				$this->regenerateFilePath($song, $file);
			}
		}
	}

	public function regenerateFilePath(MobileSheetsSong $song, MobileSheetsFile $file): void {
		$ext = getFileExtension($file->path);
		$targetPath = createTargetPath($song, $ext);

		$this->logger->info('Computed target path for MobileSheets file', [
			'fileId' => $file->id,
			'sourcePath' => $file->path,
			'targetPath' => $targetPath,
		]);
	}
}