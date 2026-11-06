'use client';

import { Suspense, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { toast } from 'sonner';
import { LogIn } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { GoogleSignInButton, googleLoginHabilitado } from '@/components/auth/GoogleSignInButton';

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

/**
 * El único acceso es con Google Workspace del dominio de la empresa. No hay
 * formulario de email y password: la API no tiene login con password.
 */
function LoginInner() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { loginGoogle } = useAuth();
  const [loading, setLoading] = useState(false);

  async function onGoogle(credential: string) {
    setLoading(true);
    try {
      const res = await loginGoogle(credential);
      toast.success(`Bienvenido, ${res.user.nombre}`);
      router.push(destinoSeguro(searchParams?.get('next') ?? null));
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
          {googleLoginHabilitado ? (
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
            ¿Problemas para acceder? Pedile a tu administrador que te dé de alta con tu email @{DOMINIO}.
          </p>
        </div>
      </div>
    </div>
  );
}
