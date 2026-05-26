'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import {
  LayoutDashboard,
  FileText,
  Users,
  Settings,
  ScrollText,
  UserCircle,
  Briefcase,
  Archive,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { useAuthStore } from '@/stores/auth';

const items = [
  { href: '/dashboard', label: 'Dashboard', icon: LayoutDashboard, roles: null },
  { href: '/facturas', label: 'Facturas', icon: FileText, roles: null },
  { href: '/servicios', label: 'Servicios', icon: Briefcase, roles: null },
  { href: '/clientes', label: 'Clientes', icon: Users, roles: null },
  { href: '/usuarios', label: 'Usuarios', icon: UserCircle, roles: ['admin'] },
  { href: '/auditoria', label: 'Auditoría', icon: ScrollText, roles: ['admin'] },
  { href: '/archivados', label: 'Archivados', icon: Archive, roles: ['admin'] },
  { href: '/configuracion', label: 'Configuración', icon: Settings, roles: ['admin'] },
];

export function Sidebar() {
  const pathname = usePathname();
  const user = useAuthStore((s) => s.user);

  return (
    <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col bg-primary text-white md:flex">
      <div className="flex h-16 items-center px-6">
        <span className="text-lg font-semibold tracking-tight">ITHub</span>
        <span className="ml-2 text-xs text-primary-200">Facturación</span>
      </div>

      <nav className="flex-1 space-y-1 px-3 py-4">
        {items.map(({ href, label, icon: Icon, roles }) => {
          if (roles && (!user || !roles.includes(user.rol))) return null;
          const active = pathname === href || pathname.startsWith(href + '/');
          return (
            <Link
              key={href}
              href={href}
              className={cn(
                'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                active
                  ? 'bg-primary-800 text-white'
                  : 'text-primary-100 hover:bg-primary-700 hover:text-white',
              )}
            >
              <Icon className="h-4 w-4" />
              {label}
            </Link>
          );
        })}
      </nav>

      <div className="border-t border-primary-700 p-3 text-xs text-primary-200">
        {user && (
          <div className="px-2 py-1">
            <div className="font-medium text-white">
              {user.nombre} {user.apellido}
            </div>
            <div className="capitalize">{user.rol}</div>
          </div>
        )}
      </div>
    </aside>
  );
}
