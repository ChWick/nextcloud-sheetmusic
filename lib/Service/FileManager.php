<?php
namespace OCA\MobileSheetsManager\Service;

use OCA\MobileSheetsManager\Db\LibraryMapper;
use OCA\MobileSheetsManager\Db\MobileSheetsFile;
use OCA\MobileSheetsManager\Db\SongMapper;
use OCA\MobileSheetsManager\Service\FileManager;
use OCP\AppFramework\OCSController;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use Psr\Log\LoggerInterface;

function getFileExtension(string $filename): string 
{
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    return $extension !== '' ? '.' . $extension : '';
}

function createWithComposerOrArtist(array $song, string $ext): string 
{
    $newPath = '';

    if (!empty($song['composer'])) {
        $newPath .= $song['composer'];
    } elseif (!empty($song['artist'])) {
        $newPath .= $song['artist'];
    } else {
        throw new Exception("No composer or artist");
    }

    $compArtist = $song['composer'] ?? $song['artist'];
    $ds = DIRECTORY_SEPARATOR;

    if (!empty($song['book'])) {
        $newPath .= $ds . "{$compArtist} - {$song['book']}";
        $newPath .= $ds . "{$compArtist} - {$song['book']} - {$song['title']}" . $ext;
    } elseif (!empty($song['collection'])) {
        $newPath .= $ds . "{$compArtist} - {$song['collection']}";
        $newPath .= $ds . "{$compArtist} - {$song['collection']} - {$song['title']}" . $ext;
    } else {
        $newPath .= $ds . "{$compArtist} - {$song['title']}" . $ext;
    }

    return $newPath;
}

function createWithCollection(array $song, string $ext): string 
{
    if (empty($song['collection'])) {
        throw new Exception("No collection");
    }

    $ds = DIRECTORY_SEPARATOR;
    $newPath = $song['collection'];
    $newPath .= $ds . "{$song['collection']} - {$song['title']}" . $ext;

    return $newPath;
}

function createTargetPath(array $song, string $ext): string 
{
    if (!empty($song['composer']) || !empty($song['artist'])) {
        return createWithComposerOrArtist($song, $ext);
    }
    
    if (!empty($song['collection'])) {
        return createWithCollection($song, $ext);
    }

    throw new \Exception("No composer, artist, or collection");
}

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
            $files = $this->songMapper->filesOfSong($databasePath, $song['Id']);
            $this->logger->error("files", ["song" => $song, "files" => $files]);

            foreach ($files as $file) {
                $this->regenerateFilePath($song, $file);
            }
        }

    }

    public function regenerateFilePath(array $song, MobileSheetsFile $file) {
        $ext = getFileExtension($file->path);
        $this->logger->error("FOO:", ["file" => $file, "ext" => $ext, "song" => $song]);
        $target_path = createTargetPath($song, $ext);
    }
}