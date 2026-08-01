import { ofetch } from 'ofetch'

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))

  return match ? decodeURIComponent(match[1]) : null
}

export const api = ofetch.create({
  credentials: 'include',
  async onRequest({ options }) {
    const method = (options.method ?? 'GET').toUpperCase()
    const headers = new Headers(options.headers)

    headers.set('Accept', 'application/json')

    if (method !== 'GET') {
      await ofetch('/sanctum/csrf-cookie', { credentials: 'include' })

      const token = readCookie('XSRF-TOKEN')

      if (token)
        headers.set('X-XSRF-TOKEN', token)
    }

    options.headers = headers
  },
})
