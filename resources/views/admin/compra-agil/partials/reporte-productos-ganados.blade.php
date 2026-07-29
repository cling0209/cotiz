<div class="card shadow-sm">
    <div class="card-header py-2">
        <h2 class="h6 mb-0">
            <span class="badge text-bg-secondary me-1">1</span>
            Productos ganados Reicol / Romulo
        </h2>
    </div>
    <div class="card-body py-3">
        <p class="small text-muted mb-3">
            Productos adjudicados agrupados por código, con cantidad y monto de venta acumulados.
            Filtre por fecha de publicación del proceso y por ganador.
        </p>
        <form method="GET"
            action="{{ route('admin.compra-agil.resultados.reportes.productos-ganados.exportar') }}"
            class="row g-2 align-items-end"
            data-no-loader>
            <div class="col-auto">
                <label for="pg-fecha-desde" class="form-label small mb-0">Publicación desde</label>
                <input type="date" class="form-control form-control-sm" id="pg-fecha-desde" name="fecha_desde" required>
            </div>
            <div class="col-auto">
                <label for="pg-fecha-hasta" class="form-label small mb-0">Publicación hasta</label>
                <input type="date" class="form-control form-control-sm" id="pg-fecha-hasta" name="fecha_hasta" required>
            </div>
            <div class="col-auto">
                <label for="pg-ganador" class="form-label small mb-0">Ganador</label>
                <select class="form-select form-select-sm" id="pg-ganador" name="ganador" style="width:9rem">
                    <option value="ambos" selected>Ambos</option>
                    <option value="reicol">Reicol</option>
                    <option value="romulo">Romulo</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-success btn-sm">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Descargar CSV
                </button>
            </div>
        </form>
        <p class="small text-muted mb-0 mt-2">
            Columnas: código producto, producto, ganador, cantidad acumulada, monto venta acumulado.
        </p>
    </div>
</div>
