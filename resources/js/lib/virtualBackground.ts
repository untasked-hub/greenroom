import { drawCovered } from './stageCompositor'

export type BackgroundMode = 'none' | 'blur' | 'image'

interface SegmentationResults {
  image: CanvasImageSource
  segmentationMask: CanvasImageSource
}

interface Segmenter {
  setOptions(options: { modelSelection: number }): void
  send(input: { image: HTMLVideoElement }): Promise<void>
  onResults(callback: (results: SegmentationResults) => void): void
  close(): Promise<void>
}

type SegmenterConstructor = new (options: { locateFile: (file: string) => string }) => Segmenter

const cdn = 'https://cdn.jsdelivr.net/npm/@mediapipe/selfie_segmentation/'
let scriptLoad: Promise<SegmenterConstructor> | undefined

function loadSegmenter(): Promise<SegmenterConstructor> {
  return scriptLoad ??= new Promise<SegmenterConstructor>((resolve, reject) => {
    const script = document.createElement('script')
    script.src = cdn + 'selfie_segmentation.js'
    script.crossOrigin = 'anonymous'
    const finish = () => {
      window.clearTimeout(timeout)
      script.remove()
      const { SelfieSegmentation } = window as Window & { SelfieSegmentation?: SegmenterConstructor }
      if (SelfieSegmentation) resolve(SelfieSegmentation)
      else reject(new Error('Selfie segmentation could not be loaded.'))
    }
    const timeout = window.setTimeout(finish, 15000)
    script.onload = finish
    script.onerror = finish
    document.head.append(script)
  }).catch((error) => {
    scriptLoad = undefined
    throw error
  })
}

export class VirtualBackground {
  private mode: BackgroundMode = 'none'
  private image: HTMLImageElement | null = null
  private video: HTMLVideoElement | null = null
  private SegmenterClass: SegmenterConstructor | null = null
  private segmenter: Segmenter | null = null
  private context: CanvasRenderingContext2D | null = null
  private pending: Promise<void> | null = null
  private revision = 0
  private ready = false
  private disposed = false
  private available = true
  private lastVideoTime = -1

  constructor(private onUnavailable: () => void) {}

  setMode(mode: BackgroundMode): void {
    if (this.mode === mode || this.disposed || !this.available) return
    this.mode = mode
    this.invalidate()
    if (mode !== 'none' && !this.SegmenterClass) {
      void loadSegmenter().then((SegmenterClass) => {
        if (!this.disposed) this.SegmenterClass = SegmenterClass
      }, () => this.fail())
    }
  }

  setImage(image: HTMLImageElement | null): void {
    this.image = image
    this.invalidate()
  }

  setCamera(video: HTMLVideoElement | null): void {
    this.video = video
    this.invalidate()
  }

  getProcessedCanvas(): HTMLCanvasElement | null {
    return this.ready ? this.context?.canvas ?? null : null
  }

  processFrame(): void {
    const video = this.video
    if (!video || video.readyState < 2 || !video.videoWidth || !video.videoHeight ||
        !this.SegmenterClass || this.mode === 'none' || !this.available || this.pending ||
        (this.mode === 'image' && !this.image) || video.currentTime === this.lastVideoTime) return

    const revision = this.revision
    this.lastVideoTime = video.currentTime
    this.pending = this.segmentFrame(video, revision).catch(() => this.fail()).finally(() => {
      this.pending = null
    })
  }

  private async segmentFrame(video: HTMLVideoElement, revision: number): Promise<void> {
    if (this.mode === 'blur' && !('filter' in this.getContext())) {
      throw new Error('Canvas blur is unavailable.')
    }
    if (!this.segmenter && this.SegmenterClass) {
      this.segmenter = new this.SegmenterClass({ locateFile: (file) => cdn + file })
      this.segmenter.setOptions({ modelSelection: 1 })
    }
    this.segmenter?.onResults((results) => {
      if (revision === this.revision) this.composite(results, video.videoWidth, video.videoHeight)
    })
    await this.segmenter?.send({ image: video })
  }

  private getContext(): CanvasRenderingContext2D {
    this.context ??= document.createElement('canvas').getContext('2d')
    if (!this.context) throw new Error('Camera effects canvas is unavailable.')
    return this.context
  }

  private composite({ image, segmentationMask }: SegmentationResults, width: number, height: number): void {
    if (!width || !height) return
    const ctx = this.getContext()
    if (ctx.canvas.width !== width || ctx.canvas.height !== height) {
      ctx.canvas.width = width
      ctx.canvas.height = height
    }

    // Compose while the result images belong to the current MediaPipe frame.
    ctx.save()
    try {
      ctx.clearRect(0, 0, width, height)
      ctx.drawImage(segmentationMask, 0, 0, width, height)
      ctx.globalCompositeOperation = 'source-in'
      ctx.drawImage(image, 0, 0, width, height)
      ctx.globalCompositeOperation = 'destination-over'

      if (this.mode === 'blur') {
        ctx.filter = 'blur(12px)'
        // Extend the blurred image beyond the frame to keep its edges opaque.
        drawCovered(ctx, image, width, height, -24, -24, width + 48, height + 48)
      } else if (this.image) {
        drawCovered(ctx, this.image, this.image.naturalWidth, this.image.naturalHeight, 0, 0, width, height)
        ctx.fillStyle = '#090d16'
        ctx.fillRect(0, 0, width, height)
      }
      this.ready = true
    } finally {
      ctx.restore()
    }
  }

  private invalidate(): void {
    this.revision++
    this.lastVideoTime = -1
    this.ready = false
  }

  private fail(): void {
    if (this.disposed || !this.available) return
    this.available = false
    this.mode = 'none'
    this.invalidate()
    this.onUnavailable()
    this.closeSegmenter()
  }

  private closeSegmenter(): void {
    const segmenter = this.segmenter
    this.segmenter = null
    if (segmenter) {
      void (this.pending ?? Promise.resolve()).then(() => segmenter.close()).catch(() => {})
    }
  }

  dispose(): void {
    this.disposed = true
    this.setCamera(null)
    this.image = null
    this.context = null
    this.closeSegmenter()
  }
}
