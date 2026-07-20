'use client';

import { Suspense } from 'react';
import { useSearchParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { FacturaForm } from '@/components/facturas/FacturaForm';
import { useFactura, useFacturaMutations } from '@/hooks/useFacturas';
import { useAuthStore } from '@/stores/auth';
import type { FacturaFormData } from '@/lib/factura-schema';

export default function EditarFacturaPage() {
  return (
    <Suspense fallback={<AppShell title="Editar factura"><Card className="p-8 text-center text-neutral-500">Cargando…</Card></AppShell>}>
      <EditarFacturaInner />
    </Suspense>
  );
}

function EditarFacturaInner() {
  const params = useSearchParams();
  const router = useRouter();
  const id = Number(params?.get('id') ?? 0);
  const { data: factura, loading, error } = useFactura(id);
  const { update } = useFacturaMutations();
  const user = useAuthStore((s) => s.user);

  if (
    user &&
    user.rol !== 'admin' &&
    user.rol !== 'ventas' &&
    user.rol !== 'cobranzas'
  ) {
    return (
      <AppShell title="Editar factura">
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          No tenés permisos para editar facturas.
        </div>
      </AppShell>
    );
  }

  async function onSubmit(data: FacturaFormData) {
    try {
      await update(id, data);
      toast.success('Factura actualizada');
      router.push(`/facturas/ver?id=${id}`);
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo actualizar'));
    }
  }

  return (
    <AppShell title="Editar factura">
      <div className="mb-4">
        <Link href={`/facturas/ver?id=${id}`}>
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

      {!loading && factura && (
        <FacturaForm
          initial={factura}
          onSubmit={onSubmit}
          onCancel={() => router.push(`/facturas/ver?id=${id}`)}
          submitLabel="Guardar cambios"
          isUpdate
          lockCliente
        />
      )}
    </AppShell>
  );
}
