package chat.side.app.screencapture;

import android.util.Log;

import org.java_websocket.WebSocket;
import org.java_websocket.handshake.ClientHandshake;
import org.java_websocket.server.WebSocketServer;

import java.net.InetSocketAddress;
import java.nio.ByteBuffer;
import java.util.Collections;
import java.util.Set;
import java.util.WeakHashMap;

/**
 * The pipe from the native capture into the WebView.
 *
 * There is no API for handing a natively-captured frame to the WebView's WebRTC stack — no
 * mobile WebView implements getDisplayMedia at all, and a MediaProjection surface cannot be
 * turned into a MediaStreamTrack. So the frames go the long way round: encoded here, carried
 * over a loopback socket, decoded in the page, drawn to a canvas, and picked back up as a
 * stream by `canvas.captureStream()`. See useDisplayCapture on the web side.
 *
 * Bound to 127.0.0.1 only, on an ephemeral port, and the URL carries a random token that the
 * handshake must match. Nothing off the device can reach it, and nothing else on the device can
 * guess it — which matters, because what travels over it is a picture of the user's screen.
 *
 * The protocol is one byte of type then the payload: 1 = a JPEG frame, 2 = 48kHz signed 16-bit
 * interleaved stereo PCM.
 */
public class FrameSocketServer extends WebSocketServer {

    private static final String TAG = "ScreenCapture";

    public static final byte FRAME_VIDEO = 1;
    public static final byte FRAME_AUDIO = 2;

    private final String token;
    private final Set<WebSocket> clients = Collections.newSetFromMap(new WeakHashMap<>());

    public FrameSocketServer(String token) {
        // Port 0 asks the OS for a free one; getPort() below reports what it settled on.
        super(new InetSocketAddress("127.0.0.1", 0));
        this.token = token;
        setReuseAddr(true);
        // Frames are produced on the capture thread and dropped if the socket can't keep up;
        // blocking there would stall the VirtualDisplay itself.
        setConnectionLostTimeout(0);
    }

    @Override
    public void onStart() {
        Log.i(TAG, "frame socket listening on " + getPort());
    }

    @Override
    public void onOpen(WebSocket connection, ClientHandshake handshake) {
        // The path is the shared secret. A connection that doesn't know it is closed unread.
        if (!("/" + token).equals(handshake.getResourceDescriptor())) {
            connection.close();
            return;
        }
        synchronized (clients) {
            clients.add(connection);
        }
    }

    @Override
    public void onClose(WebSocket connection, int code, String reason, boolean remote) {
        synchronized (clients) {
            clients.remove(connection);
        }
    }

    @Override
    public void onMessage(WebSocket connection, String message) {
        // Nothing is expected back — the page only ever reads.
    }

    @Override
    public void onError(WebSocket connection, Exception e) {
        Log.w(TAG, "frame socket error", e);
    }

    /** Prefix the payload with its type and push it to whoever is listening. */
    public void broadcastFrame(byte type, byte[] payload, int length) {
        ByteBuffer buffer = ByteBuffer.allocate(length + 1);
        buffer.put(type);
        buffer.put(payload, 0, length);
        buffer.flip();

        synchronized (clients) {
            for (WebSocket client : clients) {
                if (!client.isOpen()) continue;
                try {
                    client.send(buffer.duplicate());
                } catch (Exception e) {
                    // A client that has gone away mid-send isn't worth tearing the capture down
                    // for; onClose will drop it from the set a moment later.
                    Log.w(TAG, "dropped a frame for a closing client", e);
                }
            }
        }
    }

    /** Is anybody actually watching? Nothing needs encoding if not. */
    public boolean hasClients() {
        synchronized (clients) {
            for (WebSocket client : clients) {
                if (client.isOpen()) return true;
            }
        }
        return false;
    }
}
