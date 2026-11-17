export const STANDALONE_MEDIA_QUERY =
	'(display-mode: standalone), (display-mode: fullscreen), (display-mode: minimal-ui), (display-mode: window-controls-overlay)'

export const PWA_STANDALONE_CLASS = 'sheetmusic-pwa-standalone'

type StandaloneNavigator = Navigator & {
	standalone?: boolean
}

/**
 * Returns true when the app is running as an installed web app (PWA)
 * rather than inside a standard browser tab.
 */
export function isStandaloneDisplayMode(): boolean {
	if (typeof window === 'undefined') {
		return false
	}

	const nav = window.navigator as StandaloneNavigator
	if (nav.standalone === true) {
		return true
	}

	if (typeof window.matchMedia === 'function') {
		return window.matchMedia(STANDALONE_MEDIA_QUERY).matches
	}

	return false
}

/**
 * Applies or removes the standalone PWA body/html class and listens for
 * display-mode changes. Returns a cleanup function.
 */
export function initPwaStandaloneMode(): () => void {
	if (typeof window === 'undefined' || typeof document === 'undefined') {
		return () => {}
	}

	const updateClass = () => {
		const active = isStandaloneDisplayMode()
		document.documentElement?.classList.toggle(PWA_STANDALONE_CLASS, active)
		document.body?.classList.toggle(PWA_STANDALONE_CLASS, active)
	}

	updateClass()

	if (typeof window.matchMedia !== 'function') {
		return () => {}
	}

	const mql = window.matchMedia(STANDALONE_MEDIA_QUERY)
	const onChange = () => updateClass()
	if (typeof mql.addEventListener === 'function') {
		mql.addEventListener('change', onChange)
		return () => mql.removeEventListener('change', onChange)
	}

	return () => {}
}
