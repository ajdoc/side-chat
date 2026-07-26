package chat.side.app.screencapture;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.graphics.Bitmap;
import android.graphics.PixelFormat;
import android.hardware.display.DisplayManager;
import android.hardware.display.VirtualDisplay;
import android.media.AudioAttributes;
import android.media.AudioFormat;
import android.media.AudioPlaybackCaptureConfiguration;
import android.media.AudioRecord;
import android.media.Image;
import android.media.ImageReader;
import android.media.projection.MediaProjection;
import android.media.projection.MediaProjectionManager;
import android.os.Build;
import android.os.Handler;
import android.os.HandlerThread;
import android.os.IBinder;
import android.util.DisplayMetrics;
import android.util.Log;
import android.view.WindowManager;

import java.io.ByteArrayOutputStream;
import java.nio.ByteBuffer;
import java.security.SecureRandom;

/**
 * The screen capture itself: MediaProjection in a foreground service.
 *
 * A service because Android insists on one — a projection may only run while a foreground
 * service of type `mediaProjection` is up, and since Android 14 that service may only be started
 * *after* the user has granted the projection. The notification it posts is not decoration; it
 * is the user's guaranteed way to see that their screen is being read, and to stop it.
 *
 * What it produces goes out over {@link FrameSocketServer} rather than to a file: the whole point
 * is to get the frames into the WebView, where they become an ordinary MediaStream and join the
 * call like any other track.
 *
 * Audio is captured with {@link AudioPlaybackCaptureConfiguration}, which is Android 10 and
 * later, and which the system silently limits to apps that haven't opted out of being captured.
 * A share with no sound is therefore normal and not an error — the same as a whole-screen share
 * on a desktop.
 */
public class ScreenCaptureService extends Service {

    private static final String TAG = "ScreenCapture";
    private static final String CHANNEL_ID = "screen_capture";
    private static final int NOTIFICATION_ID = 4711;

    public static final String EXTRA_RESULT_CODE = "resultCode";
    public static final String EXTRA_RESULT_DATA = "resultData";
    public static final String EXTRA_HEIGHT = "height";
    public static final String EXTRA_FRAME_RATE = "frameRate";
    public static final String EXTRA_AUDIO = "audio";

    /** JPEG quality for a frame. High enough to read code on a shared editor, low enough to send. */
    private static final int JPEG_QUALITY = 55;
    private static final int AUDIO_SAMPLE_RATE = 48000;
    private static final int AUDIO_CHANNELS = 2;

    /** Told when the capture ends for any reason, so the plugin can tell the page. */
    public interface Listener {
        void onReady(String endpoint, int width, int height, int frameRate, boolean audio);

        void onEnded();
    }

    private static Listener listener;
    private static ScreenCaptureService instance;

    public static void setListener(Listener next) {
        listener = next;
    }

    /** Stop a capture from outside — the app's own "Stop sharing" button. */
    public static void stopCapture(Context context) {
        if (instance != null) instance.stopSelf();
        else context.stopService(new Intent(context, ScreenCaptureService.class));
    }

    public static boolean isRunning() {
        return instance != null;
    }

    private MediaProjection projection;
    private VirtualDisplay display;
    private ImageReader imageReader;
    private FrameSocketServer socket;
    private HandlerThread captureThread;
    private Handler captureHandler;

    private AudioRecord audioRecord;
    private Thread audioThread;
    private volatile boolean running;

    private long minimumFrameIntervalMs;
    private long lastFrameAt;

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (intent == null) {
            stopSelf();
            return START_NOT_STICKY;
        }

        instance = this;
        startForegroundNotification();

        int resultCode = intent.getIntExtra(EXTRA_RESULT_CODE, 0);
        Intent resultData = intent.getParcelableExtra(EXTRA_RESULT_DATA);
        int targetHeight = intent.getIntExtra(EXTRA_HEIGHT, 720);
        int frameRate = Math.max(1, intent.getIntExtra(EXTRA_FRAME_RATE, 15));
        boolean wantAudio = intent.getBooleanExtra(EXTRA_AUDIO, false);

        try {
            begin(resultCode, resultData, targetHeight, frameRate, wantAudio);
        } catch (Exception e) {
            Log.e(TAG, "could not start the capture", e);
            stopSelf();
        }

