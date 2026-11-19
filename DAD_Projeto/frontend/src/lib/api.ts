export type FieldErrors = Record<string, string[]>

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public errors: FieldErrors = {},
  ) {
    super(message)
  }
}

export async function api<T>(path: string, options: RequestInit = {}, retry = true): Promise<T> {
  const token = document.cookie
    .split('; ')
    .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
    ?.slice(11)
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (options.body) headers.set('Content-Type', 'application/json')
  if (token) headers.set('X-XSRF-TOKEN', decodeURIComponent(token))

  let response: Response
  try {
    response = await fetch(path, {
      ...options,
      headers,
      credentials: 'same-origin',
      signal: AbortSignal.timeout(10000),
    })
  } catch {
    throw new ApiError(
      0,
      'Não foi possível ligar à plataforma. Confirma que o servidor está a executar e tenta novamente.',
    )
  }

  if (response.status === 419 && retry) {
    await api('/sanctum/csrf-cookie', {}, false)
    return api<T>(path, options, false)
  }
  if (!response.ok) {
    const body = await response.json().catch(() => ({}))
    if (
      (response.status === 401 && path !== '/api/auth/me') ||
      body.code === 'account_unavailable'
    ) {
      window.dispatchEvent(new Event('auth:expired'))
    }
    const defaults: Record<number, string> = {
      401: 'A tua sessão terminou. Entra novamente.',
      403: 'Não tens permissão para aceder a esta área.',
      419: 'A sessão expirou. Atualiza a página e tenta novamente.',
      422: 'Verifica os campos assinalados e tenta novamente.',
      429: 'Demasiadas tentativas. Aguarda um minuto e tenta novamente.',
    }
    const message =
      response.status === 422
        ? defaults[422]
        : response.status >= 500
          ? 'Ocorreu um problema no servidor. Tenta novamente dentro de instantes.'
          : ((response.status === 403 || response.status === 409 ? body.message : undefined) ??
            defaults[response.status] ??
            body.message ??
            'Não foi possível concluir o pedido.')
    throw new ApiError(response.status, message ?? 'Pedido inválido.', body.errors ?? {})
  }
  return response.status === 204 ? (undefined as T) : response.json()
}
