package de.ncards.data.auth

import de.ncards.core.network.api.model.Device
import java.util.UUID

/**
 * [DeviceDescriptorProvider] 的替身。
 *
 * [id] 在一个实例的生命周期里**不变** —— 真实现把它存在一个清会话不碰的
 * `SharedPreferences` 里，理由（每次换 id = 每次登录都给用户发一封
 * 「新设备登录」提醒信）写在 `AndroidDeviceDescriptorProvider` 的类注释里。
 * `DefaultAuthRepositoryTest.sendsStableDeviceId` 验的就是这条不变量。
 */
internal class FakeDeviceDescriptorProvider(
    private val id: UUID = UUID.fromString("0192f3a1-b2c3-7d4e-8f01-00000000d0de"),
) : DeviceDescriptorProvider {
    var calls = 0
        private set

    override fun current(): Device {
        calls++
        return Device(
            id = id,
            platform = Device.Platform.android,
            model = "Pixel 8",
            osVersion = "15",
            appVersion = "1.0.0",
        )
    }
}
