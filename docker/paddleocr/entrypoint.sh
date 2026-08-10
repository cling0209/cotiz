#!/bin/sh
set -eu

if [ "${PADDLEOCR_WARMUP:-true}" = "true" ]; then
  MODE="${PADDLEOCR_MODE:-tesseract}"
  case "$MODE" in
    structure)
      echo "PaddleOCR: descargando modelos PPStructure (warmup)..."
      python -c "from table_extractor import get_ppstructure_engine; get_ppstructure_engine(); print('PaddleOCR: modelos listos.')" \
        || echo "PaddleOCR: warmup falló; se reintentará en la primera petición."
      ;;
    paddle_ocr|paddle)
      echo "PaddleOCR: descargando modelos OCR (warmup)..."
      python -c "from table_extractor import get_paddle_ocr_engine; get_paddle_ocr_engine(); print('PaddleOCR: modelos listos.')" \
        || echo "PaddleOCR: warmup falló; se reintentará en la primera petición."
      ;;
    *)
      echo "PaddleOCR: modo tesseract (liviano)..."
      tesseract --version >/dev/null
      echo "PaddleOCR: tesseract listo."
      ;;
  esac
fi

exec uvicorn app:app --host 0.0.0.0 --port 8080 --workers 1