        return START_NOT_STICKY;
    }

    private void begin(int resultCode, Intent resultData, int targetHeight, int frameRate, boolean wantAudio) {
        MediaProjectionManager manager = (MediaProjectionManager) getSystemService(Context.MEDIA_PROJECTION_SERVICE);
        projection = manager.getMediaProjection(resultCode, resultData);
        if (projection == null) {
            stopSelf();
            return;
        }

        // The user can revoke the projection from the system notification at any moment, and
        // that path never touches the app's own button.
        projection.registerCallback(new MediaProjection.Callback() {
            @Override
            public void onStop() {
                stopSelf();
            }
        }, new Handler(getMainLooper()));

        DisplayMetrics metrics = new DisplayMetrics();
        WindowManager windows = (WindowManager) getSystemService(Context.WINDOW_SERVICE);
        windows.getDefaultDisplay().getRealMetrics(metrics);

        // Scale the capture down to the requested height, keeping the screen's shape. This is
        // the real lever on cost: every pixel here is encoded, sent, decoded and then encoded
        // again by WebRTC once per peer.
        float ratio = Math.min(1f, (float) targetHeight / (float) metrics.heightPixels);
        // Widths must be even for the encoders downstream; an odd one produces a sheared image.
        int width = Math.max(2, (int) (metrics.widthPixels * ratio) & ~1);
        int height = Math.max(2, (int) (metrics.heightPixels * ratio) & ~1);

        minimumFrameIntervalMs = 1000L / frameRate;

        String token = newToken();
        socket = new FrameSocketServer(token);
        socket.start();

        captureThread = new HandlerThread("screen-capture");
        captureThread.start();
        captureHandler = new Handler(captureThread.getLooper());

        imageReader = ImageReader.newInstance(width, height, PixelFormat.RGBA_8888, 2);
        imageReader.setOnImageAvailableListener(this::onFrame, captureHandler);

        display = projection.createVirtualDisplay(
                "SideChatScreen",
                width, height, metrics.densityDpi,
                DisplayManager.VIRTUAL_DISPLAY_FLAG_AUTO_MIRROR,
                imageReader.getSurface(), null, captureHandler);

        boolean audioStarted = wantAudio && startAudioCapture();

        running = true;

        // getPort() is only meaningful once the server has actually bound, which start() does
        // asynchronously; WebSocketServer resolves the port synchronously enough for this, and
        // the page retries nothing — so report it only after the display is up.
        if (listener != null) {
            listener.onReady("ws://127.0.0.1:" + socket.getPort() + "/" + token, width, height, frameRate, audioStarted);
        }
    }

    /** A fresh secret per capture — the socket carries a picture of the user's screen. */
    private String newToken() {
        byte[] bytes = new byte[16];
        new SecureRandom().nextBytes(bytes);
        StringBuilder out = new StringBuilder();
        for (byte b : bytes) out.append(String.format("%02x", b));
        return out.toString();
    }

    private void onFrame(ImageReader reader) {
        Image image = null;
        try {
            image = reader.acquireLatestImage();
            if (image == null || !running || socket == null) return;

            // Pace to the requested frame rate. The VirtualDisplay hands over frames as fast as
            // the screen changes, and encoding every one of them on a phone is what makes the
            // device hot and the call stutter.
            long now = System.currentTimeMillis();
            if (now - lastFrameAt < minimumFrameIntervalMs) return;
            // Nothing to do at all while the page hasn't connected (or has gone away).
            if (!socket.hasClients()) return;
            lastFrameAt = now;

            Image.Plane plane = image.getPlanes()[0];
            ByteBuffer buffer = plane.getBuffer();
            int pixelStride = plane.getPixelStride();
            int rowStride = plane.getRowStride();
            int width = image.getWidth();
            int height = image.getHeight();
            // The plane is padded out to a hardware-friendly row width; the extra pixels have to
            // be included in the bitmap and then cropped, or every row is offset from the last.
            int padding = (rowStride - pixelStride * width) / pixelStride;

            Bitmap padded = Bitmap.createBitmap(width + padding, height, Bitmap.Config.ARGB_8888);
            padded.copyPixelsFromBuffer(buffer);

            Bitmap frame = padding == 0 ? padded : Bitmap.createBitmap(padded, 0, 0, width, height);

            ByteArrayOutputStream out = new ByteArrayOutputStream();
            frame.compress(Bitmap.CompressFormat.JPEG, JPEG_QUALITY, out);
            socket.broadcastFrame(FrameSocketServer.FRAME_VIDEO, out.toByteArray(), out.size());

            if (frame != padded) frame.recycle();
            padded.recycle();
        } catch (Exception e) {
            Log.w(TAG, "dropped a frame", e);
        } finally {
            if (image != null) image.close();
        }
    }

    /**
     * The device's own output, as PCM.
     *
     * Android 10 and later only, and only for apps that allow it — a media app can opt out, and
     * many do. Failing here is normal: the picture is still worth sharing without the sound, so
     * this reports false rather than tearing the capture down.
     */
    private boolean startAudioCapture() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) return false;

        try {
            AudioPlaybackCaptureConfiguration config =
                    new AudioPlaybackCaptureConfiguration.Builder(projection)
                            .addMatchingUsage(AudioAttributes.USAGE_MEDIA)
                            .addMatchingUsage(AudioAttributes.USAGE_GAME)
                            .addMatchingUsage(AudioAttributes.USAGE_UNKNOWN)
                            .build();

            AudioFormat format = new AudioFormat.Builder()
                    .setEncoding(AudioFormat.ENCODING_PCM_16BIT)
                    .setSampleRate(AUDIO_SAMPLE_RATE)
                    .setChannelMask(AudioFormat.CHANNEL_IN_STEREO)
                    .build();

            int minimum = AudioRecord.getMinBufferSize(
                    AUDIO_SAMPLE_RATE, AudioFormat.CHANNEL_IN_STEREO, AudioFormat.ENCODING_PCM_16BIT);
            int bufferSize = Math.max(minimum, AUDIO_SAMPLE_RATE * AUDIO_CHANNELS); // ~0.5s of slack

            audioRecord = new AudioRecord.Builder()
                    .setAudioFormat(format)
                    .setBufferSizeInBytes(bufferSize)
                    .setAudioPlaybackCaptureConfig(config)
                    .build();

            audioRecord.startRecording();

            audioThread = new Thread(() -> {
                // ~20ms per packet: small enough that the page can schedule it gaplessly, large
                // enough that the socket isn't handling thousands of messages a second.
                byte[] chunk = new byte[AUDIO_SAMPLE_RATE / 50 * AUDIO_CHANNELS * 2];
                while (running) {
                    int read = audioRecord.read(chunk, 0, chunk.length);
                    if (read > 0 && socket != null && socket.hasClients()) {
                        socket.broadcastFrame(FrameSocketServer.FRAME_AUDIO, chunk, read);
                    } else if (read < 0) {
                        break;
                    }
                }
            }, "screen-capture-audio");
            audioThread.start();

            return true;
        } catch (Exception e) {
            Log.w(TAG, "no system audio available for this capture", e);
            audioRecord = null;
            return false;
        }
    }

    private void startForegroundNotification() {
        NotificationManager notifications = getSystemService(NotificationManager.class);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel channel = new NotificationChannel(
                    CHANNEL_ID, "Screen sharing", NotificationManager.IMPORTANCE_LOW);
            channel.setDescription("Shown while your screen is being shared with a call.");
            notifications.createNotificationChannel(channel);
        }

        Intent open = getPackageManager().getLaunchIntentForPackage(getPackageName());
        PendingIntent tap = open == null ? null : PendingIntent.getActivity(
                this, 0, open, PendingIntent.FLAG_IMMUTABLE);

        Notification notification = new Notification.Builder(this, CHANNEL_ID)
                .setContentTitle("Sharing your screen")
                .setContentText("Side Chat is sharing your screen with the call.")
                .setSmallIcon(android.R.drawable.ic_menu_share)
                .setOngoing(true)
                .setContentIntent(tap)
                .build();

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(NOTIFICATION_ID, notification,
                    android.content.pm.ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PROJECTION);
        } else {
            startForeground(NOTIFICATION_ID, notification);
        }
    }

    @Override
    public void onDestroy() {
        running = false;

        try {
            if (audioRecord != null) {
                audioRecord.stop();
                audioRecord.release();
            }
        } catch (Exception ignored) {
            // Already gone; nothing to salvage.
        }
        audioRecord = null;

        if (display != null) display.release();
        display = null;

        if (imageReader != null) imageReader.close();
        imageReader = null;

        if (projection != null) projection.stop();
        projection = null;

        if (socket != null) {
            try {
                socket.stop(0);
            } catch (Exception ignored) {
                // The page sees the socket close and stops its tracks either way.
            }
        }
        socket = null;

        if (captureThread != null) captureThread.quitSafely();
        captureThread = null;

        instance = null;
        if (listener != null) listener.onEnded();

        super.onDestroy();
    }
}
