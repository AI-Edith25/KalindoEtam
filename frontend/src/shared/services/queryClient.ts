import { MutationCache, QueryCache, QueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { getErrorMessage } from './errorHandler'

/**
 * Every failed query/mutation surfaces a toast automatically — this is
 * what makes BusinessException responses show up as user-friendly
 * notifications app-wide without every page repeating the same onError.
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      refetchOnWindowFocus: false,
      staleTime: 30_000,
    },
  },
  queryCache: new QueryCache({
    onError: (error) => {
      const message = getErrorMessage(error)
  
      if (!message) return
  
      toast.error(message)
    },
  }),
  mutationCache: new MutationCache({
    onError: (error) => {
      const message = getErrorMessage(error)
  
      if (!message) return
  
      toast.error(message)
    },
  }),
})
