<?php

declare(strict_types=1);

namespace OCA\MobileSheetsManager\Controller;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\MobileSheetsFile;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCA\MobileSheetsManager\Service\FileManager;
use OCA\MobileSheetsManager\Service\LibrarySyncService;
use OCA\MobileSheetsManager\Service\MobileSheetsFileFacts;
use OCA\MobileSheetsManager\Service\MobileSheetsFileLogEntry;
use OCA\MobileSheetsManager\Service\MobileSheetsHashCodesFile;
use OCA\MobileSheetsManager\Service\MobileSheetsPdfMetadata;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * @psalm-suppress UnusedClass
 */
class ApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private LibraryMapper $libraryMapper,
		private SongMapper $songMapper,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
		private IUserSession $userSession,
		private FileManager $fileManager,
		private LibrarySyncService $librarySync,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * An example API endpoint
	 *
	 * @return DataResponse<Http::STATUS_OK, array{message: string}, array{}>
	 *
	 * 200: Data returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api')]
	public function index(): DataResponse {
		return new DataResponse(
			['message' => 'Hello world!']
		);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/libraries')]
	public function getLibraries(): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		return new DataResponse(['libraries' => $this->libraryMapper->findAllForUser($user->getUID())]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/libraries')]
	public function addLibrary(string $path): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if (!str_starts_with($path, '/') || !str_ends_with(strtolower($path), '.db')) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$this->libraryMapper->addForUser($user->getUID(), $path);
		return new DataResponse(['path' => $path], Http::STATUS_CREATED);
	}

	/**
	 * Remove a library from the user's list of libraries.
	 *
	 * Only the entry in this app's own table is removed. The MobileSheets database
	 * and every file it references keep sitting in the user's folder, so nothing is
	 * lost and the library can be added again at any time.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/libraries/{id}')]
	public function deleteLibrary(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$libraryPath = $this->resolveLibraryPath($user->getUID(), $id);
		if ($libraryPath === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$this->libraryMapper->deleteForUser($user->getUID(), $id);

		return new DataResponse([
			'id' => $id,
			'path' => $libraryPath,
		]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/songs')]
	public function getSongs(?string $id = null): DataResponse {
		$id = $id === null ? -1 : (int)$id;
		if ($id < 0) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$library = $this->libraryMapper->findForUser($user->getUID(), $id);

		if (!$library) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$path = $library['path'] ?? '';

		try {
			$node = $this->rootFolder->getUserFolder($user->getUID())->get(ltrim($path, '/'));
			if ($node->getType() !== 'file') {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$databasePath = $node->getStorage()->getLocalFile($node->getInternalPath());
			if ($databasePath === false) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($this->songMapper->findLibraryData($databasePath));
		} catch (\Throwable $exception) {
			$this->logger->error('Could not load songs from MobileSheets library', [
				'exception' => $exception,
				'path' => $path,
				'userId' => $user->getUID(),
			]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Create a song in a library.
	 *
	 * The response is the library data plus the id of the new song, so the client can add
	 * files to the song right afterwards.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs')]
	public function createSong(
		?int $libraryId = null,
		?string $title = null,
		array $composerIds = [],
		array $bookIds = [],
		array $collectionIds = [],
		array $artistIds = [],
	): DataResponse {
		$title = $title ?? '';
		if ($libraryId === null || trim($title) === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$libraryPath = $this->resolveLibraryPath($user->getUID(), $libraryId);
		if ($libraryPath === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$node = $this->rootFolder->getUserFolder($user->getUID())->get(ltrim($libraryPath, '/'));
			$databasePath = $node->getStorage()->getLocalFile($node->getInternalPath());
			if ($databasePath === false) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$songId = $this->songMapper->createSong(
				$databasePath,
				trim($title),
				$composerIds,
				$bookIds,
				$collectionIds,
				$artistIds,
			);

			return new DataResponse(
				['createdSongId' => $songId] + $this->songMapper->findLibraryData($databasePath),
				Http::STATUS_CREATED,
			);
		} catch (\Throwable $exception) {
			$this->logger->error('Could not create song in MobileSheets library', [
				'exception' => $exception,
				'libraryId' => $libraryId,
				'path' => $libraryPath,
				'title' => $title,
			]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}')]
	public function updateSong(
		int $id,
		?int $libraryId = null,
		?string $title = null,
		array $composerIds = [],
		array $bookIds = [],
		array $collectionIds = [],
		array $artistIds = [],
	): DataResponse {
		$title = $title ?? '';
		if ($libraryId === null || $title === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$libraryPath = $this->resolveLibraryPath($user->getUID(), $libraryId);
		if ($libraryPath === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$node = $this->rootFolder->getUserFolder($user->getUID())->get(ltrim($libraryPath, '/'));
			$databasePath = $node->getStorage()->getLocalFile($node->getInternalPath());
			if ($databasePath === false) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$this->songMapper->updateSong($databasePath, $id, $title, $composerIds, $bookIds, $collectionIds, $artistIds);

			// Best-effort: the song is saved even when one of its files refuses
			// the metadata write (an exotic PDF, or one encrypted with a user
			// password).
			$this->syncSongFileMetadata($user, $databasePath, $id);

			return new DataResponse($this->songMapper->findLibraryData($databasePath));
		} catch (\Throwable $exception) {
			$this->logger->error('Could not update song in MobileSheets library', [
				'exception' => $exception,
				'libraryId' => $libraryId,
				'path' => $libraryPath,
				'songId' => $id,
			]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs-batch-update')]
	public function batchUpdateSongs(
		?int $libraryId = null,
		array $songIds = [],
		?array $composers = null,
		?array $books = null,
		?array $collections = null,
		?array $artists = null,
	): DataResponse {
		if ($libraryId === null || empty($songIds)) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$libraryPath = $this->resolveLibraryPath($user->getUID(), $libraryId);
		if ($libraryPath === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$node = $this->rootFolder->getUserFolder($user->getUID())->get(ltrim($libraryPath, '/'));
			$databasePath = $node->getStorage()->getLocalFile($node->getInternalPath());
			if ($databasePath === false) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$this->songMapper->batchUpdateSongs(
				$databasePath,
				$songIds,
				$composers,
				$books,
				$collections,
				$artists,
			);

			foreach ($songIds as $songId) {
				$this->syncSongFileMetadata($user, $databasePath, (int)$songId);
			}

			return new DataResponse($this->songMapper->findLibraryData($databasePath));
		} catch (\Throwable $exception) {
			$this->logger->error('Could not batch update songs in MobileSheets library', [
				'exception' => $exception,
				'libraryId' => $libraryId,
				'path' => $libraryPath,
				'songIds' => $songIds,
			]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Delete a song from a library.
	 *
	 * The song with every row that points at it is removed from the MobileSheets
	 * database. Its attached files can go with it - moved into the Nextcloud trash
	 * bin, so deleting a song by accident stays recoverable - or stay in place,
	 * based on $deleteFiles.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/songs/{id}')]
	public function deleteSong(int $id, ?int $libraryId = null, bool $deleteFiles = true): DataResponse {
		if ($libraryId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$libraryPath = $this->resolveLibraryPath($user->getUID(), $libraryId);
		if ($libraryPath === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$node = $this->rootFolder->getUserFolder($user->getUID())->get(ltrim($libraryPath, '/'));
			$databasePath = $node->getStorage()->getLocalFile($node->getInternalPath());
			if ($databasePath === false) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$song = $this->songMapper->findSong($databasePath, $id);
			if ($song === null) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$filePaths = array_map(
				static fn (MobileSheetsFile $file): string => $file->path,
				$song->files,
			);
			if ($deleteFiles) {
				// The files are handled while the database still lists them: a failure below
				// leaves the library as it was, with the files restorable from the trash bin.
				// A stored path belonging to another user yields nothing to delete there.
				$hashCodes = $this->hashCodesFor($databasePath);
				foreach ($filePaths as $filePath) {
					$storedFile = $this->fileManager->pathOfStoredFile($filePath, $user);
					if ($storedFile !== null) {
						$this->deleteFileNode($storedFile->internal);
					}
					$hashCodes?->removePath($filePath);
				}
				$hashCodes?->save();
			}

			$this->songMapper->deleteSong($databasePath, $id);

			$this->logger->info('Deleted MobileSheets song', [
				'libraryId' => $libraryId,
				'songId' => $id,
				'title' => $song->title,
				'files' => count($filePaths),
				'filesDeleted' => $deleteFiles,
			]);

			return new DataResponse($this->songMapper->findLibraryData($databasePath));
		} catch (\Throwable $exception) {
			$this->logger->error('Could not delete song from MobileSheets library', [
				'exception' => $exception,
				'libraryId' => $libraryId,
				'path' => $libraryPath,
				'songId' => $id,
			]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Read the document properties of an uploaded PDF so a new song can be
	 * prefilled from them before it is created.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/import-metadata')]
	public function importSongMetadata(): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$fileInfo = $this->request->getUploadedFile('file');
		if ($fileInfo === null && isset($_FILES['file'])) {
			$fileInfo = $_FILES['file'];
		}

		if ($fileInfo === null || !isset($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
			return new DataResponse(['message' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
		}

		$content = file_get_contents($fileInfo['tmp_name']);
		if ($content === false || $content === '') {
			return new DataResponse(['message' => 'Could not read the uploaded file'], Http::STATUS_BAD_REQUEST);
		}

		try {
			return new DataResponse(MobileSheetsPdfMetadata::prefillFor($content));
		} catch (\Throwable $exception) {
			// Not a readable PDF: the form is still opened, just not prefilled.
			$this->logger->info('Could not read the metadata of an imported PDF', [
				'exception' => $exception,
			]);

			return new DataResponse(['message' => 'Could not read the PDF metadata'], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/file/upload')]
	public function uploadSongFile(int $id, ?int $libraryId = null): DataResponse {
		if ($libraryId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$libraryPath = $this->resolveLibraryPath($user->getUID(), $libraryId);
		if ($libraryPath === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$fileInfo = $this->request->getUploadedFile('file');
		if ($fileInfo === null && isset($_FILES['file'])) {
			$fileInfo = $_FILES['file'];
		}

		if ($fileInfo === null || !isset($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
			return new DataResponse(['message' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
		}

		$fileName = basename((string)($fileInfo['name'] ?? 'song.pdf'));
		if (!str_ends_with(strtolower($fileName), '.pdf')) {
			return new DataResponse(['message' => 'Only PDF files are supported'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$libraryNode = $userFolder->get(ltrim($libraryPath, '/'));
			$databasePath = $libraryNode->getStorage()->getLocalFile($libraryNode->getInternalPath());
			if ($databasePath === false) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$libraryDir = $this->libraryFolderPath($libraryPath);
			$targetFolder = $libraryDir === '' ? $userFolder : $userFolder->get($libraryDir);
			if (!($targetFolder instanceof Folder)) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$content = file_get_contents($fileInfo['tmp_name']);
			if ($content === false) {
				return new DataResponse(['message' => 'Could not read the uploaded file'], Http::STATUS_BAD_REQUEST);
			}

			// The upload is stored next to the library database under its own name
			// first. FileManager then moves it - together with the song's other files -
			// to the location its naming rules produce.
			$targetRelative = ($libraryDir === '' ? '' : $libraryDir . '/') . $fileName;
			// Never replace a file that is already there. Uploading the same file twice,
			// or to two songs that share a title, has to leave one file per song entry,
			// so a taken name gets a counter ("X (2).pdf").
			$targetRelative = $this->fileManager->uniqueRelativePath($userFolder, $targetRelative);
			$targetFile = $userFolder->newFile($targetRelative);
			$targetFile->putContent($content);

			// FileManager owns both path representations, so the WebDAV path stored in
			// the database is derived from the file's node path instead of being
			// assembled here.
			$this->recordSongFile($id, $databasePath, $targetFile, $targetFolder, $user);

			return new DataResponse($this->songMapper->findLibraryData($databasePath));
		} catch (\Throwable $exception) {
			$this->logger->error('Could not upload file for song', [
				'exception' => $exception,
				'libraryId' => $libraryId,
				'path' => $libraryPath,
				'songId' => $id,
			]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/file/attach')]
	public function attachNextcloudFile(
		int $id,
		?int $libraryId = null,
		?string $sourcePath = null,
		string $action = 'copy',
	): DataResponse {
		$sourcePath = $sourcePath ?? (string)$this->request->getParam('sourcePath', '');
		$action = (string)$this->request->getParam('action', $action);

		if ($libraryId === null || $sourcePath === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		$sourcePath = '/' . ltrim($sourcePath, '/');

		if (!str_ends_with(strtolower($sourcePath), '.pdf')) {
			return new DataResponse(['message' => 'Only PDF files are supported'], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$libraryPath = $this->resolveLibraryPath($user->getUID(), $libraryId);
		if ($libraryPath === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$libraryNode = $userFolder->get(ltrim($libraryPath, '/'));
			$databasePath = $libraryNode->getStorage()->getLocalFile($libraryNode->getInternalPath());
			if ($databasePath === false) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$sourceNode = $userFolder->get(ltrim($sourcePath, '/'));
			if ($sourceNode->getType() !== 'file') {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$libraryDir = $this->libraryFolderPath($libraryPath);
			$targetFolder = $libraryDir === '' ? $userFolder : $userFolder->get($libraryDir);
			if (!($targetFolder instanceof Folder)) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			// The file is placed next to the library database under its own name
			// first. FileManager then moves it - together with the song's other files -
			// to the location its naming rules produce.
			$targetName = $sourceNode->getName();
			$targetRelative = ($libraryDir === '' ? '' : $libraryDir . '/') . $targetName;
			$sourceRelative = ltrim($sourcePath, '/');

			if ($sourceRelative !== $targetRelative) {
				// A taken name gets a counter: copying may not replace another song's
				// file and moving may not take it away, and the song keeps its own file
				// even when a name is used twice.
				$targetRelative = $this->fileManager->uniqueRelativePath($userFolder, $targetRelative);
				$targetName = basename($targetRelative);

				// Node::move() and Node::copy() resolve against the filesystem root, so
				// they need the full internal path, not one relative to the user folder.
				$targetInternalPath = $targetFolder->getPath() . '/' . $targetName;
				if ($action === 'move') {
					$sourceNode->move($targetInternalPath);
				} else {
					$sourceNode->copy($targetInternalPath);
				}
			}

			// The copy/move above replaced the node, so read the file that is now in the
			// library folder to get its real path.
			$attachedFile = $targetFolder->get($targetName);
			$this->recordSongFile($id, $databasePath, $attachedFile, $targetFolder, $user);

			return new DataResponse($this->songMapper->findLibraryData($databasePath));
		} catch (\Throwable $exception) {
			$this->logger->error('Could not attach Nextcloud file for song', [
				'exception' => $exception,
				'libraryId' => $libraryId,
				'path' => $libraryPath,
				'songId' => $id,
				'sourcePath' => $sourcePath,
			]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/file/{fileId}/delete')]
	public function deleteSongFile(int $id, int $fileId, ?int $libraryId = null): DataResponse {
		if ($libraryId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$libraryPath = $this->resolveLibraryPath($user->getUID(), $libraryId);
		if ($libraryPath === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$libraryNode = $userFolder->get(ltrim($libraryPath, '/'));
			$databasePath = $libraryNode->getStorage()->getLocalFile($libraryNode->getInternalPath());
			if ($databasePath === false) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$libraryDir = $this->libraryFolderPath($libraryPath);
			$targetFolder = $libraryDir === '' ? $userFolder : $userFolder->get($libraryDir);
			if (!($targetFolder instanceof Folder)) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$filePath = $this->songMapper->removeSongFile($databasePath, $id, $fileId);

			if ($filePath !== null) {
				// The stored path is a WebDAV path, so FileManager turns it into the node
				// path the filesystem work needs. A path belonging to another user yields
				// nothing to delete - the database row was the only thing to remove.
				$deletedFile = $this->fileManager->pathOfStoredFile($filePath, $user);

				if ($deletedFile !== null) {
					$this->deleteFileNode($deletedFile->internal);
				}
			}

			// Losing a file changes the numbering of the song's remaining files.
			$this->relocateSongFiles($id, $databasePath, $targetFolder, $user, $filePath);

			return new DataResponse($this->songMapper->findLibraryData($databasePath));
		} catch (\Throwable $exception) {
			$this->logger->error('Could not delete file for song', [
				'exception' => $exception,
				'libraryId' => $libraryId,
				'path' => $libraryPath,
				'songId' => $id,
				'fileId' => $fileId,
			]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Delete a file from the user's folder, ignoring one that is already gone.
	 *
	 * @param string $internalPath Nextcloud node path of the file
	 */
	private function deleteFileNode(string $internalPath): void {
		try {
			$node = $this->rootFolder->get($internalPath);
		} catch (NotFoundException) {
			// The file is already gone; there was only the database row left to remove.
			return;
		}

		// Only ever a file: a corrupted path in the library must not take a folder -
		// and everything in it - with it.
		if ($node->getType() !== 'file') {
			$this->logger->warning('Refusing to delete a stored MobileSheets path that is not a file', [
				'internalPath' => $internalPath,
			]);

			return;
		}

		$node->delete();
	}

	/**
	 * Folder of a library, relative to the user folder, e.g. "Noten" for a library at
	 * "Noten/mobilesheets.db".
	 *
	 * MobileSheets stores absolute WebDAV paths that sit next to the library database,
	 * so the database's folder is the base every file path is built on. '' means the
	 * library sits directly in the user folder.
	 */
	private function libraryFolderPath(string $libraryPath): string {
		$directory = dirname(ltrim($libraryPath, '/'));

		return ($directory === '' || $directory === '.') ? '' : $directory;
	}

	/**
	 * Record a file that was added to a song and move the song's files into place.
	 *
	 * The file already sits in the library folder under its own name. FileManager
	 * derives the WebDAV path the database expects from the file's node path, so both
	 * path representations always agree - a hand-built path used to repeat the "files"
	 * segment of the user folder.
	 *
	 * @param Node $file the file as it sits in the library folder
	 * @param Folder $targetFolder folder the regenerated files belong in, i.e. the
	 *                             library database's parent
	 */
	private function recordSongFile(int $songId, string $databasePath, Node $file, Folder $targetFolder, IUser $user): void {
		$storedPath = $this->fileManager->pathOfExistingFile($file->getPath(), $user);

		// A file may only be listed once per song: attaching a file that already belongs
		// to it would otherwise add a second entry pointing at the same path.
		foreach ($this->songMapper->findSong($databasePath, $songId)?->files ?? [] as $existing) {
			if ($existing->path === $storedPath->webdav) {
				$this->logger->debug('Song already lists this file, nothing to add', [
					'songId' => $songId,
					'path' => $storedPath->webdav,
				]);
				return;
			}
		}

		// Fill in the file's metadata (size, LastModified, hash, page count) so the
		// row looks exactly like one MobileSheets wrote itself.
		$this->songMapper->setSongFile($databasePath, $songId, $storedPath->webdav, MobileSheetsFileFacts::fromNode($file));

		$this->relocateSongFiles($songId, $databasePath, $targetFolder, $user);
	}

	/**
	 * Move the song's files to the paths the FileManager naming rules produce and keep
	 * the hash codes file in sync.
	 *
	 * Adding or removing a file changes the numbering of a multi-file song: the position
	 * of a file decides its numeric prefix, and a song that drops back to one file loses
	 * the prefix again. So the song's files are all regenerated, not just the new one.
	 * A song without a composer, artist or collection has no naming rule to follow: its
	 * files keep their current location, which for a freshly added file is the library
	 * folder.
	 *
	 * @param Folder $targetFolder folder the regenerated files belong in, i.e. the
	 *                             library database's parent
	 * @param string|null $removedPath WebDAV path of a file that was just removed, whose
	 *                                 hash codes entry has to go
	 */
	private function relocateSongFiles(
		int $songId,
		string $databasePath,
		Folder $targetFolder,
		IUser $user,
		?string $removedPath = null,
	): void {
		// The hash codes file tracks each file by its WebDAV path, so the moves below -
		// and a removed file - have to be reflected there as well. It is parsed once and
		// written back once.
		$hashCodes = $this->hashCodesFor($databasePath);

		try {
			if ($removedPath !== null) {
				$hashCodes?->removePath($removedPath);
			}

			$this->fileManager->regenerateSongFilePaths($songId, $user, $targetFolder, $databasePath, $hashCodes);
		} catch (\Throwable $exception) {
			$this->logger->warning('Could not move the song files to their regenerated paths', [
				'exception' => $exception,
				'databasePath' => $databasePath,
				'songId' => $songId,
			]);
		}

		// Persist the moves that happened - and the removed file - even when the run
		// above aborted halfway.
		try {
			$hashCodes?->save();
		} catch (\Throwable $exception) {
			$this->logger->warning('Could not save the MobileSheets hash codes file', [
				'exception' => $exception,
				'databasePath' => $databasePath,
			]);
		}
	}

	/**
	 * Write the song's metadata into its attached files so importing one of them
	 * back into MobileSheets recreates the song, and refresh the file facts the
	 * changed bytes invalidate - in the Files table and in the hash codes file,
	 * which MobileSheets compares its files against.
	 *
	 * Best-effort per file: an unreadable or exotic PDF is reported and skipped,
	 * it must not fail the song edit the metadata is meant to accompany. Files
	 * that already carry the song's properties are left alone - the write is
	 * skipped rather than repeated.
	 */
	private function syncSongFileMetadata(IUser $user, string $databasePath, int $songId): void {
		$song = $this->songMapper->findSong($databasePath, $songId);
		if ($song === null) {
			$this->logger->warning('Could not find the song to sync its metadata into attached files', [
				'databasePath' => $databasePath,
				'songId' => $songId,
			]);
			return;
		}

		$hashCodes = $this->hashCodesFor($databasePath);
		foreach ($song->files as $file) {
			$this->logger->debug('Syncing song metadata into an attached file', [
				'songId' => $songId,
				'fileId' => $file->id,
				'path' => $file->path,
			]);

			try {
				$this->fileManager->syncMetadataIntoFile($song, $file, $user, $databasePath, $hashCodes);
			} catch (\Throwable $exception) {
				$this->logger->warning('Could not write the song metadata into an attached file', [
					'exception' => $exception,
					'songId' => $songId,
					'fileId' => $file->id,
					'path' => $file->path,
				]);
			}
		}

		// Writing the hash codes file back is best-effort like the loop above:
		// a failure must not fail the song edit the metadata accompanies.
		try {
			$hashCodes?->save();
		} catch (\Throwable $exception) {
			$this->logger->warning('Could not refresh the MobileSheets hash codes file', [
				'exception' => $exception,
				'databasePath' => $databasePath,
				'songId' => $songId,
			]);
		}
	}

	/** Hash codes file of a library, or null when it is missing or cannot be read. */
	private function hashCodesFor(string $databasePath): ?MobileSheetsHashCodesFile {
		try {
			return MobileSheetsHashCodesFile::forDatabase($databasePath, $this->logger);
		} catch (\Throwable $exception) {
			$this->logger->warning('Could not read the MobileSheets hash codes file', [
				'exception' => $exception,
				'databasePath' => $databasePath,
			]);

			return null;
		}
	}

	/**
	 * Resolves the Nextcloud path of the MobileSheets library with the given id.
	 *
	 * @return string|null the path of the library, or null if it does not exist for the user
	 */
	private function resolveLibraryPath(string $userId, int $libraryId): ?string {
		$library = $this->libraryMapper->findForUser($userId, $libraryId);
		if ($library === null) {
			return null;
		}

		return (string)($library['path'] ?? '');
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/sync-library')]
	public function syncLibrary(int $libraryId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(
				['message' => 'User not logged in'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		try {
			$log = $this->librarySync->syncLibrary($libraryId, $user);
		} catch (\Exception $e) {
			$this->logger->error("Error syncing library ID $libraryId: " . $e->getMessage());
			return new DataResponse(
				['message' => "Error syncing library ID $libraryId: " . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
		// Per-file failures do not fail the request: the whole log - what happened
		// to every file - is sent back so the client can show the user how the run
		// went and link to the files that need attention.
		$failures = count(array_filter($log, static fn ($entry) => $entry->level !== MobileSheetsFileLogEntry::LEVEL_INFO));

		return new DataResponse(
			[
				'message' => $failures === 0
					? 'File paths and metadata are up to date'
					: sprintf('Library synced, but %d file(s) need attention', $failures),
				'log' => $log,
			],
			Http::STATUS_OK
		);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/composers')]
	public function createComposer(?int $libraryId = null, ?string $name = null): DataResponse {
		$name = $name ?? (string)$this->request->getParam('name', '');
		if ($libraryId === null || trim($name) === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		$libraryPath = $this->resolveLibraryPath($user->getUID(), $libraryId);
		if ($libraryPath === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$node = $this->rootFolder->getUserFolder($user->getUID())->get(ltrim($libraryPath, '/'));
			$databasePath = $node->getStorage()->getLocalFile($node->getInternalPath());
			if ($databasePath === false) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$id = $this->songMapper->createComposer($databasePath, trim($name));
			return new DataResponse(['id' => $id, 'name' => trim($name)], Http::STATUS_CREATED);
		} catch (\Throwable $exception) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/books')]
	public function createBook(?int $libraryId = null, ?string $title = null): DataResponse {
		$title = $title ?? (string)$this->request->getParam('title', '');
		if ($libraryId === null || trim($title) === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		$libraryPath = $this->resolveLibraryPath($user->getUID(), $libraryId);
		if ($libraryPath === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$node = $this->rootFolder->getUserFolder($user->getUID())->get(ltrim($libraryPath, '/'));
			$databasePath = $node->getStorage()->getLocalFile($node->getInternalPath());
			if ($databasePath === false) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$id = $this->songMapper->createBook($databasePath, trim($title));
			return new DataResponse(['id' => $id, 'title' => trim($title)], Http::STATUS_CREATED);
		} catch (\Throwable $exception) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/collections')]
	public function createCollection(?int $libraryId = null, ?string $name = null): DataResponse {
		$name = $name ?? (string)$this->request->getParam('name', '');
		if ($libraryId === null || trim($name) === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		$libraryPath = $this->resolveLibraryPath($user->getUID(), $libraryId);
		if ($libraryPath === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$node = $this->rootFolder->getUserFolder($user->getUID())->get(ltrim($libraryPath, '/'));
			$databasePath = $node->getStorage()->getLocalFile($node->getInternalPath());
			if ($databasePath === false) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$id = $this->songMapper->createCollection($databasePath, trim($name));
			return new DataResponse(['id' => $id, 'name' => trim($name)], Http::STATUS_CREATED);
		} catch (\Throwable $exception) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/artists')]
	public function createArtist(?int $libraryId = null, ?string $name = null): DataResponse {
		$name = $name ?? (string)$this->request->getParam('name', '');
		if ($libraryId === null || trim($name) === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		$libraryPath = $this->resolveLibraryPath($user->getUID(), $libraryId);
		if ($libraryPath === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$node = $this->rootFolder->getUserFolder($user->getUID())->get(ltrim($libraryPath, '/'));
			$databasePath = $node->getStorage()->getLocalFile($node->getInternalPath());
			if ($databasePath === false) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$id = $this->songMapper->createArtist($databasePath, trim($name));
			return new DataResponse(['id' => $id, 'name' => trim($name)], Http::STATUS_CREATED);
		} catch (\Throwable $exception) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/composers/{id}')]
	public function deleteComposer(int $id, ?int $libraryId = null): DataResponse {
		return $this->deleteEntity('composers', $id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/books/{id}')]
	public function deleteBook(int $id, ?int $libraryId = null): DataResponse {
		return $this->deleteEntity('books', $id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/collections/{id}')]
	public function deleteCollection(int $id, ?int $libraryId = null): DataResponse {
		return $this->deleteEntity('collections', $id, $libraryId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/artists/{id}')]
	public function deleteArtist(int $id, ?int $libraryId = null): DataResponse {
		return $this->deleteEntity('artists', $id, $libraryId);
	}

	/**
	 * Delete a composer, book, collection or artist from a library.
	 *
	 * The songs of the library are kept and only lose the deleted entry in their
	 * composers, books, collections or artists, so the response is the refreshed
	 * library data with the field removed from the songs.
	 *
	 * @param 'composers'|'books'|'collections'|'artists' $entityType
	 */
	private function deleteEntity(string $entityType, int $id, ?int $libraryId): DataResponse {
		if ($libraryId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$libraryPath = $this->resolveLibraryPath($user->getUID(), $libraryId);
		if ($libraryPath === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$node = $this->rootFolder->getUserFolder($user->getUID())->get(ltrim($libraryPath, '/'));
			$databasePath = $node->getStorage()->getLocalFile($node->getInternalPath());
			if ($databasePath === false) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			if (!$this->songMapper->deleteEntity($databasePath, $entityType, $id)) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse($this->songMapper->findLibraryData($databasePath));
		} catch (\Throwable $exception) {
			$this->logger->error('Could not delete entity from MobileSheets library', [
				'exception' => $exception,
				'entityType' => $entityType,
				'entityId' => $id,
				'libraryId' => $libraryId,
				'path' => $libraryPath,
			]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
