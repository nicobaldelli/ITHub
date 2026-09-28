'use client';

import { useEffect, useRef } from 'react';
import type { FieldValues, UseFormReturn } from 'react-hook-form';
import { toast } from 'sonner';

/**
 * Borrador de formulario en sessionStorage.
 *
 * Para que no se pierda lo tipeado si se cae la sesión (refresh token vencido)
 * o se recarga la pestaña a mitad de un form largo. Se guarda con debounce en
 * cada cambio, se restaura al montar y la página lo borra al guardar OK con
 * `clearFormDraft(key)`.
 *
 * sessionStorage (no localStorage): vive solo en la pestaña, se descarta al
 * cerrarla y nunca sale del browser.
 */
const PREFIX = 'ithub:draft:';
const DEBOUNCE_MS = 400;

export function clearFormDraft(key: string): void {
  try {
    sessionStorage.removeItem(PREFIX + key);
  } catch {
    // storage bloqueado (modo privado, etc.): no hay nada que borrar
  }
}

export function useFormDraft<T extends FieldValues>(
  key: string | undefined,
  form: UseFormReturn<T>,
  /** Callback al restaurar, para que el form ajuste lo que dependa de los valores. */
  onRestore?: (values: T) => void,
): void {
  const restoredRef = useRef(false);
  const onRestoreRef = useRef(onRestore);
  onRestoreRef.current = onRestore;

  // Restaurar una sola vez al montar
  useEffect(() => {
    if (!key || restoredRef.current) return;
    restoredRef.current = true;
    try {
      const raw = sessionStorage.getItem(PREFIX + key);
      if (!raw) return;
      const values = JSON.parse(raw) as T;
      form.reset(values, { keepDefaultValues: true });
      onRestoreRef.current?.(values);
      toast.info('Se restauró un borrador que no habías guardado', { duration: 5000 });
    } catch {
      clearFormDraft(key);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);

  // Guardar con debounce en cada cambio
  useEffect(() => {
    if (!key) return;
    let timer: ReturnType<typeof setTimeout> | null = null;
    const sub = form.watch((values) => {
      if (timer) clearTimeout(timer);
      timer = setTimeout(() => {
        try {
          sessionStorage.setItem(PREFIX + key, JSON.stringify(values));
        } catch {
          // storage lleno o bloqueado: el borrador es best-effort
        }
      }, DEBOUNCE_MS);
    });
    return () => {
      sub.unsubscribe();
      if (timer) clearTimeout(timer);
    };
  }, [key, form]);
}
