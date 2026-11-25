/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import MusicGlyphIcon from '../../../src/components/song-viewer/MusicGlyphIcon.vue'

describe('MusicGlyphIcon.vue', () => {
	it('renders an SVG glyph centered with inkOffset for known SMuFL symbols', () => {
		const wrapper = mount(MusicGlyphIcon, {
			props: {
				symbol: '\uE522', // forte
				musicFont: 'leland',
				width: 28,
				height: 28,
				baseFontSize: 22,
			},
		})

		const svg = wrapper.find('svg')
		expect(svg.exists()).toBe(true)
		expect(svg.attributes('viewBox')).toBe('0 0 28 28')

		const text = svg.find('text')
		expect(text.text()).toBe('\uE522')
		expect(text.attributes('font-size')).toBe('22')
		expect(text.attributes('font-family')).toContain('Leland')
	})

	it('scales fingering digits by 1.65x for legibility in picker/pill thumbnails', () => {
		const wrapper = mount(MusicGlyphIcon, {
			props: {
				symbol: '\uED13', // fingering 3
				baseFontSize: 20,
			},
		})

		const text = wrapper.find('svg text')
		expect(text.exists()).toBe(true)
		expect(text.attributes('font-size')).toBe('33')
		expect(text.attributes('font-family')).toContain('Bravura')
	})

	it('falls back to a span when the symbol is not in MUSIC_STAMPS', () => {
		const wrapper = mount(MusicGlyphIcon, {
			props: {
				symbol: '?',
				musicFont: 'petaluma',
			},
		})

		expect(wrapper.find('svg').exists()).toBe(false)
		const span = wrapper.find('span')
		expect(span.exists()).toBe(true)
		expect(span.text()).toBe('?')
		expect(span.attributes('style')).toContain('Petaluma')
	})
})
