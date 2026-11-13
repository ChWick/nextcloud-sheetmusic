export interface ImageBuffer {
	width: number
	height: number
	channels: 1 | 3 | 4 // 1=Grayscale, 3=RGB, 4=RGBA
	data: Uint8ClampedArray
}

export type KnownScanOpType = 'gaussian' | 'grayLevelMapping' | 'adaptiveThreshold' | 'compression'
export type ScanOpType = KnownScanOpType | (string & {})

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
	softness?: number // Soft transition width (0 = hard binary, >0 = smooth ramp around local threshold)
}

export type CompressionFormat =
	| 'jpeg'
	| 'grayscale-8bit'
	| 'grayscale-4bit'
	| 'grayscale-2bit'
	| 'bw-1bit'
	| 'rgb-24bit'

export interface CompressionOp extends BaseScanOp {
	type: 'compression'
	format: CompressionFormat
	quality: number // 10-100 (for 'jpeg', default 75)
	colorMode?: 'grayscale' | 'color' // for 'jpeg' (default 'grayscale')
}

export interface CustomScanOp extends BaseScanOp {
	type: Exclude<string, KnownScanOpType>
	[key: string]: any
}

export type ScanOperation = GaussianOp | GrayLevelMappingOp | AdaptiveThresholdOp | CompressionOp | CustomScanOp


