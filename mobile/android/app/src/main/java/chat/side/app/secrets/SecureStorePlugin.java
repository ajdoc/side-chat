package chat.side.app.secrets;

import android.content.Context;
import android.content.SharedPreferences;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

import java.nio.charset.StandardCharsets;
import java.security.KeyStore;

import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

/**
 * Secret storage for the WebView, backed by the Android Keystore.
 *
 * The page holds one thing that has to survive a restart *and* has to be bytes: the vault key
 * that wraps its message-encryption chain keys. In a browser those chain keys sit readable in
 * the profile directory. Here the vault key is encrypted under a key that never leaves the
 * Keystore — on most devices that means hardware-backed, held by the TEE or a secure element,
 * and not extractable even from a rooted phone.
 *
 * What is stored in SharedPreferences is therefore ciphertext plus its IV. Copying the app's
 * data directory off the device — the thing an ADB backup or a forensic dump gets you —
 * yields nothing usable, because the key that opens it is not in the directory.
 *
 * Deliberately *not* `androidx.security:security-crypto`. It would do much the same job, but
 * it is a dependency to add and keep current for about forty lines of Keystore calls that are
 * stable API since Android 6. See MainActivity for registration.
 *
 * The methods mirror the Electron bridge exactly (see desktop/preload.js), because the page
 * has one interface for both and neither shell's shape should leak into it.
 */
@CapacitorPlugin(name = "SecureStore")
public class SecureStorePlugin extends Plugin {

    private static final String KEYSTORE = "AndroidKeyStore";
    private static final String KEY_ALIAS = "chat.side.app.secure-store";
    private static final String PREFS = "chat.side.app.secrets";
    private static final String TRANSFORMATION = "AES/GCM/NoPadding";
    private static final int GCM_TAG_BITS = 128;
    private static final int IV_BYTES = 12;

    @PluginMethod
    public void available(PluginCall call) {
        JSObject result = new JSObject();

        // The one honest way to answer: try to get at the key. A device with a broken or
        // locked keystore fails here rather than at the first write, which is when the page
        // has already decided it has protection.
        try {
            secretKey();
            result.put("available", true);
        } catch (Exception e) {
            result.put("available", false);
        }

        call.resolve(result);
    }

    @PluginMethod
    public void get(PluginCall call) {
        String name = call.getString("name");
        JSObject result = new JSObject();

        if (name == null) {
            call.resolve(result);
            return;
        }

        String stored = prefs().getString(name, null);

        if (stored == null) {
            call.resolve(result);
            return;
        }

        try {
            byte[] blob = Base64.decode(stored, Base64.NO_WRAP);

            // IV is stored in front of the ciphertext — one self-contained value, so the two
            // can never be separated by a bug in whatever holds them.
            byte[] iv = new byte[IV_BYTES];
            System.arraycopy(blob, 0, iv, 0, IV_BYTES);

            Cipher cipher = Cipher.getInstance(TRANSFORMATION);
            cipher.init(Cipher.DECRYPT_MODE, secretKey(), new GCMParameterSpec(GCM_TAG_BITS, iv));

            byte[] plaintext = cipher.doFinal(blob, IV_BYTES, blob.length - IV_BYTES);
            result.put("value", new String(plaintext, StandardCharsets.UTF_8));
        } catch (Exception e) {
            // The Keystore entry is gone — the app's data was cleared, or it was restored onto
            // a different device where the hardware key doesn't exist. Nothing to recover, and
            // the caller treats an absent value as "mint a new one".
        }

        call.resolve(result);
    }

    @PluginMethod
    public void set(PluginCall call) {
        String name = call.getString("name");
        String value = call.getString("value");
        JSObject result = new JSObject();

        if (name == null || value == null) {
            result.put("stored", false);
            call.resolve(result);
            return;
        }

        try {
            Cipher cipher = Cipher.getInstance(TRANSFORMATION);
            cipher.init(Cipher.ENCRYPT_MODE, secretKey());

            byte[] iv = cipher.getIV();
            byte[] ciphertext = cipher.doFinal(value.getBytes(StandardCharsets.UTF_8));

            byte[] blob = new byte[iv.length + ciphertext.length];
            System.arraycopy(iv, 0, blob, 0, iv.length);
            System.arraycopy(ciphertext, 0, blob, iv.length, ciphertext.length);

            prefs().edit().putString(name, Base64.encodeToString(blob, Base64.NO_WRAP)).apply();
            result.put("stored", true);
        } catch (Exception e) {
            // Resolved rather than rejected: the page's fallback is to carry on without
            // protection, which is a worse day but a working one. A rejection here would have
            // to be handled identically at every call site.
            result.put("stored", false);
        }

        call.resolve(result);
    }

    private SharedPreferences prefs() {
        return getContext().getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    /**
     * The Keystore key, created on first use.
     *
     * No user-authentication requirement on it, and that is a considered choice: requiring a
     * fingerprint would mean the app could not decrypt a message until the person unlocked it
     * again, which breaks notifications and background sync. `setRandomizedEncryptionRequired`
     * stays on so the Keystore itself insists on a fresh IV per encryption.
     */
    private SecretKey secretKey() throws Exception {
        KeyStore keyStore = KeyStore.getInstance(KEYSTORE);
        keyStore.load(null);

        KeyStore.Entry entry = keyStore.getEntry(KEY_ALIAS, null);
        if (entry instanceof KeyStore.SecretKeyEntry) {
            return ((KeyStore.SecretKeyEntry) entry).getSecretKey();
        }

        KeyGenerator generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, KEYSTORE);
        generator.init(
            new KeyGenParameterSpec.Builder(
                KEY_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setRandomizedEncryptionRequired(true)
                .build()
        );

        return generator.generateKey();
    }
}
