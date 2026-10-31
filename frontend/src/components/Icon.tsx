import type { ReactNode, SVGProps } from 'react'

const paths = {
  coffee: <><path d="M4 9h12v6a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5Z" /><path d="M16 10h1a3 3 0 1 1 0 6h-1M8 3v2m4-2v2M3 22h15" /></>,
  feed: <><rect x="4" y="3" width="16" height="18" rx="4" /><path d="M8 8h8M8 12h8m-8 4h4" /></>,
  journal: <><path d="M5 19a2 2 0 0 1 2-2h12V3H7a2 2 0 0 0-2 2Zm0 0a2 2 0 0 0 2 2h12v-4M9 7h6m-6 4h4" /></>,
  user: <><circle cx="12" cy="8" r="4" /><path d="M4 21v-2a8 8 0 0 1 16 0v2" /></>,
  bookmark: <path d="M6 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16l-6-4Z" />,
  plus: <path d="M12 5v14M5 12h14" />,
  sun: <><circle cx="12" cy="12" r="4" /><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5" /></>,
  moon: <path d="M20.5 13A8.5 8.5 0 0 1 11 3.5 8.5 8.5 0 1 0 20.5 13Z" />,
  bean: <><ellipse cx="12" cy="12" rx="7.5" ry="10" transform="rotate(35 12 12)" /><path d="M16.5 4.5c-8 2-1 12-9 15" /></>,
  star: <path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9Z" />,
  arrow: <path d="M5 12h14m-6-6 6 6-6 6" />,
  alert: <><circle cx="12" cy="12" r="9" /><path d="M12 7v6m0 4h.01" /></>,
  back: <path d="M19 12H5m6 6-6-6 6-6" />,
  close: <path d="M18 6 6 18M6 6l12 12" />,
  edit: <><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" /><path d="m15 5 4 4" /></>,
  trash: <><path d="M3 6h18M8 6V4h8v2m-9 0 1 14h8l1-14" /><path d="M10 11v6m4-6v6" /></>,
  heart: <path d="M12 20s-7-4.4-7-9.5A4.5 4.5 0 0 1 12 7a4.5 4.5 0 0 1 7 3.5C19 15.6 12 20 12 20Z" />,
  share: <><circle cx="18" cy="5" r="3" /><circle cx="6" cy="12" r="3" /><circle cx="18" cy="19" r="3" /><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4" /></>,
  globe: <><circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18Z" /></>,
  lock: <><rect x="4" y="10" width="16" height="11" rx="3" /><path d="M8 10V7a4 4 0 0 1 8 0v3" /></>,
  link: <><path d="M10 13a5 5 0 0 0 7.5.5l3-3A5 5 0 0 0 13.5 3.5L12 5" /><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3A5 5 0 0 0 10.5 20.5L12 19" /></>,
  camera: <><path d="M3 9a3 3 0 0 1 3-3h1.5l1.2-2h6.6l1.2 2H18a3 3 0 0 1 3 3v8a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3Z" /><circle cx="12" cy="13" r="3.5" /></>,
  check: <path d="m5 13 4 4L19 7" />,
  logout: <><path d="M9 21H6a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3h3" /><path d="m16 16 5-4-5-4M21 12H9" /></>,
  droplet: <path d="M12 3s6 6.3 6 10.5A6 6 0 0 1 6 13.5C6 9.3 12 3 12 3Z" />,
  timer: <><circle cx="12" cy="13" r="8" /><path d="M12 9v4l2.5 2.5M9 2h6" /></>,
  scale: <><path d="M6 21h12M12 3v18M4 9h16l-3 6H7Z" /><circle cx="12" cy="3" r="1" /></>,
  search: <><circle cx="11" cy="11" r="7" /><path d="m21 21-4.3-4.3" /></>,
} satisfies Record<string, ReactNode>

export type IconName = keyof typeof paths

export function Icon({ name, ...props }: SVGProps<SVGSVGElement> & { name: IconName }) {
  return (
    <svg
      width="24"
      height="24"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.7"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
      {...props}
    >
      {paths[name]}
    </svg>
  )
}
