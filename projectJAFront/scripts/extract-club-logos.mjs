import sharp from 'sharp'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const brand = fileURLToPath(new URL('../src/assets/brand', import.meta.url))
const src = path.join(brand, 'clubes-logos.png')
const meta = await sharp(src).metadata()
console.log('source', src, meta.width, meta.height)

async function extractLogo(name, region) {
  const width = Math.min(region.width, (meta.width ?? 0) - region.left)
  const height = Math.min(region.height, (meta.height ?? 0) - region.top)
  await sharp(src)
    .extract({ left: region.left, top: region.top, width, height })
    .png()
    .toFile(path.join(brand, name))
}

await extractLogo('aventureros-club.png', { left: 2, top: 95, width: 158, height: 255 })
await extractLogo('guias-club.png', { left: 298, top: 88, width: 145, height: 230 })
console.log('Club logos extracted')
