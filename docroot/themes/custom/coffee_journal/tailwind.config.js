/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './templates/**/*.twig',
    './js/**/*.js',
    './src/**/*.css',
  ],
  darkMode: 'class',
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'Open Sans', 'system-ui', '-apple-system', 'sans-serif'],
      },
      colors: {
        // Coffee palette — maps to CSS custom properties
        cj: {
          bg:        'hsl(28 4% 8%)',
          surface:   'hsl(28 5% 10%)',
          elevated:  'hsl(28 5% 13%)',
          muted:     'hsl(28 6% 18%)',
          border:    'hsl(28 4% 18%)',
          'border-card': 'hsl(28 5% 14%)',
          text:      'hsl(30 5% 95%)',
          'text-muted': 'hsl(30 5% 65%)',
          'text-faint': 'hsl(30 4% 45%)',
          primary:   'hsl(25 85% 42%)',
          'primary-hover': 'hsl(25 85% 50%)',
          'primary-fg': 'hsl(25 10% 98%)',
          star:      '#f59e0b',
        },
      },
      borderRadius: {
        'cj': '0.5rem',
        'cj-lg': '0.75rem',
      },
    },
  },
  plugins: [],
};
