// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

// The API rejects unsafe icon and picture values on write. These helpers keep
// legacy labs from turning a stored value into a same-origin request or an
// arbitrary third-party image load when the value is only read.

const ICON_BASENAME = /^[^/\\\x00-\x1f]+$/
const PICTURE_DATA = /^data:image\/(?:png|jpeg|gif|webp|svg\+xml);base64,[A-Za-z0-9+/]+={0,2}$/i

// Icons are static basenames under /images/icons/. Anything else falls back to
// the caller's default icon rather than producing a URL from the raw value.
export function safeIconSrc(value) {
  if (typeof value !== 'string' || value.includes('..') || /[%?#]/.test(value) || !ICON_BASENAME.test(value)) return ''
  return `/images/icons/${value}`
}

// Picture nodes accept an empty value, a base64 image data URI, or a static
// path under /images/. Reject query strings, fragments, traversal, encoded
// separators, and everything else so the value can never address /api/ or
// another origin.
export function safePictureSrc(value) {
  if (typeof value !== 'string' || !value) return ''
  if (PICTURE_DATA.test(value)) return value
  if (!value.startsWith('/images/')) return ''
  if (/[\\\x00-\x1f?#]/.test(value)) return ''
  // The server never writes into /images, but legacy labs may point at a
  // script that the web server would execute on this same-origin GET.
  if (/\.(?:php\d*|phtml|phar|phps|pht)$/i.test(value)) return ''

  const segments = value.split('/')
  // The leading slash produces one empty segment. Every later segment must be
  // a non-empty name, and encoded values are re-checked after decoding.
  for (let index = 1; index < segments.length; index += 1) {
    const segment = segments[index]
    if (!segment) return ''
    let decoded
    try {
      decoded = decodeURIComponent(segment)
    } catch {
      return ''
    }
    if (decoded === '.' || decoded === '..') return ''
    if (/[\\/\x00-\x1f?#]/.test(decoded)) return ''
  }

  return value
}
