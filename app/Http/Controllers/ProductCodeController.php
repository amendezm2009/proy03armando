<?php

namespace App\Http\Controllers;

use App\Actions\GenerateBarcode;
use App\Actions\RenderBarcodeSvg;
use App\Actions\RenderQrSvg;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Código de barras y QR de un producto como SVG, para la ficha (web) y para la API. Se generan al momento
 * y no se guardan: el código de barras sale de products.barcode y el QR codifica la dirección de la ficha
 * (el slug no cambia, así que un QR impreso sigue funcionando). Exigen products.view (routes/web.php y routes/api.php).
 * Con "?download=1" el navegador lo descarga en lugar de mostrarlo.
 */
class ProductCodeController extends Controller
{
    public function barcode(Request $request, Product $product, RenderBarcodeSvg $render): Response
    {
        abort_if($product->barcode === null || ! GenerateBarcode::isValid($product->barcode), 404);

        return $this->svg($request, $render->handle($product->barcode), "{$product->sku}-barcode");
    }

    public function qr(Request $request, Product $product, RenderQrSvg $render): Response
    {
        // La dirección sale de APP_URL, no del dominio con que se hizo la petición: una etiqueta impresa desde
        // "localhost" o una IP interna no debe llevar esa dirección (ni un Host falsificado).
        $url = rtrim((string) config('app.url'), '/').route('products.show', $product, false);

        return $this->svg($request, $render->handle($url), "{$product->sku}-qr");
    }

    /**
     * El SVG se muestra en un <img> (sin scripts) y, si alguien abre la dirección directamente, la política de
     * contenido impide cualquier recurso o script; el nombre de descarga sale del SKU ya convertido a slug.
     */
    private function svg(Request $request, string $svg, string $name): Response
    {
        $disposition = $request->boolean('download')
            ? HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, Str::slug($name).'.svg')
            : 'inline';

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
