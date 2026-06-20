---
name: Etheric Cloud
colors:
  surface: '#111319'
  surface-dim: '#111319'
  surface-bright: '#373940'
  surface-container-lowest: '#0c0e14'
  surface-container-low: '#191b22'
  surface-container: '#1e1f26'
  surface-container-high: '#282a30'
  surface-container-highest: '#33343b'
  on-surface: '#e2e2eb'
  on-surface-variant: '#c8c4d3'
  inverse-surface: '#e2e2eb'
  inverse-on-surface: '#2e3037'
  outline: '#928f9d'
  outline-variant: '#474551'
  surface-tint: '#c6c0ff'
  primary: '#c6c0ff'
  on-primary: '#2c2179'
  primary-container: '#3d348b'
  on-primary-container: '#aba3ff'
  inverse-primary: '#5b53aa'
  secondary: '#f6be3d'
  on-secondary: '#402d00'
  secondary-container: '#c08e00'
  on-secondary-container: '#3e2b00'
  tertiary: '#c4c6d3'
  on-tertiary: '#2d303b'
  tertiary-container: '#3e414c'
  on-tertiary-container: '#abadba'
  error: '#ffb4ab'
  on-error: '#690005'
  error-container: '#93000a'
  on-error-container: '#ffdad6'
  primary-fixed: '#e4dfff'
  primary-fixed-dim: '#c6c0ff'
  on-primary-fixed: '#150066'
  on-primary-fixed-variant: '#433a91'
  secondary-fixed: '#ffdea2'
  secondary-fixed-dim: '#f6be3d'
  on-secondary-fixed: '#261900'
  on-secondary-fixed-variant: '#5c4200'
  tertiary-fixed: '#e0e2f0'
  tertiary-fixed-dim: '#c4c6d3'
  on-tertiary-fixed: '#181b25'
  on-tertiary-fixed-variant: '#444652'
  background: '#111319'
  on-background: '#e2e2eb'
  surface-variant: '#33343b'
typography:
  display-xl:
    fontFamily: DM Sans
    fontSize: 48px
    fontWeight: '700'
    lineHeight: '1.1'
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: DM Sans
    fontSize: 32px
    fontWeight: '600'
    lineHeight: '1.2'
    letterSpacing: -0.01em
  headline-lg-mobile:
    fontFamily: DM Sans
    fontSize: 24px
    fontWeight: '600'
    lineHeight: '1.2'
  body-lg:
    fontFamily: Inter
    fontSize: 18px
    fontWeight: '400'
    lineHeight: '1.6'
  body-md:
    fontFamily: Inter
    fontSize: 15px
    fontWeight: '400'
    lineHeight: '1.5'
  label-sm:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '600'
    lineHeight: '1'
    letterSpacing: 0.05em
  metadata:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '400'
    lineHeight: '1.4'
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  sidebar-width: 72px
  container-padding: 40px
  card-gap: 24px
  inner-padding: 32px
  section-margin: 64px
---

## Brand & Style
The design system is built on a foundation of **Minimalism** and **High-End Utility**, drawing inspiration from the precision of developer tools and the tactile elegance of premium consumer hardware. It targets power users who value focus, speed, and organizational clarity.

The UI should evoke a sense of "digital weightlessness"—files feel archived in a secure, premium vault rather than a cluttered directory. By utilizing a deep navy foundation with luminous accents, the system creates a high-contrast, immersive environment that reduces eye strain while maintaining a distinct professional edge.

The aesthetic avoids structural noise (borders, lines) in favor of **Tonal Layering** and **Subtle Elevation**. Interactions are fluid, with a heavy emphasis on whitespace to signify luxury and ease of use.

## Colors
This design system utilizes a sophisticated dark palette optimized for depth and focus:

*   **Background (#0F1117):** The "Deep Space" foundation. All primary views sit on this neutral, non-reflective base.
*   **Surface (#1A1D27):** Used for cards and elevated containers. This provides a soft contrast against the background without the need for borders.
*   **Accent Purple (#3D348B):** Reserved for primary actions, active states, and focus indicators. It provides a regal, calm energy.
*   **Gold Highlight (#E6AF2E):** Used sparingly for "premium" features, critical alerts, or "starring" items. It acts as a high-visibility contrast point.
*   **Text:** Pure white (#FFFFFF) for primary headings; muted gray (#94A3B8) for metadata and descriptions.

## Typography
The system uses a dual-font approach. **DM Sans** provides a modern, geometric character for headings, while **Inter** ensures maximum legibility for functional data and body text.

Headings should be "confident"—large and bold with tight letter-spacing to create a sense of authority. Metadata should be noticeably smaller and slightly muted in color to create a clear visual hierarchy, ensuring that filenames and primary actions remain the focal point.

## Layout & Spacing
The layout follows a **Fixed-Fluid Hybrid** model:
1.  **Sidebar:** A fixed 72px ultra-slim vertical bar containing icon-only navigation.
2.  **Main Content:** A fluid area with a minimum horizontal margin of 40px. 
3.  **Grid:** Content is organized in a 12-column grid for dashboard views, but individual file views use a flexible masonry or list layout.

Spacing is intentionally "extravagant." Do not crowd elements. Cards must have a minimum of 32px internal padding to breathe. Gaps between grid items should never drop below 24px. The goal is to make the user feel they have unlimited space.

## Elevation & Depth
This design system avoids physical borders. Depth is achieved through two methods:
1.  **Tonal Stacking:** Surfaces (#1A1D27) sit on top of the Background (#0F1117). For sub-modals or flyouts, a slightly lighter tint of the Surface is used.
2.  **Soft Ambient Shadows:** Elevated elements (cards, floating toolbars) use a broad, low-opacity shadow to separate them from the base layer.
    *   *Shadow Profile:* `0px 20px 40px rgba(0, 0, 0, 0.4)`.
3.  **Interactive Lift:** On hover, cards should subtly scale (1.02x) and their shadow should become slightly more diffused to simulate "floating" closer to the user.

## Shapes
The shape language is bold and friendly. 
*   **Main Containers/Cards:** Use a 24px-28px corner radius. This "super-ellipse" feel mimics high-end hardware.
*   **Primary Buttons & Inputs:** Use a 16px corner radius.
*   **Floating Toolbars/Chips:** These are always **Pill-Shaped** (fully rounded ends) to distinguish them as interactive floating utilities rather than structural content.

## Components
### Buttons
*   **Primary:** Solid Accent Purple (#3D348B) with white text. 16px radius.
*   **Secondary:** Ghost style (no fill) with a 1px subtle white outline at 10% opacity. 16px radius.

### Floating Toolbars
Floating toolbars are centered at the bottom of the viewport. They use a Pill-shape, a heavy backdrop blur (20px), and a background of `rgba(26, 29, 39, 0.8)`. Icons inside are 20px and spaced 24px apart.

### Cards
Cards are the primary container for files and folders. No borders. Background is #1A1D27. Padding is 32px. Use a subtle `1px` inner-glow (top-down) for added premium finish.

### Input Fields
Inputs are dark-themed with the same 16px radius as buttons. Background matches the main Background (#0F1117) to create a "punched-out" effect inside the Surface cards.

### Sidebar Icons
The 72px sidebar uses 24px icons. Active states are indicated by a small vertical purple bar (4px wide, 24px tall) on the far left edge of the screen, or a subtle purple glow behind the icon.