<?php

namespace App\Actions;

use InvalidArgumentException;
use Picqer\Barcode\BarcodeGeneratorSVG;
use RuntimeException;

/**
 * Dibuja un código de barras EAN-13 como SVG listo para mostrar o imprimir: barras (picqer/php-barcode-generator),
 * fondo blanco con zona de silencio y los dígitos legibles debajo (1 + 6 + 6, como en las etiquetas comerciales).
 * Solo acepta EAN-13 con dígito de control correcto: el SVG se arma únicamente con esos 13 dígitos.
 *
 * USO: app(RenderBarcodeSvg::class)->handle($product->barcode);
 */
class RenderBarcodeSvg
{
    private const int MODULE = 2;

    private const int BAR_HEIGHT = 70;

    private const int MARGIN = 14;

    private const int TEXT_BAND = 24;

    /**
     * @throws InvalidArgumentException Si no es un EAN-13 válido.
     */
    public function handle(string $ean13): string
    {
        if (! GenerateBarcode::isValid($ean13)) {
            throw new InvalidArgumentException('El código no es un EAN-13 válido.');
        }

        $generator = new BarcodeGeneratorSVG;
        $svg = $generator->getBarcode($ean13, $generator::TYPE_EAN_13, self::MODULE, self::BAR_HEIGHT);

        if (preg_match('/<svg width="([\d.]+)" height="([\d.]+)"/', $svg, $size) !== 1
            || preg_match('~<g id="bars".*?</g>~s', $svg, $bars) !== 1) {
            throw new RuntimeException('No se pudo interpretar el SVG del código de barras.');
        }

        $width = (int) ceil((float) $size[1]) + 2 * self::MARGIN;
        $height = (int) ceil((float) $size[2]) + self::MARGIN + self::TEXT_BAND;
        $digits = substr($ean13, 0, 1).' '.substr($ean13, 1, 6).' '.substr($ean13, 7, 6);
        $center = $width / 2;
        $baseline = $height - 8;
        $margin = self::MARGIN;

        return <<<SVG
        <?xml version="1.0" encoding="UTF-8"?>
        <svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}" role="img">
        <title>Código de barras EAN-13 {$ean13}</title>
        <rect width="100%" height="100%" fill="#ffffff"/>
        <g transform="translate({$margin},{$margin})">{$bars[0]}</g>
        <text x="{$center}" y="{$baseline}" text-anchor="middle" font-family="monospace" font-size="14" letter-spacing="2">{$digits}</text>
        </svg>
        SVG;
    }
}
