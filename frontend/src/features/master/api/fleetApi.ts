import { createCrudApi } from '@/shared/services/crudApi'
import { fetchLookupList } from '@/shared/services/lookupApi'
import type { Fleet, FleetFormValues } from '../types'

const fleetCrud = createCrudApi<Fleet, FleetFormValues>('/fleets')

export const fetchFleets = fleetCrud.fetchList
export const createFleet = fleetCrud.create
export const updateFleet = fleetCrud.update
export const deleteFleet = fleetCrud.remove

/** Delivery form's Fleet dropdown — active fleets only, see FleetController::index(active_only=true). */
export const fetchActiveFleetsLookup = () => fetchLookupList<Fleet>('/fleets', { active_only: 'true' })
