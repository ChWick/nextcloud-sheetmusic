<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sync\MobileSheets;

use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSyncMetadata;
use PDO;
use PHPUnit\Framework\TestCase;

final class MobileSheetsSyncMetadataTest extends TestCase {
	private PDO $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db = new PDO('sqlite::memory:', options: [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
	}

	public function testEmptyDatabaseHasNoMetadata(): void {
		$meta = MobileSheetsSyncMetadata::fromDatabase($this->db);
		self::assertFalse($meta->hasMetadata);
		self::assertNull($meta->getSongMeta(10));
		self::assertNull($meta->getFileMeta(10));
		self::assertNull($meta->getEntityMeta('composer', 10));
		self::assertSame([], $meta->getEntriesByType('song'));
	}

	public function testFromDatabaseLoadsEntries(): void {
		$this->db->exec('CREATE TABLE _nc_sync_metadata (
			entity_type TEXT NOT NULL,
			uuid TEXT NOT NULL,
			client_id INTEGER,
			updated_at INTEGER,
			extra_json TEXT,
			PRIMARY KEY (entity_type, uuid)
		)');

		$this->db->exec("INSERT INTO _nc_sync_metadata (entity_type, uuid, client_id, updated_at, extra_json) VALUES
			('song', 'song-uuid-1', 101, 1500, '{\"opus\":\"Op. 1\"}'),
			('file', 'file-uuid-1', 201, 1600, '{\"file_id\":42}'),
			('composer', 'comp-uuid-1', 301, 1700, '{}'),
			('entity:artist', 'art-uuid-1', 401, 1800, '{}')");

		$meta = MobileSheetsSyncMetadata::fromDatabase($this->db);
		self::assertTrue($meta->hasMetadata);

		$song = $meta->getSongMeta(101);
		self::assertNotNull($song);
		self::assertSame('song-uuid-1', $song['uuid']);
		self::assertSame(1500, $song['updated_at']);
		self::assertSame(['opus' => 'Op. 1'], $song['extra']);

		$file = $meta->getFileMeta(201);
		self::assertNotNull($file);
		self::assertSame('file-uuid-1', $file['uuid']);
		self::assertSame(42, $file['extra']['file_id']);

		$composer = $meta->getEntityMeta('composer', 301);
		self::assertNotNull($composer);
		self::assertSame('comp-uuid-1', $composer['uuid']);

		// entity: prefix fallback
		$artist = $meta->getEntityMeta('artist', 401);
		self::assertNotNull($artist);
		self::assertSame('art-uuid-1', $artist['uuid']);

		self::assertCount(1, $meta->getEntriesByType('song'));
		self::assertCount(1, $meta->getEntriesByType('file'));

		$arr = $meta->toArray();
		self::assertTrue($arr['hasMetadata']);
		self::assertArrayHasKey('song', $arr['byTypeAndId']);
	}
}
