/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { StampCategory, StampFontKind } from '../types/annotation'

export interface MusicStampDefinition {
	/** Canonical Unicode glyph string */
	symbol: string
	/** Human-readable name */
	label: string
	/** Semantic category */
	category: StampCategory
	/** Font rendering kind */
	fontKind: StampFontKind
	/** ASCII / legacy aliases that map to this canonical Unicode symbol */
	aliases?: string[]
	/** Default normalized width relative to page width */
	defaultWidth?: number
	/** Default normalized height relative to page width */
	defaultHeight?: number
	/** Optional keyboard shortcut hint */
	shortcut?: string
	/**
	 * Offset in em units (for Noto Music with text-anchor="middle", dominant-baseline="alphabetic")
	 * to shift the glyph's ink bounding-box center onto the target (cx, cy).
	 */
	inkOffset?: { ox: number; oy: number }
}

export const MUSIC_FONT_FAMILY = "'Noto Music', 'Segoe UI Symbol', 'Apple Symbols', sans-serif"
export const FINGERING_FONT_FAMILY = "'Century Schoolbook', 'TeX Gyre Schola', 'Baskerville', 'Times New Roman', serif"

export const STAMP_CATEGORIES: Array<{ id: StampCategory; label: string }> = [
	{ id: 'dynamic', label: 'Dynamics' },
	{ id: 'accidental', label: 'Accidentals' },
	{ id: 'ornament', label: 'Ornaments' },
	{ id: 'articulation', label: 'Articulations' },
	{ id: 'navigation', label: 'Navigation' },
	{ id: 'fingering', label: 'Fingerings' },
	{ id: 'rhythm', label: 'Notes & Rests' },
]

/**
 * Canonical Unicode music stamps backed by Noto Music (U+2669..U+266F, U+1D100..U+1D1FF)
 * and Century Schoolbook / TeX Gyre Schola for fingerings.
 */
