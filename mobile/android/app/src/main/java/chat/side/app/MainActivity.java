package chat.side.app;

import android.os.Bundle;

import com.getcapacitor.BridgeActivity;

import chat.side.app.screencapture.ScreenCapturePlugin;
import chat.side.app.voice.VoicePlugin;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        // Screen sharing is the app's own plugin rather than an npm one, because it exists only
        // to make up for something the WebView hasn't got: getDisplayMedia. Registered before
        // super.onCreate, which is where Capacitor builds the bridge the page talks to.
        registerPlugin(ScreenCapturePlugin.class);
        // Likewise the call's foreground service: nothing in the WebView can ask Android for
        // permission to keep the microphone once the app is off screen.
        registerPlugin(VoicePlugin.class);
        super.onCreate(savedInstanceState);
    }
}
