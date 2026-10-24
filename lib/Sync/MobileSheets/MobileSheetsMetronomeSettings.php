<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

use JsonSerializable;

class MobileSheetsMetronomeSettings implements JsonSerializable {
	public function __construct(
		public readonly int $id,
		public readonly int $songId,
		public readonly int $autoStart = 0,
		public readonly int $countIn = 0,
		public readonly int $sound = 0,
		public readonly int $volume = 100,
		public readonly int $mode = 0,
		public readonly int $beatType = 0,
		public readonly int $subdivision = 0,
		public readonly int $beatsPerBar = 4,
		public readonly int $beatValue = 4,
		public readonly int $accentFirstBeat = 1,
	) {
	}

	/**
	 * @return array{
	 *     id: int,
	 *     songId: int,
	 *     autoStart: int,
	 *     countIn: int,
	 *     sound: int,
	 *     volume: int,
	 *     mode: int,
	 *     beatType: int,
	 *     subdivision: int,
	 *     beatsPerBar: int,
	 *     beatValue: int,
	 *     accentFirstBeat: int
	 * }
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'songId' => $this->songId,
			'autoStart' => $this->autoStart,
			'countIn' => $this->countIn,
			'sound' => $this->sound,
			'volume' => $this->volume,
			'mode' => $this->mode,
			'beatType' => $this->beatType,
			'subdivision' => $this->subdivision,
			'beatsPerBar' => $this->beatsPerBar,
			'beatValue' => $this->beatValue,
			'accentFirstBeat' => $this->accentFirstBeat,
		];
	}
}
