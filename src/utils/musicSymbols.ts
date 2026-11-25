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
	{ symbol: '\uE52A', label: 'ppp (pianississimo)', category: 'dynamic', inkOffset: { ox: 0.045, oy: 0.066 } },
	{ symbol: '\uE52B', label: 'pp (pianissimo)', category: 'dynamic', inkOffset: { ox: 0.041, oy: 0.066 } },
	{ symbol: '\uE520', label: 'p (piano)', category: 'dynamic', shortcut: 'Shift+P', inkOffset: { ox: 0.044, oy: 0.066 } },
	{ symbol: '\uE52C', label: 'mp (mezzo-piano)', category: 'dynamic', inkOffset: { ox: 0.011, oy: 0.066 } },
	{ symbol: '\uE52D', label: 'mf (mezzo-forte)', category: 'dynamic', inkOffset: { ox: -0.001, oy: 0.133 } },
	{ symbol: '\uE522', label: 'f (forte)', category: 'dynamic', shortcut: 'F', inkOffset: { ox: 0.07, oy: 0.146 } },
	{ symbol: '\uE52F', label: 'ff (fortissimo)', category: 'dynamic', inkOffset: { ox: 0.067, oy: 0.146 } },
	{ symbol: '\uE530', label: 'fff (fortississimo)', category: 'dynamic', inkOffset: { ox: 0.078, oy: 0.146 } },
	{ symbol: '\uE534', label: 'fp (forte-piano)', category: 'dynamic', inkOffset: { ox: 0.07, oy: 0.146 } },
	{ symbol: '\uE536', label: 'sf (sforzando)', category: 'dynamic', inkOffset: { ox: 0, oy: 0.146 } },
	{ symbol: '\uE539', label: 'sfz (sforzato)', category: 'dynamic', inkOffset: { ox: -0.001, oy: 0.146 } },
	{ symbol: '\uE53D', label: 'rfz (rinforzando)', category: 'dynamic', inkOffset: { ox: 0.01, oy: 0.146 } },

	// Accidentals (SMuFL U+E260..U+E264)
	{ symbol: '\uE262', label: 'Sharp', category: 'accidental', shortcut: '#', inkOffset: { ox: 0, oy: 0.001 } },
	{ symbol: '\uE260', label: 'Flat', category: 'accidental', shortcut: 'B', inkOffset: { ox: 0, oy: 0.132 } },
	{ symbol: '\uE261', label: 'Natural', category: 'accidental', shortcut: 'N', inkOffset: { ox: 0, oy: 0.003 } },
	{ symbol: '\uE263', label: 'Double sharp', category: 'accidental', inkOffset: { ox: 0.002, oy: 0.001 } },
	{ symbol: '\uE264', label: 'Double flat', category: 'accidental', inkOffset: { ox: 0.001, oy: 0.131 } },

	// Ornaments & Fermatas (SMuFL)
	{ symbol: '\uE4C0', label: 'Fermata (above)', category: 'ornament', inkOffset: { ox: -0.002, oy: 0.163 } },
	{ symbol: '\uE4C1', label: 'Fermata (below)', category: 'ornament', inkOffset: { ox: -0.002, oy: -0.166 } },
	{ symbol: '\uE566', label: 'Trill (tr)', category: 'ornament', inkOffset: { ox: 0, oy: 0.19 } },
	{ symbol: '\uE567', label: 'Turn', category: 'ornament', inkOffset: { ox: 0, oy: 0.109 } },
	{ symbol: '\uE568', label: 'Inverted turn', category: 'ornament', inkOffset: { ox: 0.002, oy: 0.109 } },
	{ symbol: '\uE56C', label: 'Mordent', category: 'ornament', inkOffset: { ox: 0.003, oy: 0.122 } },
	{ symbol: '\uE56D', label: 'Inverted mordent', category: 'ornament', inkOffset: { ox: -0.001, oy: 0.123 } },
	{ symbol: '\uE4CE', label: 'Breath mark', category: 'ornament', inkOffset: { ox: 0, oy: 0.127 } },
	{ symbol: '\uE4D1', label: 'Caesura', category: 'ornament', inkOffset: { ox: 0.001, oy: 0.266 } },

	// Articulations (SMuFL U+E4A0..U+E4AC)
	{ symbol: '\uE4A0', label: 'Accent', category: 'articulation', inkOffset: { ox: 0, oy: 0.123 } },
	{ symbol: '\uE4A2', label: 'Staccato', category: 'articulation', inkOffset: { ox: 0, oy: 0.042 } },
	{ symbol: '\uE4A4', label: 'Tenuto', category: 'articulation', inkOffset: { ox: 0.001, oy: 0.024 } },
	{ symbol: '\uE4A6', label: 'Staccatissimo', category: 'articulation', inkOffset: { ox: 0.001, oy: 0.145 } },
	{ symbol: '\uE4AC', label: 'Marcato', category: 'articulation', inkOffset: { ox: 0.001, oy: 0.126 } },

	// Navigation & Pedal (SMuFL)
	{ symbol: '\uE047', label: 'Segno', category: 'navigation', inkOffset: { ox: 0.002, oy: 0.366 } },
	{ symbol: '\uE048', label: 'Coda', category: 'navigation', inkOffset: { ox: 0.002, oy: 0.37 } },
	{ symbol: '\uE045', label: 'Dal Segno (D.S.)', category: 'navigation', inkOffset: { ox: -0.005, oy: 0.216 } },
	{ symbol: '\uE046', label: 'Da Capo (D.C.)', category: 'navigation', inkOffset: { ox: -0.005, oy: 0.216 } },
	{ symbol: '\uE040', label: 'Repeat start', category: 'navigation', inkOffset: { ox: 0.001, oy: 0.5 } },
	{ symbol: '\uE041', label: 'Repeat end', category: 'navigation', inkOffset: { ox: -0.001, oy: 0.5 } },
	{ symbol: '\uE650', label: 'Pedal (Ped.)', category: 'navigation', inkOffset: { ox: 0, oy: 0.274 } },
	{ symbol: '\uE655', label: 'Pedal up (*)', category: 'navigation', inkOffset: { ox: 0, oy: 0.225 } },

	// Fingerings (SMuFL U+ED10..U+ED15), Organ Pedaling (U+E661/E664), & String Marks (U+E610..E631)
	{ symbol: '\uED10', label: 'Fingering 0', category: 'fingering', shortcut: '0', inkOffset: { ox: 0, oy: 0.125 } },
	{ symbol: '\uED11', label: 'Fingering 1', category: 'fingering', shortcut: '1', inkOffset: { ox: 0, oy: 0.127 } },
	{ symbol: '\uED12', label: 'Fingering 2', category: 'fingering', shortcut: '2', inkOffset: { ox: 0, oy: 0.125 } },
	{ symbol: '\uED13', label: 'Fingering 3', category: 'fingering', shortcut: '3', inkOffset: { ox: 0, oy: 0.126 } },
	{ symbol: '\uED14', label: 'Fingering 4', category: 'fingering', shortcut: '4', inkOffset: { ox: 0, oy: 0.127 } },
	{ symbol: '\uED15', label: 'Fingering 5', category: 'fingering', shortcut: '5', inkOffset: { ox: 0, oy: 0.129 } },
	{ symbol: '\uE661', label: 'Organ heel (U)', category: 'fingering', inkOffset: { ox: 0, oy: 0.266 } },
	{ symbol: '\uE664', label: 'Organ toe (∧)', category: 'fingering', inkOffset: { ox: 0, oy: 0.266 } },
	{ symbol: '\uE610', label: 'Down bow', category: 'fingering', inkOffset: { ox: 0, oy: 0.159 } },
	{ symbol: '\uE612', label: 'Up bow', category: 'fingering', inkOffset: { ox: 0, oy: 0.248 } },
	{ symbol: '\uE614', label: 'Harmonic / Open string', category: 'fingering', inkOffset: { ox: 0, oy: 0.1 } },
	{ symbol: '\uE631', label: 'Snap pizzicato', category: 'fingering', inkOffset: { ox: 0, oy: 0.15 } },

	// Rhythm (Notes & Rests — SMuFL U+E1D2..E1D9, U+E4E3..E4E7; noteheads sit on baseline y=0)
	{ symbol: '\uE1D2', label: 'Whole note', category: 'rhythm', inkOffset: { ox: 0, oy: -0.001 }, canvasOffset: { ox: 0, oy: 0 } },
	{ symbol: '\uE1D3', label: 'Half note', category: 'rhythm', inkOffset: { ox: 0, oy: 0.365 }, canvasOffset: { ox: 0, oy: 0 } },
	{ symbol: '\uE1D5', label: 'Quarter note', category: 'rhythm', inkOffset: { ox: 0, oy: 0.367 }, canvasOffset: { ox: 0, oy: 0 } },
	{ symbol: '\uE1D7', label: 'Eighth note', category: 'rhythm', inkOffset: { ox: 0, oy: 0.367 }, canvasOffset: { ox: 0.117, oy: 0 } },
	{ symbol: '\uE1D9', label: 'Sixteenth note', category: 'rhythm', inkOffset: { ox: 0, oy: 0.367 }, canvasOffset: { ox: 0.125, oy: 0 } },
	{ symbol: '\uE4E3', label: 'Whole rest', category: 'rhythm', inkOffset: { ox: 0.001, oy: -0.063 } },
	{ symbol: '\uE4E4', label: 'Half rest', category: 'rhythm', inkOffset: { ox: 0.001, oy: 0.07 } },
	{ symbol: '\uE4E5', label: 'Quarter rest', category: 'rhythm', inkOffset: { ox: -0.001, oy: -0.001 } },
	{ symbol: '\uE4E6', label: 'Eighth rest', category: 'rhythm', inkOffset: { ox: 0.002, oy: -0.038 } },
	{ symbol: '\uE4E7', label: 'Sixteenth rest', category: 'rhythm', inkOffset: { ox: 0, oy: -0.161 } },
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

/**
 * Check whether a SMuFL symbol is a numeric fingering digit (U+ED10..U+ED15).
 */
export function isFingeringDigit(symbol: string): boolean {
	const cp = symbol.trim().codePointAt(0)
	return cp !== undefined && cp >= 0xED10 && cp <= 0xED15
}
