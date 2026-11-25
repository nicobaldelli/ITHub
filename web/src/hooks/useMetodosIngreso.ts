'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import type { ApiSuccess } from '@/types/api';

/**
 * Con qué se ingresa: ITHub (sesión única de Intellihelp) o Google. Lo decide
 * la API según su .env (GET /auth/metodos), así el mismo build sirve con o sin
 * ITHub configurado. Se pide una sola vez por carga de página.
 */
export interface MetodosIngreso {
  ithub: boolean;
  google: boolean;
}

let pedido: Promise<MetodosIngreso> | null = null;

function pedirMetodos(): Promise<MetodosIngreso> {
  if (!pedido) {
    pedido = api
      .get<ApiSuccess<MetodosIngreso>>('/auth/metodos')
      .then((r) => r.data.data)
      .catch((err) => {
        pedido = null; // que el próximo intento vuelva a preguntar
        throw err;
      });
  }
  return pedido;
}

/** URL de la API que manda a ITHub; `next` = ruta interna a la que volver. */
export function urlIngresoItHub(next?: string | null): string {
  const base = (api.defaults.baseURL ?? '').replace(/\/$/, '');
  const q = next ? `?next=${encodeURIComponent(next)}` : '';
  return `${base}/auth/ithub/login${q}`;
}

/** `null` mientras carga; `error` si la API no respondió. */
export function useMetodosIngreso(): { metodos: MetodosIngreso | null; error: boolean } {
  const [metodos, setMetodos] = useState<MetodosIngreso | null>(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    let cancelado = false;
    pedirMetodos()
      .then((m) => !cancelado && setMetodos(m))
      .catch(() => !cancelado && setError(true));
    return () => {
      cancelado = true;
    };
  }, []);

  return { metodos, error };
}
