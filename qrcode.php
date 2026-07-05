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
    private $outputMode = 'markdown'; // 'markdown' or 'html'

    public static function getSubscribedEvents()
    {
        return [
            'onMarkdownLoaded' => 'onMarkdownLoaded',
            'onTwigLoaded'     => 'onTwigLoaded'
        ];
    }

    public function onTwigLoaded($event)
    {
        $this->addTwigFilter('qrcode', function($content) {
            $this->outputMode = 'html';
            return $this->processHtmlContent($content);
        });

        // CSS Injection for Frontend Alignment
        // 1. Image Level: For raw images
        // 2. Figure Level: Using :has() to target the parent figure wrapper inserted by Typemill/Parsedown
        $css = '
            /* Right Alignment */
            img[src*="#align-right"] { display: block !important; margin-left: auto !important; margin-right: 0 !important; }
            figure:has(img[src*="#align-right"]) { margin-left: auto !important; margin-right: 0 !important; display: table !important; }

            /* Left Alignment */
            img[src*="#align-left"] { display: block !important; margin-right: auto !important; margin-left: 0 !important; }
            figure:has(img[src*="#align-left"]) { margin-right: auto !important; margin-left: 0 !important; display: table !important; }

            /* Center Alignment */
            img[src*="#align-center"] { display: block !important; margin: 10px auto !important; }
            figure:has(img[src*="#align-center"]) { margin-left: auto !important; margin-right: auto !important; }
        ';
        $this->addInlineCSS($css);
    }

    public function onMarkdownLoaded($plugindata)
    {
        $this->ensureAutoloader();

        $markdown = $plugindata->getData();
        $this->outputMode = 'markdown'; 

        $regex = '/\[qrcode(.*?)\]/s';
        $newMarkdown = preg_replace_callback($regex, array($this, 'processShortcode'), $markdown);

        $plugindata->setData($newMarkdown);
    }

    private function processHtmlContent($content)
    {
        $this->ensureAutoloader();
        $this->outputMode = 'html';
        $regex = '/\[qrcode(.*?)\]/s';
        return preg_replace_callback($regex, array($this, 'processShortcode'), $content);
    }

    private function ensureAutoloader()
    {
        if (file_exists(__DIR__ . '/vendor/autoload.php')) {
            require_once __DIR__ . '/vendor/autoload.php';
        }
    }

    private function processShortcode($matches)
    {
        $attributesString = $matches[1];
        $attributes = $this->parseAttributes($attributesString);

        if (isset($attributes['disabled']) && strtolower($attributes['disabled']) === 'true') {
            if ($this->outputMode === 'html') {
                return '<code>' . htmlspecialchars($matches[0]) . '</code>';
            }
            return '`' . $matches[0] . '`';
        }

        if (!isset($attributes['data'])) {
            return '<span class="error">QR Code Error: No data provided.</span>';
        }

        $settings = $this->getPluginSettings('qrcode');
        
        $data = $attributes['data'];
        $size = isset($attributes['size']) ? intval($attributes['size']) : (isset($settings['size']) ? intval($settings['size']) : 300);
        $margin = isset($attributes['margin']) ? intval($attributes['margin']) : (isset($settings['margin']) ? intval($settings['margin']) : 10);
        $fgColorHex = isset($attributes['color']) ? $attributes['color'] : (isset($settings['foreground_color']) ? $settings['foreground_color'] : '#000000');
        $bgColorHex = isset($attributes['background']) ? $attributes['background'] : (isset($settings['background_color']) ? $settings['background_color'] : '#ffffff');
        $logoPath = isset($attributes['logo']) ? $attributes['logo'] : (isset($settings['logo_path']) ? $settings['logo_path'] : '');
        $logoResize = isset($attributes['logo_width']) ? intval($attributes['logo_width']) : (isset($settings['logo_resize']) ? intval($settings['logo_resize']) : null);
        $labelText = isset($attributes['label']) ? $attributes['label'] : (isset($settings['label_text']) ? $settings['label_text'] : '');
        $labelSize = isset($settings['label_font_size']) ? intval($settings['label_font_size']) : 16;
        $alignment = isset($attributes['alignment']) ? strtolower($attributes['alignment']) : (isset($settings['alignment']) ? strtolower($settings['alignment']) : 'left');

        $fg = $this->hexToRgb($fgColorHex);
        $bg = $this->hexToRgb($bgColorHex);

        try {
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

            if (!empty($logoPath)) {
                $absLogoPath =  getcwd() . '/' . ltrim($logoPath, '/');
                if (file_exists($absLogoPath)) {
                    $builder->logoPath($absLogoPath);
                    if ($logoResize) { $builder->logoResizeToWidth($logoResize); }
                    $builder->logoPunchoutBackground(true);
                }
            }

            if (!empty($labelText)) {
                $builder->labelText($labelText);
                $builder->labelFont(new NotoSans($labelSize));
                $builder->labelAlignment(LabelAlignment::Center);
            }

            $result = $builder->build();
            $dataUri = $result->getDataUri();

            $alt = !empty($labelText) ? $labelText : 'QR Code';

            if ($this->outputMode === 'html') {
                $containerStyle = 'display: block; width: 100%;';
                if ($alignment === 'center') { $containerStyle .= ' text-align: center;'; }
                elseif ($alignment === 'right') { $containerStyle .= ' text-align: right;'; }
                else { $containerStyle .= ' text-align: left;'; }
                $imgStyle = 'display: inline-block; max-width: 100%; height: auto;';
                return '<div style="' . $containerStyle . '"><img src="' . $dataUri . '" alt="' . $alt . '" class="qrcode-plugin" style="' . $imgStyle . '" /></div>';
            }

            $hash = '#align-' . $alignment;
            return '![' . $alt . '](' . $dataUri . $hash . ')';

        } catch (\Exception $e) {
            return '**QR Code Error: ' . $e->getMessage() . '**';
        }
    }

    private function parseAttributes($string)
    {
        $attributes = [];
        $pattern = '/(\w+)="([^"]*)"/';
        preg_match_all($pattern, $string, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $attributes[$match[1]] = $match[2];
        }
        return $attributes;
    }

    private function hexToRgb($hex)
    {
        $hex = str_replace("#", "", $hex);
        if(strlen($hex) == 3) {
            $r = hexdec(substr($hex,0,1).substr($hex,0,1));
            $g = hexdec(substr($hex,1,1).substr($hex,1,1));
            $b = hexdec(substr($hex,2,1).substr($hex,2,1));
        } else {
            $r = hexdec(substr($hex,0,2));
            $g = hexdec(substr($hex,2,2));
            $b = hexdec(substr($hex,4,2));
        }
        return array("r" => $r, "g" => $g, "b" => $b);
    }
}
