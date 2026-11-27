import { csrfToken, PresenterBroadcaster } from './webrtcSignaling'
import { drawStageFrame, SpeakerLayout, StageSource } from './stageCompositor'
import { VirtualBackground, type BackgroundMode } from './virtualBackground'
import { loadPdfDeck, loadPptxDeck, RenderedDeck } from './slideRenderer'

export interface StudioSlide {
  id: number
  name: string
  mime: string
  size: number
}

export interface BackgroundState {
  mode: BackgroundMode
  imageId: string | null
  available: boolean
}

const backgroundModeKey = 'greenroom.vb.mode'
const backgroundImageKey = 'greenroom.vb.image'
const backgroundUploadKey = 'greenroom.vb.upload'

function backgroundPreference(key: string): string | null {
  try {
    return localStorage.getItem(key)
  } catch {
    return null
  }
}

function saveBackgroundPreference(key: string, value: string | null): void {
  try {
    if (value === null) localStorage.removeItem(key)
    else localStorage.setItem(key, value)
  } catch {
    // Effects still work for the current session if browser storage is blocked or full.
  }
}

async function decodeBackground(url: string): Promise<HTMLImageElement> {
  const image = new Image()
  image.crossOrigin = 'anonymous'
  image.src = url
  await image.decode()
  return image
}

async function downscaleBackground(image: HTMLImageElement): Promise<HTMLImageElement> {
  const canvas = document.createElement('canvas')
  const scale = Math.min(1, 1280 / Math.max(image.naturalWidth, image.naturalHeight))
  canvas.width = Math.max(1, Math.round(image.naturalWidth * scale))
  canvas.height = Math.max(1, Math.round(image.naturalHeight * scale))
  const ctx = canvas.getContext('2d')
  if (!ctx) throw new Error('Image canvas is unavailable.')
  ctx.fillStyle = '#090d16'
  ctx.fillRect(0, 0, canvas.width, canvas.height)
  ctx.drawImage(image, 0, 0, canvas.width, canvas.height)
  return decodeBackground(canvas.toDataURL('image/jpeg', 0.85))
}

export interface StudioSessionCallbacks {
  onBackgroundState?: (state: BackgroundState) => void
  onViewerCount: (n: number) => void
  onSlideMeta: (page: number, total: number, error: string) => void
  onMediaState: (camOn: boolean, screenOn: boolean, micOn: boolean, error: string) => void
  onLiveState: (isLive: boolean, statusText: string) => void
}

export class StudioSession {
  private canvas: HTMLCanvasElement | null = null
  private ctx: CanvasRenderingContext2D | null = null
  private cameraVideo: HTMLVideoElement | null = null
  private screenVideo: HTMLVideoElement | null = null
  private virtualBackground: VirtualBackground
  private backgroundState: BackgroundState = { mode: 'none', imageId: null, available: true }
  private backgroundImageRequest = 0
  private backgroundRestored = false
  private backgroundImageRestoring = false
  private destroyed = false
  private cameraStarting = false
  private cameraStream: MediaStream | null = null
  private screenStream: MediaStream | null = null
  private broadcaster: PresenterBroadcaster
  private tickWorker: Worker | null = null
  private tickTimer: number | null = null

  private deck: RenderedDeck | null = null
  private slideCanvas: HTMLCanvasElement | null = null
  private slideId: number | null = null

  private recorder: MediaRecorder | null = null
  private recordedChunks: Blob[] = []
  private recordStartedAt = 0

  public stageSource: StageSource = 'slide'
  public speakerLayout: SpeakerLayout = 'sidebar'
  public currentPage = 1
  public totalPages = 0
  public isLive = false
  public micMuted = false
  public roomTitle = ''
  public speakerName = 'Presenter'

  constructor(
    private slug: string,
    private presenterKey: string,
    private cb: StudioSessionCallbacks,
  ) {
    this.virtualBackground = new VirtualBackground(() => {
      this.backgroundState.mode = 'none'
      this.backgroundState.available = false
      this.emitBackgroundState()
    })
    this.broadcaster = new PresenterBroadcaster(slug, presenterKey, (n) => cb.onViewerCount(n))
  }

  attach(
    canvas: HTMLCanvasElement,
    cameraVideo: HTMLVideoElement,
    screenVideo: HTMLVideoElement,
  ): void {
    this.canvas = canvas
    this.ctx = canvas.getContext('2d')
    this.cameraVideo = cameraVideo
    this.screenVideo = screenVideo
    this.restoreBackground()
    this.startLoop()
  }

