import { createCrudApi } from '@/shared/services/crudApi'
import type { TermsOfPayment, TermsOfPaymentFormValues } from '../types'

const termsOfPaymentCrud = createCrudApi<TermsOfPayment, TermsOfPaymentFormValues>('/terms-of-payments')

export const fetchTermsOfPayments = termsOfPaymentCrud.fetchList
export const createTermsOfPayment = termsOfPaymentCrud.create
export const updateTermsOfPayment = termsOfPaymentCrud.update
export const deleteTermsOfPayment = termsOfPaymentCrud.remove
