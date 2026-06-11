<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OTP en panel administrador
    |--------------------------------------------------------------------------
    |
    | true: login y recuperación de contraseña requieren código por correo.
    | false: login directo con email/contraseña; recuperación por enlace.
    |
    */

    'otp_enabled' => filter_var(env('ADMIN_OTP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

];
