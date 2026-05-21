'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { toast } from 'sonner';
import { KeyRound } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAuth } from '@/hooks/useAuth';

/**
 * Página de cambio de password.
 * - Si el usuario tiene must_change_password=true, no le pedimos current_password.
 * - Si no, sí lo pedimos.
 */
export default function CambiarPasswordPage() {
  const router = useRouter();
  const { user, hydrate, hydrated, changePassword } = useAuth();
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirm, setConfirm] = useState('');
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!hydrated) {
      hydrate().then((u) => {
        if (!u) router.replace('/login');
      });
    }
  }, [hydrated, hydrate, router]);

  const isForced = user?.must_change_password === true;

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (next !== confirm) {
      toast.error('Las passwords no coinciden');
      return;
    }
    setLoading(true);
    try {
      await changePassword(next, isForced ? undefined : current);
      // changePassword ya hace router.push('/login') al finalizar
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'No se pudo cambiar la password');
    } finally {
      setLoading(false);
    }
  }

  if (!hydrated || !user) {
    return (
      <div className="flex min-h-screen items-center justify-center text-neutral-500">
        Cargando…
      </div>
    );
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-neutral-50 px-4">
      <div className="w-full max-w-md">
        <div className="mb-8 text-center">
          <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-primary text-white">
            <KeyRound className="h-6 w-6" />
          </div>
          <h1 className="text-2xl font-semibold text-foreground">Cambiar password</h1>
          {isForced && (
            <p className="mt-1 text-sm text-amber-600">
              Es la primera vez que ingresás. Cambiá tu password para continuar.
            </p>
          )}
        </div>

        <form onSubmit={onSubmit} className="space-y-5 rounded-xl bg-white p-8 shadow-card">
          {!isForced && (
            <div>
              <Label htmlFor="current">Password actual</Label>
              <Input
                id="current"
                type="password"
                value={current}
                onChange={(e) => setCurrent(e.target.value)}
                required
              />
            </div>
          )}

          <div>
            <Label htmlFor="new">Nueva password</Label>
            <Input
              id="new"
              type="password"
              value={next}
              onChange={(e) => setNext(e.target.value)}
              required
              minLength={12}
            />
            <p className="mt-1 text-xs text-neutral-500">
              Mínimo 12 caracteres, con mayúsculas, minúsculas, números y símbolos.
            </p>
          </div>

          <div>
            <Label htmlFor="confirm">Repetir nueva password</Label>
            <Input
              id="confirm"
              type="password"
              value={confirm}
              onChange={(e) => setConfirm(e.target.value)}
              required
            />
          </div>

          <Button type="submit" loading={loading} className="w-full" size="lg">
            Cambiar password
          </Button>
        </form>
      </div>
    </div>
  );
}
