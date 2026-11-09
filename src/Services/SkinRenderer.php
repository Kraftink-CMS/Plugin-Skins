<?php

namespace KraftInk\Plugins\Skins\Services;

use GdImage;

class SkinRenderer
{
    public function render(string $png, string $variant, string $view): string
    {
        abort_unless(function_exists('imagecreatefromstring'), 503);
        $skin = @imagecreatefromstring($png);
        abort_unless($skin instanceof GdImage && imagesx($skin) === 64 && in_array(imagesy($skin), [32, 64], true), 404);

        $legacy = imagesy($skin) === 32;
        $arms = !$legacy && $variant === 'slim' ? 3 : 4;
        $width = $view === 'face' ? 8 : 8 + 2 * $arms;
        $height = match ($view) {
            'face' => 8,
            'body' => 24,
            default => 32,
        };
        $canvas = $this->transparent($width, $height);
        imagealphablending($canvas, true);
        if ($view !== 'body') {
            $this->part($canvas, $skin, $view === 'face' ? 0 : $arms, 0, 8, 8, [8, 8], [40, 8]);
        }
        if ($view !== 'face') {
            $y = $view === 'body' ? 0 : 8;
            $this->part($canvas, $skin, $arms, $y, 8, 12, [20, 20], $legacy ? null : [20, 36]);
            $this->part($canvas, $skin, 0, $y, $arms, 12, [44, 20], $legacy ? null : [44, 36]);
            $this->part($canvas, $skin, $arms + 8, $y, $arms, 12, $legacy ? [44, 20] : [36, 52], $legacy ? null : [52, 52], $legacy);
            $this->part($canvas, $skin, $arms, $y + 12, 4, 12, [4, 20], $legacy ? null : [4, 36]);
            $this->part($canvas, $skin, $arms + 4, $y + 12, 4, 12, $legacy ? [4, 20] : [20, 52], $legacy ? null : [4, 52], $legacy);
        }

        $scale = $view === 'face' ? 8 : 4;
        $output = $this->transparent($width * $scale, $height * $scale);
        imagecopyresized($output, $canvas, 0, 0, 0, 0, $width * $scale, $height * $scale, $width, $height);
        ob_start();
        $written = imagepng($output);
        $rendered = ob_get_clean();
        abort_unless($written && is_string($rendered), 500);

        return $rendered;
    }

    private function transparent(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));

        return $image;
    }

    private function part(
        GdImage $canvas,
        GdImage $skin,
        int $x,
        int $y,
        int $width,
        int $height,
        array $base,
        ?array $overlay,
        bool $mirror = false,
    ): void {
        if ($mirror) {
            for ($column = 0; $column < $width; $column++) {
                imagecopy($canvas, $skin, $x + $column, $y, $base[0] + $width - $column - 1, $base[1], 1, $height);
            }
        } else {
            imagecopy($canvas, $skin, $x, $y, $base[0], $base[1], $width, $height);
        }
        if ($overlay !== null) {
            imagecopy($canvas, $skin, $x, $y, $overlay[0], $overlay[1], $width, $height);
        }
    }
}
