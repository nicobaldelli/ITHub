'use client';

import { useState } from 'react';
import { Copy, Check, AlertTriangle } from 'lucide-react';
import { toast } from 'sonner';
import { Dialog, DialogFooter } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';

export interface PasswordTemporalModalProps {
  open: boolean;
  password: string;
  email: string;
  title?: string;
  onClose: () => void;
}

export function PasswordTemporalModal({
  open,
  password,
  email,
  title = 'Password temporal',
  onClose,
}: PasswordTemporalModalProps) {
  const [copiado, setCopiado] = useState(false);

  async function copiar() {
    try {
      await navigator.clipboard.writeText(password);
      setCopiado(true);
      toast.success('Password copiada al portapapeles');
      setTimeout(() => setCopiado(false), 2500);
    } catch {
      toast.error('No se pudo copiar (navegador bloqueó el portapapeles)');
    }
  }

  return (
    <Dialog open={open} onClose={onClose} title={title} size="md">
      <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
        <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" />
        <div>
          Esta password se muestra <strong>una sola vez</strong>. Anotala o copiala ahora —
          después de cerrar este diálogo no se podrá recuperar. El usuario va a estar obligado
          a cambiarla en su primer login.
        </div>
      </div>

      <Card className="mt-4 p-4">
        <div className="text-xs uppercase tracking-wide text-neutral-500">Usuario</div>
        <div className="mt-1 font-mono text-sm">{email}</div>

        <div className="mt-4 text-xs uppercase tracking-wide text-neutral-500">Password</div>
        <div className="mt-1 flex items-center gap-2">
          <code className="flex-1 rounded-md bg-neutral-100 px-3 py-2 font-mono text-sm">
            {password}
          </code>
          <Button variant="secondary" size="sm" onClick={copiar}>
            {copiado ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
            {copiado ? 'Copiado' : 'Copiar'}
          </Button>
        </div>
      </Card>

      <DialogFooter>
        <Button onClick={onClose}>Entendido</Button>
      </DialogFooter>
    </Dialog>
  );
}
