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
      password: '',
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
          ) : (
            <Field
              label="Password temporal"
              hint="Dejar vacío para que el sistema genere una aleatoria"
              error={(errors as Record<string, { message?: string } | undefined>).password?.message}
            >
              <Input type="text" autoComplete="off" {...register('password')} />
            </Field>
          )}
        </div>

        {!isUpdate && (
          <p className="mt-4 rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-xs text-neutral-600">
            El usuario va a estar marcado con <code>must_change_password=true</code>. En su
            primer login se le va a pedir cambiar la password. Vas a ver la password temporal
            una sola vez después de crear el usuario.
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
