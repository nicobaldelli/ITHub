import type { Config } from 'tailwindcss';

/**
 * Paleta corporativa ITHub:
 *  - background  #FFFFFF
 *  - primary     #663399 (violeta IntelliHelp)
 *  - foreground  #161922 (negro/grafito para textos)
 *  - accent      #9CC930 (verde — uso moderado, solo positivos)
 */
const config: Config = {
  content: ['./src/**/*.{ts,tsx,js,jsx,mdx}'],
  theme: {
    container: {
      center: true,
      padding: '1rem',
    },
    extend: {
      colors: {
        background: '#FFFFFF',
        foreground: '#161922',
        primary: {
          DEFAULT: '#663399',
          50: '#F5EFFA',
          100: '#E8DBF2',
          200: '#D3B8E5',
          300: '#B68FD2',
          400: '#9866BE',
          500: '#7B45AB',
          600: '#663399', // brand
          700: '#552B7F',
          800: '#432263',
          900: '#321A4A',
        },
        accent: {
          DEFAULT: '#9CC930',
          50: '#F1F8DD',
          100: '#E2F0BB',
          200: '#C9E388',
          300: '#B0D654',
          400: '#9CC930', // brand
          500: '#80AA22',
          600: '#608018',
          700: '#465F11',
        },
        neutral: {
          50: '#F8F9FB',
          100: '#EFF1F5',
          200: '#DDE0E8',
          300: '#C2C7D2',
          400: '#9097A6',
          500: '#6B7283',
          600: '#4B5263',
          700: '#363B49',
          800: '#252934',
          900: '#161922', // brand foreground
        },
        success: '#9CC930',
        warning: '#F59E0B',
        danger: '#E11D48',
        info: '#0EA5E9',
      },
      fontFamily: {
        sans: ['var(--font-saira)', 'system-ui', 'sans-serif'],
      },
      borderRadius: {
        lg: '0.75rem',
        xl: '1rem',
      },
      boxShadow: {
        card: '0 1px 3px 0 rgb(22 25 34 / 0.06), 0 1px 2px -1px rgb(22 25 34 / 0.04)',
        cardHover: '0 4px 12px -2px rgb(22 25 34 / 0.10), 0 2px 4px -2px rgb(22 25 34 / 0.06)',
      },
    },
  },
  plugins: [],
};

export default config;
