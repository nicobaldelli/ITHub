'use client';

import { Suspense } from 'react';
import { useSearchParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ClienteForm } from '@/components/clientes/ClienteForm';
import { useCliente, useClienteMutations } from '@/hooks/useClientes';
import { useAuthStore } from '@/stores/auth';
import type { ClienteFormData } from '@/lib/cliente-schema';

export default function EditarClientePage() {
  return (
    <Suspense fallback={<AppShell title="Editar cliente"><Card className="p-8 text-center text-neutral-500">Cargando…</Card></AppShell>}>
      <EditarClienteInner />
    </Suspense>
  );
}

function EditarClienteInner() {
  const params = useSearchParams();
  const router = useRouter();
  const id = Number(params?.get('id') ?? 0);
  const { data: cliente, loading, error } = useCliente(id);
  const { update } = useClienteMutations();
  const user = useAuthStore((s) => s.user);

  if (user && user.rol !== 'admin' && user.rol !== 'ventas') {
    return (
      <AppShell title="Editar cliente">
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          No tenés permisos para editar clientes.
        </div>
      </AppShell>
    );
  }

  async function onSubmit(data: ClienteFormData) {
    try {
      await update(id, data);
      toast.success('Cliente actualizado');
      router.push(`/clientes/ver?id=${id}`);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'No se pudo actualizar');
      throw e;
    }
  }

  return (
    <AppShell title="Editar cliente">
      <div className="mb-4">
        <Link href={`/clientes/ver?id=${id}`}>
          <Button variant="ghost" size="sm">
            <ArrowLeft className="h-4 w-4" />
            Volver al detalle
          </Button>
        </Link>
      </div>

      {loading && <Card className="p-8 text-center text-neutral-500">Cargando…</Card>}
      {error && (
        <Card className="border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{error}</Card>
      )}

      {!loading && cliente && (
        <ClienteForm
          initial={cliente}
          onSubmit={onSubmit}
          onCancel={() => router.push(`/clientes/ver?id=${id}`)}
          submitLabel="Guardar cambios"
          isUpdate
        />
      )}
    </AppShell>
  );
}
