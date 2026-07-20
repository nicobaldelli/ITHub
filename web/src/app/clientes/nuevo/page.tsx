'use client';

import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/button';
import { ClienteForm } from '@/components/clientes/ClienteForm';
import { useClienteMutations } from '@/hooks/useClientes';
import { useAuthStore } from '@/stores/auth';
import type { ClienteFormData } from '@/lib/cliente-schema';

export default function NuevoClientePage() {
  const router = useRouter();
  const { create } = useClienteMutations();
  const user = useAuthStore((s) => s.user);

  if (user && user.rol !== 'admin' && user.rol !== 'ventas') {
    return (
      <AppShell title="Nuevo cliente">
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          No tenés permisos para crear clientes.
        </div>
      </AppShell>
    );
  }

  async function onSubmit(data: ClienteFormData) {
    try {
      const cliente = await create(data);
      toast.success('Cliente creado');
      router.push(`/clientes/ver?id=${cliente.id}`);
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo crear el cliente'));
    }
  }

  return (
    <AppShell title="Nuevo cliente">
      <div className="mb-4">
        <Link href="/clientes">
          <Button variant="ghost" size="sm">
            <ArrowLeft className="h-4 w-4" />
            Volver a clientes
          </Button>
        </Link>
      </div>

      <ClienteForm
        onSubmit={onSubmit}
        onCancel={() => router.push('/clientes')}
        submitLabel="Crear cliente"
      />
    </AppShell>
  );
}
