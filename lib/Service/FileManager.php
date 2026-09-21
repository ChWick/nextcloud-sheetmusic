<?php
namespace OCA\MobileSheetsManager\Service;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCA\MobileSheetsManager\Service\FileManager;
use OCP\AppFramework\OCSController;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class FileManager {

    public function __construct(
		private LibraryMapper $libraryMapper,
		private SongMapper $songMapper,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
    ) {}

    public function regenerateFilePaths(int $libraryId, IUser $user) {
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
            $title = $song['title'];
            $composer = $song['composer'] ?? '';
            $files = $song['files'];

            foreach ($files as $file) {
                $this->logger->error("FOO:", ["file" => $file]);
            }
        }

    }
}