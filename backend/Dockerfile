# syntax=docker/dockerfile:1
#
# N-Cards 后端镜像（FrankenPHP + Symfony，§4.1 / §14.2）。
#
# 两个 target：
#   dev   本地 compose 用。装 dev 依赖，APP_ENV=dev。源码由 compose 以 :ro 绑定挂载
#         覆盖掉镜像里的那份，改代码不用重建镜像。
#   prod  staging / production 用。源码烤进镜像，只装 runtime 依赖，autoloader 权威类映射。
#
# §7.4 的两条硬性约束在这里落地：**非 root 用户** + **只读根文件系统**。
# 只读 rootfs 需要的可写点由 compose 的 tmpfs 提供（/tmp、/app/var/cache、/app/var/log），
# 见 infra/compose/docker-compose.base.yml。

ARG FRANKENPHP_VERSION=1
ARG PHP_VERSION=8.3

# =============================================================================
# base —— 两个 target 的共同底座
# =============================================================================
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-alpine AS base

# PHP 版本与 composer.json 的 config.platform.php（8.3.99）及 CI 的 php-version
# 保持一致（§12.2：8.3+）。三处任何一处要改，三处一起改。

# curl：compose healthcheck 打 /health/live 要用
# pdo_pgsql：T-003 的 /health/ready 探活 + T-101 起的全部持久化
# intl / zip / opcache：Symfony 常规运行时
RUN apk add --no-cache curl \
    && install-php-extensions pdo_pgsql intl zip opcache

# §7.4：容器以非 root 用户运行。
# uid/gid 固定 1000，与开发机常见的第一个普通用户一致 —— 本地 :ro 绑定挂载时
# 免去「容器里读不动宿主机文件」的权限扯皮。
RUN addgroup -g 1000 ncards \
    && adduser -D -u 1000 -G ncards ncards

WORKDIR /app

# FrankenPHP 内嵌的 Caddy 默认往 $XDG_* 写状态。rootfs 只读，指到 tmpfs 上。
# 这里的 TLS/证书状态在本栈里用不到（TLS 由前面的 caddy 服务终结），
# 但 Caddy 启动时无论如何都要能写这两个目录。
ENV XDG_CONFIG_HOME=/tmp/caddy-config \
    XDG_DATA_HOME=/tmp/caddy-data

# 监听 8080 而不是 80：非 root 进程绑定特权端口需要 CAP_NET_BIND_SERVICE，
# 而 §4.1 的拓扑里 TLS 与 443 本来就归前面的 caddy 服务管，app 只需内网明文。
ENV SERVER_NAME=:8080

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

COPY docker/entrypoint.sh /usr/local/bin/ncards-entrypoint
RUN chmod +x /usr/local/bin/ncards-entrypoint

ENTRYPOINT ["ncards-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# =============================================================================
# dev
# =============================================================================
FROM base AS dev

ENV APP_ENV=dev \
    APP_DEBUG=1

# pcov 只装在 dev：`composer test:coverage`（§13.3 的覆盖率门禁）需要它，
# 而开发机上通常没装 pcov/xdebug。有了它，`docker compose exec app composer
# test:coverage` 就能在本地复现 CI 的覆盖率判定。prod 镜像里不需要，也不该有。
RUN install-php-extensions pcov

# 先只拷依赖清单，让 composer 层能被 Docker 缓存住 —— 改源码不会让它失效。
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload && chown -R ncards:ncards /app

USER ncards

# =============================================================================
# prod
# =============================================================================
FROM base AS prod

ENV APP_ENV=prod \
    APP_DEBUG=0

COPY docker/php.prod.ini /usr/local/etc/php/conf.d/ncards.ini

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
# 刻意**不**在构建期跑 cache:warmup：var/cache 在运行时是 tmpfs（§7.4 的只读 rootfs
# 要求），构建期烤好的缓存会被 tmpfs 整个盖掉。缓存改由 entrypoint 在容器启动时现场
# 预热，代价是启动多几百毫秒。同理，opcache.preload 也没开 —— 预加载发生在 PHP 启动、
# 也就是 entrypoint 之前，那时 tmpfs 里还没有容器编译产物。
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && chown -R ncards:ncards /app

USER ncards
