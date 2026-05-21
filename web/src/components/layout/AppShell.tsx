'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';
import { useAuth } from '@/hooks/useAuth';

/**
 * Guard de rutas autenticadas + estructura visual (sidebar + topbar).
 *
 * - Si todavía no se intentó hydrate: lo hace y muestra "Cargando…"
 * - Si no hay sesión válida: redirige a /login
 * - Si must_change_password: redirige a /cambiar-password
 * - Escucha 'auth:unauthorized' del interceptor del API client para forzar logout
 */
export function AppShell({ title, children }: { title?: string; children: React.ReactNode }) {
  const router = useRouter();
  const { user, hydrated, hydrate, isAuthenticated } = useAuth();

  useEffect(() => {
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
  }, [hydrated, isAuthenticated, hydrate, router, user?.must_change_password]);

  useEffect(() => {
    function onUnauth() {
      router.replace('/login');
    }
    window.addEventListener('auth:unauthorized', onUnauth);
    return () => window.removeEventListener('auth:unauthorized', onUnauth);
  }, [router]);

  if (!hydrated || !user) {
    return (
      <div className="flex min-h-screen items-center justify-center text-neutral-500">
        Cargando…
      </div>
    );
  }
  if (user.must_change_password) {
    return null;
  }

  return (
    <div className="min-h-screen bg-neutral-50">
      <Sidebar />
      <div className="md:pl-64">
        <Topbar title={title} />
        <main className="p-6">{children}</main>
      </div>
    </div>
  );
}
