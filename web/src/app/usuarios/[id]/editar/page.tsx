'use client';

import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { UsuarioForm } from '@/components/usuarios/UsuarioForm';
import { useUsuario, useUsuarioMutations } from '@/hooks/useUsuarios';
import { useAuthStore } from '@/stores/auth';
import type { UsuarioCreateData, UsuarioUpdateData } from '@/lib/usuario-schema';

export default function EditarUsuarioPage() {
  const params = useParams<{ id: string }>();
  const router = useRouter();
  const id = Number(params.id);
  const { data: usuario, loading, error } = useUsuario(id);
  const { update } = useUsuarioMutations();
  const yo = useAuthStore((s) => s.user);

  if (yo && yo.rol !== 'admin') {
    return (
      <AppShell title="Editar usuario">
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          Solo los administradores pueden editar usuarios.
        </div>
      </AppShell>
    );
  }

  const esYoMismo = usuario?.id === yo?.id;

  async function onSubmit(data: UsuarioCreateData | UsuarioUpdateData) {
    try {
      await update(id, data as UsuarioUpdateData);
      toast.success('Usuario actualizado');
      router.push('/usuarios');
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'No se pudo actualizar');
      throw e;
    }
  }

  return (
    <AppShell title="Editar usuario">
      <div className="mb-4">
        <Link href="/usuarios">
          <Button variant="ghost" size="sm">
            <ArrowLeft className="h-4 w-4" />
            Volver a usuarios
          </Button>
        </Link>
      </div>

      {loading && <Card className="p-8 text-center text-neutral-500">Cargando…</Card>}
      {error && (
        <Card className="border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{error}</Card>
      )}

      {!loading && usuario && (
        <UsuarioForm
          initial={usuario}
          onSubmit={onSubmit}
          onCancel={() => router.push('/usuarios')}
          isUpdate
          esYoMismo={esYoMismo}
        />
      )}
    </AppShell>
  );
}
