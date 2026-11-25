'use client';

import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Save, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card } from '@/components/ui/card';
import {
  usuarioCreateSchema,
  usuarioUpdateSchema,
  ROLES,
  rolLabel,
  type UsuarioCreateData,
  type UsuarioUpdateData,
  DOMINIO_USUARIOS,
} from '@/lib/usuario-schema';
import type { Usuario } from '@/types/usuario';

export interface UsuarioFormProps {
  initial?: Partial<Usuario>;
  onSubmit: (data: UsuarioCreateData | UsuarioUpdateData) => Promise<void> | void;
  onCancel: () => void;
  isUpdate?: boolean;
  /** En edición, true si el usuario está editando su propio registro (oculta acciones autodestructivas). */
  esYoMismo?: boolean;
}

export function UsuarioForm({
  initial,
  onSubmit,
  onCancel,
  isUpdate = false,
  esYoMismo = false,
}: UsuarioFormProps) {
  const schema = isUpdate ? usuarioUpdateSchema : usuarioCreateSchema;
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm({
    resolver: zodResolver(schema),
    defaultValues: {
      nombre: initial?.nombre ?? '',
      apellido: initial?.apellido ?? '',
      email: initial?.email ?? '',
      rol: initial?.rol ?? 'visualizador',
      activo: initial?.activo ?? true,
    },
  });

  return (
    <form onSubmit={handleSubmit(onSubmit as (d: UsuarioCreateData | UsuarioUpdateData) => Promise<void>)} className="space-y-4">
      <Card className="p-5">
        <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          Datos del usuario
        </h3>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Field label="Nombre" required error={(errors as Record<string, { message?: string } | undefined>).nombre?.message}>
            <Input autoFocus {...register('nombre')} />
          </Field>

          <Field label="Apellido" required error={(errors as Record<string, { message?: string } | undefined>).apellido?.message}>
            <Input {...register('apellido')} />
          </Field>

          <Field
            label="Email"
            required
            error={(errors as Record<string, { message?: string } | undefined>).email?.message}
            className="md:col-span-2"
          >
            <Input type="email" autoComplete="off" {...register('email')} />
          </Field>

          <Field label="Rol" required error={(errors as Record<string, { message?: string } | undefined>).rol?.message}>
            <select
              className="input-base"
              {...register('rol')}
              disabled={isUpdate && esYoMismo}
            >
              {ROLES.map((r) => (
                <option key={r} value={r}>
                  {rolLabel(r)}
                </option>
              ))}
            </select>
            {isUpdate && esYoMismo && (
              <p className="mt-1 text-xs text-neutral-500">
                No podés cambiar tu propio rol.
              </p>
            )}
          </Field>

          {isUpdate ? (
            <Field
              label="Estado"
              error={(errors as Record<string, { message?: string } | undefined>).activo?.message}
            >
              <label className="flex items-center gap-2 pt-2 text-sm">
                <input
                  type="checkbox"
                  {...register('activo')}
                  disabled={esYoMismo}
                  className="h-4 w-4"
                />
                Activo
              </label>
              {esYoMismo && (
                <p className="mt-1 text-xs text-neutral-500">
                  No podés desactivarte a vos mismo.
                </p>
              )}
            </Field>
          ) : null}
        </div>

        {!isUpdate && (
          <p className="mt-4 rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-xs text-neutral-600">
            El usuario entra con su cuenta de Google <strong>@{DOMINIO_USUARIOS}</strong>, con el
            mismo email que cargues acá. No tiene password.
          </p>
        )}
      </Card>

      <div className="flex items-center justify-end gap-2 pb-6">
        <Button type="button" variant="ghost" onClick={onCancel} disabled={isSubmitting}>
          <X className="h-4 w-4" />
          Cancelar
        </Button>
        <Button type="submit" loading={isSubmitting}>
          <Save className="h-4 w-4" />
          {isUpdate ? 'Guardar cambios' : 'Crear usuario'}
        </Button>
      </div>
    </form>
  );
}

function Field({
  label,
  required,
  error,
  hint,
  className,
  children,
}: {
  label: string;
  required?: boolean;
  error?: string;
  hint?: string;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <div className={className}>
      <Label className="mb-1 block">
        {label}
        {required && <span className="ml-0.5 text-rose-500">*</span>}
      </Label>
      {children}
      {hint && !error && <p className="mt-1 text-xs text-neutral-500">{hint}</p>}
      {error && <p className="mt-1 text-xs text-rose-600">{error}</p>}
    </div>
  );
}
