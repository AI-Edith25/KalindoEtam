// CI gate for the Chrome-109 (no oklch()/color-mix()/color(display-p3 …) support) regression class.
// The one failure mode that actually breaks on that browser is a CUSTOM PROPERTY (--foo: …)
// whose value is exactly one of those functions with no @supports/@media guard around it —
// browsers never fail to parse a custom-property declaration (the value is an opaque token
// stream), so there is no automatic "invalid, keep the previous declaration" fallback the way
// there is for a normal typed property (background-color: #fff; background-color: oklch(...);
// is safe as-is: the second line fails to parse in old Chrome and is dropped, so the hex line
// wins — that pattern needs no guard and this check intentionally does not flag it).
//
// Run: `node scripts/check-css-color-fallbacks.mjs` after `npm run build`.
import { readFileSync, readdirSync } from 'node:fs'

const cssFile = readdirSync('dist/assets').find((f) => f.endsWith('.css'))
if (!cssFile) throw new Error('dist/assets/*.css not found — run `npm run build` first')
const css = readFileSync(`dist/assets/${cssFile}`, 'utf8')

const RISKY_FUNCTION = /oklch\(|oklab\(|color-mix\(|color\(\s*display-p3/
const guardStack = []
const violations = []

// Single-pass scanner: walk character by character tracking `{`/`}` depth, remembering which
// opened blocks were `@supports`/`@media` (guards) vs anything else (selectors, `@layer`, …).
// At each top-level declaration end (`;` or `}` at guard-agnostic depth), check custom
// properties for the risky functions.
let i = 0
let buf = ''
let atRuleBuf = ''
let inAtRule = false
while (i < css.length) {
  const ch = css[i]
  if (ch === '@' && !inAtRule) {
    inAtRule = true
    atRuleBuf = '@'
  } else if (inAtRule) {
    atRuleBuf += ch
    if (ch === '{' || ch === ';') inAtRule = false
  }
  if (ch === '{') {
    const isGuard = /^@(supports|media)\b/.test(atRuleBuf.trim())
    guardStack.push(isGuard)
    buf = ''
  } else if (ch === '}') {
    guardStack.pop()
    buf = ''
  } else if (ch === ';') {
    const declMatch = buf.match(/(--[\w-]+)\s*:\s*(.+)$/s)
    if (declMatch && RISKY_FUNCTION.test(declMatch[2]) && !guardStack.some(Boolean)) {
      violations.push(`${declMatch[1]}: ${declMatch[2].slice(0, 80)}`)
    }
    buf = ''
  } else {
    buf += ch
  }
  i++
}

if (violations.length) {
  console.error(`✗ ${violations.length} unguarded custom-property color declaration(s) using oklch()/oklab()/color-mix()/color(display-p3 …):\n`)
  for (const v of violations.slice(0, 30)) console.error(`  ${v}`)
  console.error('\nThese resolve to `transparent`/inherited color in Chrome <111 (no @supports fallback protects a custom property — see this script\'s own doc comment). Wrap the enhanced value in `@supports (color: oklab(0% 0 0%)) { ... }` with a plain hex/rgb value outside it (postcss-preset-env\'s oklab-function feature does this automatically — check postcss.config.js is active).')
  process.exit(1)
}
console.log(`✓ No unguarded oklch()/oklab()/color-mix()/color(display-p3 …) custom-property declarations in ${cssFile}.`)
