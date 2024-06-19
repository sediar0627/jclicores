<?php

namespace App\Print;

use Illuminate\Support\Str;
use Mike42\Escpos\Printer;

class OrderItemPrint
{
    public function __construct(public $nombre, public $cantidad, public $precio, public Printer &$impresora, public bool $esFijo = true)
    {
    }

    public static function formatoMoneda($numero) {
        // Formatear el número como moneda
        $moneda = number_format($numero, 0, '', '.');
        return $moneda;
    }

    public function print($cantCaracteresPorLinea = 42)
    {
        // $cantCaracteresPorLinea = 42;
        $caracterRelleno = ' ';
        
        $total = self::formatoMoneda($this->precio);
        $total = Str::padLeft($total, 12, $caracterRelleno); // 9 caracteres + 3 espacios

        $cantidad = '';

        if($this->cantidad != -1){

            if($this->esFijo){
                $cantidad = 'x '.Str::padRight($this->cantidad, 2, $caracterRelleno);
            } else {
                $cantidad = Str::padRight($this->cantidad, 2, $caracterRelleno). ' %';
            }

            $cantidad = Str::padLeft($cantidad, 6, $caracterRelleno); // 4 caracteres + 2 espacios
        }

        $descripcion = $cantidad.$total;
        
        $cantCaracteresMaximoNombre = $cantCaracteresPorLinea - strlen($descripcion);
        $nombre = Str::upper($this->nombre);

        if (strlen($nombre) > $cantCaracteresMaximoNombre) {
            // Se resta 3 porque se le agrega '...' al final
            $nombre = Str::limit($nombre, $cantCaracteresMaximoNombre - 3, '...');
        }

        $nombre = Str::padRight($nombre, $cantCaracteresMaximoNombre, $caracterRelleno);

        $descripcion = $nombre . $descripcion;

        $this->impresora->text($descripcion."\n");

        return;
    }
}
