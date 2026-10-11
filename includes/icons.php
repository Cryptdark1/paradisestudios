<?php

/**
 * Paradise Studios — inline SVG icon helper.
 *
 * Added alongside the existing frontend work. This file is purely additive:
 * nothing here replaces or alters the behaviour of includes/functions.php or
 * any existing API.
 *
 * Icons are 24x24, stroke-based and inherit `currentColor`, which keeps them
 * consistent with the theme without needing an icon font.
 */

if (!function_exists('ps_icon_paths')) {
    /**
     * Return the SVG body for a given icon name.
     *
     * @return string|null the SVG inner markup, or null for an unknown icon
     */
    function ps_icon_paths(string $name): ?string
    {
        static $icons = [
            /* Navigation */
            'dashboard'  => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
            'studios'    => '<path d="M3 21h18"/><path d="M5 21V5.5A2.5 2.5 0 0 1 7.5 3H13a2 2 0 0 1 2 2.5V21"/><path d="M15 9.5h1.5A2.5 2.5 0 0 1 19 12v9"/><path d="M9 7h2M9 11h2M9 15h2"/>',
            'bookings'   => '<rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M16 3v4M8 3v4M3 10h18"/>',
            'analytics'  => '<path d="M3 3v15.5A2.5 2.5 0 0 0 5.5 21H21"/><path d="m7 15 3.6-4.2 3 2.6L18 7"/><circle cx="18" cy="7" r="1.4"/>',
            'chart-line' => '<path d="M3 3v15.5A2.5 2.5 0 0 0 5.5 21H21"/><path d="m6 16 4-5 3.5 3L20 6"/><circle cx="20" cy="6" r="1.4"/>',
            'profile'    => '<circle cx="12" cy="8" r="3.75"/><path d="M4.5 20.5v-1a5.5 5.5 0 0 1 5.5-5.5h4a5.5 5.5 0 0 1 5.5 5.5v1"/>',

            /* Interface */
            'menu'       => '<path d="M3.5 6.5h17M3.5 12h17M3.5 17.5h17"/>',
            'close'      => '<path d="M18 6 6 18M6 6l12 12"/>',
            'search'     => '<circle cx="11" cy="11" r="7"/><path d="m20.5 20.5-4.2-4.2"/>',
            'plus'       => '<path d="M12 5.25v13.5M5.25 12h13.5"/>',
            'minus'      => '<path d="M5.25 12h13.5"/>',
            'edit'       => '<path d="M17.5 3.2a2.6 2.6 0 0 1 3.7 3.7L7.6 20.5l-5 1.3 1.3-5z"/>',
            'chevron-down'  => '<path d="m6 9.5 6 6 6-6"/>',
            'chevron-up'    => '<path d="m6 14.5 6-6 6 6"/>',
            'chevron-left'  => '<path d="m14.5 5.5-6.5 6.5 6.5 6.5"/>',
            'chevron-right' => '<path d="m9.5 5.5 6.5 6.5-6.5 6.5"/>',
            'arrow-up'   => '<path d="M12 19V5M5.5 11.5 12 5l6.5 6.5"/>',
            'arrow-down' => '<path d="M12 5v14M18.5 12.5 12 19l-6.5-6.5"/>',
            'external'   => '<path d="M14 3.5h6.5V10"/><path d="M10.5 13.5 20 4"/><path d="M20 14.5V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4.5"/>',
            'refresh'    => '<path d="M20.5 12a8.5 8.5 0 1 1-2.9-6.4"/><path d="M20.5 4.5V10H15"/>',
            'more'       => '<circle cx="5" cy="12" r="1.6" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1.6" fill="currentColor" stroke="none"/>',
            'filter'     => '<path d="M3.5 5.5h17l-6.6 7.8V19l-3.8 2v-7.7z"/>',
            'list'       => '<path d="M8.5 6.5h12M8.5 12h12M8.5 17.5h12"/><circle cx="4" cy="6.5" r="1.2" fill="currentColor" stroke="none"/><circle cx="4" cy="12" r="1.2" fill="currentColor" stroke="none"/><circle cx="4" cy="17.5" r="1.2" fill="currentColor" stroke="none"/>',
            'sliders'    => '<path d="M4 20.5V14M4 10V3.5M12 20.5V12M12 8V3.5M20 20.5V16M20 12V3.5M1.5 14h5M9.5 8h5M17.5 16h5"/>',
            'logout'     => '<path d="M9.5 20.5H5.5a2 2 0 0 1-2-2v-13a2 2 0 0 1 2-2h4"/><path d="m16 16.5 4.5-4.5L16 7.5"/><path d="M20.5 12H9.5"/>',
            'settings'   => '<circle cx="12" cy="12" r="3.2"/><path d="M19.1 14.5a1.6 1.6 0 0 0 .32 1.77l.06.06a1.95 1.95 0 1 1-2.76 2.76l-.06-.06a1.6 1.6 0 0 0-1.77-.32 1.6 1.6 0 0 0-.97 1.47v.16a1.95 1.95 0 0 1-3.9 0v-.09a1.6 1.6 0 0 0-1.05-1.47 1.6 1.6 0 0 0-1.77.32l-.06.06A1.95 1.95 0 1 1 4.36 16.4l.06-.06a1.6 1.6 0 0 0 .32-1.77 1.6 1.6 0 0 0-1.47-.97h-.16a1.95 1.95 0 1 1 0-3.9h.09a1.6 1.6 0 0 0 1.47-1.05 1.6 1.6 0 0 0-.32-1.77l-.06-.06A1.95 1.95 0 1 1 7.05 4.1l.06.06a1.6 1.6 0 0 0 1.77.32h.07a1.6 1.6 0 0 0 .97-1.47v-.16a1.95 1.95 0 0 1 3.9 0v.09a1.6 1.6 0 0 0 .97 1.47 1.6 1.6 0 0 0 1.77-.32l.06-.06a1.95 1.95 0 1 1 2.76 2.76l-.06.06a1.6 1.6 0 0 0-.32 1.77v.07a1.6 1.6 0 0 0 1.47.97h.16a1.95 1.95 0 0 1 0 3.9h-.09a1.6 1.6 0 0 0-1.47.97z"/>',

            /* Theme */
            'moon'       => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
            'sun'        => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2.2M12 19.8V22M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2 12h2.2M19.8 12H22M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/>',

            /* Status */
            'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m8.2 12.3 2.6 2.6 5-5.4"/>',
            'x-circle'     => '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/>',
            'alert'        => '<path d="M10.3 4 2.3 18.2A2 2 0 0 0 4 21.2h16a2 2 0 0 0 1.7-3L13.7 4a2 2 0 0 0-3.4 0z"/><path d="M12 9.5v4M12 17h.01"/>',
            'info'         => '<circle cx="12" cy="12" r="9"/><path d="M12 11.2v5M12 7.8h.01"/>',
            'clock'        => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.3l3.2 1.9"/>',
            'inbox'        => '<path d="M3 12.5h4.5l1.6 2.8h5.8l1.6-2.8H21"/><path d="M5.6 4.5h12.8l2.6 8v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-5z"/>',

            /* Domain */
            'map-pin'    => '<path d="M20 10.2c0 5.6-8 11.3-8 11.3s-8-5.7-8-11.3a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="2.8"/>',
            'mic'        => '<rect x="9" y="2.5" width="6" height="11" rx="3"/><path d="M5 11v1a7 7 0 0 0 14 0v-1"/><path d="M12 19v2.5M9 21.5h6"/>',
            'music'      => '<path d="M9 17.5V4.8l11-2v12.4"/><ellipse cx="6.2" cy="17.6" rx="2.7" ry="2.4"/><ellipse cx="17.2" cy="15.6" rx="2.7" ry="2.4"/>',
            'wallet'     => '<path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H16a2 2 0 0 1 2 2v1"/><path d="M3 7.5v10A2.5 2.5 0 0 0 5.5 20h13a2.5 2.5 0 0 0 2.5-2.5v-6a1 1 0 0 0-1-1H5.5A2.5 2.5 0 0 1 3 8"/><circle cx="16.5" cy="14.5" r="1.1" fill="currentColor" stroke="none"/>',
            'star'       => '<path d="m12 3.5 2.6 5.4 5.9.8-4.3 4.2 1 5.9-5.2-2.8-5.2 2.8 1-5.9-4.3-4.2 5.9-.8z"/>',
            'bolt'       => '<path d="M13.2 2.5 4.5 13.4h6.1l-.8 8.1 8.7-10.9h-6.1z"/>',
            'shield'     => '<path d="M12 3 20 6v6c0 4.8-3.4 8.2-8 9.3C7.4 20.2 4 16.8 4 12V6z"/><path d="m9 12 2.2 2.2L15.2 10"/>',
            'lock'       => '<rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
            'badge-check'=> '<path d="M12 2.6 14.3 5l3.3-.3.9 3.2 2.6 2-1.6 2.9 1.6 2.9-2.6 2-.9 3.2-3.3-.3L12 22.4 9.7 20l-3.3.3-.9-3.2-2.6-2L4.5 12 2.9 9.1l2.6-2 .9-3.2L9.7 5z"/><path d="m9.2 12.2 2 2 3.6-3.9"/>',
            'ticket'     => '<path d="M3 9V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2.2 2.2 0 0 0 0 6v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2.2 2.2 0 0 0 0-6z"/><path d="M12 7.5v9"/>',
            'save'       => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7.5 3v4.5h8"/>',
            'image'      => '<rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="8.5" cy="9.5" r="1.6"/><path d="m3.5 17 4.8-4.6a2 2 0 0 1 2.7 0l3.2 3.1a2 2 0 0 0 2.7 0l3.6-3.4"/>',
            'trash'      => '<path d="M4 7h16M9.5 7V5.2A1.2 1.2 0 0 1 10.7 4h2.6a1.2 1.2 0 0 1 1.2 1.2V7"/><path d="M6 7.5 6.9 20a1.5 1.5 0 0 0 1.5 1.4h7.2a1.5 1.5 0 0 0 1.5-1.4L18 7.5"/>',
        ];

        return $icons[$name] ?? null;
    }
}

if (!function_exists('ps_icon')) {
    /**
     * Build an inline SVG icon.
     *
     * @param string $name      Icon key (see ps_icon_paths)
     * @param string $class     Extra CSS classes
     * @param int    $size      Pixel size
     * @param string $label     Accessible label; omit for decorative icons
     */
    function ps_icon(string $name, string $class = '', int $size = 20, string $label = ''): string
    {
        $body = ps_icon_paths($name);

        if ($body === null) {
            return '';
        }

        $attrs = sprintf(
            'class="ps-icon %s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false"',
            e($class),
            $size,
            $size
        );

        if ($label !== '') {
            $attrs .= sprintf(' role="img" aria-label="%s"', e($label));
        } else {
            $attrs .= ' aria-hidden="true"';
        }

        return sprintf('<svg %s>%s</svg>', $attrs, $body);
    }
}