export const MUSIC_STAMPS: MusicStampDefinition[] = [
	// Dynamics
	{ symbol: '𝆏𝆏𝆏', label: 'ppp (pianississimo)', category: 'dynamic', fontKind: 'music', aliases: ['ppp'], inkOffset: { ox: 0.056, oy: 0.414 } },
	{ symbol: '𝆏𝆏', label: 'pp (pianissimo)', category: 'dynamic', fontKind: 'music', aliases: ['pp', 'π', '\\pi'], inkOffset: { ox: 0.056, oy: 0.414 } },
	{ symbol: '𝆏', label: 'p (piano)', category: 'dynamic', fontKind: 'music', aliases: ['p'], shortcut: 'Shift+P', inkOffset: { ox: 0.056, oy: 0.414 } },
	{ symbol: '𝆐𝆏', label: 'mp (mezzo-piano)', category: 'dynamic', fontKind: 'music', aliases: ['mp'], inkOffset: { ox: -0.02, oy: 0.414 } },
	{ symbol: '𝆐𝆑', label: 'mf (mezzo-forte)', category: 'dynamic', fontKind: 'music', aliases: ['mf'], inkOffset: { ox: -0.031, oy: 0.489 } },
	{ symbol: '𝆑', label: 'f (forte)', category: 'dynamic', fontKind: 'music', aliases: ['f'], shortcut: 'F', inkOffset: { ox: 0.023, oy: 0.489 } },
	{ symbol: '𝆑𝆑', label: 'ff (fortissimo)', category: 'dynamic', fontKind: 'music', aliases: ['ff'], inkOffset: { ox: 0.023, oy: 0.489 } },
	{ symbol: '𝆑𝆑𝆑', label: 'fff (fortississimo)', category: 'dynamic', fontKind: 'music', aliases: ['fff'], inkOffset: { ox: 0.023, oy: 0.489 } },
	{ symbol: '𝆑𝆏', label: 'fp (forte-piano)', category: 'dynamic', fontKind: 'music', aliases: ['fp'], inkOffset: { ox: 0.023, oy: 0.489 } },
	{ symbol: '𝆍𝆑', label: 'sf (sforzando)', category: 'dynamic', fontKind: 'music', aliases: ['sf'], inkOffset: { ox: 0, oy: 0.489 } },
	{ symbol: '𝆍𝆑𝆎', label: 'sfz (sforzato)', category: 'dynamic', fontKind: 'music', aliases: ['sfz'], inkOffset: { ox: 0, oy: 0.489 } },
	{ symbol: '𝆌𝆑𝆎', label: 'rfz (rinforzando)', category: 'dynamic', fontKind: 'music', aliases: ['rfz'], inkOffset: { ox: 0, oy: 0.489 } },

	// Accidentals
	{ symbol: '♯', label: 'Sharp', category: 'accidental', fontKind: 'music', aliases: ['#', 'sharp'], shortcut: '#', inkOffset: { ox: 0, oy: 0.131 } },
	{ symbol: '♭', label: 'Flat', category: 'accidental', fontKind: 'music', aliases: ['b', 'flat'], shortcut: 'B', inkOffset: { ox: 0, oy: 0.28 } },
	{ symbol: '♮', label: 'Natural', category: 'accidental', fontKind: 'music', aliases: ['n', 'natural'], shortcut: 'N', inkOffset: { ox: 0, oy: 0.133 } },
	{ symbol: '𝄪', label: 'Double sharp', category: 'accidental', fontKind: 'music', aliases: ['##', 'x', 'X'], inkOffset: { ox: 0, oy: 0.131 } },
	{ symbol: '𝄫', label: 'Double flat', category: 'accidental', fontKind: 'music', aliases: ['bb', 'BB', '♭♭'], inkOffset: { ox: 0, oy: 0.28 } },

	// Ornaments & Fermatas
	{ symbol: '𝄐', label: 'Fermata (above)', category: 'ornament', fontKind: 'music', aliases: ['fermata'], inkOffset: { ox: 0, oy: 0.824 } },
	{ symbol: '𝄑', label: 'Fermata (below)', category: 'ornament', fontKind: 'music', inkOffset: { ox: 0, oy: -0.177 } },
	{ symbol: '𝆖', label: 'Trill (tr)', category: 'ornament', fontKind: 'music', aliases: ['tr', 'm'], inkOffset: { ox: 0, oy: 0.552 } },
	{ symbol: '𝆗', label: 'Turn', category: 'ornament', fontKind: 'music', aliases: ['turn'], inkOffset: { ox: 0, oy: 0.499 } },
	{ symbol: '𝆘', label: 'Inverted turn', category: 'ornament', fontKind: 'music', inkOffset: { ox: 0, oy: 0.501 } },
	{ symbol: '𝆚', label: 'Mordent', category: 'ornament', fontKind: 'music', aliases: ['mordent'], inkOffset: { ox: 0, oy: 0.492 } },
	{ symbol: '𝆛', label: 'Inverted mordent', category: 'ornament', fontKind: 'music', inkOffset: { ox: 0.002, oy: 0.627 } },
	{ symbol: '𝄒', label: 'Breath mark', category: 'ornament', fontKind: 'music', aliases: ['breath'], inkOffset: { ox: 0, oy: 0.85 } },
	{ symbol: '𝄓', label: 'Caesura', category: 'ornament', fontKind: 'music', aliases: ['caesura'], inkOffset: { ox: 0, oy: 0.5 } },

	// Articulations (Noto Music combining glyphs have advanceWidth=0 and negative Y; inkOffset centers them)
	{ symbol: '𝅻', label: 'Accent', category: 'articulation', fontKind: 'music', inkOffset: { ox: -0.258, oy: -0.146 } },
	{ symbol: '𝅼', label: 'Staccato', category: 'articulation', fontKind: 'music', inkOffset: { ox: -0.097, oy: -0.067 } },
	{ symbol: '𝅽', label: 'Tenuto', category: 'articulation', fontKind: 'music', inkOffset: { ox: -0.199, oy: -0.039 } },
	{ symbol: '𝅾', label: 'Staccatissimo', category: 'articulation', fontKind: 'music', inkOffset: { ox: -0.101, oy: -0.104 } },
	{ symbol: '𝅿', label: 'Marcato', category: 'articulation', fontKind: 'music', inkOffset: { ox: -0.206, oy: -0.158 } },

	// Navigation & Pedal
	{ symbol: '𝄋', label: 'Segno', category: 'navigation', fontKind: 'music', aliases: ['segno'], inkOffset: { ox: 0, oy: 0.501 } },
	{ symbol: '𝄌', label: 'Coda', category: 'navigation', fontKind: 'music', aliases: ['coda'], inkOffset: { ox: 0, oy: 0.5 } },
	{ symbol: '𝄉', label: 'Dal Segno (D.S.)', category: 'navigation', fontKind: 'music', aliases: ['ds', 'D.S.'], inkOffset: { ox: 0, oy: 0.502 } },
	{ symbol: '𝄊', label: 'Da Capo (D.C.)', category: 'navigation', fontKind: 'music', aliases: ['dc', 'D.C.'], inkOffset: { ox: 0, oy: 0.504 } },
	{ symbol: '𝄆', label: 'Repeat start', category: 'navigation', fontKind: 'music', inkOffset: { ox: 0, oy: 0.5 } },
	{ symbol: '𝄇', label: 'Repeat end', category: 'navigation', fontKind: 'music', inkOffset: { ox: 0, oy: 0.5 } },
	{ symbol: '𝆮', label: 'Pedal (Ped.)', category: 'navigation', fontKind: 'music', aliases: ['ped', 'Ped.'], inkOffset: { ox: 0, oy: 0.5 } },
	{ symbol: '𝆯', label: 'Pedal up (*)', category: 'navigation', fontKind: 'music', aliases: ['pedup'], inkOffset: { ox: 0, oy: 0.499 } },

	// Fingerings (Century Schoolbook)
	{ symbol: '0', label: 'Fingering 0', category: 'fingering', fontKind: 'fingering', shortcut: '0' },
	{ symbol: '1', label: 'Fingering 1', category: 'fingering', fontKind: 'fingering', shortcut: '1' },
	{ symbol: '2', label: 'Fingering 2', category: 'fingering', fontKind: 'fingering', shortcut: '2' },
	{ symbol: '3', label: 'Fingering 3', category: 'fingering', fontKind: 'fingering', shortcut: '3' },
	{ symbol: '4', label: 'Fingering 4', category: 'fingering', fontKind: 'fingering', shortcut: '4' },
	{ symbol: '5', label: 'Fingering 5', category: 'fingering', fontKind: 'fingering', shortcut: '5' },

	// Rhythm (Notes & Rests)
	{ symbol: '𝅝', label: 'Whole note', category: 'rhythm', fontKind: 'music', aliases: ['w'], inkOffset: { ox: 0, oy: 0.134 } },
	{ symbol: '𝅗𝅥', label: 'Half note', category: 'rhythm', fontKind: 'music', aliases: ['h'], inkOffset: { ox: 0, oy: 0.504 } },
	{ symbol: '♩', label: 'Quarter note', category: 'rhythm', fontKind: 'music', aliases: ['q', '𝅘𝅥'], inkOffset: { ox: 0, oy: 0.289 } },
	{ symbol: '♪', label: 'Eighth note', category: 'rhythm', fontKind: 'music', aliases: ['e', '𝅘𝅥𝅮'], inkOffset: { ox: 0, oy: 0.289 } },
	{ symbol: '♫', label: 'Beamed eighth notes', category: 'rhythm', fontKind: 'music', aliases: ['ee'], inkOffset: { ox: 0, oy: 0.273 } },
	{ symbol: '𝅘𝅥𝅯', label: 'Sixteenth note', category: 'rhythm', fontKind: 'music', aliases: ['s'], inkOffset: { ox: 0, oy: 0.51 } },
	{ symbol: '𝄻', label: 'Whole rest', category: 'rhythm', fontKind: 'music', inkOffset: { ox: 0, oy: 0.445 } },
	{ symbol: '𝄼', label: 'Half rest', category: 'rhythm', fontKind: 'music', inkOffset: { ox: 0, oy: 0.311 } },
	{ symbol: '𝄽', label: 'Quarter rest', category: 'rhythm', fontKind: 'music', inkOffset: { ox: 0, oy: 0.501 } },
	{ symbol: '𝄾', label: 'Eighth rest', category: 'rhythm', fontKind: 'music', inkOffset: { ox: 0, oy: 0.5 } },
	{ symbol: '𝄿', label: 'Sixteenth rest', category: 'rhythm', fontKind: 'music', inkOffset: { ox: 0, oy: 0.5 } },
]

