'use client';

import { Suspense, useEffect, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { toast } from 'sonner';
import { LogIn } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { GoogleSignInButton, googleLoginHabilitado } from '@/components/auth/GoogleSignInButton';
import { urlIngresoItHub, useMetodosIngreso } from '@/hooks/useMetodosIngreso';

export default function LoginPage() {
  // useSearchParams exige Suspense en static export
  return (
    <Suspense fallback={null}>
      <LoginInner />
    </Suspense>
  );
}

/**
 * Solo aceptamos rutas internas absolutas ("/facturas/nueva?x=1"). Cualquier
 * cosa con esquema, host, "//" o barra invertida se descarta para no servir
 * de open redirect: el browser normaliza "/\evil.com" a "//evil.com", así que
 * además de mirar el prefijo se resuelve la URL y se exige el mismo origen.
 */
function destinoSeguro(next: string | null): string {
  if (!next) return '/dashboard';
  if (!next.startsWith('/') || next.startsWith('//') || next.includes('\\') || next.startsWith('/login')) {
    return '/dashboard';
  }
  if (typeof window !== 'undefined') {
    try {
      const url = new URL(next, window.location.origin);
      if (url.origin !== window.location.origin || !url.pathname.startsWith('/')) {
        return '/dashboard';
      }
      return url.pathname + url.search + url.hash;
    } catch {
      return '/dashboard';
    }
  }
  return next;
}

const DOMINIO = process.env.NEXT_PUBLIC_GOOGLE_HOSTED_DOMAIN || 'intellihelp.tech';

/** Avisos con los que la API devuelve a esta pantalla cuando el ingreso con ITHub no se completó. */
const AVISOS: Record<string, string> = {
  ithub_sin_acceso:
    'Tu cuenta no tiene acceso a Facturación. Pedíselo a un administrador de ITHub.',
  ithub_rol: 'Tu rol en ITHub no es uno de Facturación. Pedí que lo revisen en ITHub.',
  ithub_estado: 'El ingreso tardó demasiado o se abrió en otra pestaña. Probá de nuevo.',
  ithub_error: 'No se pudo completar el ingreso con ITHub. Probá de nuevo.',
  ithub_no_configurado: 'El ingreso con ITHub no está configurado en el servidor.',
};

/**
 * Ingreso. No hay formulario de email y password: la API no tiene login con
 * password.
 *
 * - Con ITHub configurado en la API (sesión única de Intellihelp) esta
 *   pantalla manda sola a ITHub; si la sesión de ITHub ya estaba abierta se
 *   entra sin ver nada. Se queda acá (con el botón) solo si volvió con un
 *   aviso o con `?manual=1`.
 * - Sin ITHub, el acceso es con Google Workspace del dominio, como siempre.
 */
function LoginInner() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { loginGoogle } = useAuth();
  const { metodos, error: errorMetodos } = useMetodosIngreso();
  const [loading, setLoading] = useState(false);

  const nextParam = searchParams?.get('next') ?? null;
  const aviso = searchParams?.get('aviso') ?? null;
  const manual = searchParams?.get('manual') === '1';
  const destinoItHub = urlIngresoItHub(nextParam ? destinoSeguro(nextParam) : null);
  const redirigiendo = !!metodos?.ithub && !aviso && !manual;

  useEffect(() => {
    if (redirigiendo) {
      window.location.replace(destinoItHub);
    }
  }, [redirigiendo, destinoItHub]);

  async function onGoogle(credential: string) {
    setLoading(true);
    try {
      const res = await loginGoogle(credential);
      toast.success(`Bienvenido, ${res.user.nombre}`);
      router.push(destinoSeguro(nextParam));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al iniciar sesión con Google');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-neutral-50 px-4">
      <div className="w-full max-w-md">
        {/* Logo / brand header */}
        <div className="mb-8 text-center">
          <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-primary text-white">
            <LogIn className="h-6 w-6" />
          </div>
          <h1 className="text-2xl font-semibold text-foreground">ITHub</h1>
          <p className="mt-1 text-sm text-neutral-500">Gestión de facturas de venta</p>
        </div>

        <div className="space-y-5 rounded-xl bg-white p-8 shadow-card">
          {aviso && (
            <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-center text-sm text-amber-800">
              {AVISOS[aviso] ?? AVISOS.ithub_error}
            </p>
          )}

          {!metodos && !errorMetodos ? (
            <p className="text-center text-sm text-neutral-500">Cargando…</p>
          ) : metodos?.ithub ? (
            redirigiendo ? (
              <p className="text-center text-sm text-neutral-500">Redirigiendo a ITHub…</p>
            ) : (
              <>
                <p className="text-center text-sm text-neutral-600">
                  El ingreso es con tu sesión de <strong>ITHub</strong>, el portal de Intellihelp.
                </p>
                <a
                  href={destinoItHub}
                  className="flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-primary-700 active:bg-primary-800"
                >
                  <LogIn className="h-4 w-4" />
                  Ingresar con ITHub
                </a>
              </>
            )
          ) : googleLoginHabilitado ? (
            <>
              <p className="text-center text-sm text-neutral-600">
                Ingresá con tu cuenta de Google <strong>@{DOMINIO}</strong>
              </p>
              <GoogleSignInButton onCredential={onGoogle} disabled={loading} />
            </>
          ) : (
            <p className="text-center text-sm text-danger">
              El acceso con Google no está configurado. Contactá a tu administrador.
            </p>
          )}

          <p className="text-center text-xs text-neutral-400">
            {metodos?.ithub
              ? 'El acceso y el rol se administran en ITHub.'
              : `¿Problemas para acceder? Pedile a tu administrador que te dé de alta con tu email @${DOMINIO}.`}
          </p>
        </div>
      </div>
    </div>
  );
}
