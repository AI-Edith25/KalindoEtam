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

/** Classic "SAY: ... ONLY" terbilang line — English words, ALL CAPS, cents spelled out separately. E.g. 5550001.11 -> "FIVE MILLION FIVE HUNDRED FIFTY THOUSAND ONE AND CENTS ELEVEN ONLY". */
export function terbilangUsd(amount: number | string): string {
  const rounded = Math.round(Number(amount) * 100) / 100
  const integerPart = Math.floor(rounded)
  const cents = Math.round((rounded - integerPart) * 100)
  const integerWords = integerToWords(integerPart)

  return cents > 0 ? `${integerWords} AND CENTS ${integerToWords(cents)} ONLY` : `${integerWords} ONLY`
}

/** Same as terbilangUsd() but always states the cents clause, even at zero (e.g. "SEVENTY THOUSAND AND CENTS ZERO ONLY") — General Journal print's own convention, unlike SO/DO/SI which omit it when cents are zero. */
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
