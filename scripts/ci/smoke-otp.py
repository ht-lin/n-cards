#!/usr/bin/env python3
"""部署后冒烟测试：真的走完一次 OTP 登录（T-114）。

============================================================================
为什么需要这一条
============================================================================
2026-09-19 的故障：staging Vault 里的 ``ncards-app`` policy 停在 9-04 那版，
缺 T-104 在 9-07 加的 ``secret/data/ncards/jwt/current``。于是
``POST /v1/auth/otp/verify`` 返回 503「The JWT signing key could not be read.」
—— 而**每一盏灯都是绿的**：

  * ``/health/ready`` 打的是**免认证**的 ``sys/health``，它证明的是
    「Vault 活着、没封印」，不是「这个 AppRole 读得到它要读的东西」；
  * ``scripts/ci/smoke-staging.sh`` 只打 ``/health/live`` 与五个安全响应头，
    **一个 /v1 端点都不碰**。

所以本脚本的判据只有一个：**`verify` 真的返回 200**。
少于这个的任何简化都测不出那次故障 —— 特别注意 ``otp/request`` 照样返回 202
（它只用 transit 的 hmac/encrypt，不读 JWT 签名密钥），
拿它当判据等于把这条冒烟写成一个永远绿的桩。

============================================================================
为什么是 Python 而不是 bash
============================================================================
``scripts/ci/`` 下别的脚本都是 bash，这一个不是。理由是读信：
IMAP over TLS 在 bash 里只能靠 ``openssl s_client`` 手搓标签、手搓 UID 检索、
手搓 quoted-printable 解码 —— 那份代码没人看得懂，也没人敢改。
``imaplib`` / ``email`` / ``urllib`` 全在标准库里，而 ``.github/workflows/deploy.yml``
已经装了 python 3.12（ansible 要用）。没有新依赖。

============================================================================
用法
============================================================================
    SMOKE_OTP_EMAIL=smoke-staging@n-cards.de \\
    SMOKE_IMAP_HOST=... SMOKE_IMAP_USER=... SMOKE_IMAP_PASSWORD=... \\
        scripts/ci/smoke-otp.py https://api.staging.n-cards.de

    SMOKE_INSECURE=1   跳过证书校验。**仅用于 ACME 排练**，与 smoke-staging.sh
                       同一口径。验收必须在不带这个变量的情况下跑。

⚠️ 这条冒烟**有副作用，而且是写的**：
  * 真的发一封信（staging 与生产**共用**同一个发信账号与配额，见
    ``infra/ansible/inventory/group_vars/ncards_staging/main.yml``）；
  * 首次 ``verify`` 成功会**建一行 users**（§6.2：首次验证即注册）。
所以收件地址必须是运维自己的、专用的那一个，别拿用户邮箱试。
"""

from __future__ import annotations

import email
import email.header
import imaplib
import json
import os
import re
import ssl
import sys
import time
import urllib.error
import urllib.request

# ⚠️ **固定的 device id，不要换成 uuid4()。**
# §7.1 的「新设备登录」提醒是按 device id 判的：每次跑换一个新 id，就等于每次
# 部署都往那个**设计上没人看的** no-reply@ 收件箱里多塞一封提醒信
# （见 docs/runbooks/email-dns.md 的退信一节）。固定下来之后只有第一次会发。
SMOKE_DEVICE_ID = "0192f3a1-b2c3-7d4e-8f01-5c0e5c0e5c0e"

# platform 必须匹配 ClientVersion::PATTERN 的 [a-z]{1,16}；
# isAtLeast() **不比 platform**，所以 "ops" 不会被 MIN_SUPPORTED_CLIENT 挡掉。
X_CLIENT = "ops/1.0.0 (1)"

# 码在**主题行**里（"418396 is your N-Cards sign-in code" /
# "418396 ist dein N-Cards-Anmeldecode"），那是最稳的提取点 ——
# 正文里还有一条 magic link，将来改版式的可能性比主题大得多。
CODE_IN_SUBJECT = re.compile(r"^\s*(\d{6})\b")
CODE_IN_BODY = re.compile(r"^\s*(\d{6})\s*$", re.MULTILINE)

