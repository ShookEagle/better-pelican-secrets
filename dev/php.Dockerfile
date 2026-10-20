FROM docker.io/library/node:22-bookworm-slim@sha256:c3de60bf2f9dd0ac6370e6117950ff62d6e339527e7472301c9c78a017978392 AS node
FROM docker.io/library/composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
FROM docker.io/library/php:8.4-cli-bookworm@sha256:836ac6c672d1372a47c8fd61b625015bd07760f493fb2eaab2087636498a2b4b
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev libonig-dev libsqlite3-dev libpng-dev libcurl4-openssl-dev && docker-php-ext-install -j2 bcmath intl mbstring pdo_mysql pdo_sqlite zip gd pcntl curl && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /opt /opt
RUN ln -s /opt/yarn-v*/bin/yarn /usr/local/bin/yarn && ln -s /opt/yarn-v*/bin/yarnpkg /usr/local/bin/yarnpkg
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /workspace/.work/panel
