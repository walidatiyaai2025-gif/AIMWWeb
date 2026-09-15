import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AppContextProvider, type AppContext } from '../core'
import { SITE_DETAILS_DELETE_OPERATION_ID, SiteDetailsDeleteControl } from '../site-details-delete-control'

const navigate = vi.fn()
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return { ...actual, useNavigate: () => navigate }
})

function context(overrides: Partial<AppContext> = {}): AppContext {
  return {
    user: { id: 1, name: 'Owner', email: 'owner@example.test' },
    tenant: { id: 7, slug: 'alpha', name: 'Alpha' },
    permissions: ['sites.manage'],
    api: {
      sites: '/api/tenants/alpha/sites',
      'sites.detail.42': '/api/tenants/alpha/sites/42',
    },
    toast: { success: vi.fn(), error: vi.fn() },
    ...overrides,
  }
}

function renderControl(value = context()) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  render(
    <MemoryRouter>
      <QueryClientProvider client={queryClient}>
        <AppContextProvider value={value}>
          <SiteDetailsDeleteControl siteId={42} />
        </AppContextProvider>
      </QueryClientProvider>
    </MemoryRouter>,
  )
  return { queryClient }
}

afterEach(() => {
  vi.restoreAllMocks()
  navigate.mockReset()
})

describe('site details delete control terminality', () => {
  it('deletes only after confirmation and authoritative reread proves absence', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(new Response(JSON.stringify([{ id: 7 }]), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    const value = context()
    renderControl(value)

    expect(screen.getByLabelText('Delete site')).toHaveAttribute('data-operation-id', SITE_DETAILS_DELETE_OPERATION_ID)
    fireEvent.click(screen.getByRole('button', { name: 'Delete site' }))
    fireEvent.click(screen.getByRole('button', { name: 'Confirm delete' }))

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))
    expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/tenants/alpha/sites/42')
    expect(fetchMock.mock.calls[0]?.[1]).toMatchObject({ method: 'DELETE' })
    expect(fetchMock.mock.calls[1]?.[0]).toBe('/api/tenants/alpha/sites')
    await waitFor(() => expect(value.toast.success).toHaveBeenCalledWith('Site deleted'))
    expect(navigate).toHaveBeenCalledWith('/sites')
  })

  it('fails closed when authoritative reread still contains the deleted id', async () => {
    vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(new Response(JSON.stringify([{ id: 42 }]), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    const value = context()
    renderControl(value)
    fireEvent.click(screen.getByRole('button', { name: 'Delete site' }))
    fireEvent.click(screen.getByRole('button', { name: 'Confirm delete' }))

    await waitFor(() => expect(value.toast.error).toHaveBeenCalled())
    expect(value.toast.success).not.toHaveBeenCalled()
    expect(navigate).not.toHaveBeenCalled()
  })

  it('cancel performs no mutation', () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch')
    renderControl()
    fireEvent.click(screen.getByRole('button', { name: 'Delete site' }))
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('does not render without sites.manage', () => {
    renderControl(context({ permissions: ['sites.view'] }))
    expect(screen.queryByLabelText('Delete site')).not.toBeInTheDocument()
  })

  it('fails closed when an advertised endpoint escapes the active tenant', () => {
    renderControl(context({ api: { sites: '/api/tenants/alpha/sites', 'sites.detail.42': '/api/tenants/beta/sites/42' } }))
    expect(screen.queryByLabelText('Delete site')).not.toBeInTheDocument()
  })

  it('fails closed when the advertised reread endpoint escapes the active tenant', () => {
    renderControl(context({ api: { sites: '/api/tenants/beta/sites', 'sites.detail.42': '/api/tenants/alpha/sites/42' } }))
    expect(screen.queryByLabelText('Delete site')).not.toBeInTheDocument()
  })
})
