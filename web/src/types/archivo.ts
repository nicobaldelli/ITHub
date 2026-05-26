export interface FacturaArchivo {
  id: number;
  factura_id: number;
  drive_file_id: string;
  nombre_archivo: string;
  mime_type: string | null;
  tamanio_bytes: number | null;
  drive_view_url: string | null;
  drive_download_url: string | null;
  uploaded_by: number;
  created_at: string;
}

export interface ArchivosResponse {
  archivos: FacturaArchivo[];
  drive_disponible: boolean;
}
