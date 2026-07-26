package chat.side.app.screencapture;

import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.media.projection.MediaProjectionManager;
import android.os.Build;

import androidx.activity.result.ActivityResult;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.ActivityCallback;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * Screen sharing, for a WebView that has no getDisplayMedia.
 *
 * The page asks for a screen; this asks Android for one, starts {@link ScreenCaptureService} to
 * hold it, and answers with the loopback address the frames will arrive on. From there the page
 * turns them back into a MediaStream and the call is none the wiser — see useDisplayCapture.
 *
 * Consent is Android's to collect, not ours: `createScreenCaptureIntent` puts up the system
 * sheet, and there is no way to capture without it. Refusing it is the ordinary case and
 * resolves as a rejection, which the app already reads as "changed their mind at the picker".
 */
@CapacitorPlugin(name = "ScreenCapture")
public class ScreenCapturePlugin extends Plugin {

    @PluginMethod
    public void isSupported(PluginCall call) {
        JSObject result = new JSObject();

        // MediaProjection is Lollipop and up, so in practice always available — but the audio
        // half needs Android 10, and it's worth the app knowing which it is going to get.
        boolean supported = Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP;
        result.put("supported", supported);
        if (!supported) result.put("reason", "This version of Android can’t share a screen.");

        call.resolve(result);
    }

    @PluginMethod
    public void start(PluginCall call) {
        if (ScreenCaptureService.isRunning()) {
            // A second share supersedes the first rather than stacking a second projection.
            ScreenCaptureService.stopCapture(getContext());
        }

        MediaProjectionManager manager = (MediaProjectionManager)
                getContext().getSystemService(Context.MEDIA_PROJECTION_SERVICE);

        // The call is held across the system consent sheet and resolved in onProjectionResult.
        startActivityForResult(call, manager.createScreenCaptureIntent(), "onProjectionResult");
    }

    @ActivityCallback
    private void onProjectionResult(PluginCall call, ActivityResult result) {
        if (call == null) return;

        if (result.getResultCode() != Activity.RESULT_OK || result.getData() == null) {
            call.reject("Screen sharing was declined.");
            return;
        }

        ScreenCaptureService.setListener(new ScreenCaptureService.Listener() {
            @Override
            public void onReady(String endpoint, int width, int height, int frameRate, boolean audio) {
                JSObject session = new JSObject();
                session.put("endpoint", endpoint);
                session.put("width", width);
                session.put("height", height);
                session.put("frameRate", frameRate);
                session.put("audio", audio);
                call.resolve(session);
            }

            @Override
            public void onEnded() {
                // Fired for every ending, including the system notification's own Stop button,
                // which never touches the app. The page tears its stream down on this.
                notifyListeners("screenCaptureEnded", new JSObject());
            }
        });

        Intent service = new Intent(getContext(), ScreenCaptureService.class);
        service.putExtra(ScreenCaptureService.EXTRA_RESULT_CODE, result.getResultCode());
        service.putExtra(ScreenCaptureService.EXTRA_RESULT_DATA, result.getData());
        service.putExtra(ScreenCaptureService.EXTRA_HEIGHT, call.getInt("height", 720));
        service.putExtra(ScreenCaptureService.EXTRA_FRAME_RATE, call.getInt("frameRate", 15));
        service.putExtra(ScreenCaptureService.EXTRA_AUDIO, Boolean.TRUE.equals(call.getBoolean("audio", false)));

        // Since Android 8 a background start would be refused; the app is in the foreground here
        // by construction — the user has just answered a dialog in it.
        getContext().startForegroundService(service);
    }

    @PluginMethod
    public void stop(PluginCall call) {
        ScreenCaptureService.stopCapture(getContext());
        call.resolve();
    }

    @Override
    protected void handleOnDestroy() {
        ScreenCaptureService.stopCapture(getContext());
        ScreenCaptureService.setListener(null);
        super.handleOnDestroy();
    }
}
