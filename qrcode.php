<?php

namespace Plugins\qrcode;

use \Typemill\Plugin;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\Label\Font\NotoSans;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

class qrcode extends Plugin
{
    public static function getSubscribedEvents()
    {
        return [
            'onShortcodeFound' => 'onShortcodeFound',
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

        $this->ensureAutoloader();

        $params   = $shortcodeArray['params'] ?? [];
        $settings = $this->getPluginSettings();
        $html     = $this->renderQrCode($params, $settings);

        $shortcode->setData($html);
    }

    private function renderQrCode(array $params, $settings): string
    {
        $data = $params['data'] ?? '';
        if (empty($data))
        {
            return '<span class="error">QR Code Error: No data provided.</span>';
        }

        $size      = intval($params['size']       ?? $settings['size']             ?? 300);
        $margin    = intval($params['margin']     ?? $settings['margin']           ?? 10);
        $fgHex     = $params['color']      ?? $settings['foreground_color'] ?? '#000000';
        $bgHex     = $params['background'] ?? $settings['background_color'] ?? '#ffffff';
        $label     = $params['label']      ?? $settings['label_text']       ?? '';
        $logoPath  = $params['logo']       ?? $settings['logo_path']        ?? '';
        $logoWidth = isset($params['logo_width'])  ? intval($params['logo_width'])  : (isset($settings['logo_resize']) ? intval($settings['logo_resize']) : null);
        $labelSize = isset($settings['label_font_size']) ? intval($settings['label_font_size']) : 16;
        $alignment = strtolower($params['alignment'] ?? $settings['alignment'] ?? 'left');

        // Build a deterministic cache key from all parameters that affect the image
        $cacheKey = serialize([$data, $size, $margin, $fgHex, $bgHex, $label, $logoPath, $logoWidth, $labelSize]);

        // generateStaticAsset() writes to /cache/generated/qrcode/ and returns a public URL.
        // The generator closure is only called when the file does not yet exist.
        $url = $this->generateStaticAsset(
            $cacheKey,
            function() use ($data, $size, $margin, $fgHex, $bgHex, $label, $logoPath, $logoWidth, $labelSize) {
                return $this->buildPngBytes($data, $size, $margin, $fgHex, $bgHex, $label, $logoPath, $logoWidth, $labelSize);
            },
            'png'
        );

        $alt = !empty($label) ? htmlspecialchars($label) : 'QR Code';

        $alignStyle = match($alignment) {
            'center' => 'margin-left:auto;margin-right:auto;',
            'right'  => 'margin-left:auto;margin-right:0;',
            default  => 'margin-right:auto;margin-left:0;',
        };

        return '<figure class="qrcode-figure" style="display:table;' . $alignStyle . '">'
             . '<img src="' . htmlspecialchars($url) . '" alt="' . $alt . '" class="qrcode-plugin" />'
             . '</figure>';
    }

    private function buildPngBytes(string $data, int $size, int $margin, string $fgHex, string $bgHex, string $label, string $logoPath, ?int $logoWidth, int $labelSize): string
    {
        $fg = $this->hexToRgb($fgHex);
        $bg = $this->hexToRgb($bgHex);

        $builder = Builder::create()
            ->writer(new PngWriter())
            ->writerOptions([])
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
                $builder->logoPath($abs);
                if ($logoWidth) { $builder->logoResizeToWidth($logoWidth); }
                $builder->logoPunchoutBackground(true);
            }
        }

        if (!empty($label))
        {
            $builder->labelText($label)
                    ->labelFont(new NotoSans($labelSize))
                    ->labelAlignment(LabelAlignment::Center);
        }

        return $builder->build()->getString();
    }

    private function ensureAutoloader(): void
    {
        if (file_exists(__DIR__ . '/vendor/autoload.php'))
        {
            require_once __DIR__ . '/vendor/autoload.php';
        }
    }

    private function hexToRgb(string $hex): array
    {
        $hex = str_replace('#', '', $hex);
        if (strlen($hex) === 3)
        {
            $r = hexdec($hex[0] . $hex[0]);
            $g = hexdec($hex[1] . $hex[1]);
            $b = hexdec($hex[2] . $hex[2]);
        }
        else
        {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
        return ['r' => $r, 'g' => $g, 'b' => $b];
    }
}
