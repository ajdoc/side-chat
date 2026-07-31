package chat.side.app.voice;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.os.Build;
import android.os.IBinder;
import android.os.PowerManager;

/**
 * Keeps a voice call alive once the app is no longer on screen.
 *
 * Nothing about the call lives here — the WebRTC mesh, the microphone and the peers are all
 * inside the WebView, exactly as on the web. What this service buys is permission to keep
 * running: from Android 12 a backgrounded app's microphone is cut, and from Android 14 the only
 * way to hold it is a foreground service that has declared `microphone` as its type. So the page
 * asks for one on join and drops it on leave, and in between Android leaves the capture alone.
 *
 * The notification is not decoration either. A foreground service must post one, and it is also
 * the user's way back into a call they've navigated away from — and their way out of it without
 * hunting for the app, via {@link #ACTION_LEAVE}.
 *
 * The wake lock is a second, separate problem: the service survives the app going to the
 * background, but the screen going off puts the CPU to sleep, and a partial lock is what keeps
 * the audio threads scheduled through it. It does not keep the screen on.
 */
public class VoiceService extends Service {

    private static final String CHANNEL_ID = "voice_call";
    private static final int NOTIFICATION_ID = 4712;

    public static final String EXTRA_TITLE = "title";
    public static final String EXTRA_TEXT = "text";

    /** The notification's own "Leave" button, which never passes through the page. */
    public static final String ACTION_LEAVE = "chat.side.app.voice.LEAVE";

    /** Told when the user asks to leave from the notification, so the plugin can tell the page. */
    public interface Listener {
        void onLeaveRequested();
    }

    private static Listener listener;
    private static VoiceService instance;

    public static void setListener(Listener next) {
        listener = next;
    }

    public static boolean isRunning() {
        return instance != null;
    }

    public static void stop(Context context) {
        if (instance != null) instance.stopSelf();
        else context.stopService(new Intent(context, VoiceService.class));
    }

    private PowerManager.WakeLock wakeLock;

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (intent != null && ACTION_LEAVE.equals(intent.getAction())) {
            // Only ask; the page owns leaving. It calls back into stop() once it has actually
            // torn the call down, so the notification doesn't vanish before the call has.
            if (listener != null) listener.onLeaveRequested();
            return START_NOT_STICKY;
        }

        instance = this;

        String title = intent == null ? null : intent.getStringExtra(EXTRA_TITLE);
        String text = intent == null ? null : intent.getStringExtra(EXTRA_TEXT);

        startForegroundNotification(
                title == null ? "In a voice call" : title,
                text == null ? "Side Chat is keeping your call connected." : text);

        acquireWakeLock();

        // Not START_STICKY: a call that the system killed is over, and reviving the service
        // without the page that was holding the call would leave a notification for nothing.
        return START_NOT_STICKY;
    }

    private void startForegroundNotification(String title, String text) {
        NotificationManager notifications = getSystemService(NotificationManager.class);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel channel = new NotificationChannel(
                    CHANNEL_ID, "Voice calls", NotificationManager.IMPORTANCE_LOW);
            channel.setDescription("Shown while you are connected to a voice channel.");
            // A call notification that buzzes on every re-post would be unbearable.
            channel.setShowBadge(false);
            notifications.createNotificationChannel(channel);
        }

        Intent open = getPackageManager().getLaunchIntentForPackage(getPackageName());
        PendingIntent tap = open == null ? null : PendingIntent.getActivity(
                this, 0, open, PendingIntent.FLAG_IMMUTABLE);

        Intent leave = new Intent(this, VoiceService.class).setAction(ACTION_LEAVE);
        PendingIntent leaveIntent = PendingIntent.getService(
                this, 1, leave, PendingIntent.FLAG_IMMUTABLE);

        Notification.Builder builder = new Notification.Builder(this, CHANNEL_ID)
                .setContentTitle(title)
                .setContentText(text)
                .setSmallIcon(android.R.drawable.stat_sys_speakerphone)
                .setOngoing(true)
                .setContentIntent(tap)
                .addAction(new Notification.Action.Builder(
                        null, "Leave", leaveIntent).build());

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            // Gets the call its own section at the top of the shade rather than a place in the
            // ordinary pile, which is where a user looks for a call in progress.
            builder.setCategory(Notification.CATEGORY_CALL);
        }

        Notification notification = builder.build();

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(NOTIFICATION_ID, notification,
                    android.content.pm.ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE);
        } else {
            startForeground(NOTIFICATION_ID, notification);
        }
    }

    private void acquireWakeLock() {
        if (wakeLock != null) return;

        PowerManager power = getSystemService(PowerManager.class);
        wakeLock = power.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "SideChat:voice");
        wakeLock.setReferenceCounted(false);
        wakeLock.acquire();
    }

    @Override
    public void onDestroy() {
        if (wakeLock != null && wakeLock.isHeld()) wakeLock.release();
        wakeLock = null;

        instance = null;
        super.onDestroy();
    }
}
