<?php

namespace App\Enums;

enum NotaAuditoriaAccion: string
{
    case AGREGAR = 'agregar';
    case MODIFICAR = 'modificar';
    case PDF = 'pdf';
}
