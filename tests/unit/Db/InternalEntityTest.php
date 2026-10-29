<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Db;

use OCA\SheetMusic\Db\InternalEntity;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsArtist;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsBook;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsCollection;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsComposer;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsCustomGroup;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsGenre;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsKey;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSignature;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSourceType;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsYear;
use PHPUnit\Framework\TestCase;

final class InternalEntityTest extends TestCase {
	public function testToMobileSheetsEntityConvertsAllTypes(): void {
		$cases = [
			['composer', 'Bach', MobileSheetsComposer::class, 'name', 'Bach'],
			['composers', 'Bach', MobileSheetsComposer::class, 'name', 'Bach'],
			['book', 'Real Book', MobileSheetsBook::class, 'title', 'Real Book'],
			['books', 'Real Book', MobileSheetsBook::class, 'title', 'Real Book'],
			['collection', 'Jazz', MobileSheetsCollection::class, 'name', 'Jazz'],
			['collections', 'Jazz', MobileSheetsCollection::class, 'name', 'Jazz'],
			['artist', 'Miles Davis', MobileSheetsArtist::class, 'name', 'Miles Davis'],
			['artists', 'Miles Davis', MobileSheetsArtist::class, 'name', 'Miles Davis'],
			['genre', 'Bebop', MobileSheetsGenre::class, 'name', 'Bebop'],
			['genres', 'Bebop', MobileSheetsGenre::class, 'name', 'Bebop'],
			['key', 'C Major', MobileSheetsKey::class, 'name', 'C Major'],
			['keys', 'C Major', MobileSheetsKey::class, 'name', 'C Major'],
			['customgroup', 'Trio', MobileSheetsCustomGroup::class, 'name', 'Trio'],
			['customgroups', 'Trio', MobileSheetsCustomGroup::class, 'name', 'Trio'],
			['year', '1959', MobileSheetsYear::class, 'year', 1959],
			['years', '1959', MobileSheetsYear::class, 'year', 1959],
			['signature', '4/4', MobileSheetsSignature::class, 'name', '4/4'],
			['signatures', '4/4', MobileSheetsSignature::class, 'name', '4/4'],
			['sourcetype', 'Lead Sheet', MobileSheetsSourceType::class, 'name', 'Lead Sheet'],
			['sourcetypes', 'Lead Sheet', MobileSheetsSourceType::class, 'name', 'Lead Sheet'],
			['instrument', 'Piano', MobileSheetsCustomGroup::class, 'name', 'Piano'],
			['instruments', 'Piano', MobileSheetsCustomGroup::class, 'name', 'Piano'],
			['unknown', 'Other', MobileSheetsCustomGroup::class, 'name', 'Other'],
		];

		foreach ($cases as [$type, $name, $expectedClass, $property, $expectedVal]) {
			$entity = new InternalEntity(
				id: 42,
				uuid: 'test-uuid',
				libraryId: 1,
				type: $type,
				name: $name,
			);

			$msEntity = $entity->toMobileSheetsEntity();
			$this->assertInstanceOf($expectedClass, $msEntity, "Failed for type {$type}");
			$this->assertSame(42, $msEntity->id);
			$this->assertSame($expectedVal, $msEntity->{$property});
		}
	}

	public function testFromRowAndToRow(): void {
		$row = [
			'id' => 10,
			'uuid' => 'row-uuid',
			'library_id' => 2,
			'type' => 'artist',
			'name' => 'John Coltrane',
			'sort_by' => 1,
			'ascending' => 1,
			'client_id' => 99,
			'created_at' => 123456,
			'updated_at' => 654321,
			'deleted_at' => null,
		];

		$entity = InternalEntity::fromRow($row);
		$this->assertSame(10, $entity->id);
		$this->assertSame('John Coltrane', $entity->name);
		$this->assertSame($row, $entity->toRow());
	}
}
