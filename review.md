# QRCode Plugin – Code Review

## Summary

The plugin has three distinct problems: it bypasses Typemill's native shortcode system entirely, it requires a dangerous modification to core security code, and it uses a delivery mechanism (base64 data URIs) that is both unnecessary and inappropriate. Each issue is described below, followed by a concrete refactoring proposal.

---

## Problem 1: Custom Shortcode Parser Replaces the Core Mechanism

### What the plugin does

`onMarkdownLoaded` intercepts the raw Markdown string before it reaches the Parsedown parser and runs its own `preg_replace_callback` against it, replacing `[qrcode ...]` tokens with Markdown image syntax or raw HTML before Typemill ever sees the content.

```php
// qrcode.php – lines 52-63
public function onMarkdownLoaded($plugindata)
{
    $markdown = $plugindata->getData();
    $regex = '/\[qrcode(.*?)\]/s';
    $newMarkdown = preg_replace_callback($regex, array($this, 'processShortcode'), $markdown);
    $plugindata->setData($newMarkdown);
}
```

### Why this is wrong

Typemill has a fully-featured shortcode system built into `ParsedownExtension`. Shortcodes use the syntax `[:name attr="value":]` and are dispatched through the `onShortcodeFound` event. Any plugin that needs to add a shortcode subscribes to that event, checks for its own shortcode name, and calls `$shortcode->setData($html)` to return the rendered HTML. See `MediaExtension.php` (video/audio shortcodes) and `demo.php` (`contactform` shortcode) for the canonical pattern.

By hooking `onMarkdownLoaded` instead, the plugin:

1. **Invents a parallel, incompatible shortcode syntax** (`[qrcode ...]` with single brackets) that conflicts with standard Markdown link/image syntax and is invisible to Typemill's shortcode registry.
2. **Breaks the shortcode allow-list** (`setAllowedShortcodes`). Typemill lets administrators restrict which shortcodes are available. Because the plugin bypasses `ParsedownExtension` entirely, the allow-list is never consulted.
3. **Breaks the editor UI integration.** The `onShortcodeFound` event also handles the `registershortcode` name, which is how plugins declare themselves to the visual editor. This plugin never registers itself.
4. **Processes content at the wrong stage.** `onMarkdownLoaded` fires before Parsedown runs. Injecting raw HTML at that point relies on undocumented Parsedown behavior and can interact badly with other plugins that also transform Markdown.
5. **Duplicates the attribute parser.** The custom `parseAttributes()` method (lines 162-172) only handles double-quoted values and silently discards any attribute without quotes. The core `shortcodeParams()` in `ParsedownExtension` handles the same job for all shortcodes correctly.

---

## Problem 2: The Plugin Requires Modifying Core Security Code

### What the README instructs

The README tells users to edit `system/typemill/Extensions/ParsedownExtension.php` to allow `data:image/` URIs through the security filter that currently blocks all `data:` schemes:

```php
// Proposed core change from README
if (strtolower($scheme) === 'data' && stripos($href, 'data:image/') === 0)
{
    // Allow base64 data URIs for images
}
```

### Why this is wrong

Modifying core files is explicitly an anti-pattern in any plugin-based CMS:

1. **Core changes are wiped out on every Typemill update.** The modification must be manually re-applied after each upgrade.
2. **The security filter exists for a reason.** The `sanitizeUrl` method in `ParsedownExtension` (lines 934-952) blocks `data:` URIs because they are a well-known XSS vector. Punching a hole in that filter – even for images – widens the attack surface. A malicious author could craft a `data:image/svg+xml` URI containing executable script.
3. **The need for this change is entirely self-inflicted.** The plugin requires this bypass only because it injects a base64 data URI into the Markdown string, which then passes through the Parsedown image handler and hits the security filter. If the plugin used the correct approach (see Problem 3 below), no core modification would be needed at all.

---

## Problem 3: Base64 Data URIs Are the Wrong Delivery Mechanism

### What the plugin does

The plugin calls `$result->getDataUri()` and embeds the resulting `data:image/png;base64,...` string directly into either a Markdown image reference or an HTML `<img src="...">` tag.

### Why this is wrong

1. **Data URIs are not cacheable.** Every page load regenerates or re-transmits the entire base64-encoded image inline. A 300 × 300 px PNG typically encodes to roughly 20–40 KB of base64 text inserted verbatim into the HTML response.
2. **They bloat HTML responses.** Content Security Policies on hardened servers commonly block inline data URIs for images.
3. **The Plugin base class already provides the correct solution.** `Plugin::generateStaticAsset()` (lines 360-387 of `Plugin.php`) was designed precisely for this use case. It writes generated binary content to `/cache/generated/{namespace}/` using a SHA1 hash of the source data as the filename, and returns a regular public URL. If the file already exists it is reused without re-running the generator. This gives free HTTP caching and avoids bloating HTML.

---

## Proposed Refactoring

### 1. Change the shortcode syntax to `[:qrcode ...:]`

Use Typemill's native syntax so the shortcode is handled by `ParsedownExtension` together with all other shortcodes.

### 2. Replace `onMarkdownLoaded` with `onShortcodeFound`

```php
public static function getSubscribedEvents()
{
    return [
        'onShortcodeFound' => 'onShortcodeFound',
        'onTwigLoaded'     => 'onTwigLoaded',
    ];
}

public function onShortcodeFound($shortcode)
{
    $shortcodeArray = $shortcode->getData();

    // Register the shortcode so the editor UI knows about it
    if (is_array($shortcodeArray) && $shortcodeArray['name'] === 'registershortcode')
    {
        $shortcodeArray['data']['qrcode'] = [
            'data'       => '',
            'size'       => '300',
            'margin'     => '10',
            'color'      => '#000000',
            'background' => '#ffffff',
            'alignment'  => 'left',
            'label'      => '',
            'logo'       => '',
        ];
        $shortcode->setData($shortcodeArray);
        return;
    }

    if (!is_array($shortcodeArray) || $shortcodeArray['name'] !== 'qrcode')
    {
        return;
    }

    $shortcode->stopPropagation();

    $params   = $shortcodeArray['params'] ?? [];
    $settings = $this->getPluginSettings();
    $html     = $this->renderQrCode($params, $settings);

    $shortcode->setData($html);
}
```

