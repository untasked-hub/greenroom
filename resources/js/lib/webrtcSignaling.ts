const ICE_CONFIG: RTCConfiguration = {
  iceServers: [
    { urls: 'stun:stun.l.google.com:19302' },
    { urls: 'stun:stun1.l.google.com:19302' },
  ],
}

/**
 * Resolve once ICE gathering finishes (or after a short timeout) so the SDP we post already
 * carries the candidates. Trickled candidates can be polled before the offer/answer they
 * belong to and get dropped, which left guests with a connected-looking but black video.
 */
function waitForIceGathering(pc: RTCPeerConnection, timeoutMs = 3000): Promise<void> {
  if (pc.iceGatheringState === 'complete') return Promise.resolve()
  return new Promise((resolve) => {
    const done = () => {
      pc.removeEventListener('icegatheringstatechange', check)
      window.clearTimeout(timer)
      resolve()
    }
    const check = () => {
      if (pc.iceGatheringState === 'complete') done()
    }
    const timer = window.setTimeout(done, timeoutMs)
    pc.addEventListener('icegatheringstatechange', check)
  })
}

export function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

export async function postSignal(
  slug: string,
  sender: string,
  recipient: string,
  kind: 'join' | 'offer' | 'answer' | 'ice' | 'bye',
  payload: unknown = {},
  presenterKey?: string,
): Promise<void> {
  await fetch(`/r/${slug}/signals`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-CSRF-TOKEN': csrfToken(),
    },
    body: JSON.stringify({
      sender,
      recipient,
      kind,
      payload: JSON.stringify(payload),
      ...(presenterKey ? { key: presenterKey } : {}),
    }),
  })
}

export interface SignalRow {
  id: number
  sender: string
  kind: 'join' | 'offer' | 'answer' | 'ice' | 'bye'
  payload: string
}

export async function pollSignals(
  slug: string,
  recipient: string,
  after: number,
  presenterKey?: string,
): Promise<SignalRow[]> {
  const qs = new URLSearchParams({
    recipient,
    after: String(after),
    ...(presenterKey ? { key: presenterKey } : {}),
  })
  const res = await fetch(`/r/${slug}/signals?${qs}`, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })
  if (!res.ok) return []
  const data = await res.json()
  return Array.isArray(data.signals) ? data.signals : []
}

/**
 * Presenter-side WebRTC broadcaster: accepts guest 'join' signals,
 * sends SDP offers carrying the composed stage MediaStream, and exchanges ICE.
 */
export class PresenterBroadcaster {
  private peers = new Map<string, RTCPeerConnection>()
  private lastSignalId = 0
  private timer: number | null = null
  private stream: MediaStream | null = null

  constructor(
    private slug: string,
    private presenterKey: string,
    private onViewerCount?: (count: number) => void,
  ) {}

  start(stream: MediaStream): void {
    this.stream = stream
    if (this.timer !== null) return
    this.timer = window.setInterval(() => void this.tick(), 1500)
    void this.tick()
  }

  replaceStream(stream: MediaStream): void {
    this.stream = stream
    for (const pc of this.peers.values()) {
      const senders = pc.getSenders()
      for (const track of stream.getTracks()) {
        const sender = senders.find((s) => s.track?.kind === track.kind)
        if (sender) {
          void sender.replaceTrack(track)
        } else {
          pc.addTrack(track, stream)
        }
      }
    }
  }

  stop(): void {
    if (this.timer !== null) {
      window.clearInterval(this.timer)
      this.timer = null
    }
    for (const [peerId, pc] of this.peers.entries()) {
      void postSignal(this.slug, 'presenter', peerId, 'bye', {}, this.presenterKey)
      pc.close()
    }
    this.peers.clear()
    this.onViewerCount?.(0)
  }

  private async tick(): Promise<void> {
    const signals = await pollSignals(this.slug, 'presenter', this.lastSignalId, this.presenterKey)
    for (const sig of signals) {
      this.lastSignalId = Math.max(this.lastSignalId, sig.id)
      try {
        await this.handleSignal(sig)
      } catch {
        // Ignore malformed or stale signals
      }
    }
  }

  private async handleSignal(sig: SignalRow): Promise<void> {
    const guestId = sig.sender
    if (sig.kind === 'join') {
      await this.connectGuest(guestId)
    } else if (sig.kind === 'answer') {
      const pc = this.peers.get(guestId)
      if (!pc) return
      const desc = JSON.parse(sig.payload)
      if (pc.signalingState === 'have-local-offer') {
        await pc.setRemoteDescription(new RTCSessionDescription(desc))
      }
    } else if (sig.kind === 'ice') {
      const pc = this.peers.get(guestId)
      if (!pc) return
      const candidate = JSON.parse(sig.payload)
      if (candidate) {
        await pc.addIceCandidate(new RTCIceCandidate(candidate))
      }
    } else if (sig.kind === 'bye') {
      this.dropGuest(guestId)
    }
  }

