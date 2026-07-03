@php
    $badges = [
        'ganada' => ['class' => 'success', 'label' => 'Ganada'],
        'perdida' => ['class' => 'danger', 'label' => 'Perdida'],
        'pendiente' => ['class' => 'warning', 'label' => 'Pendiente'],
        'desierta' => ['class' => 'secondary', 'label' => 'Desierta'],
        'cancelada' => ['class' => 'secondary', 'label' => 'Cancelada'],
        'no_participo' => ['class' => 'light text-dark border', 'label' => 'No participó'],
    ];
    $info = $badges[$resultado ?? ''] ?? ['class' => 'secondary', 'label' => $resultado ?: '—'];
@endphp
<span class="badge text-bg-{{ $info['class'] === 'light text-dark border' ? 'light text-dark border' : $info['class'] }}">{{ $info['label'] }}</span>
