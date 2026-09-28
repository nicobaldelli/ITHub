'use client';

import { useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { LogIn } from 'lucide-react';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { useAuth } from '@/hooks/useAuth';

/**
 * Guard de rutas autenticadas + estructura visual (sidebar + topbar).
 *
 * - Si todavía no se intentó hydrate: lo hace y muestra "Cargando…"
 * - Si no hay sesión válida: redirige a /login
 * - Si must_change_password: redirige a /cambiar-password
 * - Escucha 'auth:unauthorized' del interceptor del API client. Si HABÍA una
 *   sesión (expiró o fue revocada), NO desmonta la página: muestra un aviso y
 *   deja lo que el usuario estaba haciendo a la vista (por ejemplo un form
 *   largo, cuyo borrador además queda en sessionStorage) hasta que elija
 *   volver a iniciar sesión. Al loguearse vuelve a la misma URL.
 */
export function AppShell({ title, children }: { title?: string; children: React.ReactNode }) {
  const router = useRouter();
  const { user, hydrated, hydrate, isAuthenticated } = useAuth();
  const [expired, setExpired] = useState(false);
  // true una vez que hubo un usuario logueado en esta vista
  const hadSessionRef = useRef(false);

  useEffect(() => {
    if (user) hadSessionRef.current = true;
  }, [user]);

  useEffect(() => {
    if (expired) return; // la sesión se cayó: el aviso decide, no el guard
    if (!hydrated) {
      hydrate().then((u) => {
        if (!u) router.replace('/login');
        else if (u.must_change_password) router.replace('/cambiar-password');
      });
    } else if (!isAuthenticated) {
      router.replace('/login');
    } else if (user?.must_change_password) {
      router.replace('/cambiar-password');
    }
  }, [expired, hydrated, isAuthenticated, hydrate, router, user?.must_change_password]);

  useEffect(() => {
    function onUnauth() {
      if (hadSessionRef.current) {
        setExpired(true);
      } else {
        router.replace('/login');
      }
    }
    window.addEventListener('auth:unauthorized', onUnauth);
    return () => window.removeEventListener('auth:unauthorized', onUnauth);
  }, [router]);

  function irALogin() {
    // window.location en vez de useSearchParams: este último exige Suspense en
    // cada página que monte AppShell (static export) y acá solo se lee al click.
    const next =
      typeof window !== 'undefined'
        ? window.location.pathname + window.location.search
        : '/dashboard';
    router.replace(`/login?next=${encodeURIComponent(next)}`);
  }

  if (!expired && (!hydrated || !user)) {
    return (
      <div className="flex min-h-screen items-center justify-center text-neutral-500">
        Cargando…
      </div>
    );
  }
  if (user?.must_change_password) {
    return null;
  }

  return (
    <div className="min-h-screen bg-neutral-50">
      <Sidebar />
      <div className="md:pl-64">
        <Topbar title={title} />
        <main className="p-6">{children}</main>
      </div>

      {/* No se puede cerrar: la única salida es volver a loguearse */}
      <Dialog open={expired} onClose={() => undefined} title="Tu sesión expiró" size="sm">
        <p className="text-sm text-neutral-700">
          Por seguridad la sesión se cerró. Lo que estabas escribiendo sigue en pantalla y, si
          era un formulario de alta, se guardó como borrador: al volver a entrar lo vas a
          encontrar donde lo dejaste.
        </p>
        <div className="mt-4 flex justify-end">
          <Button onClick={irALogin}>
            <LogIn className="h-4 w-4" />
            Volver a iniciar sesión
          </Button>
        </div>
      </Dialog>
    </div>
  );
}
