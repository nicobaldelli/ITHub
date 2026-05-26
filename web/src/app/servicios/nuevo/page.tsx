'use client';

import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/button';
import { ServicioForm } from '@/components/servicios/ServicioForm';
import { useServicioMutations } from '@/hooks/useServicios';
import { useAuthStore } from '@/stores/auth';
import type { ServicioCreateData } from '@/lib/servicio-schema';

export default function NuevoServicioPage() {
  const router = useRouter();
  const { create } = useServicioMutations();
  const user = useAuthStore((s) => s.user);

  if (user && user.rol !== 'admin' && user.rol !== 'ventas') {
    return (
      <AppShell title="Nuevo servicio">
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          No tenés permisos para crear servicios.
        </div>
      </AppShell>
    );
  }

  async function onSubmit(data: ServicioCreateData) {
    try {
      // El schema deja algunos campos como null que el backend no espera explícitamente
      // (por ejemplo cuotas en mantenimiento). Limpiamos el payload según el tipo.
      const payload: Record<string, unknown> = {
        tipo: data.tipo,
        cliente_id: data.cliente_id,
        nombre: data.nombre,
        descripcion: data.descripcion,
        moneda: data.moneda,
        importe_base: data.importe_base,
        fecha_inicio: data.fecha_inicio,
        fecha_fin: data.fecha_fin,
        observaciones: data.observaciones,
      };
      if (data.tipo === 'mantenimiento') {
        payload.modo_facturacion = data.modo_facturacion;
        payload.dia_facturacion = data.dia_facturacion;
        payload.intervalo_dias = data.intervalo_dias;
        payload.frecuencia_ajuste_meses = data.frecuencia_ajuste_meses;
        payload.aviso_dias_previos = data.aviso_dias_previos;
      } else {
        payload.cuotas = data.cuotas;
      }

      const servicio = await create(payload);
      toast.success('Servicio creado');
      router.push(`/servicios/${servicio.id}`);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'No se pudo crear el servicio');
      throw e;
    }
  }

  return (
    <AppShell title="Nuevo servicio">
      <div className="mb-4">
        <Link href="/servicios">
          <Button variant="ghost" size="sm">
            <ArrowLeft className="h-4 w-4" />
            Volver a servicios
          </Button>
        </Link>
      </div>

      <ServicioForm onSubmit={onSubmit} onCancel={() => router.push('/servicios')} />
    </AppShell>
  );
}
