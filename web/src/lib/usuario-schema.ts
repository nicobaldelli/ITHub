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

/** Dominio de Workspace: el único login es con Google, así que solo se cargan emails de acá. */
export const DOMINIO_USUARIOS = process.env.NEXT_PUBLIC_GOOGLE_HOSTED_DOMAIN || 'intellihelp.tech';

const emailDelDominio = z
  .string()
  .trim()
  .email('Email inválido')
  .max(150)
  .refine((v) => v.toLowerCase().endsWith(`@${DOMINIO_USUARIOS}`), {
    message: `Tiene que ser una cuenta de Google @${DOMINIO_USUARIOS}`,
  });

export const usuarioCreateSchema = z.object({
  nombre: z.string().trim().min(1, 'Requerido').max(100, 'Máximo 100 caracteres'),
  apellido: z.string().trim().min(1, 'Requerido').max(100, 'Máximo 100 caracteres'),
  email: emailDelDominio,
  rol: z.enum(ROLES as [Rol, ...Rol[]]),
  activo: z.boolean().optional(),
});

export const usuarioUpdateSchema = z.object({
  nombre: z.string().trim().min(1, 'Requerido').max(100),
  apellido: z.string().trim().min(1, 'Requerido').max(100),
  email: emailDelDominio,
  rol: z.enum(ROLES as [Rol, ...Rol[]]),
  activo: z.boolean(),
});

export type UsuarioCreateData = z.infer<typeof usuarioCreateSchema>;
export type UsuarioUpdateData = z.infer<typeof usuarioUpdateSchema>;