MAIL_TIMEOUT_SECONDS = 120
MAIL_POLL_SECONDS = 5


class SmokeFailure(Exception):
    """一次有话可说的失败。__str__ 就是打给人看的那段话。"""


def env(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise SmokeFailure(f"缺少环境变量 {name}。用法见本文件头部。")
    return value


def ssl_context() -> ssl.SSLContext:
    if os.environ.get("SMOKE_INSECURE") == "1":
        print("⚠️ SMOKE_INSECURE=1：跳过证书校验。这只该出现在 ACME 排练里，不该出现在验收里。")
        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        return ctx
    return ssl.create_default_context()


# ---------------------------------------------------------------------------
# HTTP
# ---------------------------------------------------------------------------
def post(url: str, payload: dict, bearer: str | None = None) -> tuple[int, dict]:
    """POST 一个 JSON，返回 (状态码, 解析出来的 body)。

    ⚠️ 4xx / 5xx **不抛异常** —— 状态码本身就是本脚本要断言的东西，
    而错误响应的 body 里有 ``code``，那是排查时最有用的一行。
    """
    request = urllib.request.Request(
        url,
        data=json.dumps(payload).encode(),
        method="POST",
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-Client": X_CLIENT,
            **({"Authorization": f"Bearer {bearer}"} if bearer else {}),
        },
    )

    try:
        with urllib.request.urlopen(request, timeout=20, context=ssl_context()) as response:
            return response.status, json.loads(response.read() or b"{}")
    except urllib.error.HTTPError as error:
        raw = error.read()
        try:
            return error.code, json.loads(raw or b"{}")
        except json.JSONDecodeError:
            return error.code, {"_raw": raw[:400].decode(errors="replace")}
    except urllib.error.URLError as error:
        raise SmokeFailure(f"连不上 {url}：{error.reason}") from error


# ---------------------------------------------------------------------------
# IMAP
# ---------------------------------------------------------------------------
def imap_connect() -> imaplib.IMAP4_SSL:
    host = env("SMOKE_IMAP_HOST")
    port = int(os.environ.get("SMOKE_IMAP_PORT", "993"))

    try:
        connection = imaplib.IMAP4_SSL(host, port, ssl_context=ssl_context())
        connection.login(env("SMOKE_IMAP_USER"), env("SMOKE_IMAP_PASSWORD"))
    except (imaplib.IMAP4.error, OSError) as error:
        raise SmokeFailure(
            f"IMAP 登录失败（{host}:{port}）：{error}\n"
            "  这个套餐只有一个邮箱账号，别名没有独立凭据 —— 用主账号登录，\n"
            "  按收件人地址过滤。见 docs/runbooks/email-dns.md 的「一个账号 + 只收别名」。"
        ) from error

    return connection


def uid_next(connection: imaplib.IMAP4_SSL) -> int:
    """记下当前水位线。

    ⚠️ **必须在发请求之前调用。** 不记水位线就只能按「最新一封」去捡，
    于是上一次跑剩下的旧码会被当成这一次的 —— 那封信的码早就用过了，
    verify 会返回 401，而症状看起来像「policy 又坏了」，排查方向完全错。
    """
    status, data = connection.status("INBOX", "(UIDNEXT)")
    if status != "OK":
        raise SmokeFailure(f"读不到 INBOX 的 UIDNEXT：{status} {data}")

    match = re.search(rb"UIDNEXT\s+(\d+)", data[0])
    if not match:
        raise SmokeFailure(f"UIDNEXT 响应解析不了：{data!r}")

    return int(match.group(1))


def decode_header(raw: str) -> str:
    return "".join(
        part.decode(charset or "utf-8", errors="replace") if isinstance(part, bytes) else part
        for part, charset in email.header.decode_header(raw)
    )


