#!/bin/sh
# N-Cards app 容器入口。
#
# 为什么要在这里预热缓存：
# §7.4 要求 app 容器 `read_only: true`，唯一可写的地方是 compose 挂上来的 tmpfs
# （/tmp、/app/var/cache、/app/var/log）。tmpfs 每次容器启动都是空的，所以构建期
# 烤好的 Symfony 容器编译产物会被整个盖掉 —— 只能在启动时现场预热。
#
# 代价是启动多几百毫秒。若将来 §14.3 的滚动重启健康检查窗口卡在这上面，替代方案是
# 「构建期烤到 /opt/ncards/cache-prebuilt，启动时 cp 进 tmpfs」，比现在快但多一层间接。
#
# 这个脚本必须是幂等的：compose restart、K8s 重启、`docker compose exec` 都会走到。

set -eu

# var/ 下的目录由 tmpfs 提供，但 tmpfs 挂上来时是空的，子目录得自己建。
mkdir -p var/cache var/log

# --quiet：预热的进度输出对容器日志毫无价值，真出错时非零退出码会让容器起不来，
# 那才是我们要看到的信号。
php bin/console cache:warmup --quiet

exec "$@"
