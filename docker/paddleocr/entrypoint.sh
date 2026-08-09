#!/bin/sh
set -eu

if [ "${PADDLEOCR_WARMUP:-true}" = "true" ]; then
  echo "PaddleOCR: descargando modelos (warmup al arranque)..."
  python -c "
from table_extractor import get_ppstructure_engine
get_ppstructure_engine()
print('PaddleOCR: modelos listos.')
" || echo "PaddleOCR: warmup falló; se reintentará en la primera petición."
fi

exec uvicorn app:app --host 0.0.0.0 --port 8080 --workers 1
