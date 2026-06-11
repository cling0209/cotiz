<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Rómulo API',
    description: 'API REST tienda online con carrito, Redis y Webpay Plus (Chile)'
)]
#[OA\Server(url: 'http://localhost:8081', description: 'Docker local')]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'apiKey',
    name: 'X-XSRF-TOKEN',
    in: 'header',
    description: 'Cookie de sesión Sanctum + CSRF'
)]
abstract class Controller
{
    //
}
