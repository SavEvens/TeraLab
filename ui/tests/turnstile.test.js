// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

import { describe, expect, it } from 'vitest'
import { isInvisibleSiteKey } from '../src/turnstile'

describe('isInvisibleSiteKey', () => {
  it('treats 3x site keys as execute-on-demand widgets', () => {
    expect(isInvisibleSiteKey('3x00000000000000000000FF')).toBe(true)
    expect(isInvisibleSiteKey('  3x00000000000000000000FF')).toBe(true)
  })

  it('keeps managed and non-interactive keys on automatic tokens', () => {
    expect(isInvisibleSiteKey('1x00000000000000000000AA')).toBe(false)
    expect(isInvisibleSiteKey('2x00000000000000000000AB')).toBe(false)
    expect(isInvisibleSiteKey('site-key-1')).toBe(false)
    expect(isInvisibleSiteKey('')).toBe(false)
    expect(isInvisibleSiteKey(undefined)).toBe(false)
  })
})
