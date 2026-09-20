<?php

declare(strict_types=1);

namespace OCA\MobileSheetsManager\Controller;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
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

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/songs')]
	public function getSongs(?string $id = null): DataResponse {
		$id = +$id ?? (string)$this->request->getParam('id', -1);
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

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}')]
	public function updateSong(
		int $id,
		?string $path = null,
		?string $title = null,
		array $composerIds = [],
		array $bookIds = [],
		array $collectionIds = [],
		array $artistIds = []
	): DataResponse {
		$path = $path ?? (string)$this->request->getParam('path', '');
		$title = $title ?? (string)$this->request->getParam('title', '');
		if ($path === '' || $title === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		$path = '/' . ltrim($path, '/');

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if (!$this->libraryMapper->findForUser($user->getUID(), $path)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$node = $this->rootFolder->getUserFolder($user->getUID())->get(ltrim($path, '/'));
			$databasePath = $node->getStorage()->getLocalFile($node->getInternalPath());
			if ($databasePath === false) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$this->songMapper->updateSong($databasePath, $id, $title, $composerIds, $bookIds, $collectionIds, $artistIds);

			return new DataResponse($this->songMapper->findLibraryData($databasePath));
		} catch (\Throwable $exception) {
			$this->logger->error('Could not update song in MobileSheets library', [
				'exception' => $exception,
				'path' => $path,
				'songId' => $id,
			]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/file/upload')]
	public function uploadSongFile(int $id, ?string $path = null): DataResponse {
		$path = $path ?? (string)$this->request->getParam('path', '');
		if ($path === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		$path = '/' . ltrim($path, '/');

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if (!$this->libraryMapper->findForUser($user->getUID(), $path)) {
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
			$libraryNode = $userFolder->get(ltrim($path, '/'));
			$databasePath = $libraryNode->getStorage()->getLocalFile($libraryNode->getInternalPath());
			if ($databasePath === false) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$libraryDir = dirname(ltrim($path, '/'));
			$targetFolder = ($libraryDir === '' || $libraryDir === '.') ? $userFolder : $userFolder->get($libraryDir);
			if (!($targetFolder instanceof Folder)) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			// If file exists, find a unique name or overwrite
			if ($targetFolder->nodeExists($fileName)) {
				$targetFile = $targetFolder->get($fileName);
			} else {
				$targetFile = $targetFolder->newFile($fileName);
			}

			$targetFile->putContent(file_get_contents($fileInfo['tmp_name']));

			$targetRelativePath = '/' . ltrim($targetFile->getPath(), '/');
			$userFolderPrefix = '/' . $user->getUID();
			if (str_starts_with($targetRelativePath, $userFolderPrefix)) {
				$targetRelativePath = substr($targetRelativePath, strlen($userFolderPrefix));
			}

			$webDavPath = '/remote.php/dav/files/' . $user->getUID() . '/' . ltrim($targetRelativePath, '/');
			$this->songMapper->setSongFile($databasePath, $id, $webDavPath);

			return new DataResponse($this->songMapper->findLibraryData($databasePath));
		} catch (\Throwable $exception) {
			$this->logger->error('Could not upload file for song', [
				'exception' => $exception,
				'path' => $path,
				'songId' => $id,
			]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/file/attach')]
	public function attachNextcloudFile(
		int $id,
		?string $path = null,
		?string $sourcePath = null,
		string $action = 'copy',
	): DataResponse {
		$path = $path ?? (string)$this->request->getParam('path', '');
		$sourcePath = $sourcePath ?? (string)$this->request->getParam('sourcePath', '');
		$action = (string)$this->request->getParam('action', $action);

		if ($path === '' || $sourcePath === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		$path = '/' . ltrim($path, '/');
		$sourcePath = '/' . ltrim($sourcePath, '/');

		if (!str_ends_with(strtolower($sourcePath), '.pdf')) {
			return new DataResponse(['message' => 'Only PDF files are supported'], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if (!$this->libraryMapper->findForUser($user->getUID(), $path)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$libraryNode = $userFolder->get(ltrim($path, '/'));
			$databasePath = $libraryNode->getStorage()->getLocalFile($libraryNode->getInternalPath());
			if ($databasePath === false) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$sourceNode = $userFolder->get(ltrim($sourcePath, '/'));
			if ($sourceNode->getType() !== 'file') {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$libraryDir = dirname(ltrim($path, '/'));
			$targetFolder = ($libraryDir === '' || $libraryDir === '.') ? $userFolder : $userFolder->get($libraryDir);
			if (!($targetFolder instanceof Folder)) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$targetFileName = $sourceNode->getName();
			$targetFileRelative = ($libraryDir === '' || $libraryDir === '.') ? $targetFileName : $libraryDir . '/' . $targetFileName;

			if (ltrim($sourcePath, '/') !== $targetFileRelative) {
				if ($action === 'move') {
					$sourceNode->move($targetFileRelative);
				} else {
					$sourceNode->copy($targetFileRelative);
				}
			}

			$webDavPath = '/remote.php/dav/files/' . $user->getUID() . '/' . ltrim($targetFileRelative, '/');
			$this->songMapper->setSongFile($databasePath, $id, $webDavPath);

			return new DataResponse($this->songMapper->findLibraryData($databasePath));
		} catch (\Throwable $exception) {
			$this->logger->error('Could not attach Nextcloud file for song', [
				'exception' => $exception,
				'path' => $path,
				'songId' => $id,
				'sourcePath' => $sourcePath,
			]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/songs/{id}/file/{fileId}/delete')]
	public function deleteSongFile(int $id, int $fileId, ?string $path = null): DataResponse {
		$path = $path ?? (string)$this->request->getParam('path', '');
		if ($path === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		$path = '/' . ltrim($path, '/');

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		if (!$this->libraryMapper->findForUser($user->getUID(), $path)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$libraryNode = $userFolder->get(ltrim($path, '/'));
			$databasePath = $libraryNode->getStorage()->getLocalFile($libraryNode->getInternalPath());
			if ($databasePath === false) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$filePath = $this->songMapper->removeSongFile($databasePath, $id, $fileId);

			if ($filePath !== null) {
				$prefix = '/remote.php/dav/files/' . $user->getUID() . '/';
				if (str_starts_with($filePath, $prefix)) {
					$relPath = substr($filePath, strlen($prefix));
					if ($userFolder->nodeExists($relPath)) {
						$fileNode = $userFolder->get($relPath);
						$fileNode->delete();
					}
				}
			}

			return new DataResponse($this->songMapper->findLibraryData($databasePath));
		} catch (\Throwable $exception) {
			$this->logger->error('Could not delete file for song', [
				'exception' => $exception,
				'path' => $path,
				'songId' => $id,
				'fileId' => $fileId,
			]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/composers')]
	public function createComposer(?string $path = null, ?string $name = null): DataResponse {
		$path = $path ?? (string)$this->request->getParam('path', '');
		$name = $name ?? (string)$this->request->getParam('name', '');
		if ($path === '' || trim($name) === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		$path = '/' . ltrim($path, '/');
		$user = $this->userSession->getUser();
		if ($user === null || !$this->libraryMapper->findForUser($user->getUID(), $path)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$node = $this->rootFolder->getUserFolder($user->getUID())->get(ltrim($path, '/'));
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
	public function createBook(?string $path = null, ?string $title = null): DataResponse {
		$path = $path ?? (string)$this->request->getParam('path', '');
		$title = $title ?? (string)$this->request->getParam('title', '');
		if ($path === '' || trim($title) === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		$path = '/' . ltrim($path, '/');
		$user = $this->userSession->getUser();
		if ($user === null || !$this->libraryMapper->findForUser($user->getUID(), $path)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$node = $this->rootFolder->getUserFolder($user->getUID())->get(ltrim($path, '/'));
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
	public function createCollection(?string $path = null, ?string $name = null): DataResponse {
		$path = $path ?? (string)$this->request->getParam('path', '');
		$name = $name ?? (string)$this->request->getParam('name', '');
		if ($path === '' || trim($name) === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		$path = '/' . ltrim($path, '/');
		$user = $this->userSession->getUser();
		if ($user === null || !$this->libraryMapper->findForUser($user->getUID(), $path)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$node = $this->rootFolder->getUserFolder($user->getUID())->get(ltrim($path, '/'));
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
	public function createArtist(?string $path = null, ?string $name = null): DataResponse {
		$path = $path ?? (string)$this->request->getParam('path', '');
		$name = $name ?? (string)$this->request->getParam('name', '');
		if ($path === '' || trim($name) === '') {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		$path = '/' . ltrim($path, '/');
		$user = $this->userSession->getUser();
		if ($user === null || !$this->libraryMapper->findForUser($user->getUID(), $path)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$node = $this->rootFolder->getUserFolder($user->getUID())->get(ltrim($path, '/'));
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
