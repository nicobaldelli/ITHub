/**
 * Next.js config — ITHub Web
 *
 * Modo Static Export para deploy en Hostinger compartido (sin Node).
 * Si en algún momento se mueve a un host con Node, comentá la línea `output: 'export'`
 * y la app sigue funcionando como SSR.
 */
/** @type {import('next').NextConfig} */
const nextConfig = {
  output: 'export',
  reactStrictMode: true,
  poweredByHeader: false,
  trailingSlash: true,
  images: {
    // Static export no soporta el Image optimization de Next por default
    unoptimized: true,
  },
  // En dev permitimos llamadas desde el container al host
  experimental: {
    instrumentationHook: false,
  },
};

module.exports = nextConfig;
