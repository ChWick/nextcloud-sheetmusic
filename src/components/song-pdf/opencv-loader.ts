let cvPromise: Promise<any> | null = null

export function isCvLoaded(): boolean {
	return cvPromise !== null && typeof window !== 'undefined' && (window as any).cv !== undefined
}

export async function getOpenCV(): Promise<any> {
	if (cvPromise) {
		return cvPromise
	}

	cvPromise = (async () => {
		try {
			if (typeof window !== 'undefined' && (window as any).cv && (window as any).cv.Mat) {
				return (window as any).cv
			}
			// Attempt dynamic import if available
			// @ts-expect-error dynamic optional import
			const cvModule = await import('@techstark/opencv-js')
			const cv = cvModule.default || cvModule
			if (cv?.getBuildInformation) {
				return cv
			}
			if (cv && typeof cv.onRuntimeInitialized !== 'undefined') {
				return new Promise((resolve) => {
					cv.onRuntimeInitialized = () => resolve(cv)
				})
			}
			return cv
		} catch {
			// Clean fallback: built-in image engine is used seamlessly
			return null
		}
	})()

	return cvPromise
}

export function withMats<T>(cv: any, fn: (track: <M>(mat: M) => M) => T): T {
	const tracked: any[] = []
	const track = <M>(m: M): M => {
		tracked.push(m)
		return m
	}
	try {
		return fn(track)
	} finally {
		for (const mat of tracked) {
			try {
				mat?.delete?.()
			} catch {}
		}
	}
}

