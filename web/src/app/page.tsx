'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/hooks/useAuth';

/**
 * Root path: si hay sesión válida vamos al dashboard, sino al login.
 */
export default function HomeRedirect() {
  const router = useRouter();
  const { hydrate } = useAuth();

  useEffect(() => {
    (async () => {
      const user = await hydrate();
      if (user) {
        router.replace(user.must_change_password ? '/cambiar-password' : '/dashboard');
      } else {
        router.replace('/login');
      }
    })();
  }, [hydrate, router]);

  return (
    <div className="flex min-h-screen items-center justify-center text-neutral-500">
      Cargando…
    </div>
  );
}
