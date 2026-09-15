import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { apiRequest, hasPermission, useAppContext } from './core'

export const SITE_DETAILS_DELETE_OPERATION_ID = 'AIMW-BILL-BE4B8C3822'

type SiteSummary = { id?: number | string }

export function SiteDetailsDeleteControl({ siteId }: { siteId: number }) {
  const context = useAppContext()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [confirming, setConfirming] = useState(false)

  const numericSiteId = Number(siteId)
  const expectedEndpoint = `/api/tenants/${context.tenant.slug}/sites/${numericSiteId}`
  const expectedListEndpoint = `/api/tenants/${context.tenant.slug}/sites`
  const advertisedEndpoint = context.api[`sites.detail.${numericSiteId}`]
  const advertisedListEndpoint = context.api.sites
  const canManage = hasPermission(context, 'sites.manage')

  const mutation = useMutation({
    mutationFn: async () => {
      await apiRequest(advertisedEndpoint, { method: 'DELETE' })
      const sites = await apiRequest<SiteSummary[]>(advertisedListEndpoint)
      if (!Array.isArray(sites) || sites.some((site) => Number(site?.id) === numericSiteId)) {
        throw new Error('Site deletion could not be authoritatively verified.')
      }
      return sites
    },
    onSuccess: async (sites) => {
      queryClient.setQueryData(['workspace', context.tenant.slug, 'sites'], sites)
      await queryClient.invalidateQueries({ queryKey: ['workspace', context.tenant.slug, 'sites'] })
      context.toast.success('Site deleted')
      navigate('/sites')
    },
    onError: (error) => {
      context.toast.error(error instanceof Error ? error.message : 'Unable to delete site')
    },
  })

  if (
    !canManage
    || !Number.isInteger(numericSiteId)
    || numericSiteId < 1
    || advertisedEndpoint !== expectedEndpoint
    || advertisedListEndpoint !== expectedListEndpoint
  ) return null

  return (
    <section aria-label="Delete site" data-operation-id={SITE_DETAILS_DELETE_OPERATION_ID}>
      {!confirming ? (
        <button type="button" className="btn btn-danger" onClick={() => setConfirming(true)} disabled={mutation.isPending}>
          Delete site
        </button>
      ) : (
        <div role="alertdialog" aria-modal="true" aria-labelledby="delete-site-title">
          <strong id="delete-site-title">Delete this site?</strong>
          <p>This permanently removes the site from this tenant. Active queued or running executions must finish first.</p>
          <button type="button" className="btn btn-danger" onClick={() => mutation.mutate()} disabled={mutation.isPending}>
            {mutation.isPending ? 'Deleting…' : 'Confirm delete'}
          </button>
          <button type="button" className="btn" onClick={() => setConfirming(false)} disabled={mutation.isPending}>
            Cancel
          </button>
        </div>
      )}
    </section>
  )
}
