export interface PptxSlide {
  index: number
  title: string
  lines: string[]
}

export interface RenderedDeck {
  totalPages: number
  kind: 'pdf' | 'pptx'
  getPageCanvas: (page: number) => Promise<HTMLCanvasElement | null>
}

let pdfjsLib: any = null

async function getPdfJs() {
  if (!pdfjsLib) {
    pdfjsLib = await import(/* @vite-ignore */ 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.mjs')
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.worker.mjs'
  }
  return pdfjsLib
}

export function drawPptxSlideToCanvas(slide: PptxSlide, totalPages: number, deckName: string, width = 1280, height = 720): HTMLCanvasElement {
  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const ctx = canvas.getContext('2d')
  if (!ctx) return canvas

  // Clean editorial slide surface
  const grad = ctx.createLinearGradient(0, 0, width, height)
  grad.addColorStop(0, '#0f172a')
  grad.addColorStop(1, '#1e293b')
  ctx.fillStyle = grad
  ctx.fillRect(0, 0, width, height)

  // Accent bar
  ctx.fillStyle = '#10b981'
  ctx.fillRect(0, 0, width, 10)

  // Header metadata
  ctx.fillStyle = '#94a3b8'
  ctx.font = '600 20px system-ui, -apple-system, sans-serif'
  ctx.fillText(deckName.slice(0, 64), 64, 62)

  ctx.textAlign = 'right'
  ctx.fillText(`Slide ${slide.index} / ${totalPages}`, width - 64, 62)
  ctx.textAlign = 'left'

  // Slide title
  ctx.fillStyle = '#f8fafc'
  ctx.font = '700 46px system-ui, -apple-system, sans-serif'
  let y = drawWrappedText(ctx, slide.title || `Slide ${slide.index}`, 64, 150, width - 128, 56, 3)

  // Divider
  y += 16
  ctx.fillStyle = '#334155'
  ctx.fillRect(64, y, 140, 4)
  y += 44

  // Body lines
  ctx.fillStyle = '#e2e8f0'
  ctx.font = '400 28px system-ui, -apple-system, sans-serif'
  const lines = slide.lines.length > 0 ? slide.lines : ['(Visual slide — no extracted text)']
  for (const line of lines) {
    if (y > height - 72) break
    y = drawWrappedText(ctx, `•  ${line}`, 64, y, width - 128, 38, 3)
    y += 12
  }

  return canvas
}

function drawWrappedText(
  ctx: CanvasRenderingContext2D,
  text: string,
  x: number,
  y: number,
  maxWidth: number,
  lineHeight: number,
  maxLines: number,
): number {
  const words = text.split(/\s+/)
  let line = ''
  let linesDrawn = 0
  for (let i = 0; i < words.length; i++) {
    const test = line ? `${line} ${words[i]}` : words[i]
    if (ctx.measureText(test).width > maxWidth && line) {
      ctx.fillText(line, x, y)
      y += lineHeight
      linesDrawn++
      line = words[i]
      if (linesDrawn >= maxLines) return y
    } else {
      line = test
    }
  }
  if (line && linesDrawn < maxLines) {
    ctx.fillText(line, x, y)
    y += lineHeight
  }
  return y
}

export async function loadPdfDeck(url: string): Promise<RenderedDeck> {
  const lib = await getPdfJs()
  const res = await fetch(url, { credentials: 'same-origin' })
  if (!res.ok) throw new Error(`Failed to fetch PDF (${res.status})`)
  const data = new Uint8Array(await res.arrayBuffer())
  const doc = await lib.getDocument({ data }).promise
  const cache = new Map<number, HTMLCanvasElement>()

  return {
    totalPages: doc.numPages,
    kind: 'pdf',
    async getPageCanvas(pageNum: number) {
      const clamped = Math.max(1, Math.min(doc.numPages, pageNum))
      const cached = cache.get(clamped)
      if (cached) return cached

      const page = await doc.getPage(clamped)
      const base = page.getViewport({ scale: 1 })
      const scale = Math.min(1280 / base.width, 720 / base.height)
      const viewport = page.getViewport({ scale: Math.max(scale, 1) })
      const canvas = document.createElement('canvas')
      canvas.width = Math.floor(viewport.width)
      canvas.height = Math.floor(viewport.height)
      const ctx = canvas.getContext('2d')
      if (!ctx) return null
      ctx.fillStyle = '#ffffff'
      ctx.fillRect(0, 0, canvas.width, canvas.height)
      await page.render({ canvasContext: ctx, viewport }).promise
      cache.set(clamped, canvas)
      return canvas
    },
  }
}

export async function loadPptxDeck(url: string, deckName: string): Promise<RenderedDeck> {
  const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
  if (!res.ok) throw new Error(`Failed to load PPTX slides (${res.status})`)
  const payload = await res.json()
  const slides: PptxSlide[] = Array.isArray(payload.slides) && payload.slides.length > 0
    ? payload.slides
    : [{ index: 1, title: deckName, lines: ['No text slides were found in this presentation.'] }]
  const cache = new Map<number, HTMLCanvasElement>()

  return {
    totalPages: slides.length,
    kind: 'pptx',
    async getPageCanvas(pageNum: number) {
      const clamped = Math.max(1, Math.min(slides.length, pageNum))
      const cached = cache.get(clamped)
      if (cached) return cached
      const canvas = drawPptxSlideToCanvas(slides[clamped - 1], slides.length, deckName)
      cache.set(clamped, canvas)
      return canvas
    },
  }
}
