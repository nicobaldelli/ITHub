'use client';

import { useEffect, useRef, useState } from 'react';

/**
 * Botón "Iniciar sesión con Google" (Google Identity Services).
 *
 * Carga el script oficial de GIS, inicializa con nuestro client id y pide
 * solo cuentas del dominio (`hd`; la restricción real la aplica el backend).
 * Google devuelve un ID token (credential) que se manda a POST /auth/google.
 *
 * Sin NEXT_PUBLIC_GOOGLE_CLIENT_ID no renderiza nada: el login con password
 * sigue siendo el camino de emergencia.
 */
const CLIENT_ID = process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID ?? '';
const HOSTED_DOMAIN = process.env.NEXT_PUBLIC_GOOGLE_HOSTED_DOMAIN ?? 'intellihelp.tech';
const GIS_SRC = 'https://accounts.google.com/gsi/client';

interface GisCredentialResponse {
  credential: string;
}

interface GisIdApi {
  initialize: (cfg: {
    client_id: string;
    callback: (r: GisCredentialResponse) => void;
    hd?: string;
    ux_mode?: 'popup' | 'redirect';
    auto_select?: boolean;
    cancel_on_tap_outside?: boolean;
  }) => void;
  renderButton: (
    parent: HTMLElement,
    opts: {
      type?: 'standard' | 'icon';
      theme?: 'outline' | 'filled_blue' | 'filled_black';
      size?: 'large' | 'medium' | 'small';
      text?: 'signin_with' | 'signup_with' | 'continue_with' | 'signin';
      shape?: 'rectangular' | 'pill';
      width?: number;
      locale?: string;
    },
  ) => void;
}

declare global {
  interface Window {
    google?: { accounts: { id: GisIdApi } };
  }
}

let gisLoading: Promise<void> | null = null;

function cargarGis(): Promise<void> {
  if (typeof window === 'undefined') return Promise.reject(new Error('sin window'));
  if (window.google?.accounts?.id) return Promise.resolve();
  if (gisLoading) return gisLoading;
  gisLoading = new Promise<void>((resolve, reject) => {
    const s = document.createElement('script');
    s.src = GIS_SRC;
    s.async = true;
    s.defer = true;
    s.onload = () => resolve();
    s.onerror = () => {
      gisLoading = null;
      reject(new Error('No se pudo cargar Google Identity Services'));
    };
    document.head.appendChild(s);
  });
  return gisLoading;
}

export const googleLoginHabilitado = CLIENT_ID !== '';

export function GoogleSignInButton({
  onCredential,
  disabled = false,
}: {
  onCredential: (credential: string) => void | Promise<void>;
  disabled?: boolean;
}) {
  const ref = useRef<HTMLDivElement>(null);
  const [error, setError] = useState<string | null>(null);
  // Siempre la última versión del callback, sin re-inicializar GIS
  const onCredentialRef = useRef(onCredential);
  onCredentialRef.current = onCredential;

  useEffect(() => {
    if (!googleLoginHabilitado || !ref.current) return;
    let cancelado = false;
    const parent = ref.current;

    cargarGis()
      .then(() => {
        if (cancelado || !window.google) return;
        window.google.accounts.id.initialize({
          client_id: CLIENT_ID,
          hd: HOSTED_DOMAIN,
          ux_mode: 'popup',
          auto_select: false,
          cancel_on_tap_outside: true,
          callback: (r) => {
            void onCredentialRef.current(r.credential);
          },
        });
        parent.innerHTML = '';
        window.google.accounts.id.renderButton(parent, {
          type: 'standard',
          theme: 'outline',
          size: 'large',
          text: 'signin_with',
          shape: 'rectangular',
          width: 320,
          locale: 'es',
        });
      })
      .catch((e: Error) => {
        if (!cancelado) setError(e.message);
      });

    return () => {
      cancelado = true;
    };
  }, []);

  if (!googleLoginHabilitado) return null;

  return (
    <div className={disabled ? 'pointer-events-none opacity-60' : ''}>
      <div ref={ref} className="flex min-h-[44px] justify-center" />
      {error && <p className="mt-2 text-center text-xs text-rose-600">{error}</p>}
    </div>
  );
}