  private async connectGuest(guestId: string): Promise<void> {
    if (!this.stream) return
    this.dropGuest(guestId)

    const pc = new RTCPeerConnection(ICE_CONFIG)
    this.peers.set(guestId, pc)
    this.onViewerCount?.(this.peers.size)

    for (const track of this.stream.getTracks()) {
      pc.addTrack(track, this.stream)
    }

    pc.onicecandidate = (e) => {
      if (e.candidate) {
        void postSignal(this.slug, 'presenter', guestId, 'ice', e.candidate.toJSON(), this.presenterKey)
      }
    }

    pc.onconnectionstatechange = () => {
      if (pc.connectionState === 'failed' || pc.connectionState === 'closed' || pc.connectionState === 'disconnected') {
        this.dropGuest(guestId)
      }
    }

    const offer = await pc.createOffer()
    await pc.setLocalDescription(offer)
    await waitForIceGathering(pc)
    await postSignal(this.slug, 'presenter', guestId, 'offer', pc.localDescription?.toJSON(), this.presenterKey)
  }

  private dropGuest(guestId: string): void {
    const existing = this.peers.get(guestId)
    if (existing) {
      existing.close()
      this.peers.delete(guestId)
      this.onViewerCount?.(this.peers.size)
    }
  }
}

/**
 * Guest-side WebRTC receiver: announces 'join' to the presenter,
 * answers SDP offers, and emits the remote MediaStream when tracks arrive.
 */
export class GuestReceiver {
  public readonly peerId: string
  private pc: RTCPeerConnection | null = null
  private lastSignalId = 0
  private timer: number | null = null
  private active = false

  constructor(
    private slug: string,
    private onStream: (stream: MediaStream | null) => void,
    private onState?: (state: string) => void,
  ) {
    this.peerId = `g-${Math.random().toString(36).slice(2, 10)}`
  }

  start(): void {
    if (this.active) return
    this.active = true
    this.onState?.('connecting')
    void postSignal(this.slug, this.peerId, 'presenter', 'join', {})
    this.timer = window.setInterval(() => void this.tick(), 1500)
    void this.tick()
  }

  stop(): void {
    this.active = false
    if (this.timer !== null) {
      window.clearInterval(this.timer)
      this.timer = null
    }
    if (this.pc) {
      void postSignal(this.slug, this.peerId, 'presenter', 'bye', {})
      this.pc.close()
      this.pc = null
    }
    this.onStream(null)
    this.onState?.('idle')
  }

  private async tick(): Promise<void> {
    if (!this.active) return
    const signals = await pollSignals(this.slug, this.peerId, this.lastSignalId)
    for (const sig of signals) {
      this.lastSignalId = Math.max(this.lastSignalId, sig.id)
      try {
        await this.handleSignal(sig)
      } catch {
        // Ignore stale signals
      }
    }
  }

  private async handleSignal(sig: SignalRow): Promise<void> {
    if (sig.kind === 'offer') {
      if (this.pc) this.pc.close()
      const pc = new RTCPeerConnection(ICE_CONFIG)
      this.pc = pc

      pc.ontrack = (e) => {
        const stream = e.streams[0] ?? new MediaStream([e.track])
        this.onStream(stream)
        this.onState?.('live')
      }

      pc.onicecandidate = (e) => {
        if (e.candidate) {
          void postSignal(this.slug, this.peerId, 'presenter', 'ice', e.candidate.toJSON())
        }
      }

      pc.onconnectionstatechange = () => {
        if (pc.connectionState === 'connected') this.onState?.('live')
        if (pc.connectionState === 'failed' || pc.connectionState === 'disconnected') {
          this.onState?.('reconnecting')
        }
        if (pc.connectionState === 'failed' && this.active && this.pc === pc) {
          void postSignal(this.slug, this.peerId, 'presenter', 'join', {})
        }
      }

      const offer = JSON.parse(sig.payload)
      await pc.setRemoteDescription(new RTCSessionDescription(offer))
      const answer = await pc.createAnswer()
      await pc.setLocalDescription(answer)
      await waitForIceGathering(pc)
      await postSignal(this.slug, this.peerId, 'presenter', 'answer', pc.localDescription?.toJSON())
    } else if (sig.kind === 'ice' && this.pc) {
      const candidate = JSON.parse(sig.payload)
      if (candidate) {
        await this.pc.addIceCandidate(new RTCIceCandidate(candidate))
      }
    } else if (sig.kind === 'bye') {
      this.onStream(null)
      this.onState?.('ended')
    }
  }
}
