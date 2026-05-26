'use client';

import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
  ServicioEditForm,
  type ServicioEditValues,
} from '@/components/servicios/ServicioEditForm';
import { useServicio, useServicioMutations } from '@/hooks/useServicios';
import { useAuthStore } from '@/stores/auth';

export default function EditarServicioPage() {
  const params = useParams<{ id: string }>();
  const router = useRouter();
  const id = Number(params.id);
  const { data: servicio, loading, error } = useServicio(id);
  const { update } = useServicioMutations();
  const user = useAuthStore((s) => s.user);

  if (user && user.rol !== 'admin' && user.rol !== 'ventas') {
    return (
      <AppShell title="Editar servicio">
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          No tenés permisos para editar servicios.
        </div>
      </AppShell>
    );
  }

  async function onSubmit(data: ServicioEditValues) {
    // Convertir strings vacíos a null y números string a number
    const payload: Record<string, unknown> = {
      nombre: data.nombre,
      descripcion: data.descripcion || null,
      observaciones: data.observaciones || null,
    };
    if (data.importe_base) payload.importe_base = Number(data.importe_base);
    if (data.fecha_inicio) payload.fecha_inicio = data.fecha_inicio;
    payload.fecha_fin = data.fecha_fin || null;
    if (data.dia_facturacion !== '') payload.dia_facturacion = Number(data.dia_facturacion);
    if (data.intervalo_dias !== '') payload.intervalo_dias = Number(data.intervalo_dias);
    payload.frecuencia_ajuste_meses =
      data.frecuencia_ajuste_meses !== '' ? Number(data.frecuencia_ajuste_meses) : null;
    payload.aviso_dias_previos =
      data.aviso_dias_previos !== '' ? Number(data.aviso_dias_previos) : null;

    try {
      await update(id, payload);
      toast.success('Servicio actualizado');
      router.push(`/servicios/${id}`);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'No se pudo actualizar');
      throw e;
    }
  }

  return (
    <AppShell title="Editar servicio">
      <div className="mb-4">
        <Link href={`/servicios/${id}`}>
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

      {!loading && servicio && (
        <ServicioEditForm
          servicio={servicio}
          onSubmit={onSubmit}
          onCancel={() => router.push(`/servicios/${id}`)}
        />
      )}
    </AppShell>
  );
}
