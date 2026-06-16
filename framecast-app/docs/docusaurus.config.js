// @ts-check
// WyvStudio knowledgebase — docs-only Docusaurus site.
// Content lives in docs/ as Markdown; sidebar auto-generates from folders.

import {themes as prismThemes} from 'prism-react-renderer';

/** @type {import('@docusaurus/types').Config} */
const config = {
  title: 'WyvStudio Help',
  tagline: 'Branded short-form video without a shoot — guides & how-tos',
  favicon: 'img/favicon.ico',

  url: 'https://docs.wyvstudio.com',
  baseUrl: '/',

  organizationName: 'wyvstudio',
  projectName: 'wyvstudio-docs',

  onBrokenLinks: 'warn',
  markdown: {hooks: {onBrokenMarkdownLinks: 'warn'}},

  i18n: {defaultLocale: 'en', locales: ['en']},

  presets: [
    [
      'classic',
      /** @type {import('@docusaurus/preset-classic').Options} */
      ({
        docs: {
          routeBasePath: '/',          // docs-only: guides live at the site root
          sidebarPath: './sidebars.js',
          editUrl: undefined,           // no "edit this page" link
        },
        blog: false,                    // knowledgebase, not a blog
        theme: {customCss: './src/css/custom.css'},
      }),
    ],
  ],

  themeConfig:
    /** @type {import('@docusaurus/preset-classic').ThemeConfig} */
    ({
      image: 'img/wyvstudio-social.png',
      colorMode: {defaultMode: 'dark', respectPrefersColorScheme: true},
      navbar: {
        title: 'WyvStudio Help',
        logo: {alt: 'WyvStudio', src: 'img/logo.svg'},
        items: [
          {type: 'docSidebar', sidebarId: 'guides', position: 'left', label: 'Guides'},
          {href: 'https://app.wyvstudio.com', label: 'Open the app', position: 'right'},
          {href: 'https://wyvstudio.com', label: 'wyvstudio.com', position: 'right'},
        ],
      },
      footer: {
        style: 'dark',
        links: [
          {
            title: 'Guides',
            items: [
              {label: 'Getting started', to: '/getting-started/create-your-first-video'},
              {label: 'Make a video', to: '/make-a-video/from-a-product-url'},
              {label: 'Export & publish', to: '/export-and-publish/export-every-aspect-ratio'},
            ],
          },
          {
            title: 'Product',
            items: [
              {label: 'Open the app', href: 'https://app.wyvstudio.com'},
              {label: 'Pricing', href: 'https://wyvstudio.com/#pricing'},
              {label: 'Status', href: 'https://status.wyvstudio.com'},
            ],
          },
        ],
        copyright: `© ${new Date().getFullYear()} WyvStudio.`,
      },
      prism: {theme: prismThemes.github, darkTheme: prismThemes.dracula},
    }),
};

export default config;
