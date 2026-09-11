// feature:legal —— 应用内法律页面（§8.6）。
//
// T-151 放了 AGB 与 Datenschutzerklärung 两页**占位文本**，因为 §8.6 要求
// 「AGB：注册页链接」与「Datenschutzerklärung：首次启动时链接可见」，
// 而那两个位置都在 onboarding 里。
//
// T-450 换成律师复核过的定稿，并补上 Impressum 与开源许可两页。

plugins {
    id("ncards.android.feature")
}

android {
    namespace = "de.ncards.feature.legal"
}
