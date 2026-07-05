# QRCode Plugin for Typemill

Generate QR Codes in your content using simple shortcodes. Supports **HTML** and **Markdown** output modes automatically. Powered by `endroid/qr-code`.

## Requirements

To prevent Typemill's security filter from blocking the base64-encoded QR code images, you must allow `data:image/` URIs in the Markdown parser.

Edit `system/typemill/Extensions/ParsedownExtension.php` around line 981 (in the `inlineLink` method) to allow image data URIs:

```php
# block dangerous URI schemes (e.g. javascript:, vbscript:, data:)
$scheme = parse_url($href, PHP_URL_SCHEME);
if ($scheme !== null && !in_array(strtolower($scheme), ['http', 'https', 'mailto', 'ftp'], true))
{
    if (strtolower($scheme) === 'data' && stripos($href, 'data:image/') === 0)
    {
        // Allow base64 data URIs for images
    }
    else
    {
        $href = '#';
    }
}
```

## Usage

Use the shortcode `[qrcode ...]` in your content.

### Basic Example
```markdown
[qrcode data="https://typemill.net"]
```

### Advanced Example
```markdown
[qrcode data="https://typemill.net" size="400" margin="20" color="#FF0000" background="#FFFFFF" label="Scan me" logo="media/files/logo.png"]
```

### Alignment Example (Right)
```markdown
[qrcode data="https://typemill.net" alignment="right" label="Scan me"]
```

## Options

These options can be set globally in the Plugin Settings or overridden in the shortcode.

| Option | Shortcode Attribute | default | Description |
| :--- | :--- | :--- | :--- |
| **Data** | `data` | (required) | The text or URL to encode. |
| **Size** | `size` | `300` | Size of the image in pixels. |
| **Margin** | `margin` | `10` | Margin around the QR code in pixels. |
| **Color** | `color` | `#000000` | Foreground color (Hex). |
| **Background** | `background` | `#ffffff` | Background color (Hex). |
| **Logo** | `logo` | (empty) | Path to a logo image (e.g. `media/files/logo.png`). |
| **Logo Width** | `logo_width` | (empty) | Force a specific width for the logo. |
| **Label** | `label` | (empty) | Text label displayed below the QR Code. |
| **Alignment** | `alignment` | `left` | Position of the QR Code: `left`, `center`, `right`. |

## Version History

*   **v1.1.1** (2026-07-05)
    *   Fixed PSR-4 namespace casing compatibility with newer Composer/PHP versions.
    *   Resolved conflict with Typemill Parsedown security filter blocking base64 `data:` URI images.
*   **v1.1.0** (2026-01-03)
    *   Added **Ebook Support** (HTML Output Mode).
    *   Added **Alignment** option (`left`, `center`, `right`).
*   **v1.0.0** (2026-01-02)
    *   Initial release with `endroid/qr-code` v5.0 support.
    *   Shortcode `[qrcode]` integration.
    *   Customizable colors, logo, and label.
