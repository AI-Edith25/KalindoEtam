import { useEffect } from 'react'
import { Providers } from './Providers'
import { AppRouter } from './router'
import { reportBrowserDiagnostics } from '@/shared/lib/browserDiagnostics'

export function App() {
  useEffect(() => {
    reportBrowserDiagnostics()
  }, [])

  return (
    <Providers>
      <AppRouter />
    </Providers>
  )
}