  private startLoop(): void {
    if (this.tickWorker !== null || this.tickTimer !== null) return
    // requestAnimationFrame stops when the studio tab is hidden (for example while the
    // presenter shares another window), which froze the broadcast for guests. Timers in a
    // Web Worker keep firing in background tabs, so the stage keeps producing frames.
    const tick = () => {
      if (this.ctx && this.canvas) {
        this.virtualBackground.processFrame()
        drawStageFrame(this.ctx, this.canvas.width, this.canvas.height, {
          stageSource: this.stageSource,
          speakerLayout: this.speakerLayout,
          slideCanvas: this.slideCanvas,
          screenVideo: this.screenVideo,
          cameraVideo: this.cameraVideo,
          processedCamera: this.virtualBackground.getProcessedCanvas(),
          roomTitle: this.roomTitle,
          isLive: this.isLive,
          speakerName: this.speakerName,
        })
      }
    }
    try {
      const src = 'setInterval(function(){postMessage(0)},42)'
      const url = URL.createObjectURL(new Blob([src], { type: 'text/javascript' }))
      this.tickWorker = new Worker(url)
      this.tickWorker.onmessage = tick
      this.tickWorker.onerror = () => {
        this.tickWorker?.terminate()
        this.tickWorker = null
        if (this.tickTimer === null) this.tickTimer = window.setInterval(tick, 42)
      }
    } catch {
      this.tickTimer = window.setInterval(tick, 42)
    }
    tick()
  }

  private buildBroadcastStream(): MediaStream {
    const out = new MediaStream()
    if (this.canvas) {
      const cap = this.canvas.captureStream(24)
      for (const track of cap.getVideoTracks()) out.addTrack(track)
    }
    if (this.cameraStream) {
      for (const track of this.cameraStream.getAudioTracks()) {
        track.enabled = !this.micMuted
        out.addTrack(track)
      }
    }
    return out
  }

  private syncBroadcastStream(): void {
    const stream = this.buildBroadcastStream()
    if (this.isLive) {
      this.broadcaster.replaceStream(stream)
    }
  }

  async loadSlide(slide: StudioSlide | null, page = 1): Promise<void> {
    if (!slide) {
      this.deck = null
      this.slideCanvas = null
      this.slideId = null
      this.currentPage = 1
      this.totalPages = 0
      this.cb.onSlideMeta(1, 0, '')
      return
    }
    try {
      if (this.slideId !== slide.id || !this.deck) {
        this.slideId = slide.id
        if (slide.mime === 'application/pdf') {
          this.deck = await loadPdfDeck(`/r/${this.slug}/slides/${slide.id}?key=${encodeURIComponent(this.presenterKey)}`)
        } else {
          this.deck = await loadPptxDeck(
            `/r/${this.slug}/slides/${slide.id}/pages?key=${encodeURIComponent(this.presenterKey)}`,
            slide.name,
          )
        }
      }
      this.totalPages = this.deck.totalPages
      this.currentPage = Math.max(1, Math.min(this.totalPages, page))
      this.slideCanvas = await this.deck.getPageCanvas(this.currentPage)
      this.cb.onSlideMeta(this.currentPage, this.totalPages, '')
    } catch (e) {
      this.slideCanvas = null
      this.cb.onSlideMeta(1, 0, e instanceof Error ? e.message : 'Unable to render slide deck.')
    }
  }

  async setPage(page: number): Promise<void> {
    if (!this.deck) return
    const next = Math.max(1, Math.min(this.deck.totalPages, page))
    this.currentPage = next
    this.slideCanvas = await this.deck.getPageCanvas(next)
    this.cb.onSlideMeta(this.currentPage, this.totalPages, '')
    await this.persistLayout()
  }

  getBackgroundState(): BackgroundState {
    return { ...this.backgroundState }
  }

  setBackgroundMode(mode: BackgroundMode): void {
    this.backgroundImageRequest++
    this.applyBackgroundMode(mode)
  }

  private applyBackgroundMode(mode: BackgroundMode): void {
    if (this.destroyed || (!this.backgroundState.available && mode !== 'none')) return
    this.backgroundState.mode = mode
    this.virtualBackground.setMode(mode)
    saveBackgroundPreference(backgroundModeKey, mode)
    this.emitBackgroundState()
  }

