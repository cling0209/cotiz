@extends('layouts.admin')

@section('title', $category->exists ? 'Editar categoría' : 'Nueva categoría')

@section('content')
<div class="container-fluid py-4">
    <div class="mb-4">
        <a href="{{ route('admin.categories.index') }}" class="text-decoration-none small">
            <i class="bi bi-arrow-left"></i> Volver al listado
        </a>
        <h1 class="h3 fw-bold mt-2">{{ $category->exists ? 'Editar categoría' : 'Nueva categoría' }}</h1>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card admin-card">
                <div class="card-body p-4">
                    <form method="post"
                          action="{{ $category->exists ? route('admin.categories.update', $category) : route('admin.categories.store') }}">
                        @csrf
                        @if($category->exists) @method('PUT') @endif

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nombre *</label>
                                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                                       value="{{ old('name', $category->name) }}" required>
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Slug (carpeta imágenes)</label>
                                <input type="text" name="slug" class="form-control @error('slug') is-invalid @enderror"
                                       value="{{ old('slug', $category->slug) }}" placeholder="Ej. LIB">
                                @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Conserva mayúsculas. Debe coincidir con la carpeta en Romulo (ej. LIB).</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Categoría padre</label>
                                <select name="parent_id" class="form-select @error('parent_id') is-invalid @enderror">
                                    <option value="">Ninguna (raíz)</option>
                                    @foreach($parents as $parent)
                                        <option value="{{ $parent->id }}" @selected(old('parent_id', $category->parent_id) == $parent->id)>
                                            {{ $parent->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('parent_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Orden</label>
                                <input type="number" name="sort_order" min="0" step="1"
                                       class="form-control @error('sort_order') is-invalid @enderror"
                                       value="{{ old('sort_order', $category->sort_order) }}">
                                @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descripción</label>
                                <textarea name="description" rows="3" class="form-control @error('description') is-invalid @enderror">{{ old('description', $category->description) }}</textarea>
                                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
                                           @checked(old('is_active', $category->is_active))>
                                    <label class="form-check-label" for="is_active">Activa en catálogo</label>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Guardar</button>
                            <a href="{{ route('admin.categories.index') }}" class="btn btn-outline-secondary">Cancelar</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