def addressed_to(message: email.message.Message, address: str) -> bool:
    """这封信真的是发给我们这个冒烟地址的吗。

    ⚠️ 这个判断**不能省**。收件箱是共用的（别名全都转进同一个主账号），
    「新信 + 里面有个 6 位码」在这里不足以认定它属于我们 ——
    捡错一封就等于拿真实用户的登录码去登录。宁可报错，不要猜。
    """
    address = address.lower()
    headers = ("To", "Delivered-To", "X-Original-To", "Envelope-To", "Cc")
    return any(
        address in decode_header(value).lower()
        for header in headers
        for value in message.get_all(header, [])
    )


def body_text(message: email.message.Message) -> str:
    if not message.is_multipart():
        payload = message.get_payload(decode=True) or b""
        return payload.decode(message.get_content_charset() or "utf-8", errors="replace")

    for part in message.walk():
        if part.get_content_type() == "text/plain":
            payload = part.get_payload(decode=True) or b""
            return payload.decode(part.get_content_charset() or "utf-8", errors="replace")

    return ""


def wait_for_code(connection: imaplib.IMAP4_SSL, address: str, since_uid: int) -> tuple[int, str]:
    """轮询到那封 OTP 信，返回 (uid, 6 位码)。"""
    deadline = time.monotonic() + MAIL_TIMEOUT_SECONDS
    seen_but_unmatched = 0

    while time.monotonic() < deadline:
        connection.select("INBOX")
        # `<uid>:*` 是 IMAP 的「从这个 UID 到最后」，正是「这次跑之后到的信」。
        status, data = connection.uid("SEARCH", None, f"{since_uid}:*")
        uids = data[0].split() if status == "OK" and data and data[0] else []

        for uid in uids:
            if int(uid) < since_uid:
                # `<uid>:*` 在邮箱里没有 >= since_uid 的信时会回最后一封，
                # 这是 IMAP 的规定行为，不是 bug。自己再筛一次。
                continue

            status, fetched = connection.uid("FETCH", uid, "(RFC822)")
            if status != "OK" or not fetched or not isinstance(fetched[0], tuple):
                continue

            message = email.message_from_bytes(fetched[0][1])

            if not addressed_to(message, address):
                seen_but_unmatched += 1
                continue

            subject = decode_header(message.get("Subject", ""))
            match = CODE_IN_SUBJECT.match(subject) or CODE_IN_BODY.search(body_text(message))
            if match:
                return int(uid), match.group(1)

            raise SmokeFailure(
                f"收到了发给 {address} 的信，但里面找不到 6 位码。\n"
                f"  主题：{subject!r}\n"
                "  邮件模板改过了？见 backend/templates/email/*/otp_code.*"
            )

        time.sleep(MAIL_POLL_SECONDS)

    raise SmokeFailure(
        f"{MAIL_TIMEOUT_SECONDS}s 内没等到发给 {address} 的 OTP 信"
        f"（期间有 {seen_but_unmatched} 封别的新信）。\n"
        "  otp/request 已经返回 202，所以挑战是建出来了 —— 问题在发信那一侧：\n"
        "  worker 起着吗？队列积压多少？死信里是什么错？\n"
        "  三条命令见 docs/runbooks/email-dns.md §3「触发条件 / 前置检查」。"
    )


def delete_mail(connection: imaplib.IMAP4_SSL, uid: int) -> None:
    """把这封信删掉。

    不删的话，每次 main 合入都往 no-reply@ 那个收件箱里留一封 ——
    而那个箱子的设计前提就是没人看（退信也落在那里），迟早把配额吃掉。
    """
    try:
        connection.uid("STORE", str(uid), "+FLAGS", "(\\Deleted)")
        connection.expunge()
    except imaplib.IMAP4.error as error:  # 清理失败不该让一次成功的冒烟变红
        print(f"  ⚠️ 删除冒烟邮件失败（不影响判定）：{error}")


