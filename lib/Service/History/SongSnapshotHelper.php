<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Service\History;

use OCA\SheetMusic\Db\InternalEntityMapper;
use OCA\SheetMusic\Db\InternalSong;
use OCA\SheetMusic\Db\InternalSongAttributeMapper;
use OCA\SheetMusic\Db\InternalSongFileMapper;
use OCA\SheetMusic\Db\InternalSongHistory;

class SongSnapshotHelper {
	public function __construct(
		private InternalEntityMapper $entityMapper,
		private InternalSongFileMapper $songFileMapper,
		private ?InternalSongAttributeMapper $songAttributeMapper = null,
	) {
	}

	/**
	 * Build a full JSON-serializable snapshot of the song, including its entity relations and attached files.
	 *
	 * @return array<string, mixed>
	 */
	public function buildSnapshot(InternalSong $song): array {
		$songId = (int)$song->id;

		// 1. Dynamic extraction of all public properties on InternalSong
		$snapshot = [];
		$reflection = new \ReflectionClass(InternalSong::class);
		foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
			$name = $prop->getName();
			if ($name === 'updatedAt') {
				continue;
			}
			$snapshot[$name] = $prop->getValue($song);
		}

		// 2. Entities grouped by normalized type
		$entitiesByType = [];
		$allEntities = $songId > 0 ? $this->entityMapper->findEntitiesForSong($songId) : [];
		foreach ($allEntities as $entity) {
			$type = InternalEntityMapper::normalizeType($entity->type);
			$entitiesByType[$type][] = [
				'id' => $entity->id,
				'name' => $entity->name,
			];
		}
		$snapshot['entities'] = $entitiesByType;

		// 3. Files attached to song
		$files = [];
		$songFiles = $songId > 0 ? $this->songFileMapper->findAllForSong($songId, includeDeleted: true) : [];
		foreach ($songFiles as $file) {
			$files[] = [
				'id' => $file->id,
				'fileId' => $file->fileId,
				'pageOrder' => $file->pageOrder,
				'pageCount' => $file->pageCount,
				'fileSize' => $file->fileSize,
				'fileHash' => $file->fileHash,
				'type' => $file->type,
				'deletedAt' => $file->deletedAt,
			];
		}
		$snapshot['files'] = $files;

		// 4. Custom key-value song attributes
		if ($songId > 0 && $this->songAttributeMapper !== null) {
			$attributes = $this->songAttributeMapper->findAllForSong($songId);
			if (!empty($attributes)) {
				$snapshot['attributes'] = $attributes;
			}
		}

