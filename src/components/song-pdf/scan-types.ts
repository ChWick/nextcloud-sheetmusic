export interface ImageBuffer {
	width: number
	height: number
	channels: 1 | 3 | 4 // 1=Grayscale, 3=RGB, 4=RGBA
	data: Uint8ClampedArray
}

export type ScanOpType = 'gaussian' | 'grayLevelMapping' | 'adaptiveThreshold'

export interface BaseScanOp {
	id: string
	enabled: boolean
}

export interface GaussianOp extends BaseScanOp {
	type: 'gaussian'
	size: number // Odd positive integer: 3, 5, 7, 9, ...
}

export interface GrayLevelMappingOp extends BaseScanOp {
	type: 'grayLevelMapping'
	lowerCutoff: number // 0-255 (values <= lowerCutoff become outMin)
	upperCutoff: number // 0-255 (values >= upperCutoff become outMax)
	colorMode?: 'color' | 'grayscale'
	outMin?: number // default 0
	outMax?: number // default 255
}

export interface AdaptiveThresholdOp extends BaseScanOp {
	type: 'adaptiveThreshold'
	windowSize: number // Odd integer: 15, 25, 35...
	delta: number // Offset subtracted from local mean, e.g. 5-15
	blackValue: number // Custom lower limit for ink (default 0)
	whiteValue: number // Custom upper limit for paper (default 255)
	lowerLimit?: number // Cutoff: pixels <= lowerLimit are unconditionally black
	upperLimit?: number // Cutoff: pixels >= upperLimit are unconditionally white
	colorMode?: 'binarize' | 'preserveColor'
}

export type ScanOperation = GaussianOp | GrayLevelMappingOp | AdaptiveThresholdOp

