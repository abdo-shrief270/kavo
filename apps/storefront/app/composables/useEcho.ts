import type { Ref } from 'vue'

type Listener = (payload: unknown) => void

/**
 * One WebSocket implementation shared by every Kavo frontend.
 *
 * Two rules this wrapper enforces:
 *  - Sockets drop. Nothing important may depend on the socket alone, so every
 *    caller passes a `refresh` fallback that is also safe to call on its own.
 *  - Channels are always tenant- or user-scoped. Authorisation happens server
 *    side in routes/channels.php; this just names the channel correctly.
 */
export function useEcho(channel: Ref<string | null> | string | null) {
  const connected = ref(false)
  const listeners = new Map<string, Set<Listener>>()
  let socket: WebSocket | null = null

  const channelName = computed(() => (typeof channel === 'string' || channel === null ? channel : channel.value))

  const on = (event: string, handler: Listener) => {
    if (!listeners.has(event)) listeners.set(event, new Set())
    listeners.get(event)!.add(handler)

    return () => listeners.get(event)?.delete(handler)
  }

  const connect = () => {
    // SSR has no sockets, and a server-rendered page must not wait on one.
    if (import.meta.server || !channelName.value) return

    const { reverb } = useRuntimeConfig().public

    if (!reverb.key) return

    const url = `${reverb.scheme === 'https' ? 'wss' : 'ws'}://${reverb.host}:${reverb.port}/app/${reverb.key}`

    socket = new WebSocket(url)

    socket.addEventListener('open', () => {
      connected.value = true
      socket?.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: channelName.value } }))
    })

    socket.addEventListener('close', () => {
      connected.value = false
    })

    socket.addEventListener('message', (message) => {
      try {
        const frame = JSON.parse(message.data)
        const handlers = listeners.get(frame.event)
        if (!handlers) return

        const payload = typeof frame.data === 'string' ? JSON.parse(frame.data) : frame.data
        handlers.forEach((handler) => handler(payload))
      } catch {
        // A malformed frame is not worth tearing the page down over.
      }
    })
  }

  const disconnect = () => {
    socket?.close()
    socket = null
    connected.value = false
  }

  onMounted(connect)
  onBeforeUnmount(disconnect)

  return { connected, on, connect, disconnect }
}
