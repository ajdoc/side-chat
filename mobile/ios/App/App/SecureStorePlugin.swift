import Capacitor
import Foundation
import Security

/**
 * Secret storage for the WebView, backed by the iOS Keychain.
 *
 * The counterpart to Android's SecureStorePlugin and Electron's `secrets` bridge, with the
 * same three methods, because the page has one interface for all three shells (see
 * `frontend/app/lib/crypto/vault.ts`).
 *
 * What it protects: the vault key that wraps the app's message-encryption chain keys. Those
 * keys have to exist as bytes — the ratchet derives from them — so in a plain WebView they
 * sit readable in the app container. Here the container holds only ciphertext, and the key
 * that opens it is in the Keychain.
 *
 * `kSecAttrAccessibleWhenUnlockedThisDeviceOnly` is the important line, and both halves of it
 * are deliberate. *WhenUnlocked* means a locked phone cannot be made to give the key up, which
 * is the case that matters for a device that is lost or seized. *ThisDeviceOnly* keeps the
 * item out of iCloud Keychain and out of encrypted backups: a key that synced would silently
 * put this device's message history within reach of anything that could restore that backup,
 * which is precisely the property the encryption exists to deny.
 *
 * Swift-only, with no bridging header or `.m` file — Capacitor 7 registers a plugin from the
 * `CAPBridgedPlugin` conformance below.
 */
@objc(SecureStorePlugin)
public class SecureStorePlugin: CAPPlugin, CAPBridgedPlugin {
    public let identifier = "SecureStorePlugin"
    public let jsName = "SecureStore"
    public let pluginMethods: [CAPPluginMethod] = [
        CAPPluginMethod(name: "available", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "get", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "set", returnType: CAPPluginReturnPromise)
    ]

    /** Namespaced so these items can never collide with anything else the app keychains. */
    private let service = "chat.side.app.secure-store"

    /**
     * Whether the Keychain will actually take an item.
     *
     * Answered by round-tripping a throwaway value rather than by assuming. The Keychain is
     * effectively always available on iOS, but "effectively always" is not the same as "and
     * therefore the page may promise the user their keys are protected".
     */
    @objc func available(_ call: CAPPluginCall) {
        let probe = "\(service).probe"
        let stored = write(name: probe, value: "1")
        if stored { delete(name: probe) }

        call.resolve(["available": stored])
    }

    @objc func get(_ call: CAPPluginCall) {
        guard let name = call.getString("name") else {
            call.resolve([:])
            return
        }

        var query = baseQuery(name: name)
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne

        var item: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &item)

        // Absent is the ordinary first-launch answer, and also what a restored-onto-another-
        // device install sees. Either way the caller mints a fresh vault key; the chains
        // sealed under the old one are unreadable, which is the intended outcome.
        guard status == errSecSuccess,
              let data = item as? Data,
              let value = String(data: data, encoding: .utf8) else {
            call.resolve([:])
            return
        }

        call.resolve(["value": value])
    }

    @objc func set(_ call: CAPPluginCall) {
        guard let name = call.getString("name"), let value = call.getString("value") else {
            call.resolve(["stored": false])
            return
        }

        // Resolved rather than rejected on failure: the page's fallback is to carry on
        // unprotected, which is a worse day but a working one, and a rejection would have to
        // be handled identically everywhere it's called.
        call.resolve(["stored": write(name: name, value: value)])
    }

    /** Upsert, because SecItemAdd refuses a duplicate rather than replacing it. */
    private func write(name: String, value: String) -> Bool {
        guard let data = value.data(using: .utf8) else { return false }

        delete(name: name)

        var query = baseQuery(name: name)
        query[kSecValueData as String] = data
        query[kSecAttrAccessible as String] = kSecAttrAccessibleWhenUnlockedThisDeviceOnly

        return SecItemAdd(query as CFDictionary, nil) == errSecSuccess
    }

    private func delete(name: String) {
        SecItemDelete(baseQuery(name: name) as CFDictionary)
    }

    private func baseQuery(name: String) -> [String: Any] {
        [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: name
        ]
    }
}
