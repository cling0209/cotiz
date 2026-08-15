FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive \
    LANG=C.UTF-8 \
    HOME=/tmp \
    SAL_USE_VCLPLUGIN=svp

RUN apt-get update && apt-get install -y --no-install-recommends \
    libreoffice-writer \
    libreoffice-calc \
    fonts-liberation \
    fonts-dejavu-core \
    python3 \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

WORKDIR /app
COPY app.py entrypoint.sh ./
RUN sed -i 's/\r$//' entrypoint.sh app.py && chmod +x entrypoint.sh

EXPOSE 8080

CMD ["./entrypoint.sh"]
