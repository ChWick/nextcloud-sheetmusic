import type { paths } from '../types/api.generated'
import axios, { type AxiosRequestConfig, type AxiosResponse } from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'

const publicSharingToken = loadState('sheetmusic', 'publicSharingToken', '')
if (publicSharingToken) {
	axios.interceptors.request.use((config) => {
		config.headers = config.headers || {}
		config.headers['X-Public-Share-Token'] = publicSharingToken
		config.params = config.params || {}
		config.params.token = publicSharingToken
		return config
	})
}

type GetPath = {
	[K in keyof paths]: paths[K] extends { get: unknown } ? K : never
}[keyof paths]

type PostPath = {
	[K in keyof paths]: paths[K] extends { post: unknown } ? K : never
}[keyof paths]

type DeletePath = {
	[K in keyof paths]: paths[K] extends { delete: unknown } ? K : never
}[keyof paths]

type ExtractQueryParams<T> = T extends { parameters: { query: infer Q } }
	? Q
	: T extends { parameters: { query?: infer Q } }
		? Q
		: undefined

type ExtractPathParams<T> = T extends { parameters: { path: infer P } } ? P : undefined

type ExtractRequestBody<T> = T extends { requestBody: { content: { 'application/json': infer B } } }
	? B
	: T extends { requestBody?: { content: { 'application/json': infer B } } }
		? B
		: unknown

type ExtractResponse<T> = T extends { responses: { 200: { content: { 'application/json': infer R } } } }
	? R
	: T extends { responses: { 201: { content: { 'application/json': infer R } } } }
		? R
		: unknown

/**
 * Type-safe GET request wrapper for Sheet Music API
 */
export function apiGet<P extends GetPath>(
	path: P,
	options?: {
		pathParams?: ExtractPathParams<paths[P]['get']>
		queryParams?: ExtractQueryParams<paths[P]['get']>
	},
): Promise<AxiosResponse<ExtractResponse<paths[P]['get']>>> {
	const url = generateOcsUrl(
		'/apps/sheetmusic' + path,
		options?.pathParams as Record<string, string | number> | undefined,
	)
	return options?.queryParams !== undefined ? axios.get(url, { params: options.queryParams }) : axios.get(url)
}

/**
 * Type-safe POST request wrapper for Sheet Music API
 */
export function apiPost<P extends PostPath>(
	path: P,
	body?: ExtractRequestBody<paths[P]['post']>,
	options?: {
		pathParams?: ExtractPathParams<paths[P]['post']>
		config?: AxiosRequestConfig
	},
): Promise<AxiosResponse<ExtractResponse<paths[P]['post']>>> {
	const url = generateOcsUrl(
		'/apps/sheetmusic' + path,
		options?.pathParams as Record<string, string | number> | undefined,
	)
	return options?.config !== undefined ? axios.post(url, body, options.config) : axios.post(url, body)
}

/**
 * Type-safe DELETE request wrapper for Sheet Music API
 */
export function apiDelete<P extends DeletePath>(
	path: P,
	options?: {
		pathParams?: ExtractPathParams<paths[P]['delete']>
		queryParams?: ExtractQueryParams<paths[P]['delete']>
	},
): Promise<AxiosResponse<ExtractResponse<paths[P]['delete']>>> {
	const url = generateOcsUrl(
		'/apps/sheetmusic' + path,
		options?.pathParams as Record<string, string | number> | undefined,
	)
	return options?.queryParams !== undefined ? axios.delete(url, { params: options.queryParams }) : axios.delete(url)
}
