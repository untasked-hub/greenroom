export type StageSource = 'slide' | 'screen'
export type SpeakerLayout = 'sidebar' | 'overlay' | 'hidden'

export interface CompositorSources {
  stageSource: StageSource
  speakerLayout: SpeakerLayout
  slideCanvas: HTMLCanvasElement | null
  screenVideo: HTMLVideoElement | null
  cameraVideo: HTMLVideoElement | null
  processedCamera?: HTMLCanvasElement | null
  roomTitle: string
  isLive: boolean
  speakerName: string
}

/**
 * Draws one 1280x720 broadcast frame combining the selected main stage
 * (slide canvas or screen-share video) and the speaker camera thumbnail
 * (either in a dedicated right sidebar or stacked overlay on top of the stage).
 */
export function drawStageFrame(
  ctx: CanvasRenderingContext2D,
  width: number,
  height: number,
  s: CompositorSources,
): void {
  ctx.fillStyle = '#090d16'
  ctx.fillRect(0, 0, width, height)

  const video = s.cameraVideo
  const hasCamera = !!(video && video.readyState >= 2 && video.videoWidth > 0)
  const processed = s.processedCamera
  const camera = processed && processed.width > 0 && processed.height > 0 ? processed : video
  const cameraWidth = camera === video ? video?.videoWidth ?? 0 : processed?.width ?? 0
  const cameraHeight = camera === video ? video?.videoHeight ?? 0 : processed?.height ?? 0
  const hasScreen = !!(s.screenVideo && s.screenVideo.readyState >= 2 && s.screenVideo.videoWidth > 0)
  const sidebarActive = s.speakerLayout === 'sidebar' && hasCamera

  const sidebarW = sidebarActive ? 300 : 0
  const stageW = width - sidebarW
  const stageH = height

  // 1. Main stage (slide or screen)
  if (s.stageSource === 'screen' && hasScreen && s.screenVideo) {
    drawContained(ctx, s.screenVideo, s.screenVideo.videoWidth, s.screenVideo.videoHeight, 0, 0, stageW, stageH)
  } else if (s.slideCanvas) {
    drawContained(ctx, s.slideCanvas, s.slideCanvas.width, s.slideCanvas.height, 0, 0, stageW, stageH)
  } else if (hasScreen && s.screenVideo) {
    drawContained(ctx, s.screenVideo, s.screenVideo.videoWidth, s.screenVideo.videoHeight, 0, 0, stageW, stageH)
  } else if (hasCamera && camera && s.speakerLayout !== 'hidden') {
    // Fallback: if neither slide nor screen is active, show speaker camera full-bleed
    drawCovered(ctx, camera, cameraWidth, cameraHeight, 0, 0, stageW, stageH)
  } else {
    drawEmptyStage(ctx, stageW, stageH, s.roomTitle, s.stageSource)
  }

  // 2. Speaker camera sidebar or stacked overlay
  if (hasCamera && camera && s.speakerLayout === 'sidebar') {
    const pad = 16
    const panelX = stageW
    ctx.fillStyle = '#0f172a'
    ctx.fillRect(panelX, 0, sidebarW, height)
    ctx.fillStyle = '#1e293b'
    ctx.fillRect(panelX, 0, 2, height)

    ctx.fillStyle = '#94a3b8'
    ctx.font = '600 13px system-ui, -apple-system, sans-serif'
    ctx.fillText('SPEAKER', panelX + pad, 30)

    const camW = sidebarW - pad * 2
    const camH = Math.round(camW * 0.75)
    const camX = panelX + pad
    const camY = 44
    drawRoundedVideo(ctx, camera, cameraWidth, cameraHeight, camX, camY, camW, camH, 12, s.speakerName || 'Presenter')
  } else if (hasCamera && camera && s.speakerLayout === 'overlay') {
    const camW = 264
    const camH = 160
    const pad = 20
    const camX = width - camW - pad
    const camY = height - camH - pad
    drawRoundedVideo(ctx, camera, cameraWidth, cameraHeight, camX, camY, camW, camH, 14, s.speakerName || 'Presenter')
  }

  // 3. Subtle LIVE badge when broadcasting
  if (s.isLive) {
    ctx.save()
    ctx.fillStyle = 'rgba(220, 38, 38, 0.92)'
    roundRect(ctx, 20, 20, 82, 30, 8)
    ctx.fill()
    ctx.fillStyle = '#ffffff'
    ctx.beginPath()
    ctx.arc(36, 35, 5, 0, Math.PI * 2)
    ctx.fill()
    ctx.font = '700 13px system-ui, -apple-system, sans-serif'
    ctx.fillText('LIVE', 48, 40)
    ctx.restore()
  }
}

