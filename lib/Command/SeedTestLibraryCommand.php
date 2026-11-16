<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Command;

use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongBookmark;
use OCA\SheetMusic\Db\InternalSongBookmarkMapper;
use OCA\SheetMusic\Db\InternalSongFile;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongMapper;
use OCA\SheetMusic\Db\LibraryMapper;
use OCA\SheetMusic\Service\SetlistService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SeedTestLibraryCommand extends Command {
	public function __construct(
		private IUserManager $userManager,
		private IRootFolder $rootFolder,
		private LibraryMapper $libraryMapper,
		private InternalSongMapper $songMapper,
		private InternalSongFileMapper $songFileMapper,
		private InternalSongBookmarkMapper $bookmarkMapper,
		private SetlistService $setlistService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('sheetmusic:seed-test-library')
			->setDescription('Ensure a valid test sheet music library with multiple songs and a setlist exists')
			->addOption('user', 'u', InputOption::VALUE_OPTIONAL, 'Target Nextcloud user ID', 'admin')
			->addOption('path', 'p', InputOption::VALUE_OPTIONAL, 'Library folder path in user files', '/SheetMusicTest');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getOption('user');
		$rawPath = (string)$input->getOption('path');
		$libraryPath = '/' . ltrim($rawPath, '/');
		if ($libraryPath !== '/') {
			$libraryPath = rtrim($libraryPath, '/');
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			$output->writeln("<error>User '{$userId}' not found.</error>");
			return Command::FAILURE;
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$folderName = ltrim($libraryPath, '/');
		if ($folderName === '') {
			$libraryFolder = $userFolder;
		} elseif ($userFolder->nodeExists($folderName)) {
			$node = $userFolder->get($folderName);
			if (!$node instanceof Folder) {
				$output->writeln("<error>Path '{$libraryPath}' exists and is not a folder.</error>");
				return Command::FAILURE;
			}
			$libraryFolder = $node;
		} else {
			$libraryFolder = $userFolder->newFolder($folderName);
		}

		$library = $this->libraryMapper->findByPathForUser($userId, $libraryPath);
		if ($library === null) {
			$this->libraryMapper->addForUser($userId, $libraryPath, $libraryFolder->getId());
			$library = $this->libraryMapper->findByPathForUser($userId, $libraryPath);
		} elseif ($library->folderId !== $libraryFolder->getId()) {
			$this->libraryMapper->updateFolderId((int)$library->id, $libraryFolder->getId());
		}

		if ($library === null || $library->id === null) {
			$output->writeln('<error>Failed to resolve or create test library.</error>');
			return Command::FAILURE;
		}

		$libraryId = (int)$library->id;
		$now = time();

		$seedSongs = [
			[
				'title' => 'Autumn Leaves',
				'filename' => 'autumn-leaves.pdf',
				'pages' => 4,
				'duration' => 210,
				'bookmark' => null,
			],
			[
				'title' => 'Blue Bossa',
				'filename' => 'blue-bossa.pdf',
				'pages' => 5,
				'duration' => 195,
				'bookmark' => null,
			],
			[
				'title' => 'Fly Me to the Moon',
				'filename' => 'fly-me-to-the-moon.pdf',
				'pages' => 4,
				'duration' => 180,
				'bookmark' => ['name' => 'Bridge & Coda', 'page' => 3],
			],
			[
				'title' => 'Take Five',
				'filename' => 'take-five.pdf',
				'pages' => 6,
				'duration' => 324,
				'bookmark' => null,
				'landscape' => false,
			],
			[
				'title' => 'All Blues (Landscape)',
				'filename' => 'all-blues-landscape.pdf',
				'pages' => 3,
				'duration' => 245,
				'bookmark' => null,
				'landscape' => true,
			],
		];

		$songIds = [];
		$bookmarkIdsBySong = [];

		foreach ($seedSongs as $spec) {
			$filename = $spec['filename'];
			$isLandscape = (bool)($spec['landscape'] ?? false);
			$pdfBytes = $this->buildMultiPagePdf($spec['title'], $spec['pages'], $isLandscape);

			if ($libraryFolder->nodeExists($filename)) {
				$fileNode = $libraryFolder->get($filename);
				if ($fileNode instanceof \OCP\Files\File && $fileNode->getSize() === 0) {
					$fileNode->putContent($pdfBytes);
				}
			} else {
				$fileNode = $libraryFolder->newFile($filename, $pdfBytes);
			}

			$song = $this->songMapper->findByTitle($libraryId, $spec['title']);
			if ($song === null) {
				// SheetMusicFileEventListener may have auto-imported the file under its filename stem
				$autoLinked = $this->songFileMapper->findByFileId($fileNode->getId());
				if (count($autoLinked) > 0) {
					$song = $this->songMapper->findById($autoLinked[0]->songId);
					if ($song !== null) {
						$song->title = $spec['title'];
						$song->sortTitle = $spec['title'];
						$song->duration = $spec['duration'];
						$song->updatedAt = $now;
						$song = $this->songMapper->update($song);
					}
				}
			}
			if ($song === null) {
				$song = $this->songMapper->insert(new InternalSong(
					uuid: $this->generateUuid(),
					libraryId: $libraryId,
					title: $spec['title'],
					sortTitle: $spec['title'],
					duration: $spec['duration'],
					createdAt: $now,
					updatedAt: $now,
				));
			}

			$songId = (int)$song->id;
			$songIds[] = $songId;

			$existingFiles = $this->songFileMapper->findAllForSong($songId);
			if (count($existingFiles) === 0) {
				$this->songFileMapper->insert(new InternalSongFile(
					uuid: $this->generateUuid(),
					songId: $songId,
					fileId: $fileNode->getId(),
					pageOrder: '1-' . $spec['pages'],
					pageCount: $spec['pages'],
					fileSize: strlen($pdfBytes),
					type: InternalSongFile::FILE_TYPE_PDF,
					createdAt: $now,
					updatedAt: $now,
				));
			}

			if ($spec['bookmark'] !== null) {
				$existingBookmarks = $this->bookmarkMapper->findForSong($songId);
				if (count($existingBookmarks) === 0) {
					$bm = $this->bookmarkMapper->insert(new InternalSongBookmark(
						id: null,
						songId: $songId,
						name: $spec['bookmark']['name'],
						pageNum: $spec['bookmark']['page'],
						showInLibrary: 1,
						createdAt: $now,
						updatedAt: $now,
					));
					$bookmarkIdsBySong[$songId] = $bm->id;
				} else {
					$bookmarkIdsBySong[$songId] = $existingBookmarks[0]->id;
				}
			}
		}

		$setlistName = 'Weekend Gig Setlist';
		$existingSetlists = $this->setlistService->getSetlists($user, $libraryId) ?? [];
		$testSetlist = null;
		foreach ($existingSetlists as $sl) {
			if (($sl['name'] ?? '') === $setlistName) {
				$testSetlist = $sl;
				break;
			}
		}

		if ($testSetlist === null) {
			$created = $this->setlistService->createSetlist($user, $libraryId, $setlistName);
			$setlistId = (int)$created->id;
		} else {
			$setlistId = (int)$testSetlist['id'];
		}

		$fullSetlist = $this->setlistService->getSetlist($user, $libraryId, $setlistId);
		$existingItems = $fullSetlist['items'] ?? [];
		$existingSongIds = array_map(static fn (mixed $item): int => (int)(is_array($item) ? $item['songId'] : $item->songId), $existingItems);
		foreach ($songIds as $songId) {
			if (!in_array($songId, $existingSongIds, true)) {
				$bookmarkId = $bookmarkIdsBySong[$songId] ?? null;
				$this->setlistService->addItem($user, $libraryId, $setlistId, $songId, $bookmarkId);
			}
		}

		$output->writeln("<info>Test library '{$libraryPath}' (ID: {$libraryId}) for user '{$userId}' is ready with " . count($songIds) . " songs and setlist '{$setlistName}' (ID: {$setlistId}).</info>");
		return Command::SUCCESS;
	}

	private function generateUuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	/**
	 * Build a minimal, valid multi-page PDF 1.4 document with visible page labels.
	 */
	private function buildMultiPagePdf(string $title, int $pageCount, bool $landscape = false): string {
		$objects = [];
		$pageIds = [];

		// 1: Catalog, 2: Pages, 3: Font (Helvetica)
		$objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
		$objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

		$nextId = 4;
		$escapedTitle = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $title);
		$mediaBox = $landscape ? '[0 0 792 612]' : '[0 0 612 792]';
		$startY = $landscape ? 540 : 720;

		for ($p = 1; $p <= $pageCount; $p++) {
			$pageObjId = $nextId++;
			$contentObjId = $nextId++;
			$pageIds[] = "{$pageObjId} 0 R";

			$stream = "BT\n/F1 28 Tf\n72 {$startY} Td\n({$escapedTitle}) Tj\n0 -42 Td\n/F1 20 Tf\n(Page {$p} of {$pageCount}) Tj\nET";
			$len = strlen($stream);

			$objects[$pageObjId] = "<< /Type /Page /Parent 2 0 R /MediaBox {$mediaBox} /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentObjId} 0 R >>";
			$objects[$contentObjId] = "<< /Length {$len} >>\nstream\n{$stream}\nendstream";
		}

		$kidsStr = implode(' ', $pageIds);
		$objects[2] = "<< /Type /Pages /Kids [ {$kidsStr} ] /Count {$pageCount} >>";
		ksort($objects);

		$pdf = "%PDF-1.4\n";
		$offsets = [0];
		foreach ($objects as $id => $body) {
			$offsets[$id] = strlen($pdf);
			$pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
		}

		$xrefOffset = strlen($pdf);
		$count = count($objects) + 1;
		$pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
		for ($i = 1; $i < $count; $i++) {
			$pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
		}
		$pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

		return $pdf;
	}
}
