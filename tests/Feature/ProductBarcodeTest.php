<?php

use App\Actions\GenerateBarcode;
use App\Models\Product;
use App\Rules\Ean13;
use Illuminate\Support\Facades\Validator;
use Picqer\Barcode\BarcodeGeneratorSVG;

it('valida el dígito de control con códigos EAN-13 reales', function (string $code, bool $valid) {
    expect(GenerateBarcode::isValid($code))->toBe($valid);
})->with([
    'válido' => ['4006381333931', true],
    'otro válido' => ['5901234123457', true],
    'dígito de control incorrecto' => ['4006381333932', false],
    'doce dígitos' => ['400638133393', false],
    'catorce dígitos' => ['40063813339311', false],
    'con letras' => ['40063813339A1', false],
    'vacío' => ['', false],
]);

it('genera el EAN-13 interno de un producto a partir de su id', function () {
    expect(GenerateBarcode::forId(1))->toBe('2000000000015')
        ->and(GenerateBarcode::forId(1))->toStartWith(GenerateBarcode::INTERNAL_PREFIX);
});

it('genera códigos válidos, de 13 dígitos y únicos para cada id', function () {
    $codes = array_map(GenerateBarcode::forId(...), range(1, 500));

    expect(array_unique($codes))->toHaveCount(500)
        ->and(array_filter($codes, fn (string $code) => ! GenerateBarcode::isValid($code)))->toBeEmpty();
});

it('genera códigos que el generador de códigos de barras sabe dibujar', function () {
    $generator = new BarcodeGeneratorSVG;

    foreach ([1, 7, 200, 12345, 99999999999] as $id) {
        expect($generator->getBarcode(GenerateBarcode::forId($id), $generator::TYPE_EAN_13))->toContain('<svg');
    }
});

it('la regla Ean13 acepta códigos válidos y rechaza el resto', function (mixed $value, bool $passes) {
    $validator = Validator::make(['barcode' => $value], ['barcode' => [new Ean13]]);

    expect($validator->passes())->toBe($passes);
})->with([
    'válido' => ['4006381333931', true],
    'control incorrecto' => ['4006381333932', false],
    'corto' => ['123', false],
    'no es texto' => [[4006381333931], false],
]);

it('la factory puede dar a un producto su código interno', function () {
    $product = Product::factory()->withBarcode()->create();

    expect($product->barcode)->toBe(GenerateBarcode::forId($product->id));
});
