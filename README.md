# QRCode Plugin for Typemill

Generate QR Codes in your content using simple shortcodes. Powered by `endroid/qr-code`.

## Usage

Use the shortcode `[:qrcode ...:]` in your content. No core modifications required.

### Basic Example
```markdown
[:qrcode data="https://typemill.net":]
```

### Advanced Example
```markdown
[:qrcode data="https://typemill.net" size="400" margin="20" color="#FF0000" background="#FFFFFF" label="Scan me" logo="media/files/logo.png":]
```

### Alignment Example (Right)
```markdown
[:qrcode data="https://typemill.net" alignment="right" label="Scan me":]
```

## Options

These options can be set globally in the Plugin Settings or overridden in the shortcode.

| Option | Shortcode Attribute | Default | Description |
| :--- | :--- | :--- | :--- |
| **Data** | `data` | (required) | The text or URL to encode. |
| **Size** | `size` | `300` | Size of the image in pixels. |
| **Margin** | `margin` | `10` | Margin around the QR code in pixels. |
| **Color** | `color` | `#000000` | Foreground color (Hex). |
| **Background** | `background` | `#ffffff` | Background color (Hex). |
| **Logo** | `logo` | (empty) | Path to a logo image (e.g. `media/files/logo.png`). |
| **Logo Width** | `logo_width` | (empty) | Force a specific width for the logo in pixels. |
| **Label** | `label` | (empty) | Text label displayed below the QR Code. |
| **Alignment** | `alignment` | `left` | Position of the QR Code: `left`, `center`, `right`. |

## How It Works

The plugin hooks into Typemill's native `onShortcodeFound` event. Generated QR code images are written to `/cache/generated/qrcode/` as static PNG files (keyed by a SHA1 hash of all parameters) and served via a normal public URL. Identical shortcodes are served from cache without regenerating the image.

## Version History

*   **v1.2.1** (2026-10-06)
    *   Fixed handling of native shortcodes without attributes so they display the missing-data error instead of raising a type error.
    *   Patched implicit nullable parameters in the bundled Endroid PNG-rendering classes for PHP 8.4/8.5 compatibility. These targeted vendor patches must be retained when replacing the bundled dependencies, or superseded by an upstream-compatible release.
    *   Added standalone regression checks: `php plugins/qrcode/tests/shortcodes.php`.
*   **v1.2.0** (2026-09-08)
    *   Refactored to use Typemill's native `onShortcodeFound` shortcode system (`[:qrcode ...:]` syntax).
    *   Replaced inline base64 data URIs with static cached PNG files via `generateStaticAsset()`.
    *   Removed dependency on core `ParsedownExtension` modification.
    *   Registered shortcode with the editor UI via `registershortcode`.
*   **v1.1.1** (2026-07-05)
    *   Fixed PSR-4 namespace casing compatibility with newer Composer/PHP versions.
*   **v1.1.0** (2026-01-03)
    *   Added Ebook Support (HTML Output Mode).
    *   Added Alignment option (`left`, `center`, `right`).
*   **v1.0.0** (2026-01-02)
    *   Initial release with `endroid/qr-code` v5.0 support.
