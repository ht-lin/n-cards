package de.ncards.feature.onboarding

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import de.ncards.core.designsystem.theme.NcardsTheme
import de.ncards.core.model.settings.AppLanguage

/**
 * J1 的第一屏：**语言 / 隐私说明**。
 *
 * 两件规格要求在这里兑现：
 * - §1.4 J1 的「语言/隐私说明」；
 * - §8.6 的「Datenschutzerklärung：**首次启动时链接可见**」。
 *
 * ⚠️ 语言开关只出现在这一屏（以及将来 T-450 的设置页）。切换会重建 Activity，
 * 而此时用户还什么都没输入 —— 换到任何一屏之后，代价就不是零了。
 */
@Composable
internal fun WelcomeScreen(
    language: AppLanguage,
    onLanguageSelected: (AppLanguage) -> Unit,
    onContinue: () -> Unit,
    onOpenPrivacy: () -> Unit,
    modifier: Modifier = Modifier,
) {
    OnboardingScaffold(
        title = stringResource(R.string.onboarding_welcome_title),
        subtitle = stringResource(R.string.onboarding_welcome_intro),
        modifier = modifier,
    ) {
        Text(
            text = stringResource(R.string.onboarding_welcome_language_label),
            style = MaterialTheme.typography.titleMedium,
        )

        Row(
            modifier = Modifier.fillMaxWidth().selectableGroup(),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            AppLanguage.entries.forEach { candidate ->
                LanguageOption(
                    language = candidate,
                    selected = candidate == language,
                    onSelect = { onLanguageSelected(candidate) },
                    modifier = Modifier.weight(1f),
                )
            }
        }

        Text(
            text = stringResource(R.string.onboarding_welcome_privacy_summary),
            style = MaterialTheme.typography.bodyMedium,
        )

        TextButton(onClick = onOpenPrivacy) {
            Text(text = stringResource(R.string.onboarding_open_privacy))
        }

        Button(
            onClick = onContinue,
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text(text = stringResource(R.string.onboarding_welcome_continue))
        }
    }
}

/**
 * ⚠️ `selectable` 挂在整行上，不是只挂在 [RadioButton] 上：§11.2 的
 * 「所有交互元素触摸目标 ≥ 48 dp」。一个裸的 RadioButton 在德语长标签旁边
 * 是一个 20 dp 的点，而这一屏的用户里有大量银发群体。
 *
 * `Role.RadioButton` + `selectableGroup()` 让 TalkBack 把两项读成一组单选，
 * 而不是两个孤立的可点元素。
 */
@Composable
private fun LanguageOption(
    language: AppLanguage,
    selected: Boolean,
    onSelect: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier =
            modifier
                .heightIn(min = 48.dp)
                .selectable(selected = selected, role = Role.RadioButton, onClick = onSelect),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        RadioButton(selected = selected, onClick = null)
        Text(
            text = stringResource(language.labelRes()),
            style = MaterialTheme.typography.bodyLarge,
        )
    }
}

/**
 * ⚠️ 两个语言名都是**自称**（Deutsch / English），两份 `strings.xml` 里逐字相同。
 * 翻译它们（把 English 译成 Englisch）会让一个只懂英语的用户在德语界面上
 * 找不到自己那一项 —— 而这一屏存在的全部意义就是让他找到。
 */
private fun AppLanguage.labelRes(): Int =
    when (this) {
        AppLanguage.GERMAN -> R.string.onboarding_language_german
        AppLanguage.ENGLISH -> R.string.onboarding_language_english
    }

@Preview(showBackground = true)
@Composable
private fun WelcomeScreenPreview() {
    NcardsTheme {
        WelcomeScreen(
            language = AppLanguage.GERMAN,
            onLanguageSelected = {},
            onContinue = {},
            onOpenPrivacy = {},
        )
    }
}
