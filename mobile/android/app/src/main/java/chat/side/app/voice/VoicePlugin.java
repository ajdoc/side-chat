package chat.side.app.voice;

import android.content.Intent;
import android.os.Build;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.getcapacitor.annotation.Permission;
import com.getcapacitor.annotation.PermissionCallback;

/**
 * The page's handle on {@link VoiceService}: "I am in a call, keep me running" and "I'm done".
 *
 * Deliberately thin. Android's rule is about the *app* being allowed to hold a microphone in the
 * background, not about who owns the call, so there is nothing to move down here — useVoice
 * keeps every bit of the call it already had and simply brackets it with start/stop.
 *
 * @see useBackgroundVoice on the web side, which is the only caller.
 */
@CapacitorPlugin(
        name = "BackgroundVoice",
        permissions = {
                @Permission(alias = VoicePlugin.NOTIFICATIONS, strings = { android.Manifest.permission.POST_NOTIFICATIONS })
        }
)
public class VoicePlugin extends Plugin {

    static final String NOTIFICATIONS = "notifications";

    @Override
    public void load() {
        VoiceService.setListener(() ->
                // The notification's Leave button. The page hangs up properly on this — the
                // service is only stopped by the stop() that follows.
                notifyListeners("backgroundVoiceLeaveRequested", new JSObject()));
    }

    /**
     * Take the foreground service for the duration of a call.
     *
     * On Android 13+ the notification needs a runtime grant, and asking for it here means asking
     * at the moment it is obviously about a call rather than at first launch. A refusal is not
     * fatal: the service still runs and the call still survives, the user just doesn't get the
     * shade entry — so the permission is requested and the start proceeds either way.
     */
    @PluginMethod
    public void start(PluginCall call) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU
                && getPermissionState(NOTIFICATIONS) != com.getcapacitor.PermissionState.GRANTED) {
            requestPermissionForAlias(NOTIFICATIONS, call, "onNotificationsResult");
            return;
        }

        begin(call);
    }

    @PermissionCallback
    private void onNotificationsResult(PluginCall call) {
        begin(call);
    }

    private void begin(PluginCall call) {
        Intent service = new Intent(getContext(), VoiceService.class);
        service.putExtra(VoiceService.EXTRA_TITLE, call.getString("title"));
        service.putExtra(VoiceService.EXTRA_TEXT, call.getString("text"));

        try {
            // A foreground start is only allowed from the foreground, which holds by
            // construction: the user has just tapped Join. If it doesn't — the call was resumed
            // from a background tab wake, say — the call still works, it just won't outlive the
            // app, and that is far better than failing the join.
            getContext().startForegroundService(service);
            call.resolve();
        } catch (Exception e) {
            call.resolve(new JSObject().put("started", false));
        }
    }

    @PluginMethod
    public void stop(PluginCall call) {
        VoiceService.stop(getContext());
        call.resolve();
    }

    @Override
    protected void handleOnDestroy() {
        // The WebView holding the call is going away, so the call is over whatever the page
        // last said. Leaving the service up would strand a notification for nothing.
        VoiceService.stop(getContext());
        VoiceService.setListener(null);
        super.handleOnDestroy();
    }
}
