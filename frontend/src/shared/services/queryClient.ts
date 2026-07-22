import { MutationCache, QueryCache, QueryClient } from '@tanstack/react-query'
import { toastApiError } from './errorHandler'

/**
 * Every failed query/mutation surfaces a toast automatically — this is
 * what makes BusinessException responses show up as user-friendly
 * notifications app-wide without every page repeating the same onError.
 * toastApiError itself skips 403s (see errorHandler.ts) since those mean
 * the UI offered something the user's permissions don't allow.
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      refetchOnWindowFocus: false,
      staleTime: 30_000,
    },
  },
  queryCache: new QueryCache({ onError: toastApiError }),
  mutationCache: new MutationCache({ onError: toastApiError }),
})
