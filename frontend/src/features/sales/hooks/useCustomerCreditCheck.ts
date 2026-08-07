import { useQuery } from '@tanstack/react-query'
import { fetchCustomerCreditStatus } from '../api/salesOrderApi'
import { evaluateCreditBlock } from '../lib/customerCredit'

/**
 * Shared by every screen that can create/submit a Sales Order (New/Edit
 * Sales Order, Sales Order Detail's own Submit button) — one query per
 * customer, refetched only when the customer changes; orderAmount is pure
 * client-side math against the already-fetched outstanding/limit, so typing
 * in a line item doesn't trigger a network call.
 */
export function useCustomerCreditCheck(customerId: string | undefined, orderAmount: number) {
  const query = useQuery({
    queryKey: ['customer-credit-status', customerId],
    queryFn: () => fetchCustomerCreditStatus(customerId!),
    enabled: !!customerId,
  })

  const { blocked, message } = query.data ? evaluateCreditBlock(query.data, orderAmount) : { blocked: false, message: '' }

  return { creditStatus: query.data, isLoading: query.isLoading, blocked, message }
}
