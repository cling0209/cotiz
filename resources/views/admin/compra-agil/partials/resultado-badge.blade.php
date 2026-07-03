@php
    $badges = [
        'cerrada' => ['class' => 'success', 'label' => 'Cerrada'],
        'pendiente' => ['class' => 'warning', 'label' => 'Pendiente seguimiento'],
        'desierta' => ['class' => 'secondary', 'label' => 'Desierta'],
        'cancelada' => ['class' => 'secondary', 'label' => 'Cancelada'],
        'no_encontrada' => ['class' => 'dark', 'label' => 'No existe en MP'],
    ];
    $info = $badges[$resultado ?? ''] ?? ['class' => 'secondary', 'label' => $resultado ?: '—'];
@endphp
<span class="badge text-bg-{{ $info['class'] }}">{{ $info['label'] }}</span>
