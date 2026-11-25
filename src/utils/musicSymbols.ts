/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { StampCategory } from '../types/annotation'

export interface MusicStampDefinition {
	/** Canonical SMuFL (Standard Music Font Layout) codepoint string */
	symbol: string
	/** Human-readable name */
	label: string
	/** Semantic category */
	category: StampCategory
	/** Optional keyboard shortcut hint */
	shortcut?: string
	/**
	 * Offset in em units (for Bravura with text-anchor="middle", dominant-baseline="alphabetic")
	 * to shift the glyph's ink bounding-box center onto the target (cx, cy).
	 */
	inkOffset: { ox: number; oy: number }
	/** Tight ink bounding-box dimensions in em units from Bravura's OTF glyph bounds */
	inkSize: { w: number; h: number }
	/**
	 * Optional override offset in em units for canvas placement (e.g. centering a note's
	 * notehead on (cx, cy) instead of the stem+head midpoint used in picker thumbnails).
	 */
	canvasOffset?: { ox: number; oy: number }
}

export const MUSIC_FONT_FAMILY = "'Bravura', serif"

export const STAMP_CATEGORIES: Array<{ id: StampCategory; label: string }> = [
	{ id: 'dynamic', label: 'Dynamics' },
	{ id: 'accidental', label: 'Accidentals' },
	{ id: 'ornament', label: 'Ornaments' },
	{ id: 'articulation', label: 'Articulations' },
	{ id: 'navigation', label: 'Navigation' },
	{ id: 'fingering', label: 'Fingerings, Pedaling & Strings' },
	{ id: 'rhythm', label: 'Notes & Rests' },
]

/**
 * Canonical SMuFL (Standard Music Font Layout) music stamps rendered with a single
 * bundled engraving font (Bravura, SIL OFL). All offsets are computed directly from
 * Bravura's OTF glyph bounding boxes.
 */