		return $snapshot;
	}

	/**
	 * Revert core and custom song properties dynamically using reflection.
	 *
	 * @param array<string, mixed> $snap
	 */
	public function revertSongProperties(InternalSong $song, array $snap): void {
		$reflection = new \ReflectionClass(InternalSong::class);
		$immutableProps = ['id', 'uuid', 'libraryId', 'createdAt', 'updatedAt', 'deletedAt'];

		// Cache constructor default values for promoted properties
		$defaultValues = [];
		$constructor = $reflection->getConstructor();
		if ($constructor !== null) {
			foreach ($constructor->getParameters() as $param) {
				if ($param->isDefaultValueAvailable()) {
					$defaultValues[$param->getName()] = $param->getDefaultValue();
				}
			}
		}

		foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
			$propName = $prop->getName();
			if (in_array($propName, $immutableProps, true)) {
				continue;
			}

			$type = $prop->getType();
			$typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
			$allowsNull = $type instanceof \ReflectionNamedType ? $type->allowsNull() : true;

			if (array_key_exists($propName, $snap)) {
				$val = $snap[$propName];
				if ($val === null) {
					if ($allowsNull) {
						$song->{$propName} = null;
					} elseif (array_key_exists($propName, $defaultValues)) {
						$song->{$propName} = $defaultValues[$propName];
					} else {
						$song->{$propName} = match ($typeName) {
							'int' => 0,
							'string' => '',
							'bool' => false,
							'float' => 0.0,
							'array' => [],
							default => null,
						};
					}
				} elseif ($typeName === 'int') {
					$song->{$propName} = (int)$val;
				} elseif ($typeName === 'string') {
					$song->{$propName} = (string)$val;
				} elseif ($typeName === 'bool') {
					$song->{$propName} = (bool)$val;
				} elseif ($typeName === 'float') {
					$song->{$propName} = (float)$val;
				} else {
					$song->{$propName} = $val;
				}
			} else {
				// Snapshot was taken before this field was added:
				// Reset to constructor/property default value or type-safe default
				if (array_key_exists($propName, $defaultValues)) {
					$song->{$propName} = $defaultValues[$propName];
				} elseif ($prop->hasDefaultValue()) {
					$song->{$propName} = $prop->getDefaultValue();
				} elseif (!$allowsNull) {
					$song->{$propName} = match ($typeName) {
						'int' => 0,
						'string' => '',
						'bool' => false,
						'float' => 0.0,
						'array' => [],
						default => null,
					};
				} else {
					$song->{$propName} = null;
				}
			}
		}
	}

	/**
	 * Revert entity relations dynamically across all present and historical entity types.
	 *
	 * @param array<string, mixed> $snap
	 */
	public function revertSongEntities(int $libraryId, int $songId, array $snap): void {
		$snapEntities = (array)($snap['entities'] ?? []);
		$currentEntities = $this->entityMapper->findEntitiesForSong($songId);
		$currentTypes = array_unique(array_map(static fn ($e) => InternalEntityMapper::normalizeType($e->type), $currentEntities));
		$allTypes = array_unique(array_merge(
			['composer', 'artist', 'book', 'collection', 'genre', 'key', 'customgroup', 'year', 'signature', 'sourcetype', 'instrument', 'arranger'],
			array_keys($snapEntities),
			$currentTypes
		));

		foreach ($allTypes as $type) {
			$items = (array)($snapEntities[$type] ?? []);
			$entityIds = [];
			foreach ($items as $item) {
				if (!empty($item['id'])) {
					$entityIds[] = (int)$item['id'];
				} elseif (!empty($item['name'])) {
					$existing = $this->entityMapper->findByName($libraryId, $type, (string)$item['name']);
					if ($existing !== null && $existing->id !== null) {
						$entityIds[] = (int)$existing->id;
					}
				}
			}
			$this->entityMapper->setSongEntitiesForType($songId, $type, array_unique($entityIds));
		}
	}

	/**
	 * Revert custom song attributes if mapper is configured.
	 *
	 * @param array<string, mixed> $snap
	 */
	public function revertSongAttributes(int $songId, array $snap): void {
		if ($this->songAttributeMapper === null) {
			return;
		}

		$currentAttrs = $this->songAttributeMapper->findAllForSong($songId);
		$snapAttrs = (array)($snap['attributes'] ?? []);
		foreach ($currentAttrs as $name => $val) {
			if (!array_key_exists($name, $snapAttrs)) {
				$this->songAttributeMapper->removeAttribute($songId, (string)$name);
			}
		}
		foreach ($snapAttrs as $name => $val) {
			$this->songAttributeMapper->setAttribute($songId, (string)$name, (string)$val);
		}
	}

	/**
	 * Compare two snapshots to see if meaningful fields changed.
	 *
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	public function areSnapshotsEquivalent(array $a, array $b): bool {
		$ignored = ['id', 'updatedAt', 'entities', 'files', 'attributes'];
		$allKeys = array_unique(array_merge(array_keys($a), array_keys($b)));

		foreach ($allKeys as $k) {
			if (in_array($k, $ignored, true)) {
				continue;
			}
			if (($a[$k] ?? null) !== ($b[$k] ?? null)) {
				return false;
			}
		}

		// Check entities
		$entA = $a['entities'] ?? [];
		$entB = $b['entities'] ?? [];
		if (json_encode($entA) !== json_encode($entB)) {
			return false;
		}

		// Check files
		$filesA = $a['files'] ?? [];
		$filesB = $b['files'] ?? [];
		if (json_encode($filesA) !== json_encode($filesB)) {
			return false;
		}

		// Check custom attributes
		$attrA = $a['attributes'] ?? [];
		$attrB = $b['attributes'] ?? [];
		if (json_encode($attrA) !== json_encode($attrB)) {
			return false;
		}

		return true;
	}

	/**
	 * Generate a readable change summary between old and new state.
	 *
	 * @param array<string, mixed>|null $old
	 * @param array<string, mixed> $new
	 */
	public function generateSummary(string $action, ?array $old, array $new): string {
		if ($action === InternalSongHistory::ACTION_CREATED) {
			return 'Song created';
		}
		if ($action === InternalSongHistory::ACTION_DELETED) {
			return 'Song deleted';
		}
		if ($action === InternalSongHistory::ACTION_RESTORED) {
			return 'Song restored from trash';
		}
		if ($action === InternalSongHistory::ACTION_REVERTED) {
			return 'Reverted to revision';
		}
		if ($action === InternalSongHistory::ACTION_SYNCED) {
			return 'Synced from MobileSheets';
		}

		if ($old === null) {
			return 'Song modified';
		}

		$changes = [];

		if (($old['title'] ?? '') !== ($new['title'] ?? '')) {
			$changes[] = 'Title: "' . ($old['title'] ?? '') . '" → "' . ($new['title'] ?? '') . '"';
		}
		if (($old['rating'] ?? 0) !== ($new['rating'] ?? 0)) {
			$changes[] = 'Rating: ' . ($new['rating'] ?? 0) . '★';
		}
		if (($old['difficulty'] ?? 0) !== ($new['difficulty'] ?? 0)) {
			$changes[] = 'Difficulty: ' . ($new['difficulty'] ?? 0);
		}
		if (($old['duration'] ?? 0) !== ($new['duration'] ?? 0)) {
			$changes[] = 'Duration: ' . ($new['duration'] ?? 0) . 's';
		}
		if (($old['opus'] ?? '') !== ($new['opus'] ?? '')) {
			$changes[] = 'Opus updated';
		}
		if (($old['catalogNumber'] ?? '') !== ($new['catalogNumber'] ?? '')) {
			$changes[] = 'Catalog # updated';
		}

		// Check entity differences across all present entity types
		$oldEnts = (array)($old['entities'] ?? []);
		$newEnts = (array)($new['entities'] ?? []);
		$allEntityTypes = array_unique(array_merge(array_keys($oldEnts), array_keys($newEnts)));
		$entityLabels = [
			'composer' => 'Composers',
			'artist' => 'Artists',
			'collection' => 'Collections',
			'book' => 'Books',
			'genre' => 'Genres',
			'key' => 'Keys',
			'customgroup' => 'Custom Groups',
			'year' => 'Years',
			'signature' => 'Time Signatures',
			'sourcetype' => 'Source Types',
			'instrument' => 'Instruments',
			'arranger' => 'Arrangers',
		];
		foreach ($allEntityTypes as $t) {
			$label = $entityLabels[$t] ?? ucfirst((string)$t) . 's';
			$oldNames = array_column((array)($oldEnts[$t] ?? []), 'name');
			$newNames = array_column((array)($newEnts[$t] ?? []), 'name');
			sort($oldNames);
			sort($newNames);
			if ($oldNames !== $newNames) {
				$added = array_diff($newNames, $oldNames);
				$removed = array_diff($oldNames, $newNames);
				if (!empty($added)) {
					$changes[] = $label . ' added: ' . implode(', ', $added);
				}
				if (!empty($removed)) {
					$changes[] = $label . ' removed: ' . implode(', ', $removed);
				}
			}
		}

		// Check files
		$oldFiles = (array)($old['files'] ?? []);
		$newFiles = (array)($new['files'] ?? []);
		if (count($oldFiles) !== count($newFiles)) {
			$changes[] = 'Attached files: ' . count($oldFiles) . ' → ' . count($newFiles);
		}

		// Check custom attributes
		$oldAttrs = (array)($old['attributes'] ?? []);
		$newAttrs = (array)($new['attributes'] ?? []);
		$allAttrKeys = array_unique(array_merge(array_keys($oldAttrs), array_keys($newAttrs)));
		foreach ($allAttrKeys as $attrKey) {
			$oldAttrVal = $oldAttrs[$attrKey] ?? null;
			$newAttrVal = $newAttrs[$attrKey] ?? null;
			if ($oldAttrVal !== $newAttrVal) {
				$humanAttr = ucfirst((string)preg_replace('/(?<!^)[A-Z]/', ' $0', (string)$attrKey));
				if ($oldAttrVal === null) {
					$changes[] = $humanAttr . ' added';
				} elseif ($newAttrVal === null) {
					$changes[] = $humanAttr . ' removed';
				} else {
					$changes[] = $humanAttr . ' updated';
				}
			}
		}

		// Generic fallback for any additional or custom song fields
		$knownFields = [
			'id', 'uuid', 'libraryId', 'clientId', 'title', 'sortTitle', 'rating',
			'difficulty', 'duration', 'keywords', 'notes', 'opus', 'catalogNumber',
			'songNumber', 'createdAt', 'updatedAt', 'deletedAt', 'entities', 'files', 'attributes',
		];
		$allSongKeys = array_unique(array_merge(array_keys($old), array_keys($new)));
		foreach ($allSongKeys as $key) {
			if (in_array($key, $knownFields, true)) {
				continue;
			}
			$oldVal = $old[$key] ?? null;
			$newVal = $new[$key] ?? null;
			if ($oldVal !== $newVal) {
				$humanLabel = ucfirst((string)preg_replace('/(?<!^)[A-Z]/', ' $0', (string)$key));
				if ($oldVal === null && $newVal !== null) {
					$changes[] = $humanLabel . ' set';
				} elseif ($oldVal !== null && $newVal === null) {
					$changes[] = $humanLabel . ' cleared';
				} else {
					$changes[] = $humanLabel . ' updated';
				}
			}
		}

		if (empty($changes)) {
			return 'Updated song details';
		}

		return implode('; ', array_slice($changes, 0, 3));
	}
}
