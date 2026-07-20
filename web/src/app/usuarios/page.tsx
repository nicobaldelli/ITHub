'use client';

import { useState } from 'react';
import Link from 'next/link';
import { Search, Plus, Pencil, KeyRound, UserX, UserCheck } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api';
import { AppShell } from '@/components/layout/AppShell';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogFooter } from '@/components/ui/dialog';
import { PasswordTemporalModal } from '@/components/usuarios/PasswordTemporalModal';
import { useUsuarios, useUsuarioMutations, type UsuariosFilters } from '@/hooks/useUsuarios';
import { useAuthStore } from '@/stores/auth';
import { rolLabel, ROLES } from '@/lib/usuario-schema';
import { dateTime } from '@/lib/format';
import type { Usuario } from '@/types/usuario';

export default function UsuariosPage() {
  const [filters, setFilters] = useState<UsuariosFilters>({ page: 1, per_page: 25 });
  const [searchInput, setSearchInput] = useState('');
  const { data, meta, loading, error, reload } = useUsuarios(filters);
  const yo = useAuthStore((s) => s.user);
  const esAdmin = yo?.rol === 'admin';

  const [modal, setModal] = useState<
    | null
    | { kind: 'reset'; user: Usuario }
    | { kind: 'desactivar'; user: Usuario }
    | { kind: 'pwd'; user: Usuario; password: string }
  >(null);

  if (!esAdmin) {
    return (
      <AppShell title="Usuarios">
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          Solo los administradores pueden gestionar usuarios.
        </div>
      </AppShell>
    );
  }

  function setFilter(patch: Partial<UsuariosFilters>) {
    setFilters((f) => ({ ...f, ...patch, page: 1 }));
  }

  return (
    <AppShell title="Usuarios">
      <div className="mb-4 flex justify-end">
        <Link href="/usuarios/nuevo">
          <Button>
            <Plus className="h-4 w-4" />
            Nuevo usuario
          </Button>
        </Link>
      </div>

      <Card className="mb-4 p-4">
        <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
          <div className="md:col-span-2">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
              <Input
                placeholder="Buscar por nombre o email..."
                value={searchInput}
                onChange={(e) => setSearchInput(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && setFilter({ search: searchInput || undefined })}
                className="pl-9"
              />
            </div>
          </div>
          <select
            className="input-base"
            value={filters.rol ?? ''}
            onChange={(e) => setFilter({ rol: (e.target.value as UsuariosFilters['rol']) || '' })}
          >
            <option value="">Todos los roles</option>
            {ROLES.map((r) => (
              <option key={r} value={r}>
                {rolLabel(r)}
              </option>
            ))}
          </select>
          <select
            className="input-base"
            value={filters.activo === undefined || filters.activo === '' ? '' : String(filters.activo)}
            onChange={(e) => {
              const v = e.target.value;
              setFilter({ activo: v === '' ? '' : v === 'true' });
            }}
          >
            <option value="">Activos e inactivos</option>
            <option value="true">Solo activos</option>
            <option value="false">Solo inactivos</option>
          </select>
        </div>
        {meta.total !== undefined && (
          <div className="mt-3 text-xs text-neutral-500">
            {meta.total} usuario{meta.total === 1 ? '' : 's'}
          </div>
        )}
      </Card>

      <Card className="overflow-hidden">
        {loading && <div className="p-8 text-center text-neutral-500">Cargando…</div>}
        {error && (
          <div className="border-b border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            {error}
          </div>
        )}
        {!loading && data.length === 0 && (
          <div className="p-8 text-center text-neutral-500">Sin usuarios para mostrar</div>
        )}
        {!loading && data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                  <th className="px-4 py-3 font-medium">Nombre</th>
                  <th className="px-4 py-3 font-medium">Email</th>
                  <th className="px-4 py-3 font-medium">Rol</th>
                  <th className="px-4 py-3 font-medium">Estado</th>
                  <th className="px-4 py-3 font-medium">Último login</th>
                  <th className="px-4 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-neutral-100">
                {data.map((u) => {
                  const esYo = u.id === yo?.id;
                  return (
                    <tr key={u.id} className="hover:bg-neutral-50">
                      <td className="px-4 py-3 font-medium">
                        {u.nombre} {u.apellido}
                        {esYo && (
                          <span className="ml-2 text-xs text-neutral-400">(vos)</span>
                        )}
                        {u.must_change_password && (
                          <div className="text-xs text-amber-600">Debe cambiar password</div>
                        )}
                      </td>
                      <td className="px-4 py-3 text-neutral-600">{u.email}</td>
                      <td className="px-4 py-3">
                        <Badge variant={u.rol === 'admin' ? 'primary' : 'outline'}>
                          {rolLabel(u.rol)}
                        </Badge>
                      </td>
                      <td className="px-4 py-3">
                        <Badge variant={u.activo ? 'success' : 'neutral'}>
                          {u.activo ? 'Activo' : 'Inactivo'}
                        </Badge>
                      </td>
                      <td className="px-4 py-3 text-xs text-neutral-500">
                        {u.last_login ? dateTime(u.last_login) : 'Nunca'}
                      </td>
                      <td className="px-4 py-3 text-right">
                        <div className="flex items-center justify-end gap-1">
                          <Link href={`/usuarios/editar?id=${u.id}`}>
                            <Button variant="ghost" size="sm" title="Editar">
                              <Pencil className="h-4 w-4" />
                            </Button>
                          </Link>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setModal({ kind: 'reset', user: u })}
                            title="Reset password"
                          >
                            <KeyRound className="h-4 w-4" />
                          </Button>
                          {u.activo ? (
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => setModal({ kind: 'desactivar', user: u })}
                              disabled={esYo}
                              title={esYo ? 'No podés desactivarte' : 'Desactivar'}
                            >
                              <UserX className="h-4 w-4 text-rose-600" />
                            </Button>
                          ) : (
                            <ActivarBtn user={u} onDone={reload} />
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {modal?.kind === 'reset' && (
        <ResetPasswordConfirm
          user={modal.user}
          onClose={() => setModal(null)}
          onSuccess={(pwd) => setModal({ kind: 'pwd', user: modal.user, password: pwd })}
        />
      )}
      {modal?.kind === 'desactivar' && (
        <DesactivarConfirm
          user={modal.user}
          onClose={() => setModal(null)}
          onDone={() => {
            setModal(null);
            reload();
          }}
        />
      )}
      {modal?.kind === 'pwd' && (
        <PasswordTemporalModal
          open
          password={modal.password}
          email={modal.user.email}
          title="Password reseteada"
          onClose={() => {
            setModal(null);
            reload();
          }}
        />
      )}
    </AppShell>
  );
}

function ResetPasswordConfirm({
  user,
  onClose,
  onSuccess,
}: {
  user: Usuario;
  onClose: () => void;
  onSuccess: (pwd: string) => void;
}) {
  const { resetPassword } = useUsuarioMutations();
  const [loading, setLoading] = useState(false);

  async function go() {
    setLoading(true);
    try {
      const result = await resetPassword(user.id);
      toast.success('Password reseteada');
      onSuccess(result.password_temporal);
    } catch (e) {
      toast.error(apiErrorMessage(e, 'Error al resetear'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Dialog open onClose={onClose} title="Resetear password" size="sm">
      <p className="text-sm text-neutral-700">
        ¿Generar una nueva password temporal para <strong>{user.email}</strong>? Todas sus
        sesiones activas se van a cerrar.
      </p>
      <DialogFooter>
        <Button variant="ghost" onClick={onClose} disabled={loading}>
          Volver
        </Button>
        <Button onClick={go} loading={loading}>
          <KeyRound className="h-4 w-4" />
          Resetear
        </Button>
      </DialogFooter>
    </Dialog>
  );
}

function DesactivarConfirm({
  user,
  onClose,
  onDone,
}: {
  user: Usuario;
  onClose: () => void;
  onDone: () => void;
}) {
  const { desactivar } = useUsuarioMutations();
  const [loading, setLoading] = useState(false);

  async function go() {
    setLoading(true);
    try {
      await desactivar(user.id);
      toast.success('Usuario desactivado');
      onDone();
    } catch (e) {
      toast.error(apiErrorMessage(e, 'Error al desactivar'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Dialog open onClose={onClose} title="Desactivar usuario" size="sm">
      <p className="text-sm text-neutral-700">
        ¿Desactivar a <strong>{user.email}</strong>? No va a poder loguearse. Sus sesiones
        activas se cierran.
      </p>
      <DialogFooter>
        <Button variant="ghost" onClick={onClose} disabled={loading}>
          Volver
        </Button>
        <Button variant="danger" onClick={go} loading={loading}>
          <UserX className="h-4 w-4" />
          Desactivar
        </Button>
      </DialogFooter>
    </Dialog>
  );
}

function ActivarBtn({ user, onDone }: { user: Usuario; onDone: () => void }) {
  const { activar } = useUsuarioMutations();
  const [loading, setLoading] = useState(false);

  async function go() {
    setLoading(true);
    try {
      await activar(user.id);
      toast.success('Usuario activado');
      onDone();
    } catch (e) {
      toast.error(apiErrorMessage(e, 'Error al activar'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Button variant="ghost" size="sm" onClick={go} loading={loading} title="Activar">
      <UserCheck className="h-4 w-4 text-accent-700" />
    </Button>
  );
}
