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
