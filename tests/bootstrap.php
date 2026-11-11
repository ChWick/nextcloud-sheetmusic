<?php

declare(strict_types=1);

// The tests need no other app of the server. Loading them all boots apps that
// do not survive the CLI bootstrap - admin_audit fails to resolve its audit
// logger there and logs "Could not boot admin_audit" on every run - so the
// switch the server bootstrap understands is set before it is pulled in.
putenv('TEST_DONT_LOAD_APPS=1');

require_once __DIR__ . '/../../../tests/bootstrap.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
	require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/TestSupport/MobileSheetsTestDatabase.php';
require_once __DIR__ . '/TestSupport/TestUtils.php';
require_once __DIR__ . '/TestSupport/RealMobileSheetsTestTrait.php';
require_once __DIR__ . '/TestSupport/MobileSheetsDatabasePresets.php';
require_once __DIR__ . '/TestSupport/MobileSheetsEndToEndSyncHarness.php';
require_once __DIR__ . '/TestSupport/InternalQueryResult.php';
require_once __DIR__ . '/TestSupport/FakeUserSession.php';
require_once __DIR__ . '/TestSupport/FakeRequest.php';
require_once __DIR__ . '/TestSupport/RecordingSapi.php';

\OC_App::loadApp(OCA\SheetMusic\AppInfo\Application::APP_ID);
OC_Hook::clear();

// Disable Nextcloud profiler DB query backtrace accumulation during test runs to prevent multi-GB memory leaks
try {
	if (\OC::$server->has(\OCP\IDBConnection::class)) {
		$adapter = \OC::$server->get(\OCP\IDBConnection::class);
		$ref = new ReflectionProperty($adapter, 'inner');
		$inner = $ref->getValue($adapter);
		$refConfig = new ReflectionProperty($inner, '_config');
		$config = $refConfig->getValue($inner);
		$config->setSQLLogger(null);
	}
} catch (\Throwable) {
	// Fall through
}

// Register class aliases for backwards compatibility with legacy namespaces
$legacyAliases = [
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSong' => 'OCA\SheetMusic\Db\MobileSheetsSong',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFile' => 'OCA\SheetMusic\Db\MobileSheetsFile',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsComposer' => 'OCA\SheetMusic\Db\MobileSheetsComposer',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsBook' => 'OCA\SheetMusic\Db\MobileSheetsBook',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsCollection' => 'OCA\SheetMusic\Db\MobileSheetsCollection',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsArtist' => 'OCA\SheetMusic\Db\MobileSheetsArtist',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsGenre' => 'OCA\SheetMusic\Db\MobileSheetsGenre',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsKey' => 'OCA\SheetMusic\Db\MobileSheetsKey',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsCustomGroup' => 'OCA\SheetMusic\Db\MobileSheetsCustomGroup',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsYear' => 'OCA\SheetMusic\Db\MobileSheetsYear',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSignature' => 'OCA\SheetMusic\Db\MobileSheetsSignature',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSourceType' => 'OCA\SheetMusic\Db\MobileSheetsSourceType',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsBookmark' => 'OCA\SheetMusic\Db\MobileSheetsBookmark',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSongNote' => 'OCA\SheetMusic\Db\MobileSheetsSongNote',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsMetronomeSettings' => 'OCA\SheetMusic\Db\MobileSheetsMetronomeSettings',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsTempo' => 'OCA\SheetMusic\Db\MobileSheetsTempo',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsAudioFile' => 'OCA\SheetMusic\Db\MobileSheetsAudioFile',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteExporter' => 'OCA\SheetMusic\Service\MobileSheetsSqliteExporter',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSqliteImporter' => 'OCA\SheetMusic\Service\MobileSheetsSqliteImporter',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsEntityExporter' => 'OCA\SheetMusic\Service\MobileSheetsEntityExporter',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsEntityMerger' => 'OCA\SheetMusic\Service\MobileSheetsEntityMerger',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsBaselineSchema' => 'OCA\SheetMusic\Service\MobileSheetsBaselineSchema',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsTableHelper' => 'OCA\SheetMusic\Service\MobileSheetsTableHelper',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSyncMetadata' => 'OCA\SheetMusic\Service\MobileSheetsSyncMetadata',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsEntityConfig' => 'OCA\SheetMusic\Service\MobileSheetsEntityConfig',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsHashCodesFile' => 'OCA\SheetMusic\Service\MobileSheetsHashCodesFile',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsHashCode' => 'OCA\SheetMusic\Service\MobileSheetsHashCode',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPathResolver' => 'OCA\SheetMusic\Service\MobileSheetsPathResolver',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPath' => 'OCA\SheetMusic\Service\MobileSheetsPath',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdf' => 'OCA\SheetMusic\Service\MobileSheetsPdf',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdfMetadata' => 'OCA\SheetMusic\Service\MobileSheetsPdfMetadata',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsPdfSecurity' => 'OCA\SheetMusic\Service\MobileSheetsPdfSecurity',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFileFacts' => 'OCA\SheetMusic\Service\MobileSheetsFileFacts',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsFileLogEntry' => 'OCA\SheetMusic\Service\MobileSheetsFileLogEntry',
	'OCA\SheetMusic\Sync\MobileSheets\MobileSheetsTimestampUtil' => 'OCA\SheetMusic\Util\MobileSheetsTimestampUtil',
	'OCA\SheetMusic\Sync\MobileSheets\SongMapper' => 'OCA\SheetMusic\Db\SongMapper',
	'OCA\SheetMusic\Sync\MobileSheets\SongReader' => 'OCA\SheetMusic\Db\SongReader',
	'OCA\SheetMusic\Sync\MobileSheets\SongFileMapper' => 'OCA\SheetMusic\Db\SongFileMapper',
	'OCA\SheetMusic\Sync\MobileSheets\SongBookmarkMapper' => 'OCA\SheetMusic\Db\SongBookmarkMapper',
	'OCA\SheetMusic\Sync\MobileSheets\SongAudioMapper' => 'OCA\SheetMusic\Db\SongAudioMapper',
	'OCA\SheetMusic\Sync\MobileSheets\SongNoteMapper' => 'OCA\SheetMusic\Db\SongNoteMapper',
	'OCA\SheetMusic\Sync\MobileSheets\LibraryEntityMapper' => 'OCA\SheetMusic\Db\LibraryEntityMapper',
	'OCA\SheetMusic\Sync\MobileSheets\AbstractDatabaseMapper' => 'OCA\SheetMusic\Db\AbstractDatabaseMapper',
];
foreach ($legacyAliases as $newClass => $oldClass) {
	if (!class_exists($oldClass, false) && class_exists($newClass)) {
		class_alias($newClass, $oldClass);
	}
}
