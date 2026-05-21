'use client';

import { LogOut, User as UserIcon } from 'lucide-react';
import { useState } from 'react';
import { useAuth } from '@/hooks/useAuth';
import { cn } from '@/lib/utils';

export function Topbar({ title }: { title?: string }) {
  const { user, logout } = useAuth();
  const [open, setOpen] = useState(false);

  return (
    <header className="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-neutral-200 bg-white px-6 md:pl-6">
      <h1 className="text-lg font-semibold text-foreground">{title ?? ''}</h1>

      {user && (
        <div className="relative">
          <button
            onClick={() => setOpen((o) => !o)}
            className="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-neutral-100"
          >
            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-sm font-semibold text-white">
              {user.nombre?.[0]?.toUpperCase() ?? '?'}
              {user.apellido?.[0]?.toUpperCase() ?? ''}
            </div>
            <div className="hidden text-left md:block">
              <div className="text-sm font-medium leading-tight">
                {user.nombre} {user.apellido}
              </div>
              <div className="text-xs capitalize text-neutral-500">{user.rol}</div>
            </div>
          </button>

          <div
            className={cn(
              'absolute right-0 mt-2 w-56 origin-top-right rounded-lg border border-neutral-200 bg-white py-1 shadow-card transition-all',
              open ? 'opacity-100' : 'pointer-events-none opacity-0',
            )}
          >
            <a
              href="/mi-perfil"
              className="flex items-center gap-2 px-3 py-2 text-sm hover:bg-neutral-50"
            >
              <UserIcon className="h-4 w-4" />
              Mi perfil
            </a>
            <button
              onClick={logout}
              className="flex w-full items-center gap-2 px-3 py-2 text-sm text-rose-600 hover:bg-rose-50"
            >
              <LogOut className="h-4 w-4" />
              Cerrar sesión
            </button>
          </div>
        </div>
      )}
    </header>
  );
}
