'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/button';
import { UsuarioForm } from '@/components/usuarios/UsuarioForm';
import { PasswordTemporalModal } from '@/components/usuarios/PasswordTemporalModal';
import { useUsuarioMutations } from '@/hooks/useUsuarios';
import { useAuthStore } from '@/stores/auth';
import type { UsuarioCreateData, UsuarioUpdateData } from '@/lib/usuario-schema';

export default function NuevoUsuarioPage() {
  const router = useRouter();
  const { create } = useUsuarioMutations();
  const yo = useAuthStore((s) => s.user);
  const [modal, setModal] = useState<null | { email: string; password: string }>(null);

  if (yo && yo.rol !== 'admin') {
    return (
      <AppShell title="Nuevo usuario">
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          Solo los administradores pueden crear usuarios.
        </div>
      </AppShell>
    );
  }

  async function onSubmit(data: UsuarioCreateData | UsuarioUpdateData) {
    try {
      const result = await create(data as UsuarioCreateData);
      toast.success('Usuario creado');
      setModal({ email: result.user.email, password: result.password_temporal });
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'No se pudo crear');
      throw e;
    }
  }

  return (
    <AppShell title="Nuevo usuario">
      <div className="mb-4">
        <Link href="/usuarios">
          <Button variant="ghost" size="sm">
            <ArrowLeft className="h-4 w-4" />
            Volver a usuarios
          </Button>
        </Link>
      </div>

      <UsuarioForm onSubmit={onSubmit} onCancel={() => router.push('/usuarios')} />

      {modal && (
        <PasswordTemporalModal
          open
          email={modal.email}
          password={modal.password}
          title="Usuario creado"
          onClose={() => router.push('/usuarios')}
        />
      )}
    </AppShell>
  );
}
