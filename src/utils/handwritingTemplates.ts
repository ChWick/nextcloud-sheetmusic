/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export interface Point2D {
	x: number
	y: number
}

export interface HandwritingTemplate {
	/** Canonical Unicode symbol from MUSIC_STAMPS, or internal letter token for multi-letter dynamics */
	symbol: string
	/** If true, this template is only used for multi-glyph dynamic/ornament assembly (e.g. m, s, r, z, t) */
	clusterOnly?: boolean
	/** Strokes in normalized [0..1] screen coordinates (y increases downward) */
	strokes: Point2D[][]
}

function arcPoints(
	cx: number,
	cy: number,
	rx: number,
	ry: number,
	startDeg: number,
	endDeg: number,
	steps = 12,
): Point2D[] {
	const pts: Point2D[] = []
	for (let i = 0; i <= steps; i++) {
		const a = ((startDeg + ((endDeg - startDeg) * i) / steps) * Math.PI) / 180
		pts.push({
			x: cx + Math.cos(a) * rx,
			y: cy + Math.sin(a) * ry,
		})
	}
	return pts
}

/**
 * Known multi-letter sequences (written left-to-right as separate horizontal clusters)
 * mapped to their canonical Unicode music symbols.
 */
export const MULTI_GLYPH_SEQUENCES: Record<string, string> = {
	pp: '\uE52B',
	ppp: '\uE52A',
	mp: '\uE52C',
	mf: '\uE52D',
	ff: '\uE52F',
	fff: '\uE530',
	fp: '\uE534',
	sf: '\uE536',
	sfz: '\uE539',
	rfz: '\uE53D',
	tr: '\uE566',
}

/**
 * Map canonical dynamic symbols back to their primitive letter token for multi-glyph assembly.
 */
export const CLUSTER_TOKEN_MAP: Record<string, string> = {
	'\uE520': 'p',
	'\uE522': 'f',
	m: 'm',
	s: 's',
	r: 'r',
	z: 'z',
	t: 't',
}

/**
 * Hand-crafted stroke templates covering fingerings (0-5) and common music symbols
 * (accidentals, dynamics, articulations, fermatas, breath/caesura, turn, trill, coda, pedal-up).
 */