  async setBackgroundImage(url: string | null): Promise<void> {
    if (this.destroyed || !this.backgroundState.available) return
    const request = ++this.backgroundImageRequest
    if (url === null) {
      this.backgroundImageRestoring = false
      this.virtualBackground.setImage(null)
      this.backgroundState.imageId = null
      saveBackgroundPreference(backgroundImageKey, null)
      saveBackgroundPreference(backgroundUploadKey, null)
      if (this.backgroundState.mode === 'image') this.applyBackgroundMode('none')
      else this.emitBackgroundState()
      return
    }

    const uploaded = url.startsWith('data:') || url.startsWith('blob:')
    let image = await decodeBackground(url)
    if (uploaded) image = await downscaleBackground(image)
    if (request !== this.backgroundImageRequest || !this.backgroundState.available) return
    const imageId = uploaded ? 'upload' : url
    this.backgroundImageRestoring = false
    this.virtualBackground.setImage(image)
    this.backgroundState.imageId = imageId
    if (uploaded) saveBackgroundPreference(backgroundUploadKey, image.src)
    saveBackgroundPreference(backgroundImageKey, imageId)
    this.applyBackgroundMode('image')
  }

  private restoreBackground(): void {
    if (this.backgroundRestored) return
    this.backgroundRestored = true
    const mode = backgroundPreference(backgroundModeKey)
    const imageId = backgroundPreference(backgroundImageKey)
    const url = imageId === 'upload' ? backgroundPreference(backgroundUploadKey) : imageId
    this.backgroundState.imageId = url ? imageId : null
    if (url) {
      this.backgroundImageRestoring = true
      void decodeBackground(url).then((image) => {
        if (!this.backgroundImageRestoring) return
        this.backgroundImageRestoring = false
        this.virtualBackground.setImage(image)
      }, () => {
        if (!this.backgroundImageRestoring) return
        this.backgroundImageRestoring = false
        this.backgroundState.imageId = null
        if (this.backgroundState.mode === 'image') this.applyBackgroundMode('none')
        else this.emitBackgroundState()
      })
    }
    this.backgroundState.mode = mode === 'blur' || (mode === 'image' && url) ? mode : 'none'
    this.virtualBackground.setMode(this.backgroundState.mode)
    this.emitBackgroundState()
  }

  private emitBackgroundState(): void {
    this.cb.onBackgroundState?.(this.getBackgroundState())
  }

