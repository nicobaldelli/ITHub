'use client';

import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { toast } from 'sonner';
import { apiErrorMessage } from '@/lib/api';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/button';
import { FacturaForm } from '@/components/facturas/FacturaForm';
import { useFacturaMutations } from '@/hooks/useFacturas';
import { useAuthStore } from '@/stores/auth';
import type { FacturaFormData } from '@/lib/factura-schema';

export default function NuevaFacturaPage() {
  const router = useRouter();
  const { create } = useFacturaMutations();
  const user = useAuthStore((s) => s.user);

  if (user && user.rol !== 'admin' && user.rol !== 'ventas') {
    return (
      <AppShell title="Nueva factura">
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          No tenés permisos para crear facturas.
        </div>
      </AppShell>
    );
  }

  async function onSubmit(data: FacturaFormData) {
    try {
      const factura = await create(data);
      toast.success('Factura creada');
      router.push(`/facturas/ver?id=${factura.id}`);
    } catch (e) {
      toast.error(apiErrorMessage(e, 'No se pudo crear la factura'));
    }
  }

  return (
    <AppShell title="Nueva factura">
      <div className="mb-4">
        <Link href="/facturas">
          <Button variant="ghost" size="sm">
            <ArrowLeft className="h-4 w-4" />
            Volver a facturas
          </Button>
        </Link>
      </div>

      <FacturaForm
        onSubmit={onSubmit}
        onCancel={() => router.push('/facturas')}
        submitLabel="Crear factura"
      />
    </AppShell>
  );
}