### 3. Replace data URIs with static file assets via `generateStaticAsset()`

```php
private function renderQrCode(array $params, $settings): string
{
    $this->ensureAutoloader();

    $data      = $params['data'] ?? '';
    if (empty($data))
    {
        return '<span class="error">QR Code Error: No data provided.</span>';
    }

    // Collect all options that affect the rendered image
    $size      = intval($params['size']       ?? $settings['size']             ?? 300);
    $margin    = intval($params['margin']     ?? $settings['margin']           ?? 10);
    $fgHex     = $params['color']      ?? $settings['foreground_color'] ?? '#000000';
    $bgHex     = $params['background'] ?? $settings['background_color'] ?? '#ffffff';
    $label     = $params['label']      ?? $settings['label_text']       ?? '';
    $logoPath  = $params['logo']       ?? $settings['logo_path']        ?? '';
    $alignment = strtolower($params['alignment'] ?? $settings['alignment'] ?? 'left');

    // Build a deterministic cache key from all parameters
    $cacheKey  = serialize([$data, $size, $margin, $fgHex, $bgHex, $label, $logoPath]);

    // generateStaticAsset() writes to /cache/generated/qrcode/ and returns a URL.
    // The generator closure is only called when the file does not yet exist.
    $url = $this->generateStaticAsset(
        $cacheKey,
        function() use ($data, $size, $margin, $fgHex, $bgHex, $label, $logoPath) {
            return $this->buildPngBytes($data, $size, $margin, $fgHex, $bgHex, $label, $logoPath);
        },
        'png'
    );

    $alt           = !empty($label) ? htmlspecialchars($label) : 'QR Code';
    $alignStyle    = match($alignment) {
        'center' => 'margin-left:auto;margin-right:auto;',
        'right'  => 'margin-left:auto;margin-right:0;',
        default  => 'margin-right:auto;margin-left:0;',
    };

    return '<figure class="qrcode-figure" style="display:table;' . $alignStyle . '">'
         . '<img src="' . htmlspecialchars($url) . '" alt="' . $alt . '" class="qrcode-plugin" />'
         . '</figure>';
}

private function buildPngBytes(string $data, int $size, int $margin, string $fgHex, string $bgHex, string $label, string $logoPath): string
{
    $fg = $this->hexToRgb($fgHex);
    $bg = $this->hexToRgb($bgHex);

    $builder = Builder::create()
        ->writer(new PngWriter())
        ->data($data)
        ->encoding(new Encoding('UTF-8'))
        ->errorCorrectionLevel(\Endroid\QrCode\ErrorCorrectionLevel::High)
        ->size($size)
        ->margin($margin)
        ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
        ->foregroundColor(new \Endroid\QrCode\Color\Color($fg['r'], $fg['g'], $fg['b']))
        ->backgroundColor(new \Endroid\QrCode\Color\Color($bg['r'], $bg['g'], $bg['b']));

    if (!empty($logoPath))
    {
        $abs = getcwd() . '/' . ltrim($logoPath, '/');
        if (file_exists($abs))
        {
            $builder->logoPath($abs)->logoPunchoutBackground(true);
        }
    }

    if (!empty($label))
    {
        $builder->labelText($label)->labelFont(new NotoSans(16))->labelAlignment(LabelAlignment::Center);
    }

    return $builder->build()->getString();
}
```

### 4. Remove the Twig filter hack for HTML/ebook mode

The current `onTwigLoaded` hook registers a Twig filter called `qrcode` and runs `processHtmlContent()` through it, which again calls its own regex shortcode parser. With the correct `onShortcodeFound` approach the shortcode is already resolved to HTML before the Twig template ever sees the content, so no Twig filter is needed at all. The ebook/HTML output path will work automatically.

### 5. Remove the CSS alignment hack

The current code appends fragment identifiers (`#align-left`, `#align-right`, `#align-center`) to the image URL and uses CSS attribute selectors to apply alignment. This is non-standard and brittle. With static file URLs the alignment is better handled directly in the `<figure>` wrapper's inline style (as shown in the refactored `renderQrCode()` above), or via a short stylesheet block added with `addInlineCSS()` using proper class names.

### 6. Remove the instruction to patch core

Once data URIs are replaced with static file URLs the `sanitizeUrl` filter in `ParsedownExtension` is never triggered for QR code images. The README section "Requirements" and the accompanying core patch can be deleted entirely.

---

## Summary of Required Changes

| # | File | Action |
|---|------|--------|
| 1 | `qrcode.php` | Replace `onMarkdownLoaded` + custom regex parser with `onShortcodeFound` |
| 2 | `qrcode.php` | Replace `getDataUri()` with `generateStaticAsset()` + `getString()` |
| 3 | `qrcode.php` | Remove the Twig filter registered in `onTwigLoaded` |
| 4 | `qrcode.php` | Remove the CSS fragment-identifier alignment hack |
| 5 | `qrcode.php` | Register shortcode name via `registershortcode` so the editor UI is aware |
| 6 | `README.md` | Remove the "Requirements" section that instructs core modification |
| 7 | `system/typemill/Extensions/ParsedownExtension.php` | No changes needed (leave untouched) |
