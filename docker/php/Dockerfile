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
        python3 \
        python3-pip \
    && rm -rf /var/lib/apt/lists/*

# Map the host architecture to the FoundationDB deb suffix (aarch64/amd64).
# dpkg's architecture is used because TARGETARCH is not always provided by
# the docker-compose legacy builder.
RUN FDB_ARCH="$(dpkg --print-architecture | sed 's/^arm64$/aarch64/')" \
    && wget -q "https://github.com/apple/foundationdb/releases/download/${FDB_VERSION}/foundationdb-clients_${FDB_VERSION}-1_${FDB_ARCH}.deb" \
        -O /tmp/fdb-clients.deb \
    && dpkg -i /tmp/fdb-clients.deb \
    && rm /tmp/fdb-clients.deb

RUN docker-php-ext-install ffi gmp

# Python FoundationDB bindings, used to drive the upstream bindingtester
# harness (see tests/bindingtester). Must match the libfdb_c version above.
RUN pip3 install --break-system-packages foundationdb==7.3.75

RUN echo "ffi.enable=true" > /usr/local/etc/php/conf.d/ffi.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

CMD ["php", "-a"]
