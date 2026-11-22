<?php

namespace App\Actions;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Dibuja un código QR como SVG (bacon/bacon-qr-code) con el texto indicado, por ejemplo la dirección de la ficha
 * de un producto. Corrección de errores media (M): sigue leyéndose aunque la etiqueta impresa se ensucie un poco.
 *
 * USO: app(RenderQrSvg::class)->handle(route('products.show', $product));
 */
class RenderQrSvg
{
    public function handle(string $content, int $size = 256): string
    {
        $renderer = new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($content, 'UTF-8', ErrorCorrectionLevel::M());
    }
}
