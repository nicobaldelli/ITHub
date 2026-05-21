'use client';

import { create } from 'zustand';
import type { User } from '@/types/api';

/**
 * Auth store — solo en memoria (NO se persiste).
 * El refresh token vive en cookie HttpOnly del lado del server,
 * el access token vive en este store en RAM y se pierde al recargar.
 *
 * Al recargar, AppGuard llama a /auth/refresh para reobtener un access nuevo.
 */
interface AuthState {
  user: User | null;
  accessToken: string | null;
  accessExpiresAt: number | null; // epoch seconds
  csrfToken: string | null;
  hydrated: boolean; // true cuando ya intentamos un refresh inicial
  setSession: (params: {
    user: User;
    accessToken: string;
    accessExpiresAt: number;
    csrfToken?: string | null;
  }) => void;
  clear: () => void;
  setHydrated: (v: boolean) => void;
  updateUser: (user: User) => void;
}

export const useAuthStore = create<AuthState>((set) => ({
  user: null,
  accessToken: null,
  accessExpiresAt: null,
  csrfToken: null,
  hydrated: false,

  setSession: ({ user, accessToken, accessExpiresAt, csrfToken }) =>
    set((s) => ({
      user,
      accessToken,
      accessExpiresAt,
      csrfToken: csrfToken ?? s.csrfToken,
    })),

  clear: () => set({ user: null, accessToken: null, accessExpiresAt: null, csrfToken: null }),

  setHydrated: (v) => set({ hydrated: v }),

  updateUser: (user) => set({ user }),
}));
