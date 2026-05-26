import type { Rol } from './api';

export interface Usuario {
  id: number;
  nombre: string;
  apellido: string;
  email: string;
  rol: Rol;
  activo: boolean;
  must_change_password: boolean;
  last_login: string | null;
  last_login_ip: string | null;
  created_at: string;
  updated_at: string;
}

export interface UsuarioConPassword {
  user: Usuario;
  password_temporal: string;
  must_change_password?: boolean;
}
