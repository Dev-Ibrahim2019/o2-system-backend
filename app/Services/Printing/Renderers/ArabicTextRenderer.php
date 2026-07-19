<?php

namespace App\Services\Printing\Renderers;

use ArPHP\I18N\Arabic;

class ArabicTextRenderer
{
    private string $fontPath;
    private int $fontSize;
    private Arabic $arabic;

    public function __construct(string $fontPath = '', int $fontSize = 20)
    {
        $this->fontPath = $fontPath ?: $this->resolveFont();
        $this->fontSize = $fontSize;
        $this->arabic = new Arabic('Glyphs');
    }

    /**
     * Render Arabic text as a PNG image and return the temp file path.
     */
    public function renderToImage(string $text, ?int $width = null): ?string
    {
        if (empty(trim($text))) {
            return null;
        }

        try {
            $shaped = $this->arabic->utf8Glyphs($text);

            $box = imagettfbbox($this->fontSize, 0, $this->fontPath, $shaped);
            $imgWidth  = abs($box[4] - $box[0]) + 30;
            $imgHeight = abs($box[5] - $box[1]) + 20;

            $im = imagecreatetruecolor($imgWidth, $imgHeight);
            $white = imagecolorallocate($im, 255, 255, 255);
            $black = imagecolorallocate($im, 0, 0, 0);

            imagefill($im, 0, 0, $white);
            imagettftext($im, $this->fontSize, 0, 10, $imgHeight - 8, $black, $this->fontPath, $shaped);

            $tempPath = storage_path('app/pos_ar_' . md5($text . microtime(true)) . '.png');
            imagepng($im, $tempPath);
            imagedestroy($im);

            return $tempPath;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Clean up temp image file.
     */
    public function cleanup(?string $path): void
    {
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }

    private function resolveFont(): string
    {
        $candidates = [
            'C:\\Windows\\Fonts\\tahoma.ttf',
            'C:\\Windows\\Fonts\\tahomabd.ttf',
            '/usr/share/fonts/truetype/tahoma.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ];

        foreach ($candidates as $font) {
            if (file_exists($font)) {
                return $font;
            }
        }

        return 'C:\\Windows\\Fonts\\tahoma.ttf';
    }
}
