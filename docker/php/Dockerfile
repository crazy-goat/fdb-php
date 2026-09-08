FROM php:8.2-cli-bookworm

ARG FDB_VERSION=7.3.75
ARG TARGETARCH

RUN apt-get update && apt-get install -y --no-install-recommends \
        wget \
        unzip \
        git \
        libgmp-dev \
        libffi-dev \
        procps \
    && rm -rf /var/lib/apt/lists/*

# Map Docker's TARGETARCH (arm64/amd64) to the FoundationDB deb suffix (aarch64/amd64).
RUN case "$TARGETARCH" in \
        arm64) FDB_ARCH=aarch64 ;; \
        *) FDB_ARCH=amd64 ;; \
    esac \
    && wget -q "https://github.com/apple/foundationdb/releases/download/${FDB_VERSION}/foundationdb-clients_${FDB_VERSION}-1_${FDB_ARCH}.deb" \
        -O /tmp/fdb-clients.deb \
    && dpkg -i /tmp/fdb-clients.deb \
    && rm /tmp/fdb-clients.deb

RUN docker-php-ext-install ffi gmp

RUN echo "ffi.enable=true" > /usr/local/etc/php/conf.d/ffi.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

CMD ["php", "-a"]
