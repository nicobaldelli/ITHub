import { z } from 'zod';
import type { Rol } from '@/types/api';

export const ROLES: readonly Rol[] = ['admin', 'cobranzas', 'ventas', 'visualizador'] as const;

const ROL_LABELS: Record<Rol, string> = {
  admin: 'Administrador',
  cobranzas: 'Cobranzas',
  ventas: 'Ventas',
  visualizador: 'Visualizador',
};

export function rolLabel(rol: Rol): string {
  return ROL_LABELS[rol] ?? rol;
}

export const usuarioCreateSchema = z.object({
  nombre: z.string().trim().min(1, 'Requerido').max(100, 'Máximo 100 caracteres'),
  apellido: z.string().trim().min(1, 'Requerido').max(100, 'Máximo 100 caracteres'),
  email: z.string().trim().email('Email inválido').max(150),
  rol: z.enum(ROLES as [Rol, ...Rol[]]),
  activo: z.boolean().optional(),
  /**
   * Opcional: si se deja vacío, el backend genera una password aleatoria.
   * Si se completa, el backend valida la política de complejidad.
   */
  password: z.string().trim().optional(),
});

export const usuarioUpdateSchema = z.object({
  nombre: z.string().trim().min(1, 'Requerido').max(100),
  apellido: z.string().trim().min(1, 'Requerido').max(100),
  email: z.string().trim().email('Email inválido').max(150),
  rol: z.enum(ROLES as [Rol, ...Rol[]]),
  activo: z.boolean(),
});

export type UsuarioCreateData = z.infer<typeof usuarioCreateSchema>;
export type UsuarioUpdateData = z.infer<typeof usuarioUpdateSchema>;