export const MUSIC_STAMPS: MusicStampDefinition[] = [
	// Dynamics (SMuFL U+E520..U+E54F)
	{ symbol: '\uE52A', label: 'ppp (pianississimo)', category: 'dynamic', inkOffset: { ox: 0.045, oy: 0.066 }, inkSize: { w: 1.165, h: 0.416 } },
	{ symbol: '\uE52B', label: 'pp (pianissimo)', category: 'dynamic', inkOffset: { ox: 0.041, oy: 0.066 }, inkSize: { w: 0.81, h: 0.416 } },
	{ symbol: '\uE520', label: 'p (piano)', category: 'dynamic', shortcut: 'Shift+P', inkOffset: { ox: 0.044, oy: 0.066 }, inkSize: { w: 0.455, h: 0.416 } },
	{ symbol: '\uE52C', label: 'mp (mezzo-piano)', category: 'dynamic', inkOffset: { ox: 0.011, oy: 0.066 }, inkSize: { w: 0.845, h: 0.416 } },
	{ symbol: '\uE52D', label: 'mf (mezzo-forte)', category: 'dynamic', inkOffset: { ox: -0.001, oy: 0.133 }, inkSize: { w: 0.838, h: 0.596 } },
	{ symbol: '\uE522', label: 'f (forte)', category: 'dynamic', shortcut: 'F', inkOffset: { ox: 0.07, oy: 0.146 }, inkSize: { w: 0.505, h: 0.596 } },
	{ symbol: '\uE52F', label: 'ff (fortissimo)', category: 'dynamic', inkOffset: { ox: 0.067, oy: 0.146 }, inkSize: { w: 0.745, h: 0.596 } },
	{ symbol: '\uE530', label: 'fff (fortississimo)', category: 'dynamic', inkOffset: { ox: 0.078, oy: 0.146 }, inkSize: { w: 0.985, h: 0.596 } },
	{ symbol: '\uE534', label: 'fp (forte-piano)', category: 'dynamic', inkOffset: { ox: 0.07, oy: 0.146 }, inkSize: { w: 0.76, h: 0.596 } },
	{ symbol: '\uE536', label: 'sf (sforzando)', category: 'dynamic', inkOffset: { ox: 0, oy: 0.146 }, inkSize: { w: 0.604, h: 0.596 } },
	{ symbol: '\uE539', label: 'sfz (sforzato)', category: 'dynamic', inkOffset: { ox: -0.001, oy: 0.146 }, inkSize: { w: 0.733, h: 0.596 } },
	{ symbol: '\uE53D', label: 'rfz (rinforzando)', category: 'dynamic', inkOffset: { ox: 0.01, oy: 0.146 }, inkSize: { w: 0.764, h: 0.596 } },

	// Accidentals (SMuFL U+E260..U+E264)
	{ symbol: '\uE262', label: 'Sharp', category: 'accidental', shortcut: '#', inkOffset: { ox: 0, oy: 0.001 }, inkSize: { w: 0.249, h: 0.698 } },
	{ symbol: '\uE260', label: 'Flat', category: 'accidental', shortcut: 'B', inkOffset: { ox: 0, oy: 0.132 }, inkSize: { w: 0.226, h: 0.614 } },
	{ symbol: '\uE261', label: 'Natural', category: 'accidental', shortcut: 'N', inkOffset: { ox: 0, oy: 0.003 }, inkSize: { w: 0.168, h: 0.676 } },
	{ symbol: '\uE263', label: 'Double sharp', category: 'accidental', inkOffset: { ox: 0.002, oy: 0.001 }, inkSize: { w: 0.247, h: 0.252 } },
	{ symbol: '\uE264', label: 'Double flat', category: 'accidental', inkOffset: { ox: 0.001, oy: 0.131 }, inkSize: { w: 0.411, h: 0.612 } },

	// Ornaments & Fermatas (SMuFL)
	{ symbol: '\uE4C0', label: 'Fermata (above)', category: 'ornament', inkOffset: { ox: -0.002, oy: 0.163 }, inkSize: { w: 0.602, h: 0.332 } },
	{ symbol: '\uE4C1', label: 'Fermata (below)', category: 'ornament', inkOffset: { ox: -0.002, oy: -0.166 }, inkSize: { w: 0.602, h: 0.332 } },
	{ symbol: '\uE566', label: 'Trill (tr)', category: 'ornament', inkOffset: { ox: 0, oy: 0.19 }, inkSize: { w: 0.521, h: 0.4 } },
	{ symbol: '\uE567', label: 'Turn', category: 'ornament', inkOffset: { ox: 0, oy: 0.109 }, inkSize: { w: 0.46, h: 0.218 } },
	{ symbol: '\uE568', label: 'Inverted turn', category: 'ornament', inkOffset: { ox: 0.002, oy: 0.109 }, inkSize: { w: 0.46, h: 0.218 } },
	{ symbol: '\uE56C', label: 'Mordent', category: 'ornament', inkOffset: { ox: 0.003, oy: 0.122 }, inkSize: { w: 0.725, h: 0.245 } },
	{ symbol: '\uE56D', label: 'Inverted mordent', category: 'ornament', inkOffset: { ox: -0.001, oy: 0.123 }, inkSize: { w: 0.728, h: 0.392 } },
	{ symbol: '\uE4CE', label: 'Breath mark', category: 'ornament', inkOffset: { ox: 0, oy: 0.127 }, inkSize: { w: 0.151, h: 0.249 } },
	{ symbol: '\uE4D1', label: 'Caesura', category: 'ornament', inkOffset: { ox: 0.001, oy: 0.266 }, inkSize: { w: 0.384, h: 0.533 } },

	// Articulations (SMuFL U+E4A0..U+E4AC)
	{ symbol: '\uE4A0', label: 'Accent', category: 'articulation', inkOffset: { ox: 0, oy: 0.123 }, inkSize: { w: 0.339, h: 0.244 } },
	{ symbol: '\uE4A2', label: 'Staccato', category: 'articulation', inkOffset: { ox: 0, oy: 0.042 }, inkSize: { w: 0.084, h: 0.084 } },
	{ symbol: '\uE4A4', label: 'Tenuto', category: 'articulation', inkOffset: { ox: 0.001, oy: 0.024 }, inkSize: { w: 0.339, h: 0.048 } },
	{ symbol: '\uE4A6', label: 'Staccatissimo', category: 'articulation', inkOffset: { ox: 0.001, oy: 0.145 }, inkSize: { w: 0.099, h: 0.295 } },
	{ symbol: '\uE4AC', label: 'Marcato', category: 'articulation', inkOffset: { ox: 0.001, oy: 0.126 }, inkSize: { w: 0.236, h: 0.254 } },

	// Navigation & Pedal (SMuFL)
	{ symbol: '\uE047', label: 'Segno', category: 'navigation', inkOffset: { ox: 0.002, oy: 0.366 }, inkSize: { w: 0.546, h: 0.786 } },
	{ symbol: '\uE048', label: 'Coda', category: 'navigation', inkOffset: { ox: 0.002, oy: 0.37 }, inkSize: { w: 0.959, h: 1.056 } },
	{ symbol: '\uE045', label: 'Dal Segno (D.S.)', category: 'navigation', inkOffset: { ox: -0.005, oy: 0.216 }, inkSize: { w: 1.071, h: 0.451 } },
	{ symbol: '\uE046', label: 'Da Capo (D.C.)', category: 'navigation', inkOffset: { ox: -0.005, oy: 0.216 }, inkSize: { w: 1.071, h: 0.451 } },
	{ symbol: '\uE040', label: 'Repeat start', category: 'navigation', inkOffset: { ox: 0.001, oy: 0.5 }, inkSize: { w: 0.366, h: 1 } },
	{ symbol: '\uE041', label: 'Repeat end', category: 'navigation', inkOffset: { ox: -0.001, oy: 0.5 }, inkSize: { w: 0.366, h: 1 } },
	{ symbol: '\uE650', label: 'Pedal (Ped.)', category: 'navigation', inkOffset: { ox: 0, oy: 0.274 }, inkSize: { w: 1.019, h: 0.563 } },
	{ symbol: '\uE655', label: 'Pedal up (*)', category: 'navigation', inkOffset: { ox: 0, oy: 0.225 }, inkSize: { w: 0.45, h: 0.45 } },

	// Fingerings (SMuFL U+ED10..U+ED15, U+ED24..U+ED27), Organ Pedaling (U+E661/E665), & String Marks (U+E610..E631)
	{ symbol: '\uED10', label: 'Fingering 0', category: 'fingering', shortcut: '0', inkOffset: { ox: 0, oy: 0.125 }, inkSize: { w: 0.215, h: 0.252 } },
	{ symbol: '\uED11', label: 'Fingering 1', category: 'fingering', shortcut: '1', inkOffset: { ox: 0, oy: 0.127 }, inkSize: { w: 0.117, h: 0.254 } },
	{ symbol: '\uED12', label: 'Fingering 2', category: 'fingering', shortcut: '2', inkOffset: { ox: 0, oy: 0.125 }, inkSize: { w: 0.202, h: 0.256 } },
	{ symbol: '\uED13', label: 'Fingering 3', category: 'fingering', shortcut: '3', inkOffset: { ox: 0, oy: 0.126 }, inkSize: { w: 0.185, h: 0.252 } },
	{ symbol: '\uED14', label: 'Fingering 4', category: 'fingering', shortcut: '4', inkOffset: { ox: 0, oy: 0.127 }, inkSize: { w: 0.196, h: 0.252 } },
	{ symbol: '\uED15', label: 'Fingering 5', category: 'fingering', shortcut: '5', inkOffset: { ox: 0, oy: 0.129 }, inkSize: { w: 0.185, h: 0.258 } },
	{ symbol: '\uED24', label: 'Fingering 6', category: 'fingering', shortcut: '6', inkOffset: { ox: 0.001, oy: 0.125 }, inkSize: { w: 0.196, h: 0.25 } },
	{ symbol: '\uED25', label: 'Fingering 7', category: 'fingering', shortcut: '7', inkOffset: { ox: 0.003, oy: 0.123 }, inkSize: { w: 0.197, h: 0.245 } },
	{ symbol: '\uED26', label: 'Fingering 8', category: 'fingering', shortcut: '8', inkOffset: { ox: 0, oy: 0.126 }, inkSize: { w: 0.192, h: 0.251 } },
	{ symbol: '\uED27', label: 'Fingering 9', category: 'fingering', shortcut: '9', inkOffset: { ox: 0, oy: 0.125 }, inkSize: { w: 0.196, h: 0.25 } },
	{ symbol: '\uE661', label: 'Organ heel (U)', category: 'fingering', inkOffset: { ox: 0, oy: 0.266 }, inkSize: { w: 0.426, h: 0.531 } },
	{ symbol: '\uE665', label: 'Organ toe (∧)', category: 'fingering', inkOffset: { ox: 0, oy: 0.266 }, inkSize: { w: 0.438, h: 0.532 } },
	{ symbol: '\uE610', label: 'Down bow', category: 'fingering', inkOffset: { ox: 0, oy: 0.159 }, inkSize: { w: 0.312, h: 0.318 } },
	{ symbol: '\uE612', label: 'Up bow', category: 'fingering', inkOffset: { ox: 0, oy: 0.248 }, inkSize: { w: 0.248, h: 0.494 } },
	{ symbol: '\uE614', label: 'Harmonic / Open string', category: 'fingering', inkOffset: { ox: 0, oy: 0.1 }, inkSize: { w: 0.2, h: 0.2 } },
	{ symbol: '\uE631', label: 'Snap pizzicato', category: 'fingering', inkOffset: { ox: 0, oy: 0.15 }, inkSize: { w: 0.2, h: 0.3 } },

	// Rhythm (Notes & Rests — SMuFL U+E1D2..E1D9, U+E4E3..E4E7; noteheads sit on baseline y=0)
	{ symbol: '\uE1D2', label: 'Whole note', category: 'rhythm', inkOffset: { ox: 0, oy: -0.001 }, inkSize: { w: 0.459, h: 0.273 }, canvasOffset: { ox: 0, oy: 0 } },
	{ symbol: '\uE1D3', label: 'Half note', category: 'rhythm', inkOffset: { ox: 0, oy: 0.365 }, inkSize: { w: 0.341, h: 1.02 }, canvasOffset: { ox: 0, oy: 0 } },
	{ symbol: '\uE1D5', label: 'Quarter note', category: 'rhythm', inkOffset: { ox: 0, oy: 0.367 }, inkSize: { w: 0.332, h: 1.016 }, canvasOffset: { ox: 0, oy: 0 } },
	{ symbol: '\uE1D7', label: 'Eighth note', category: 'rhythm', inkOffset: { ox: 0, oy: 0.367 }, inkSize: { w: 0.566, h: 1.011 }, canvasOffset: { ox: 0.117, oy: 0 } },
	{ symbol: '\uE1D9', label: 'Sixteenth note', category: 'rhythm', inkOffset: { ox: 0, oy: 0.367 }, inkSize: { w: 0.581, h: 1.011 }, canvasOffset: { ox: 0.125, oy: 0 } },
	{ symbol: '\uE4E3', label: 'Whole rest', category: 'rhythm', inkOffset: { ox: 0.001, oy: -0.063 }, inkSize: { w: 0.282, h: 0.144 } },
	{ symbol: '\uE4E4', label: 'Half rest', category: 'rhythm', inkOffset: { ox: 0.001, oy: 0.07 }, inkSize: { w: 0.282, h: 0.144 } },
	{ symbol: '\uE4E5', label: 'Quarter rest', category: 'rhythm', inkOffset: { ox: -0.001, oy: -0.001 }, inkSize: { w: 0.269, h: 0.748 } },
	{ symbol: '\uE4E6', label: 'Eighth rest', category: 'rhythm', inkOffset: { ox: 0.002, oy: -0.038 }, inkSize: { w: 0.247, h: 0.425 } },
	{ symbol: '\uE4E7', label: 'Sixteenth rest', category: 'rhythm', inkOffset: { ox: 0, oy: -0.161 }, inkSize: { w: 0.32, h: 0.679 } },
]

const STAMP_BY_SYMBOL = new Map<string, MusicStampDefinition>(
	MUSIC_STAMPS.map((def) => [def.symbol, def]),
)

/**
 * Look up stamp metadata by its SMuFL codepoint string.
 */
export function getStampDefinition(symbol: string): MusicStampDefinition | undefined {
	return STAMP_BY_SYMBOL.get(symbol.trim())
}

export const FINGERING_DIGIT_SYMBOLS = [
	'\uED10',
	'\uED11',
	'\uED12',
	'\uED13',
	'\uED14',
	'\uED15',
	'\uED24',
	'\uED25',
	'\uED26',
	'\uED27',
] as const

/**
 * Check whether a SMuFL symbol is a numeric fingering digit (0-5: U+ED10..U+ED15, 6-9: U+ED24..U+ED27).
 */
export function isFingeringDigit(symbol: string): boolean {
	const cp = symbol.trim().codePointAt(0)
	return cp !== undefined && ((cp >= 0xED10 && cp <= 0xED15) || (cp >= 0xED24 && cp <= 0xED27))
}
