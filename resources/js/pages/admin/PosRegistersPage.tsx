import { useEffect, useMemo, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import {
  AlertCircle,
  CheckCircle2,
  Copy,
  KeyRound,
  Loader2,
  MonitorSmartphone,
  Plus,
  RefreshCcw,
  Search,
  ShieldCheck,
  X,
  XCircle,
} from 'lucide-react';
import api from '@/lib/api';

type DeviceBindingStatus = 'ACTIVE' | 'PENDING_ACTIVATION' | 'INACTIVE';

interface Branch {
  id: number;
  name: string;
  static_ip?: string | null;
  staticIp?: string | null;
}

interface PosRegister {
  id: number;
  code: string;
  name: string;
  branch_id?: number;
  branch?: Branch | null;
  device_uuid?: string | null;
  deviceUuid?: string | null;
  status?: DeviceBindingStatus;
  device_binding_status?: DeviceBindingStatus;
}

interface BranchOption {
  id: number;
  name: string;
  static_ip?: string | null;
  staticIp?: string | null;
}

interface ApiListResponse<T> {
  data: T[] | { data?: T[] };
}

interface TokenResponse {
  data?: {
    token?: string;
    activation_token?: string;
    expires_in_minutes?: number;
  };
  token?: string;
  activation_token?: string;
  expires_in_minutes?: number;
}

interface AlertState {
  type: 'success' | 'error' | 'info';
  message: string;
}

interface CreateRegisterForm {
  branch_id: string;
  code: string;
  name: string;
}

const emptyForm: CreateRegisterForm = {
  branch_id: '',
  code: '',
  name: '',
};

function normalizeList<T>(response: ApiListResponse<T>): T[] {
  if (Array.isArray(response.data)) {
    return response.data;
  }

  return response.data.data ?? [];
}

function getStatus(register: PosRegister): DeviceBindingStatus {
  return register.device_binding_status ?? register.status ?? 'INACTIVE';
}

function getDeviceUuid(register: PosRegister): string | null {
  return register.device_uuid ?? register.deviceUuid ?? null;
}

function getBranchIp(branch?: Branch | BranchOption | null): string | null {
  return branch?.static_ip ?? branch?.staticIp ?? null;
}

function statusMeta(status: DeviceBindingStatus) {
  switch (status) {
    case 'ACTIVE':
      return {
        label: 'ACTIVE',
        icon: ShieldCheck,
        className: 'border-emerald-400/30 bg-emerald-400/10 text-emerald-200',
        dot: 'bg-emerald-300',
      };
    case 'PENDING_ACTIVATION':
      return {
        label: 'PENDING ACTIVATION',
        icon: KeyRound,
        className: 'border-amber-400/30 bg-amber-400/10 text-amber-200',
        dot: 'bg-amber-300',
      };
    default:
      return {
        label: 'INACTIVE',
        icon: XCircle,
        className: 'border-slate-500/40 bg-slate-700/50 text-slate-300',
        dot: 'bg-slate-400',
      };
  }
}

export default function PosRegistersPage() {
  const [registers, setRegisters] = useState<PosRegister[]>([]);
  const [branches, setBranches] = useState<BranchOption[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [form, setForm] = useState<CreateRegisterForm>(emptyForm);
  const [searchTerm, setSearchTerm] = useState('');
  const [alert, setAlert] = useState<AlertState | null>(null);
  const [activationToken, setActivationToken] = useState<{
    token: string;
    register: PosRegister;
    expiresInMinutes: number;
  } | null>(null);

  const filteredRegisters = useMemo(() => {
    const term = searchTerm.trim().toLowerCase();

    if (!term) {
      return registers;
    }

    return registers.filter((register) => {
      const branchName = register.branch?.name ?? '';
      const branchIp = getBranchIp(register.branch) ?? '';

      return [register.code, register.name, branchName, branchIp]
        .join(' ')
        .toLowerCase()
        .includes(term);
    });
  }, [registers, searchTerm]);

  const pendingCount = registers.filter((item) => getStatus(item) === 'PENDING_ACTIVATION').length;
  const activeCount = registers.filter((item) => getStatus(item) === 'ACTIVE').length;

  useEffect(() => {
    void fetchPageData();
  }, []);

  async function fetchPageData() {
    setIsLoading(true);
    setAlert(null);

    try {
      const [registersResponse, branchesResponse] = await Promise.all([
        api.get<ApiListResponse<PosRegister>>('/admin/pos-registers'),
        api.get<ApiListResponse<BranchOption>>('/admin/branches'),
      ]);

      setRegisters(normalizeList(registersResponse.data));
      setBranches(normalizeList(branchesResponse.data));
    } catch {
      setAlert({
        type: 'error',
        message: 'Unable to load POS registers. Please refresh and try again.',
      });
    } finally {
      setIsLoading(false);
    }
  }

  async function handleCreateRegister(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setIsSaving(true);
    setAlert(null);

    try {
      await api.post('/admin/pos-registers', {
        branch_id: Number(form.branch_id),
        code: form.code.trim(),
        name: form.name.trim(),
      });

      setAlert({ type: 'success', message: 'POS register created successfully.' });
      setForm(emptyForm);
      setIsCreateOpen(false);
      await fetchPageData();
    } catch {
      setAlert({
        type: 'error',
        message: 'Could not create the POS register. Check the fields and try again.',
      });
    } finally {
      setIsSaving(false);
    }
  }

  async function handleGenerateToken(register: PosRegister) {
    setBusyId(register.id);
    setAlert(null);

    try {
      const response = await api.post<TokenResponse>(`/admin/pos-registers/${register.id}/generate-token`);
      const payload = response.data.data ?? response.data;
      const token = payload.token ?? payload.activation_token;

      if (!token) {
        throw new Error('Missing activation token.');
      }

      setActivationToken({
        token,
        register,
        expiresInMinutes: payload.expires_in_minutes ?? 15,
      });

      setAlert({ type: 'success', message: 'Activation token generated.' });
      await fetchPageData();
    } catch {
      setAlert({
        type: 'error',
        message: 'Could not generate an activation token for this register.',
      });
    } finally {
      setBusyId(null);
    }
  }

  async function handleRevokeDevice(register: PosRegister) {
    const confirmed = window.confirm(
      `Reset device binding for ${register.code}? The current browser/device will lose access to this register.`
    );

    if (!confirmed) {
      return;
    }

    setBusyId(register.id);
    setAlert(null);

    try {
      await api.post(`/admin/pos-registers/${register.id}/revoke`);
      setAlert({ type: 'success', message: 'Device binding revoked. A new device can now be paired.' });
      await fetchPageData();
    } catch {
      setAlert({
        type: 'error',
        message: 'Could not revoke this device binding. Please try again.',
      });
    } finally {
      setBusyId(null);
    }
  }

  async function copyToken() {
    if (!activationToken?.token) {
      return;
    }

    await navigator.clipboard.writeText(activationToken.token);
    setAlert({ type: 'success', message: 'Activation token copied to clipboard.' });
  }

  return (
    <main dir="rtl" className="min-h-screen bg-slate-950 px-4 py-6 text-slate-100 sm:px-6 lg:px-8">
      <div className="mx-auto flex max-w-7xl flex-col gap-6">
        <header className="flex flex-col gap-4 border-b border-slate-800 pb-6 lg:flex-row lg:items-center lg:justify-between">
          <div className="space-y-2">
            <div className="inline-flex items-center gap-2 rounded-full border border-cyan-400/20 bg-cyan-400/10 px-3 py-1 text-xs font-medium text-cyan-200">
              <MonitorSmartphone className="h-4 w-4" />
              Admin POS Security
            </div>
            <div>
              <h1 className="text-2xl font-semibold tracking-normal text-white sm:text-3xl">
                POS Registers
              </h1>
              <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-400">
                Manage branch registers, activation tokens, and browser device bindings.
              </p>
            </div>
          </div>

          <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
            <button
              type="button"
              onClick={() => void fetchPageData()}
              className="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-700 bg-slate-900 px-4 text-sm font-medium text-slate-200 transition hover:border-slate-600 hover:bg-slate-800"
            >
              <RefreshCcw className="h-4 w-4" />
              Refresh
            </button>
            <button
              type="button"
              onClick={() => setIsCreateOpen(true)}
              className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-cyan-500 px-4 text-sm font-semibold text-slate-950 transition hover:bg-cyan-400"
            >
              <Plus className="h-4 w-4" />
              Add POS
            </button>
          </div>
        </header>

        {alert ? <AlertBanner alert={alert} onClose={() => setAlert(null)} /> : null}

        {activationToken ? (
          <section className="rounded-lg border border-cyan-400/25 bg-cyan-400/10 p-4 shadow-2xl shadow-cyan-950/20">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
              <div className="space-y-2">
                <div className="flex items-center gap-2 text-sm font-semibold text-cyan-100">
                  <KeyRound className="h-4 w-4" />
                  Activation token for {activationToken.register.code}
                </div>
                <div className="flex flex-wrap items-center gap-3">
                  <code className="rounded-md border border-cyan-300/30 bg-slate-950 px-4 py-2 text-xl font-bold tracking-[0.35em] text-cyan-100">
                    {activationToken.token}
                  </code>
                  <button
                    type="button"
                    onClick={() => void copyToken()}
                    className="inline-flex h-10 items-center gap-2 rounded-md border border-cyan-300/30 px-3 text-sm font-medium text-cyan-100 transition hover:bg-cyan-300/10"
                  >
                    <Copy className="h-4 w-4" />
                    Copy
                  </button>
                </div>
                <p className="text-sm text-cyan-100/80">
                  This token expires in {activationToken.expiresInMinutes} minutes. Share it only with the trusted device operator.
                </p>
              </div>
              <button
                type="button"
                onClick={() => setActivationToken(null)}
                className="inline-flex h-10 items-center justify-center rounded-md border border-cyan-300/30 px-3 text-sm text-cyan-100 transition hover:bg-cyan-300/10"
              >
                Dismiss
              </button>
            </div>
          </section>
        ) : null}

        <section className="grid gap-4 sm:grid-cols-3">
          <StatCard label="Total registers" value={registers.length} tone="cyan" />
          <StatCard label="Active devices" value={activeCount} tone="emerald" />
          <StatCard label="Pending activation" value={pendingCount} tone="amber" />
        </section>

        <section className="overflow-hidden rounded-lg border border-slate-800 bg-slate-900 shadow-2xl shadow-slate-950/40">
          <div className="flex flex-col gap-3 border-b border-slate-800 p-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <h2 className="text-base font-semibold text-white">Register inventory</h2>
              <p className="mt-1 text-sm text-slate-400">
                Device binding controls are intentionally restricted to this page.
              </p>
            </div>
            <label className="relative block w-full lg:w-80">
              <Search className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
              <input
                value={searchTerm}
                onChange={(event) => setSearchTerm(event.target.value)}
                placeholder="Search registers..."
                className="h-10 w-full rounded-md border border-slate-700 bg-slate-950 pr-10 pl-3 text-sm text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20"
              />
            </label>
          </div>

          {isLoading ? (
            <div className="flex min-h-80 items-center justify-center">
              <Loader2 className="h-8 w-8 animate-spin text-cyan-300" />
            </div>
          ) : filteredRegisters.length === 0 ? (
            <div className="flex min-h-80 flex-col items-center justify-center px-6 text-center">
              <MonitorSmartphone className="h-10 w-10 text-slate-600" />
              <h3 className="mt-4 text-base font-semibold text-slate-200">No POS registers found</h3>
              <p className="mt-2 max-w-md text-sm text-slate-500">
                Add a register or adjust your search filters.
              </p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-800 text-right">
                <thead className="bg-slate-950/60">
                  <tr>
                    <TableHead>POS Code</TableHead>
                    <TableHead>POS Name</TableHead>
                    <TableHead>Branch</TableHead>
                    <TableHead>Device Status</TableHead>
                    <TableHead>Device UUID</TableHead>
                    <TableHead className="text-left">Actions</TableHead>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-800">
                  {filteredRegisters.map((register) => {
                    const status = getStatus(register);
                    const deviceUuid = getDeviceUuid(register);
                    const branchIp = getBranchIp(register.branch);

                    return (
                      <tr key={register.id} className="transition hover:bg-slate-800/50">
                        <TableCell>
                          <span className="font-mono text-sm font-semibold text-cyan-100">{register.code}</span>
                        </TableCell>
                        <TableCell>
                          <div className="font-medium text-slate-100">{register.name}</div>
                        </TableCell>
                        <TableCell>
                          <div className="space-y-1">
                            <div className="text-sm font-medium text-slate-200">
                              {register.branch?.name ?? 'Unassigned'}
                            </div>
                            {branchIp ? (
                              <div className="font-mono text-xs text-slate-500">{branchIp}</div>
                            ) : (
                              <div className="text-xs text-slate-600">No static IP</div>
                            )}
                          </div>
                        </TableCell>
                        <TableCell>
                          <StatusBadge status={status} />
                        </TableCell>
                        <TableCell>
                          {deviceUuid ? (
                            <span className="inline-block max-w-48 truncate font-mono text-xs text-slate-400">
                              {deviceUuid}
                            </span>
                          ) : (
                            <span className="text-sm text-slate-600">Not bound</span>
                          )}
                        </TableCell>
                        <TableCell>
                          <div className="flex justify-end gap-2">
                            {status === 'PENDING_ACTIVATION' ? (
                              <button
                                type="button"
                                onClick={() => void handleGenerateToken(register)}
                                disabled={busyId === register.id}
                                className="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-amber-300/30 bg-amber-400/10 px-3 text-sm font-medium text-amber-100 transition hover:bg-amber-400/20 disabled:cursor-not-allowed disabled:opacity-60"
                              >
                                {busyId === register.id ? (
                                  <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                  <KeyRound className="h-4 w-4" />
                                )}
                                Generate Token
                              </button>
                            ) : null}

                            {status === 'ACTIVE' ? (
                              <button
                                type="button"
                                onClick={() => void handleRevokeDevice(register)}
                                disabled={busyId === register.id}
                                className="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-red-400/30 bg-red-500/10 px-3 text-sm font-medium text-red-200 transition hover:bg-red-500/20 disabled:cursor-not-allowed disabled:opacity-60"
                              >
                                {busyId === register.id ? (
                                  <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                  <RefreshCcw className="h-4 w-4" />
                                )}
                                Revoke Device
                              </button>
                            ) : null}

                            {status === 'INACTIVE' ? (
                              <span className="inline-flex h-9 items-center rounded-md border border-slate-700 px-3 text-sm text-slate-500">
                                Disabled
                              </span>
                            ) : null}
                          </div>
                        </TableCell>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </div>

      {isCreateOpen ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-sm">
          <div className="w-full max-w-lg rounded-lg border border-slate-800 bg-slate-900 shadow-2xl shadow-slate-950">
            <div className="flex items-center justify-between border-b border-slate-800 p-5">
              <div>
                <h2 className="text-lg font-semibold text-white">Add POS Register</h2>
                <p className="mt-1 text-sm text-slate-400">
                  Create a pending register that can later be paired with a device.
                </p>
              </div>
              <button
                type="button"
                onClick={() => setIsCreateOpen(false)}
                className="rounded-md p-2 text-slate-400 transition hover:bg-slate-800 hover:text-white"
              >
                <X className="h-5 w-5" />
              </button>
            </div>

            <form onSubmit={(event) => void handleCreateRegister(event)} className="space-y-4 p-5">
              <div>
                <label className="mb-2 block text-sm font-medium text-slate-200">Branch</label>
                <select
                  value={form.branch_id}
                  onChange={(event) => setForm((current) => ({ ...current, branch_id: event.target.value }))}
                  required
                  className="h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-3 text-sm text-slate-100 outline-none transition focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20"
                >
                  <option value="">Select branch</option>
                  {branches.map((branch) => (
                    <option key={branch.id} value={branch.id}>
                      {branch.name}
                    </option>
                  ))}
                </select>
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-2 block text-sm font-medium text-slate-200">POS Code</label>
                  <input
                    value={form.code}
                    onChange={(event) => setForm((current) => ({ ...current, code: event.target.value }))}
                    required
                    placeholder="POS-001"
                    className="h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-3 text-sm text-slate-100 outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20"
                  />
                </div>
                <div>
                  <label className="mb-2 block text-sm font-medium text-slate-200">POS Name</label>
                  <input
                    value={form.name}
                    onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
                    required
                    placeholder="Main Dining Register"
                    className="h-11 w-full rounded-md border border-slate-700 bg-slate-950 px-3 text-sm text-slate-100 outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20"
                  />
                </div>
              </div>

              <div className="flex flex-col-reverse gap-3 pt-2 sm:flex-row sm:justify-end">
                <button
                  type="button"
                  onClick={() => setIsCreateOpen(false)}
                  className="inline-flex h-10 items-center justify-center rounded-md border border-slate-700 px-4 text-sm font-medium text-slate-300 transition hover:bg-slate-800"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={isSaving}
                  className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-cyan-500 px-4 text-sm font-semibold text-slate-950 transition hover:bg-cyan-400 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {isSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />}
                  Create Register
                </button>
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </main>
  );
}

function AlertBanner({ alert, onClose }: { alert: AlertState; onClose: () => void }) {
  const Icon = alert.type === 'success' ? CheckCircle2 : alert.type === 'error' ? AlertCircle : KeyRound;
  const className =
    alert.type === 'success'
      ? 'border-emerald-400/25 bg-emerald-400/10 text-emerald-100'
      : alert.type === 'error'
        ? 'border-red-400/25 bg-red-500/10 text-red-100'
        : 'border-cyan-400/25 bg-cyan-400/10 text-cyan-100';

  return (
    <div className={`flex items-start justify-between gap-3 rounded-lg border p-4 ${className}`}>
      <div className="flex items-start gap-3">
        <Icon className="mt-0.5 h-5 w-5 shrink-0" />
        <p className="text-sm leading-6">{alert.message}</p>
      </div>
      <button type="button" onClick={onClose} className="rounded-md p-1 transition hover:bg-white/10">
        <X className="h-4 w-4" />
      </button>
    </div>
  );
}

function StatusBadge({ status }: { status: DeviceBindingStatus }) {
  const meta = statusMeta(status);
  const Icon = meta.icon;

  return (
    <span className={`inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold ${meta.className}`}>
      <span className={`h-2 w-2 rounded-full ${meta.dot}`} />
      <Icon className="h-3.5 w-3.5" />
      {meta.label}
    </span>
  );
}

function StatCard({ label, value, tone }: { label: string; value: number; tone: 'cyan' | 'emerald' | 'amber' }) {
  const toneClass = {
    cyan: 'border-cyan-400/20 bg-cyan-400/10 text-cyan-100',
    emerald: 'border-emerald-400/20 bg-emerald-400/10 text-emerald-100',
    amber: 'border-amber-400/20 bg-amber-400/10 text-amber-100',
  }[tone];

  return (
    <div className={`rounded-lg border p-4 ${toneClass}`}>
      <div className="text-sm font-medium opacity-80">{label}</div>
      <div className="mt-2 text-3xl font-semibold tracking-normal">{value}</div>
    </div>
  );
}

function TableHead({ children, className = '' }: { children: ReactNode; className?: string }) {
  return (
    <th className={`whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-normal text-slate-400 ${className}`}>
      {children}
    </th>
  );
}

function TableCell({ children }: { children: ReactNode }) {
  return <td className="whitespace-nowrap px-4 py-4 align-middle text-sm">{children}</td>;
}
