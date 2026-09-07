/*
 * Magic Link 落地页的全部脚本（T-106 / ADR-0016）。
 *
 * ============================================================================
 * ⚠️ 它做的事只有一件：把 URL 路径里的令牌拼进一个 intent:// 地址
 * ============================================================================
 * **不联网。** 没有 fetch、没有信标、没有第三方。这一页存在的唯一理由就是
 * 「GET 不消费令牌」（§7.1 的邮件安全网关陷阱），一旦它自己发起请求，
 * 那个理由就没了 —— 网关执行 JS 的日子迟早会来。
 *
 * 真正的消费是 App 发的 `POST /v1/auth/magic/consume`。
 *
 * ============================================================================
 * 为什么是 intent:// 而不是直接跳 https://
 * ============================================================================
 * 走到这一页，恰恰说明 App Links **没有**把这个 https 地址接走
 * （接走了的话 Android 早就直接拉起 App 了，这一页不会加载）。
 * 再跳一次同一个 https 地址只会回到这里，是个死循环。
 *
 * `intent://…#Intent;package=de.ncards;S.browser_fallback_url=…;end` 显式点名了
 * 包名，绕开 App Links 的域名校验；没装 App 的话 Chrome 自己跳
 * `browser_fallback_url`（Play 商店）。这是 Android 上唯一可靠的交接方式。
 */
(function () {
    'use strict';

    /** 与 AndroidManifest 的 applicationId 一致（android/app/build.gradle.kts）。 */
    var PACKAGE = 'de.ncards';

    var STORE = 'https://play.google.com/store/apps/details?id=' + PACKAGE;

    /**
     * 令牌是 32 字节 base64url，也就是 43 个 [A-Za-z0-9_-]。
     *
     * ⚠️ 这条校验不是「防攻击」——令牌本来就是给持有者用的，页面上也没有什么
     * 可以被它污染的状态。它防的是**把垃圾拼进 intent:// 再交给系统**：
     * `/l/magic/` 后面跟着任意内容的 URL 谁都能造，而 intent: 地址里的
     * `#Intent;…;end` 是有结构的，未经过滤的字符串能把 package 那一段顶掉。
     */
    var TOKEN = /^[A-Za-z0-9_-]{32,512}$/;

    var PREFIX = '/l/magic/';

    function tokenFromPath(path) {
        if (path.indexOf(PREFIX) !== 0) {
            return null;
        }

        var candidate = path.slice(PREFIX.length);

        return TOKEN.test(candidate) ? candidate : null;
    }

    function show(id) {
        var el = document.getElementById(id);

        if (el) {
            el.hidden = false;
        }
    }

    function hide(id) {
        var el = document.getElementById(id);

        if (el) {
            el.hidden = true;
        }
    }

    var token = tokenFromPath(window.location.pathname);

    if (token === null) {
        // 有人直接打开了 /l/magic/，或者链接在转发时被截断了。
        // 默认展示的就是「改用 6 位码」那一段，什么都不用做。
        return;
    }

    var link = document.getElementById('open-app');

    if (!link) {
        return;
    }

    link.setAttribute(
        'href',
        'intent://' + window.location.host + PREFIX + token
            + '#Intent;scheme=https;package=' + PACKAGE
            + ';S.browser_fallback_url=' + encodeURIComponent(STORE)
            + ';end'
    );

    show('handoff');
    show('handoff-en');
    hide('fallback');
    hide('fallback-en');
})();