# ---------------------------------------------------------------------------
# 主流程
# ---------------------------------------------------------------------------
def run(base_url: str) -> None:
    address = env("SMOKE_OTP_EMAIL")
    api = f"{base_url.rstrip('/')}/v1"

    print(f"[1/5] IMAP 登录并记下水位线（{address}）")
    connection = imap_connect()
    try:
        since_uid = uid_next(connection)
        print(f"  ✓ UIDNEXT={since_uid}")

        print(f"[2/5] POST {api}/auth/otp/request")
        status, body = post(f"{api}/auth/otp/request", {"email": address, "locale": "en"})
        if status == 429:
            raise SmokeFailure(
                "otp/request 返回 429 —— 同一地址 1 分钟只能打一次（§7.5）。\n"
                "  两次部署挨得太近了。重跑这一步即可，这不是故障。"
            )
        if status != 202:
            raise SmokeFailure(
                f"otp/request 期望 202，实得 {status}：{body}\n"
                "  503 → Vault 那一侧不通（AppRole 登录失败 / transit key 不存在 / 仍是封印态）。\n"
                "  注意这一步**不读** JWT 签名密钥，所以它红说明问题比 T-114 那次更靠前。"
            )

        challenge_id = body.get("challenge_id")
        if not challenge_id:
            raise SmokeFailure(f"otp/request 返回 202 但没有 challenge_id：{body}")
        print(f"  ✓ 202，challenge_id={challenge_id}")

        print(f"[3/5] 等那封信（最多 {MAIL_TIMEOUT_SECONDS}s）")
        uid, code = wait_for_code(connection, address, since_uid)
        print("  ✓ 收到了，码已取到（不打印）")

        print(f"[4/5] POST {api}/auth/otp/verify  ← 这一条才是本脚本存在的理由")
        status, body = post(
            f"{api}/auth/otp/verify",
            {
                "challenge_id": challenge_id,
                "code": code,
                "device": {
                    "id": SMOKE_DEVICE_ID,
                    "platform": "android",
                    "model": "ci-smoke",
                    "os_version": "14",
                    "app_version": "1.0.0",
                },
            },
        )

        if status == 503:
            raise SmokeFailure(
                f"otp/verify 返回 503：{body}\n"
                "\n"
                "  ⚠️ **第一嫌疑是 Vault 里的 ncards-app policy 漂移了**，"
                "这正是 T-114 的那次故障：\n"
                "  detail 为 'The JWT signing key could not be read.' 时，多半是 policy 里缺\n"
                "  `secret/data/ncards/jwt/current`（或 T-105 的 `previous`）那一行。\n"
                "\n"
                "  对一次账：\n"
                "    ansible-playbook -i inventory/staging.yml deploy.yml --tags vault-policy\n"
                "  它会打出 Vault 与 infra/vault/policies/*.hcl 的逐字 diff 并自动下发。"
            )
        if status != 200:
            raise SmokeFailure(f"otp/verify 期望 200，实得 {status}：{body}")

        access_token = body.get("access_token")
        if not access_token:
            raise SmokeFailure(f"otp/verify 返回 200 但没有 access_token：{body}")
        print("  ✓ 200，拿到了令牌对 —— JWT 签名密钥读得到，policy 是对的")

        print("[5/5] 收尾：登出并删掉那封信")
        status, body = post(f"{api}/auth/logout", {}, bearer=access_token)
        if status not in (200, 204):
            # 登出失败不该让一次成功的登录变红，但要说出来。
            print(f"  ⚠️ logout 期望 200/204，实得 {status}：{body}")
        else:
            print("  ✓ 已登出")

        delete_mail(connection, uid)
        print("  ✓ 已删除冒烟邮件")
    finally:
        try:
            connection.logout()
        except (imaplib.IMAP4.error, OSError):
            pass


def main() -> int:
    if len(sys.argv) != 2:
        print(f"用法: {sys.argv[0]} <base_url>   例：{sys.argv[0]} https://api.staging.n-cards.de",
              file=sys.stderr)
        return 2

    try:
        run(sys.argv[1])
    except SmokeFailure as failure:
        print(f"\n!! OTP 冒烟测试失败\n{failure}", file=sys.stderr)
        return 1

    print("\n✓ OTP 冒烟测试通过：请求码 → 收信 → 验码 → 200")
    return 0


if __name__ == "__main__":
    sys.exit(main())
