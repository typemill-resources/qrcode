<?php

namespace Typemill {
    class Plugin
    {
        protected function getPluginSettings()
        {
            return [];
        }

        protected function generateStaticAsset(string $source, callable $generator, string $extension = 'svg', ?string $namespace = null): string
        {
            return '/test/qrcode.' . $extension;
        }
    }
}

namespace {
    require dirname(__DIR__) . '/qrcode.php';

    error_reporting(E_ALL);
    set_error_handler(function ($severity, $message, $file, $line) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    require dirname(__DIR__) . '/vendor/autoload.php';
    \Endroid\QrCode\Builder\Builder::create()->writer(new \Endroid\QrCode\Writer\PngWriter());

    class ShortcodeTestEvent
    {
        public function __construct(private mixed $data) {}
        public function getData() { return $this->data; }
        public function setData($data) { $this->data = $data; }
        public function stopPropagation() {}
    }

    $plugin = new \Plugins\qrcode\qrcode();
    $checks = 0;
    if ($plugin::getSubscribedEvents() !== ['onShortcodeFound' => 'onShortcodeFound'] || method_exists($plugin, 'onMarkdownLoaded')) {
        throw new \RuntimeException('Plugin must only process native shortcode events.');
    }
    $checks++;

    $event = new ShortcodeTestEvent(['name' => 'registershortcode', 'data' => []]);
    $plugin->onShortcodeFound($event);
    if (!isset($event->getData()['data']['qrcode'])) {
        throw new \RuntimeException('QR shortcode was not registered with the editor.');
    }
    $checks++;

    $otherShortcode = ['name' => 'other', 'params' => ['data' => 'value']];
    $event = new ShortcodeTestEvent($otherShortcode);
    $plugin->onShortcodeFound($event);
    if ($event->getData() !== $otherShortcode) {
        throw new \RuntimeException('Unrelated shortcode was changed.');
    }
    $checks++;

    foreach ([false, null, []] as $params) {
        $event = new ShortcodeTestEvent(['name' => 'qrcode', 'params' => $params]);
        $plugin->onShortcodeFound($event);
        if ($event->getData() !== '<span class="error">QR Code Error: No data provided.</span>') {
            throw new \RuntimeException('Missing-data shortcode did not return an error message.');
        }
        $checks++;
    }

    foreach (['left', 'center', 'right'] as $alignment) {
        $event = new ShortcodeTestEvent(['name' => 'qrcode', 'params' => ['data' => 'https://typemill.net', 'alignment' => $alignment, 'label' => 'Scan me']]);
        $plugin->onShortcodeFound($event);
        if (!str_contains($event->getData(), 'src="/test/qrcode.png"') || !str_contains($event->getData(), 'alt="Scan me"')) {
            throw new \RuntimeException('Native shortcode did not return image markup.');
        }
        $checks++;
    }

    if (extension_loaded('gd')) {
        $buildPng = new \ReflectionMethod($plugin, 'buildPngBytes');
        foreach (['', 'Scan me'] as $label) {
            $bytes = $buildPng->invoke($plugin, 'https://typemill.net', 150, 10, '#000000', '#ffffff', $label, '', null, 16);
            $image = getimagesizefromstring($bytes);
            if (!$image || $image[2] !== IMAGETYPE_PNG || $image[0] <= 0 || $image[1] <= 0) {
                throw new \RuntimeException('QR rendering did not return a valid PNG.');
            }
            $checks++;
        }
    } else {
        echo "PNG generation checks skipped: PHP GD is not enabled.\n";
    }

    echo "Passed {$checks} shortcode checks.\n";
}