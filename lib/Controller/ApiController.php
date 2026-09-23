<?php

declare(strict_types=1);

namespace OCA\MobileSheetsManager\Controller;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\MobileSheetsFile;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCA\MobileSheetsManager\Service\FileManager;
use OCA\MobileSheetsManager\Service\MobileSheetsHashCodesFile;
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
		array $artistIds = []
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
		array $artistIds = []
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

	/**
	 * Delete a song from a library.
	 *
	 * The files of the song are moved into the Nextcloud trash bin, so deleting a song by
	 * accident stays recoverable, and only then the song with every row that points at it
	 * is removed from the MobileSheets database.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/songs/{id}')]
	public function deleteSong(int $id, ?int $libraryId = null): DataResponse {
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

			// The files are handled while the database still lists them: a failure below
			// leaves the library as it was, with the files restorable from the trash bin.
			// A stored path belonging to another user yields nothing to delete there.
			$filePaths = array_map(
				static fn (MobileSheetsFile $file): string => $file->path,
				$song->files,
			);
			$hashCodes = $this->hashCodesFor($databasePath);
			foreach ($filePaths as $filePath) {
				$storedFile = $this->fileManager->pathOfStoredFile($filePath, $user);
				if ($storedFile !== null) {
					$this->deleteFileNode($storedFile->internal);
				}
				$hashCodes?->removePath($filePath);
			}
			$hashCodes?->save();

			$this->songMapper->deleteSong($databasePath, $id);

			$this->logger->info('Deleted MobileSheets song', [
				'libraryId' => $libraryId,
				'songId' => $id,
				'title' => $song->title,
				'files' => count($filePaths),
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
			// An existing file at that name is overwritten, as the editor always did.
			$targetFile = $userFolder->nodeExists($targetRelative)
				? $userFolder->get($targetRelative)
				: $userFolder->newFile($targetRelative);
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

			if (ltrim($sourcePath, '/') !== $targetRelative) {
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
	 * @param Node   $file         the file as it sits in the library folder
	 * @param Folder $targetFolder folder the regenerated files belong in, i.e. the
	 *                             library database's parent
	 */
	private function recordSongFile(int $songId, string $databasePath, Node $file, Folder $targetFolder, IUser $user): void {
		$storedPath = $this->fileManager->pathOfExistingFile($file->getPath(), $user);
		$this->songMapper->setSongFile($databasePath, $songId, $storedPath->webdav);

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
	 * @param Folder      $targetFolder folder the regenerated files belong in, i.e. the
	 *                                  library database's parent
	 * @param string|null $removedPath  WebDAV path of a file that was just removed, whose
	 *                                  hash codes entry has to go
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
    #[ApiRoute(verb: 'POST', url: '/regenerate-file-paths')]
    public function regenerateFilePaths(int $libraryId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(
				['message' => 'User not logged in'],
				Http::STATUS_UNAUTHORIZED
			);
		} 

		try {
			$this->fileManager->regenerateFilePaths($libraryId, $user);
			
		} catch (\Exception $e) {
			$this->logger->error("Error regenerating file paths for library ID $libraryId: " . $e->getMessage());
			return new DataResponse(
				['message' => "Error regenerating file paths for library ID $libraryId: " . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
		return new DataResponse(
			['message' => 'File paths regenerated successfully'],
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
			return new DataResponse([], Http::STATUS_NOT_FOUND);
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
			return new DataResponse([], Http::STATUS_NOT_FOUND);
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
			return new DataResponse([], Http::STATUS_NOT_FOUND);
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
			return new DataResponse([], Http::STATUS_NOT_FOUND);
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
}
