const ONES = [
  '',
  'ONE',
  'TWO',
  'THREE',
  'FOUR',
  'FIVE',
  'SIX',
  'SEVEN',
  'EIGHT',
  'NINE',
  'TEN',
  'ELEVEN',
  'TWELVE',
  'THIRTEEN',
  'FOURTEEN',
  'FIFTEEN',
  'SIXTEEN',
  'SEVENTEEN',
  'EIGHTEEN',
  'NINETEEN',
]
const TENS = ['', '', 'TWENTY', 'THIRTY', 'FORTY', 'FIFTY', 'SIXTY', 'SEVENTY', 'EIGHTY', 'NINETY']
const SCALES = ['', 'THOUSAND', 'MILLION', 'BILLION', 'TRILLION']

function threeDigitsToWords(n: number): string {
  const parts: string[] = []
  const hundreds = Math.floor(n / 100)
  const remainder = n % 100

  if (hundreds > 0) parts.push(`${ONES[hundreds]} HUNDRED`)
  if (remainder > 0) {
    if (remainder < 20) {
      parts.push(ONES[remainder])
    } else {
      const tens = Math.floor(remainder / 10)
      const ones = remainder % 10
      parts.push(TENS[tens])
      if (ones > 0) parts.push(ONES[ones])
    }
  }

  return parts.join(' ')
}

function integerToWords(n: number): string {
  if (n === 0) return 'ZERO'

  const groups: number[] = []
  let remaining = n
  while (remaining > 0) {
    groups.push(remaining % 1000)
    remaining = Math.floor(remaining / 1000)
  }

  const parts: string[] = []
  for (let i = groups.length - 1; i >= 0; i--) {
    if (groups[i] === 0) continue
    const words = threeDigitsToWords(groups[i])
    parts.push(SCALES[i] ? `${words} ${SCALES[i]}` : words)
  }

  return parts.join(' ')
}

/** Sales Order print's own "RP : ..." terbilang line only (grep-verified: no other page imports
    this) — English words, ALL CAPS, compound tens hyphenated ("NINETY-NINE", not "NINETY NINE"),
    integer part only. Both the hyphenation and the dropped cents/"ONLY" were confirmed directly off
    SalesOrder.pdf's own text layer: its sample amount has a non-zero cents remainder (7,399,999.26)
    yet the terbilang line prints only "...NINE HUNDRED NINETY-NINE" with nothing after — the PDF
    itself never states a cents clause here, not just when cents happen to be zero. Deliberately NOT
    built on threeDigitsToWords/integerToWords/ONES/TENS's plain (space-joined) compounds — those
    stay exactly as-is for terbilangUsdWithCents below (General Journal print), which this must not
    affect. */
export function terbilangUsd(amount: number | string): string {
  function threeDigitsHyphenated(n: number): string {
    const parts: string[] = []
    const hundreds = Math.floor(n / 100)
    const remainder = n % 100
    if (hundreds > 0) parts.push(`${ONES[hundreds]} HUNDRED`)
    if (remainder > 0) {
      if (remainder < 20) {
        parts.push(ONES[remainder])
      } else {
        const tens = Math.floor(remainder / 10)
        const ones = remainder % 10
        parts.push(ones > 0 ? `${TENS[tens]}-${ONES[ones]}` : TENS[tens])
      }
    }
    return parts.join(' ')
  }

  function integerToWordsHyphenated(n: number): string {
    if (n === 0) return 'ZERO'
    const groups: number[] = []
    let remaining = n
    while (remaining > 0) {
      groups.push(remaining % 1000)
      remaining = Math.floor(remaining / 1000)
    }
    const parts: string[] = []
    for (let i = groups.length - 1; i >= 0; i--) {
      if (groups[i] === 0) continue
      const words = threeDigitsHyphenated(groups[i])
      parts.push(SCALES[i] ? `${words} ${SCALES[i]}` : words)
    }
    return parts.join(' ')
  }

  return integerToWordsHyphenated(Math.floor(Number(amount)))
}

/** Classic "SAY: ... ONLY" terbilang shape (space-joined compounds, "AND CENTS ... ONLY" clause always stated) — General Journal print's own convention, e.g. "SEVENTY THOUSAND AND CENTS ZERO ONLY". Unrelated to terbilangUsd above (Sales Order only), which now differs in every one of these respects. */
export function terbilangUsdWithCents(amount: number | string): string {
  const rounded = Math.round(Number(amount) * 100) / 100
  const integerPart = Math.floor(rounded)
  const cents = Math.round((rounded - integerPart) * 100)

  return `${integerToWords(integerPart)} AND CENTS ${integerToWords(cents)} ONLY`
}

const ID_SATUAN = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas']

/** Classic recursive Indonesian terbilang — handles the "se-" irregulars (sebelas/seratus/seribu) a naive digit-by-digit reader would get wrong. Rupiah has no spelled-out subunit, unlike terbilangUsd's cents clause. */
function angkaToKata(n: number): string {
  if (n < 12) return ID_SATUAN[n]
  if (n < 20) return `${angkaToKata(n - 10)} belas`
  if (n < 100) return `${angkaToKata(Math.floor(n / 10))} puluh${n % 10 !== 0 ? ` ${angkaToKata(n % 10)}` : ''}`
  if (n < 200) return `seratus${n - 100 !== 0 ? ` ${angkaToKata(n - 100)}` : ''}`
  if (n < 1000) return `${angkaToKata(Math.floor(n / 100))} ratus${n % 100 !== 0 ? ` ${angkaToKata(n % 100)}` : ''}`
  if (n < 2000) return `seribu${n - 1000 !== 0 ? ` ${angkaToKata(n - 1000)}` : ''}`
  if (n < 1_000_000) return `${angkaToKata(Math.floor(n / 1000))} ribu${n % 1000 !== 0 ? ` ${angkaToKata(n % 1000)}` : ''}`
  if (n < 1_000_000_000) return `${angkaToKata(Math.floor(n / 1_000_000))} juta${n % 1_000_000 !== 0 ? ` ${angkaToKata(n % 1_000_000)}` : ''}`
  if (n < 1_000_000_000_000) return `${angkaToKata(Math.floor(n / 1_000_000_000))} miliar${n % 1_000_000_000 !== 0 ? ` ${angkaToKata(n % 1_000_000_000)}` : ''}`
  return `${angkaToKata(Math.floor(n / 1_000_000_000_000))} triliun${n % 1_000_000_000_000 !== 0 ? ` ${angkaToKata(n % 1_000_000_000_000)}` : ''}`
}

/** Indonesian terbilang, ALL CAPS, e.g. 1470000 -> "SATU JUTA EMPAT RATUS TUJUH PULUH RIBU RUPIAH". Rounds to the nearest Rupiah — no sen clause, unlike terbilangUsd's cents. */
export function terbilangIdr(amount: number | string): string {
  const rounded = Math.round(Number(amount))
  if (rounded === 0) return 'NOL RUPIAH'
  return `${angkaToKata(rounded)} rupiah`.toUpperCase()
}
