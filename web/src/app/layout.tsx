import type { Metadata } from 'next';
import { Saira } from 'next/font/google';
import { Toaster } from 'sonner';
import './globals.css';

const saira = Saira({
  subsets: ['latin'],
  weight: ['400', '500', '600', '700'],
  variable: '--font-saira',
  display: 'swap',
});

export const metadata: Metadata = {
  title: 'ITHub — Facturación',
  description: 'Gestión de facturas de venta — IntelliHelp',
  robots: { index: false, follow: false },
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="es-AR" className={saira.variable}>
      {/*
        suppressHydrationWarning evita los avisos cuando alguna extensión
        del browser (ColorZilla, Grammarly, etc.) inyecta atributos en el
        body antes de que React hidrate. No afecta otras diferencias.
      */}
      <body className="min-h-screen" suppressHydrationWarning>
        {children}
        <Toaster
          position="top-right"
          richColors
          closeButton
          toastOptions={{ style: { fontFamily: 'inherit' } }}
        />
      </body>
    </html>
  );
}
