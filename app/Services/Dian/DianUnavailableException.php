<?php

namespace App\Services\Dian;

use RuntimeException;

/** La DIAN no respondio (caida, mantenimiento o sin conexion): el envio se reintenta en cola. */
class DianUnavailableException extends RuntimeException {}