  async toggleCamera(): Promise<void> {
    if (this.destroyed || this.cameraStarting) return
    if (this.cameraStream) {
      this.cameraStream.getTracks().forEach((t) => t.stop())
      this.cameraStream = null
      this.virtualBackground.setCamera(null)
      if (this.cameraVideo) this.cameraVideo.srcObject = null
      this.emitMediaState('')
      this.syncBroadcastStream()
      return
    }
    if (!navigator.mediaDevices?.getUserMedia) {
      this.emitMediaState('Camera capture is not supported in this browser.')
      return
    }
    this.cameraStarting = true
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true })
      if (this.destroyed) {
        stream.getTracks().forEach((track) => track.stop())
        return
      }
      this.cameraStream = stream
      this.restoreBackground()
      if (this.cameraVideo) {
        this.cameraVideo.srcObject = stream
        this.cameraVideo.muted = true
        await this.cameraVideo.play().catch(() => {})
      }
      if (this.destroyed) return
      this.virtualBackground.setCamera(this.cameraVideo)
      this.emitMediaState('')
      this.syncBroadcastStream()
    } catch {
      if (!this.destroyed) this.emitMediaState('Camera permission was denied or no camera was found.')
    } finally {
      this.cameraStarting = false
    }
  }

  toggleMic(): void {
    this.micMuted = !this.micMuted
    if (this.cameraStream) {
      this.cameraStream.getAudioTracks().forEach((t) => {
        t.enabled = !this.micMuted
      })
    }
    this.emitMediaState('')
  }

  async toggleScreen(): Promise<void> {
    if (this.screenStream) {
      this.screenStream.getTracks().forEach((t) => t.stop())
      this.screenStream = null
      if (this.screenVideo) this.screenVideo.srcObject = null
      if (this.stageSource === 'screen') {
        this.stageSource = 'slide'
        await this.persistLayout()
      }
      this.emitMediaState('')
      return
    }
    if (!navigator.mediaDevices?.getDisplayMedia) {
      this.emitMediaState('Screen sharing is not supported in this browser.')
      return
    }
    try {
      const stream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false })
      this.screenStream = stream
      if (this.screenVideo) {
        this.screenVideo.srcObject = stream
        await this.screenVideo.play().catch(() => {})
      }
      stream.getVideoTracks()[0]?.addEventListener('ended', () => {
        this.screenStream = null
        if (this.screenVideo) this.screenVideo.srcObject = null
        if (this.stageSource === 'screen') {
          this.stageSource = 'slide'
          void this.persistLayout()
        }
        this.emitMediaState('')
      })
      this.stageSource = 'screen'
      await this.persistLayout()
      this.emitMediaState('')
    } catch {
      this.emitMediaState('Screen share was cancelled or blocked.')
    }
  }

  async setLayout(stageSource: StageSource, speakerLayout: SpeakerLayout): Promise<void> {
    this.stageSource = stageSource
    this.speakerLayout = speakerLayout
    await this.persistLayout()
  }

  private async persistLayout(): Promise<void> {
    await fetch(`/studio/${this.slug}/${this.presenterKey}/layout`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
      },
      body: JSON.stringify({
        stage_source: this.stageSource,
        speaker_layout: this.speakerLayout,
        current_slide_page: this.currentPage,
      }),
    }).catch(() => {})
  }

  async goLive(): Promise<void> {
    const res = await fetch(`/studio/${this.slug}/${this.presenterKey}/go-live`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
    })
    if (!res.ok) {
      this.cb.onLiveState(false, 'Could not start live broadcast.')
      return
    }
    this.isLive = true
    const stream = this.buildBroadcastStream()
    this.broadcaster.start(stream)
    this.startRecording(stream)
    this.cb.onLiveState(true, 'Live & recording')
  }

  async endLive(): Promise<void> {
    this.isLive = false
    await this.broadcaster.stop()
    const blob = await this.stopRecording()
    const durationSec = Math.max(1, Math.round((Date.now() - this.recordStartedAt) / 1000))

    await fetch(`/studio/${this.slug}/${this.presenterKey}/end-live`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
    }).catch(() => {})

    if (blob && blob.size > 0) {
      this.cb.onLiveState(false, 'Saving recording…')
      const form = new FormData()
      form.append('video', blob, `${this.slug}-${Date.now()}.webm`)
      form.append('title', this.roomTitle || 'Live Webinar')
      form.append('duration_seconds', String(durationSec))
      const up = await fetch(`/studio/${this.slug}/${this.presenterKey}/recordings`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        body: form,
      }).catch(() => null)
      if (up && up.ok) {
        this.cb.onLiveState(false, 'Recording saved to archive')
      } else {
        this.cb.onLiveState(false, 'Ended live (recording upload skipped or failed)')
      }
    } else {
      this.cb.onLiveState(false, 'Live ended')
    }
  }

  private startRecording(stream: MediaStream): void {
    this.recordedChunks = []
    this.recordStartedAt = Date.now()
    if (typeof MediaRecorder === 'undefined') return
    try {
      const mime = MediaRecorder.isTypeSupported('video/webm;codecs=vp8,opus')
        ? 'video/webm;codecs=vp8,opus'
        : MediaRecorder.isTypeSupported('video/webm')
          ? 'video/webm'
          : ''
      this.recorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream)
      this.recorder.ondataavailable = (ev) => {
        if (ev.data && ev.data.size > 0) this.recordedChunks.push(ev.data)
      }
      this.recorder.start(1000)
    } catch {
      this.recorder = null
    }
  }

  private stopRecording(): Promise<Blob | null> {
    return new Promise((resolve) => {
      if (!this.recorder || this.recorder.state === 'inactive') {
        resolve(this.recordedChunks.length ? new Blob(this.recordedChunks, { type: 'video/webm' }) : null)
        return
      }
      const rec = this.recorder
      rec.onstop = () => {
        const blob = this.recordedChunks.length ? new Blob(this.recordedChunks, { type: 'video/webm' }) : null
        this.recordedChunks = []
        this.recorder = null
        resolve(blob)
      }
      rec.stop()
    })
  }

  private emitMediaState(error: string): void {
    this.cb.onMediaState(!!this.cameraStream, !!this.screenStream, !this.micMuted, error)
  }

  destroy(): void {
    this.destroyed = true
    this.backgroundImageRestoring = false
    this.backgroundImageRequest++
    this.virtualBackground.dispose()
    this.tickWorker?.terminate()
    this.tickWorker = null
    if (this.tickTimer !== null) window.clearInterval(this.tickTimer)
    this.tickTimer = null
    void this.broadcaster.stop()
    this.cameraStream?.getTracks().forEach((t) => t.stop())
    this.screenStream?.getTracks().forEach((t) => t.stop())
  }
}
