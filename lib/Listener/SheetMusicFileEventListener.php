<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Listener;

use OCA\SheetMusic\Db\InternalLibrary;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Service\LibraryReconciler;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Real-time listener for file system events to automatically index, move,
 * and prune music files.
 *
 * @template-implements IEventListener<NodeCreatedEvent|NodeRenamedEvent|NodeDeletedEvent>
 */
class SheetMusicFileEventListener implements IEventListener {
	public function __construct(
		private LibraryMapper $libraryMapper,
		private InternalSongMapper $songMapper,
		private InternalSongFileMapper $songFileMapper,
		private LibraryReconciler $reconciler,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof NodeCreatedEvent) {
			$this->onNodeCreated($event->getNode());
		} elseif ($event instanceof NodeRenamedEvent) {
			$this->onNodeRenamed($event->getSource(), $event->getTarget());
		} elseif ($event instanceof NodeDeletedEvent) {
			$this->onNodeDeleted($event->getNode());
		}
	}

	public function onNodeCreated(Node $node): void {
		if (!($node instanceof File)) {
			return;
		}

		if ($this->reconciler->isIgnoredFilename($node->getName())) {
			return;
		}

		$library = $this->findLibraryForNode($node);
		if ($library === null || $library->id === null) {
			return;
		}

		$existing = $this->songFileMapper->findByFileId($node->getId(), includeDeleted: false);
		if ($existing !== []) {
			return;
		}

		try {
			$this->reconciler->indexFile($library, $node);
			$this->libraryMapper->touch($library->id);
			$this->logger->info('Automatically indexed new sheet music file', [
				'file' => $node->getName(),
				'libraryId' => $library->id,
			]);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to auto-index new sheet music file: ' . $e->getMessage(), [
				'exception' => $e,
				'file' => $node->getName(),
			]);
		}
	}

	public function onNodeRenamed(Node $source, Node $target): void {
		if (!($target instanceof File)) {
			return;
		}

		$sourceLib = $this->findLibraryForNode($source);
		$targetLib = $this->findLibraryForNode($target);

		if ($sourceLib === null && $targetLib === null) {
			return;
		}

		if ($sourceLib !== null && $targetLib === null) {
			// File moved OUT of library
			$files = $this->songFileMapper->findByFileId($target->getId(), includeDeleted: false);
			foreach ($files as $file) {
				$this->songFileMapper->delete($file->id, hardDelete: false);
			}
			if ($sourceLib->id !== null) {
				$this->libraryMapper->touch($sourceLib->id);
			}
			return;
		}

		if ($sourceLib === null && $targetLib !== null) {
			// File moved INTO library
			$this->onNodeCreated($target);
			return;
		}

		// Renamed within same library (or between libraries)
		if ($targetLib !== null && $targetLib->id !== null) {
			$this->libraryMapper->touch($targetLib->id);
		}
	}

	public function onNodeDeleted(Node $node): void {
		if (!($node instanceof File)) {
			return;
		}

		$files = $this->songFileMapper->findByFileId($node->getId(), includeDeleted: false);
		if ($files === []) {
			return;
		}

		foreach ($files as $file) {
			$this->songFileMapper->delete($file->id, hardDelete: false);
		}

		$library = $this->findLibraryForNode($node);
		if ($library !== null && $library->id !== null) {
			$this->libraryMapper->touch($library->id);
		}
	}

	public function findLibraryForNode(Node $node): ?InternalLibrary {
		$path = $node->getPath(); // e.g. "/admin/files/Noten/Song.pdf"
		$parts = explode('/', ltrim($path, '/'));
		if (count($parts) < 3 || $parts[1] !== 'files') {
			return null;
		}

		$userId = $parts[0];
		// Path relative to user folder, e.g. "Noten/Song.pdf"
		$relToUser = implode('/', array_slice($parts, 2));

		$libraries = $this->libraryMapper->findAllForUser($userId);
		foreach ($libraries as $lib) {
			$cleanLibPath = trim($lib->path, '/');
			if ($cleanLibPath === '' || str_starts_with($relToUser, $cleanLibPath . '/') || $relToUser === $cleanLibPath) {
				return $lib;
			}
		}

		return null;
	}
}
