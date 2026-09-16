import { onBeforeUnmount, onMounted, ref } from 'vue'

type Listener = (payload: unknown) => void

/**
 * Minimal Reverb (Pusher protocol) client for private tenant channels.
 *
 * Two rules this enforces, both from the platform's realtime contract:
 *  - Private channels require server-side auth. The socket id is exchanged
 *    with /broadcasting/auth, which checks tenant membership — the client
 *    cannot talk its way onto another tenant's channel.
 *  - Sockets drop. Nothing here is a delivery guarantee: callers keep an HTTP
 *    fetch as the source of truth and use this only to make it feel instant.
 */
export function useEcho(channel: () => string | null, apiBase: string) {
  const connected = ref(false)
  const listeners = new Map<string, Set<Listener>>()

  let socket: WebSocket | null = null
  let socketId: string | null = null
  let reconnectAttempt = 0
  let reconnectTimer: ReturnType<typeof setTimeout> | undefined
  let closedByUs = false

  const config = {
    key: import.meta.env.VITE_REVERB_KEY ?? '',
    host: import.meta.env.VITE_REVERB_HOST ?? 'localhost',
    port: import.meta.env.VITE_REVERB_PORT ?? '8080',
    scheme: import.meta.env.VITE_REVERB_SCHEME ?? 'http',
  }

  function on(event: string, handler: Listener): () => void {
    if (!listeners.has(event)) listeners.set(event, new Set())
    listeners.get(event)!.add(handler)

    return () => listeners.get(event)?.delete(handler)
  }

  async function authorize(name: string): Promise<string | null> {
    if (!socketId) return null

    const response = await fetch(`${apiBase.replace(/\/$/, '')}/broadcasting/auth`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ socket_id: socketId, channel_name: name }),
    })

    // A 403 here is the server correctly refusing a channel this user is not
    // a member of. Not an error to retry — an answer.
    if (!response.ok) return null

    return (await response.json()).auth as string
  }

  async function subscribe() {
    const name = channel()
    if (!name || !socket || socket.readyState !== WebSocket.OPEN) return

    const auth = await authorize(name)
    if (!auth) return

    socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: name, auth } }))
  }

  function connect() {
    if (!config.key || !channel()) return

    closedByUs = false
    const protocol = config.scheme === 'https' ? 'wss' : 'ws'
    socket = new WebSocket(`${protocol}://${config.host}:${config.port}/app/${config.key}?protocol=7&client=js&version=8.0.0`)

    socket.addEventListener('message', async (message) => {
      let frame: { event: string; data: unknown }

      try {
        frame = JSON.parse(message.data)
      } catch {
        return
      }

      const payload = typeof frame.data === 'string' ? safeParse(frame.data) : frame.data

      if (frame.event === 'pusher:connection_established') {
        socketId = (payload as { socket_id?: string })?.socket_id ?? null
        connected.value = true
        reconnectAttempt = 0
        await subscribe()

        return
      }

      listeners.get(frame.event)?.forEach((handler) => handler(payload))
    })

    socket.addEventListener('close', () => {
      connected.value = false
      socketId = null
      if (!closedByUs) scheduleReconnect()
    })

    socket.addEventListener('error', () => socket?.close())
  }

  /**
   * Backs off to 30s rather than hammering. The dashboard already refetches
   * over HTTP, so a slow reconnect costs immediacy, not data.
   */
  function scheduleReconnect() {
    clearTimeout(reconnectTimer)
    const delay = Math.min(30_000, 1000 * 2 ** reconnectAttempt++)
    reconnectTimer = setTimeout(connect, delay)
  }

  function disconnect() {
    closedByUs = true
    clearTimeout(reconnectTimer)
    socket?.close()
    socket = null
    connected.value = false
  }

  function safeParse(value: string): unknown {
    try {
      return JSON.parse(value)
    } catch {
      return value
    }
  }

  onMounted(connect)
  onBeforeUnmount(disconnect)

  return { connected, on, connect, disconnect }
}
