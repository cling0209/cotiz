@props([
    'name',
    'id' => null,
    'required' => false,
    'autocomplete' => 'current-password',
    'maxlength' => null,
    'minlength' => null,
])

@php
    $inputId = $id ?? $name;
@endphp

<div class="input-group">
    <input
        type="password"
        name="{{ $name }}"
        id="{{ $inputId }}"
        {{ $attributes->class(['form-control']) }}
        @if($required) required @endif
        autocomplete="{{ $autocomplete }}"
        @if($maxlength) maxlength="{{ $maxlength }}" @endif
        @if($minlength) minlength="{{ $minlength }}" @endif
    >
    <button
        type="button"
        class="btn btn-outline-secondary js-password-toggle"
        data-target="{{ $inputId }}"
        aria-label="Mostrar contraseña"
        tabindex="-1"
    >
        <i class="bi bi-eye" aria-hidden="true"></i>
    </button>
</div>
