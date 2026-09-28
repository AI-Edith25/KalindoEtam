// Runs after @tailwindcss/vite generates Tailwind's own CSS. Transpiles the oklch()/color-mix()
// syntax Tailwind v4 emits (both unsupported before Chrome 111) down to rgb()/static-mix
// equivalents for browsers in .browserslistrc, with an @supports-gated modern version left in
// place for browsers that do support them — see feedback_css_oklch_chrome109_fallback memory.
export default {
  plugins: {
    'postcss-preset-env': {
      features: {
        'oklab-function': { preserve: true },
        'color-mix': { preserve: true },
      },
    },
  },
}