export const HANDWRITING_TEMPLATES: HandwritingTemplate[] = [
	// --- Fingerings (0–5) ---
	{
		symbol: '\uED10',
		strokes: [arcPoints(0.5, 0.5, 0.34, 0.48, -90, 270, 18)],
	},
	{
		symbol: '\uED10',
		strokes: [arcPoints(0.5, 0.5, 0.34, 0.48, -90, -450, 18)],
	},
	{
		symbol: '\uED11',
		strokes: [[{ x: 0.5, y: 0.05 }, { x: 0.5, y: 0.95 }]],
	},
	{
		// 1: slightly slanted straight downstroke (common fast handwriting)
		symbol: '\uED11',
		strokes: [[{ x: 0.62, y: 0.05 }, { x: 0.38, y: 0.95 }]],
	},
	{
		// 1: short top hook
		symbol: '\uED11',
		strokes: [[{ x: 0.25, y: 0.28 }, { x: 0.55, y: 0.05 }, { x: 0.55, y: 0.95 }]],
	},
	{
		// 1: long European/German diagonal up-stroke + vertical stem
		symbol: '\uED11',
		strokes: [[{ x: 0.12, y: 0.46 }, { x: 0.64, y: 0.05 }, { x: 0.64, y: 0.95 }]],
	},
	{
		// 1: slanted European 1
		symbol: '\uED11',
		strokes: [[{ x: 0.14, y: 0.42 }, { x: 0.72, y: 0.05 }, { x: 0.48, y: 0.95 }]],
	},
	{
		// 1: serif top + base
		symbol: '\uED11',
		strokes: [
			[{ x: 0.22, y: 0.3 }, { x: 0.54, y: 0.05 }, { x: 0.54, y: 0.94 }],
			[{ x: 0.22, y: 0.94 }, { x: 0.8, y: 0.94 }],
		],
	},
	{
		// 2: standard
		symbol: '\uED12',
		strokes: [[
			...arcPoints(0.48, 0.26, 0.32, 0.24, -160, 25, 10),
			{ x: 0.14, y: 0.95 },
			{ x: 0.88, y: 0.95 },
		]],
	},
	{
		// 2: narrow/tall fast 2
		symbol: '\uED12',
		strokes: [[
			...arcPoints(0.48, 0.22, 0.24, 0.18, -155, 25, 9),
			{ x: 0.22, y: 0.95 },
			{ x: 0.78, y: 0.95 },
		]],
	},
	{
		// 2: soft/rounded quick 2 with curved base
		symbol: '\uED12',
		strokes: [[
			{ x: 0.2, y: 0.24 },
			{ x: 0.45, y: 0.06 },
			{ x: 0.76, y: 0.18 },
			{ x: 0.72, y: 0.42 },
			{ x: 0.42, y: 0.68 },
			{ x: 0.16, y: 0.9 },
			{ x: 0.48, y: 0.94 },
			{ x: 0.86, y: 0.88 },
		]],
	},
	{
		// 2: angular / Z-like 2
		symbol: '\uED12',
		strokes: [[
			{ x: 0.18, y: 0.14 },
			{ x: 0.8, y: 0.14 },
			{ x: 0.76, y: 0.34 },
			{ x: 0.14, y: 0.94 },
			{ x: 0.88, y: 0.94 },
		]],
	},
	{
		// 2: looped bottom
		symbol: '\uED12',
		strokes: [[
			...arcPoints(0.48, 0.24, 0.3, 0.22, -160, 20, 9),
			{ x: 0.2, y: 0.86 },
			{ x: 0.16, y: 0.95 },
			{ x: 0.32, y: 0.95 },
			{ x: 0.28, y: 0.82 },
			{ x: 0.5, y: 0.94 },
			{ x: 0.88, y: 0.92 },
		]],
	},
	{
		symbol: '\uED13',
		strokes: [[
			...arcPoints(0.46, 0.26, 0.32, 0.23, -150, 85, 9),
			...arcPoints(0.48, 0.74, 0.35, 0.24, -85, 155, 10),
		]],
	},
	{
		// 3: flat-top European 3
		symbol: '\uED13',
		strokes: [[
			{ x: 0.16, y: 0.08 },
			{ x: 0.82, y: 0.08 },
			{ x: 0.44, y: 0.46 },
			...arcPoints(0.48, 0.72, 0.35, 0.24, -85, 160, 10),
		]],
	},
	{
		// 4: 2-stroke open (slanted L + full vertical stem)
		symbol: '\uED14',
		strokes: [
			[{ x: 0.32, y: 0.05 }, { x: 0.12, y: 0.62 }, { x: 0.88, y: 0.62 }],
			[{ x: 0.68, y: 0.08 }, { x: 0.68, y: 0.95 }],
		],
	},
	{
		// 4: 2-stroke European open (slanted down + right, short lower vertical stem)
		symbol: '\uED14',
		strokes: [
			[{ x: 0.42, y: 0.05 }, { x: 0.12, y: 0.62 }, { x: 0.88, y: 0.62 }],
			[{ x: 0.64, y: 0.34 }, { x: 0.64, y: 0.95 }],
		],
	},
	{
		// 4: 2-stroke vertical L + lower vertical stem
		symbol: '\uED14',
		strokes: [
			[{ x: 0.2, y: 0.05 }, { x: 0.18, y: 0.6 }, { x: 0.86, y: 0.6 }],
			[{ x: 0.64, y: 0.32 }, { x: 0.64, y: 0.95 }],
		],
	},
	{
		// 4: 2-stroke vertical L + full vertical stem
		symbol: '\uED14',
		strokes: [
			[{ x: 0.2, y: 0.05 }, { x: 0.2, y: 0.6 }, { x: 0.86, y: 0.6 }],
			[{ x: 0.72, y: 0.06 }, { x: 0.72, y: 0.95 }],
		],
	},
	{
		// 4: 2-stroke closed top
		symbol: '\uED14',
		strokes: [
			[{ x: 0.68, y: 0.05 }, { x: 0.12, y: 0.64 }, { x: 0.88, y: 0.64 }],
			[{ x: 0.68, y: 0.05 }, { x: 0.68, y: 0.95 }],
		],
	},
	{
		// 4: 1-stroke continuous (bottom-up then triangle)
		symbol: '\uED14',
		strokes: [[
			{ x: 0.68, y: 0.95 },
			{ x: 0.68, y: 0.05 },
			{ x: 0.12, y: 0.64 },
			{ x: 0.88, y: 0.64 },
		]],
	},
	{
		// 4: 1-stroke top-down (down-left, right, up, down)
		symbol: '\uED14',
		strokes: [[
			{ x: 0.34, y: 0.05 },
			{ x: 0.12, y: 0.62 },
			{ x: 0.86, y: 0.62 },
			{ x: 0.66, y: 0.28 },
			{ x: 0.66, y: 0.95 },
		]],
	},
	{
		// 5: 1-stroke
		symbol: '\uED15',
		strokes: [[
			{ x: 0.82, y: 0.06 },
			{ x: 0.22, y: 0.06 },
			{ x: 0.18, y: 0.45 },
			...arcPoints(0.48, 0.68, 0.34, 0.26, -125, 145, 10),
		]],
	},
	{
		// 5: 2-stroke (body first, then top bar)
		symbol: '\uED15',
		strokes: [
			[
				{ x: 0.22, y: 0.08 },
				{ x: 0.18, y: 0.45 },
				...arcPoints(0.48, 0.68, 0.34, 0.26, -125, 145, 10),
			],
			[{ x: 0.22, y: 0.08 }, { x: 0.82, y: 0.08 }],
		],
	},

	// --- Accidentals ---
	{
		// Sharp (#): 4 strokes
		symbol: '\uE262',
		strokes: [
			[{ x: 0.32, y: 0.05 }, { x: 0.26, y: 0.95 }],
			[{ x: 0.74, y: 0.05 }, { x: 0.68, y: 0.95 }],
			[{ x: 0.06, y: 0.36 }, { x: 0.94, y: 0.28 }],
			[{ x: 0.06, y: 0.72 }, { x: 0.94, y: 0.64 }],
		],
	},
	{
		// Flat (b): 1-stroke
		symbol: '\uE260',
		strokes: [[
			{ x: 0.22, y: 0.05 },
			{ x: 0.22, y: 0.95 },
			...arcPoints(0.44, 0.72, 0.34, 0.22, 110, -135, 10),
		]],
	},
	{
		// Flat (b): 2-stroke
		symbol: '\uE260',
		strokes: [
			[{ x: 0.22, y: 0.05 }, { x: 0.22, y: 0.95 }],
			arcPoints(0.44, 0.72, 0.34, 0.22, -135, 125, 10),
		],
	},
	{
		// Natural (♮): 2-stroke
		symbol: '\uE261',
		strokes: [
			[{ x: 0.26, y: 0.05 }, { x: 0.26, y: 0.68 }, { x: 0.76, y: 0.6 }],
			[{ x: 0.26, y: 0.38 }, { x: 0.76, y: 0.3 }, { x: 0.76, y: 0.95 }],
		],
	},
	{
		// Double sharp (x): 2 crossing diagonals
		symbol: '\uE263',
		strokes: [
			[{ x: 0.12, y: 0.12 }, { x: 0.88, y: 0.88 }],
			[{ x: 0.88, y: 0.12 }, { x: 0.12, y: 0.88 }],
		],
	},

	// --- Dynamics (single-glyph + cluster tokens) ---
	{
		// p: 1-stroke (down then up into loop)
		symbol: '\uE520',
		strokes: [[
			{ x: 0.24, y: 0.95 },
			{ x: 0.24, y: 0.08 },
			...arcPoints(0.48, 0.3, 0.32, 0.22, -135, 145, 10),
		]],
	},
	{
		// p: 1-stroke top-down (down, back up, loop)
		symbol: '\uE520',
		strokes: [[
			{ x: 0.24, y: 0.08 },
			{ x: 0.24, y: 0.95 },
			{ x: 0.24, y: 0.2 },
			...arcPoints(0.48, 0.3, 0.32, 0.22, -135, 145, 10),
		]],
	},
	{
		// p: 2-stroke
		symbol: '\uE520',
		strokes: [
			[{ x: 0.24, y: 0.08 }, { x: 0.24, y: 0.95 }],
			arcPoints(0.48, 0.3, 0.32, 0.22, -135, 145, 10),
		],
	},
	{
		// f: 2-stroke
		symbol: '\uE522',
		strokes: [
			[
				{ x: 0.8, y: 0.1 },
				{ x: 0.6, y: 0.04 },
				{ x: 0.44, y: 0.2 },
				{ x: 0.36, y: 0.92 },
				{ x: 0.18, y: 0.96 },
			],
			[{ x: 0.16, y: 0.46 }, { x: 0.74, y: 0.46 }],
		],
	},
	{
		// f: 1-stroke cursive
		symbol: '\uE522',
		strokes: [[
			{ x: 0.78, y: 0.1 },
			{ x: 0.58, y: 0.04 },
			{ x: 0.42, y: 0.22 },
			{ x: 0.35, y: 0.95 },
			{ x: 0.56, y: 0.66 },
			{ x: 0.16, y: 0.46 },
			{ x: 0.76, y: 0.46 },
		]],
	},
	// Cluster-only letters for multi-letter dynamics (mp, mf, sf, sfz, rfz) and tr
	{
		symbol: 'm',
		clusterOnly: true,
		strokes: [[
			{ x: 0.06, y: 0.88 },
			{ x: 0.12, y: 0.2 },
			{ x: 0.28, y: 0.14 },
			{ x: 0.44, y: 0.85 },
			{ x: 0.56, y: 0.2 },
			{ x: 0.72, y: 0.14 },
			{ x: 0.92, y: 0.88 },
		]],
	},
	{
		symbol: 's',
		clusterOnly: true,
		strokes: [[
			{ x: 0.78, y: 0.16 },
			{ x: 0.48, y: 0.05 },
			{ x: 0.18, y: 0.24 },
			{ x: 0.5, y: 0.5 },
			{ x: 0.82, y: 0.76 },
			{ x: 0.5, y: 0.95 },
			{ x: 0.18, y: 0.84 },
		]],
	},
	{
		symbol: 'r',
		clusterOnly: true,
		strokes: [[
			{ x: 0.22, y: 0.16 },
			{ x: 0.22, y: 0.92 },
			{ x: 0.22, y: 0.36 },
			{ x: 0.5, y: 0.15 },
			{ x: 0.84, y: 0.22 },
		]],
	},
	{
		symbol: 'z',
		clusterOnly: true,
		strokes: [[
			{ x: 0.12, y: 0.16 },
			{ x: 0.88, y: 0.16 },
			{ x: 0.12, y: 0.84 },
			{ x: 0.88, y: 0.84 },
		]],
	},
	{
		symbol: 't',
		clusterOnly: true,
		strokes: [
			[{ x: 0.46, y: 0.06 }, { x: 0.46, y: 0.88 }, { x: 0.74, y: 0.92 }],
			[{ x: 0.16, y: 0.36 }, { x: 0.8, y: 0.36 }],
		],
	},

	// --- Organ Pedaling (Heel ∪, Toe ∧) ---
	{
		// ∪ (Heel / U): left-to-right U
		symbol: '\uE661',
		strokes: [[
			{ x: 0.16, y: 0.1 },
			{ x: 0.16, y: 0.56 },
			...arcPoints(0.5, 0.58, 0.34, 0.34, 180, 0, 10),
			{ x: 0.84, y: 0.1 },
		]],
	},
	{
		// ∪ (Heel / U): wide rounded bowl
		symbol: '\uE661',
		strokes: [arcPoints(0.5, 0.25, 0.38, 0.65, 180, 0, 12)],
	},
	{
		// ∧ (Toe / inverted V): left-to-right (wider angle)
		symbol: '\uE664',
		strokes: [[{ x: 0.08, y: 0.86 }, { x: 0.5, y: 0.14 }, { x: 0.92, y: 0.86 }]],
	},
	{
		// ∧ (Toe / inverted V): right-to-left
		symbol: '\uE664',
		strokes: [[{ x: 0.92, y: 0.86 }, { x: 0.5, y: 0.14 }, { x: 0.08, y: 0.86 }]],
	},

	// --- String Marks (Down bow 𝆪, Up bow 𝆫) ---
	{
		// Down bow (⊓): left-to-right square arch
		symbol: '\uE610',
		strokes: [[
			{ x: 0.16, y: 0.9 },
			{ x: 0.16, y: 0.16 },
			{ x: 0.84, y: 0.16 },
			{ x: 0.84, y: 0.9 },
		]],
	},
	{
		// Down bow (⊓): right-to-left
		symbol: '\uE610',
		strokes: [[
			{ x: 0.84, y: 0.9 },
			{ x: 0.84, y: 0.16 },
			{ x: 0.16, y: 0.16 },
			{ x: 0.16, y: 0.9 },
		]],
	},
	{
		// Up bow (V): left-to-right
		symbol: '\uE612',
		strokes: [[{ x: 0.14, y: 0.12 }, { x: 0.5, y: 0.9 }, { x: 0.86, y: 0.12 }]],
	},
	{
		// Up bow (V): right-to-left
		symbol: '\uE612',
		strokes: [[{ x: 0.86, y: 0.12 }, { x: 0.5, y: 0.9 }, { x: 0.14, y: 0.12 }]],
	},

	// --- Articulations ---
	{
		// Accent (>): top-to-bottom
		symbol: '\uE4A0',
		strokes: [[{ x: 0.1, y: 0.18 }, { x: 0.9, y: 0.5 }, { x: 0.1, y: 0.82 }]],
	},
	{
		// Accent (>): bottom-to-top
		symbol: '\uE4A0',
		strokes: [[{ x: 0.1, y: 0.82 }, { x: 0.9, y: 0.5 }, { x: 0.1, y: 0.18 }]],
	},
	{
		// Marcato (^): left-to-right (taller/narrower peak)
		symbol: '\uE4AC',
		strokes: [[{ x: 0.2, y: 0.9 }, { x: 0.5, y: 0.08 }, { x: 0.8, y: 0.9 }]],
	},
	{
		// Marcato (^): right-to-left
		symbol: '\uE4AC',
		strokes: [[{ x: 0.8, y: 0.9 }, { x: 0.5, y: 0.08 }, { x: 0.2, y: 0.9 }]],
	},
	{
		// Tenuto (-)
		symbol: '\uE4A4',
		strokes: [[{ x: 0.08, y: 0.5 }, { x: 0.92, y: 0.5 }]],
	},

	// --- Ornaments & Navigation ---
	{
		// Fermata above: upward arch + dot below
		symbol: '\uE4C0',
		strokes: [
			arcPoints(0.5, 0.75, 0.44, 0.55, 180, 360, 12),
			[{ x: 0.48, y: 0.76 }, { x: 0.52, y: 0.8 }],
		],
	},
	{
		// Fermata below: downward U-arch + dot above
		symbol: '\uE4C1',
		strokes: [
			arcPoints(0.5, 0.25, 0.44, 0.55, 0, 180, 12),
			[{ x: 0.48, y: 0.2 }, { x: 0.52, y: 0.24 }],
		],
	},
	{
		// Breath mark (comma)
		symbol: '\uE4CE',
		strokes: [[
			{ x: 0.56, y: 0.14 },
			{ x: 0.62, y: 0.44 },
			{ x: 0.48, y: 0.76 },
			{ x: 0.28, y: 0.94 },
		]],
	},
	{
		// Caesura (//)
		symbol: '\uE4D1',
		strokes: [
			[{ x: 0.14, y: 0.9 }, { x: 0.46, y: 0.1 }],
			[{ x: 0.54, y: 0.9 }, { x: 0.86, y: 0.1 }],
		],
	},
	{
		// Turn (~)
		symbol: '\uE567',
		strokes: [[
			{ x: 0.06, y: 0.66 },
			{ x: 0.24, y: 0.22 },
			{ x: 0.5, y: 0.5 },
			{ x: 0.76, y: 0.78 },
			{ x: 0.94, y: 0.34 },
		]],
	},
	{
		// Coda (circle + crosshairs)
		symbol: '\uE048',
		strokes: [
			arcPoints(0.5, 0.5, 0.34, 0.38, -90, 270, 14),
			[{ x: 0.5, y: 0.04 }, { x: 0.5, y: 0.96 }],
			[{ x: 0.06, y: 0.5 }, { x: 0.94, y: 0.5 }],
		],
	},
	{
		// Pedal up (*)
		symbol: '\uE655',
		strokes: [
			[{ x: 0.5, y: 0.08 }, { x: 0.5, y: 0.92 }],
			[{ x: 0.14, y: 0.26 }, { x: 0.86, y: 0.74 }],
			[{ x: 0.86, y: 0.26 }, { x: 0.14, y: 0.74 }],
		],
	},
]