function drawEmptyStage(
  ctx: CanvasRenderingContext2D,
  w: number,
  h: number,
  title: string,
  source: StageSource,
): void {
  const grad = ctx.createLinearGradient(0, 0, w, h)
  grad.addColorStop(0, '#0f172a')
  grad.addColorStop(1, '#1e293b')
  ctx.fillStyle = grad
  ctx.fillRect(0, 0, w, h)

  ctx.fillStyle = '#f8fafc'
  ctx.font = '700 36px system-ui, -apple-system, sans-serif'
  ctx.textAlign = 'center'
  ctx.fillText(title.slice(0, 48), w / 2, h / 2 - 14)

  ctx.fillStyle = '#94a3b8'
  ctx.font = '400 20px system-ui, -apple-system, sans-serif'
  const hint = source === 'screen'
    ? 'Click "Share screen" to put your screen on the main stage'
    : 'Upload and select a PDF or PPTX deck to put slides on stage'
  ctx.fillText(hint, w / 2, h / 2 + 26)
  ctx.textAlign = 'left'
}

function drawContained(
  ctx: CanvasRenderingContext2D,
  source: CanvasImageSource,
  sw: number,
  sh: number,
  dx: number,
  dy: number,
  dw: number,
  dh: number,
): void {
  if (!sw || !sh) return
  const scale = Math.min(dw / sw, dh / sh)
  const rw = sw * scale
  const rh = sh * scale
  const rx = dx + (dw - rw) / 2
  const ry = dy + (dh - rh) / 2
  ctx.drawImage(source, rx, ry, rw, rh)
}

export function drawCovered(
  ctx: CanvasRenderingContext2D,
  source: CanvasImageSource,
  sw: number,
  sh: number,
  dx: number,
  dy: number,
  dw: number,
  dh: number,
): void {
  if (!sw || !sh) return
  const scale = Math.max(dw / sw, dh / sh)
  const cw = dw / scale
  const ch = dh / scale
  const sx = (sw - cw) / 2
  const sy = (sh - ch) / 2
  ctx.drawImage(source, sx, sy, cw, ch, dx, dy, dw, dh)
}

function drawRoundedVideo(
  ctx: CanvasRenderingContext2D,
  source: CanvasImageSource,
  sourceWidth: number,
  sourceHeight: number,
  x: number,
  y: number,
  w: number,
  h: number,
  r: number,
  label: string,
): void {
  ctx.save()
  roundRect(ctx, x, y, w, h, r)
  ctx.clip()
  drawCovered(ctx, source, sourceWidth, sourceHeight, x, y, w, h)

  // Bottom name pill
  ctx.fillStyle = 'rgba(15, 23, 42, 0.72)'
  ctx.fillRect(x, y + h - 28, w, 28)
  ctx.fillStyle = '#f8fafc'
  ctx.font = '600 13px system-ui, -apple-system, sans-serif'
  ctx.fillText(label.slice(0, 26), x + 10, y + h - 9)
  ctx.restore()

  ctx.save()
  ctx.strokeStyle = 'rgba(148, 163, 184, 0.35)'
  ctx.lineWidth = 2
  roundRect(ctx, x, y, w, h, r)
  ctx.stroke()
  ctx.restore()
}

function roundRect(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  r: number,
): void {
  ctx.beginPath()
  ctx.moveTo(x + r, y)
  ctx.arcTo(x + w, y, x + w, y + h, r)
  ctx.arcTo(x + w, y + h, x, y + h, r)
  ctx.arcTo(x, y + h, x, y, r)
  ctx.arcTo(x, y, x + w, y, r)
  ctx.closePath()
}
