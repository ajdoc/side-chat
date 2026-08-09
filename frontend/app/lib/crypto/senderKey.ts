/**
 * The sender key: how one person's messages get encrypted once and read by everybody.
 *
 * The alternative is pairwise — encrypt each message separately to each recipient's device.
 * That is what the pairwise sessions in `identity.ts` do, and it is the right answer for
 * *establishing* trust, but it is the wrong answer for sending: a 25-person group chat
 * where everybody has two devices means fifty encryptions per message, and fifty copies of
 * every message on the wire. A sender key inverts it. Each sender has one chain, sends one
 * ciphertext, and distributes the chain's starting key to each device *once* over the
 * pairwise sessions. Messages after that cost one encryption regardless of the audience.
 *
 * The chain ratchets. Every message consumes the current chain key to produce two things —
 * a message key used exactly once, and the *next* chain key — and the current chain key is
 * then forgotten. Because the derivation only runs forwards, someone who compromises a
 * device today gets the chain from today onwards and nothing before it. That property is
 * the entire reason for the ratchet, and it is why {@link advance} never keeps what it
 * consumed.
 *
 * What this deliberately does not have is a Double Ratchet's DH step, so it doesn't heal
 * after a compromise the way a pairwise Signal session does. The epoch is the coarse
 * substitute: membership changes and the encryption toggle both start a fresh chain (see
 * the channels migration), which bounds how long any one leak stays useful. Worth revisiting
 * if this ever protects something where that bound isn't good enough.
 */

import { deriveBytes, importAesKey, open, randomBytes, seal, utf8, type Sealed } from './primitives'

/** Chain and message keys are both 32 bytes — SHA-256's output, and AES-256's input. */
const KEY_BYTES = 32

/**
 * Distinct HKDF purposes, so the two things derived from one chain key are unrelated.
 *
 * If both used the same `info`, the message key *would be* the next chain key: anyone given
 * a single message key could compute the rest of the conversation. The strings are load
 * bearing, and changing one is a wire-format break, not a rename.
 */
const MESSAGE_INFO = 'side-chat/sender-key/message'
const CHAIN_INFO = 'side-chat/sender-key/chain'

/**
 * One sender's chain, at one point in time.
 *
 * `index` counts messages, and travels with each one so a recipient who missed some can
 * catch up (see {@link messageKeyAt}). It is not a secret — it says how much was said, which
 * anybody watching the timeline can see anyway.
 */
export interface SenderChain {
  /** Which key era this chain belongs to. Matches `channels.encryption_epoch`. */
  epoch: number
  /** The device this chain belongs to. A person with two devices has two chains. */
  deviceId: string
  /**
   * The key this chain **started** with, and the value that is persisted.
   *
   * Always the root, never a ratcheted-forward value. This is the invariant the whole design
   * rests on and it is easy to violate by accident: {@link advance} returns a chain carrying
   * the *next* key, which is exactly what you want in a loop and exactly what you must not
   * write to storage.
   *
   * The reason is that reading is repeatable and sending is not. A message can be re-rendered
   * any number of times, and each read winds a fresh copy from the root to the index its
   * envelope names — so the root has to still be there. Persisting an advanced key makes
   * sending look perfect while silently breaking every re-read of your own messages.
   */
  chainKey: Uint8Array
  /**
   * How many messages *this device* has sent on this chain.
   *
   * A send counter, not a position in the key material — the key above never moves. Zero on
   * somebody else's chain, which we only ever read from. It must never go backwards: two
   * messages sealed at the same index share a key, which AES-GCM does not survive.
   */
  index: number
}

/** A message key, and where in the chain it came from. */
export interface RatchetStep {
  messageKey: CryptoKey
  chain: SenderChain
}

/** A brand-new chain, at the start of an epoch. */
export function createChain(epoch: number, deviceId: string): SenderChain {
  return { epoch, deviceId, chainKey: randomBytes(KEY_BYTES), index: 0 }
}

/**
 * Take one step: a key for this message, and the chain positioned for the next.
 *
 * Returns a *new* chain rather than mutating the one passed in. Two sends racing on a shared
 * mutable chain would derive the same message key twice — which in GCM means two messages
 * encrypted under one key, and that is a break, not a glitch. Making the caller replace its
 * chain reference makes the sequencing explicit.
 */
export async function advance(chain: SenderChain): Promise<RatchetStep> {
  const messageBytes = await deriveBytes(chain.chainKey, MESSAGE_INFO, KEY_BYTES)
  const nextChainKey = await deriveBytes(chain.chainKey, CHAIN_INFO, KEY_BYTES)

  return {
    messageKey: await importAesKey(messageBytes),
    chain: { ...chain, chainKey: nextChainKey, index: chain.index + 1 },
  }
}

/**
 * Wind a chain forward to a given message index.
 *
 * Needed on the receiving side, where messages arrive out of order or a client comes back
 * from being offline: the chain is at 4 and message 9 has just landed. Deriving forwards is
 * cheap and only possible in that direction — which is also why a recipient who has wound
 * past a message can no longer read it, and must keep any keys it skipped.
 *
 * Refuses to go backwards rather than silently returning the wrong key. A request for an
 * index already passed means the caller lost track of state, and quietly handing back
 * something that fails to decrypt would send them looking in the wrong place entirely.
 */
export async function messageKeyAt(chain: SenderChain, index: number): Promise<RatchetStep> {
  // A chain sitting at index N has *produced* messages 1..N; the next step yields N+1. So a
  // request for N or lower is a request for something already consumed, not a short wind.
  if (index <= chain.index) {
    throw new Error(
      `sender chain is already at ${chain.index}; message key ${index} has been consumed`,
    )
  }

  let step = await advance(chain)

  while (step.chain.index < index) {
    step = await advance(step.chain)
  }

  return step
}

/**
 * Encrypt one message, and hand back the advanced chain.
 *
 * `context` is authenticated but not encrypted — the epoch, the sender and the index, as
 * they will travel on the wire. Binding them into the ciphertext is what stops a message
 * being replayed under a different epoch or attributed to somebody else: change any of
 * them and the decrypt fails rather than succeeding with a lie attached.
 */
export async function encryptWithChain(
  chain: SenderChain,
  plaintext: string,
  context: Uint8Array,
): Promise<{ sealed: Sealed; chain: SenderChain; index: number }> {
  const step = await advance(chain)
  const sealed = await seal(step.messageKey, utf8(plaintext), context)

  return { sealed, chain: step.chain, index: step.chain.index }
}

/** Decrypt one message with a key already wound to the right index. */
export async function decryptWithKey(
  messageKey: CryptoKey,
  sealed: Sealed,
  context: Uint8Array,
): Promise<Uint8Array> {
  return open(messageKey, sealed, context)
}