const ALIAS_TO_UNICODE = new Map<string, MusicStampDefinition>()
for (const def of MUSIC_STAMPS) {
	ALIAS_TO_UNICODE.set(def.symbol, def)
	if (def.aliases) {
		for (const alias of def.aliases) {
			ALIAS_TO_UNICODE.set(alias, def)
		}
	}
}

/**
 * Normalize any ASCII, legacy, or Unicode music symbol into its canonical Unicode string.
 */
export function normalizeMusicSymbol(raw: string): string {
	const trimmed = raw.trim()
	return ALIAS_TO_UNICODE.get(trimmed)?.symbol ?? trimmed
}

/**
 * Look up stamp metadata for a Unicode or legacy symbol.
 */
export function getStampDefinition(raw: string): MusicStampDefinition | undefined {
	return ALIAS_TO_UNICODE.get(raw.trim())
}

/**
 * Determine the CSS font-family stack for a stamp symbol.
 */
export function getMusicStampFontFamily(symbol: string, fontKind?: StampFontKind): string {
	if (fontKind === 'fingering' || /^\d+$/.test(symbol.trim())) {
		return FINGERING_FONT_FAMILY
	}
	if (fontKind === 'text') {
		return "'Century Schoolbook', 'TeX Gyre Schola', serif"
	}
	return MUSIC_FONT_FAMILY
}
