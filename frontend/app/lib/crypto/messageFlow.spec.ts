import { describe, expect, it } from 'vitest'

import { contextBytes, packEnvelope, sealedFrom, unpackEnvelope } from './envelope'
import { fromUtf8, seal, toBase64, utf8 } from './primitives'
import {
  createChain,
  decryptWithKey,
  messageKeyAt,
  type SenderChain,
} from './senderKey'

/*
 * The message path, end to end — everything `useMessageCrypto` does, minus Nuxt.
 *
 * The composable itself is thin glue over these calls, and mounting a Nuxt runtime to test
 * glue would test the runtime. What is worth pinning is the *sequence*: encrypt, pack, ship
 * as text, unpack, wind the receiving chain, decrypt. Every bug this layer can have is an
 * ordering or state-keeping bug in that sequence, and all of them are reachable from here.
 *
 * The receive side deliberately mirrors the real one: a copy of the chain wound from zero on
 * every read, never the stored chain advanced in place. That distinction is the subject of
 * more than one test below, because getting it wrong makes messages readable exactly once.
 */

/**
 * Send one message exactly the way `useMessageCrypto` does — including what it persists.
 *
 * The "including what it persists" is the point. An earlier version of this helper kept the
 * pristine chain and read back from that, which is not what the app does and is why a real
 * bug got through: the app stored the *advanced* key, so re-reading its own messages failed
 * while every test passed. The chain returned here is the one that goes to disk, and the
 * tests below read from it rather than from a copy they saved earlier.
 */
async function sendVia(
  chain: SenderChain,
  epoch: number,
  body: string,
): Promise<{ wire: string; chain: SenderChain }> {
  const nextIndex = chain.index + 1
  const header = { v: 1, e: epoch, d: chain.deviceId, i: nextIndex }

  const step = await messageKeyAt({ ...chain, index: 0 }, nextIndex)
  const sealed = await seal(step.messageKey, utf8(body), contextBytes(header))

  return {
    wire: packEnvelope({ e: epoch, d: chain.deviceId, i: nextIndex }, sealed),
    // Root key unchanged; only the send counter moves. See SenderChain.
    chain: { ...chain, index: nextIndex },
  }
}

/** Read one, from a *starting* chain — the copy-and-wind the receive side always does. */
async function readVia(start: SenderChain, wire: string): Promise<string> {
  const envelope = unpackEnvelope(wire)
  if (!envelope) throw new Error('not an envelope')

  const step = await messageKeyAt({ ...start, index: 0 }, envelope.i)

  return fromUtf8(await decryptWithKey(step.messageKey, sealedFrom(envelope), contextBytes(envelope)))
}

describe('message round trip', () => {
  it('carries a message from composer to timeline', async () => {
    const sender = createChain(1, 'ana-laptop')
    const sent = await sendVia(sender, 1, 'are we still on for Thursday?')

    expect(await readVia(sender, sent.wire)).toBe('are we still on for Thursday?')
  })

  it('reads the same message twice, because receiving never consumes the chain', async () => {
    // The bug this guards: advancing the *stored* receive chain on every read. It works
    // beautifully once and then the message is unreadable on re-render, scroll-back, or a
    // second device — a "message that disappears when you look away".
    const sender = createChain(1, 'ana-laptop')
    const sent = await sendVia(sender, 1, 'read me twice')

    expect(await readVia(sender, sent.wire)).toBe('read me twice')
    expect(await readVia(sender, sent.wire)).toBe('read me twice')
  })

  it('reads back its own messages after sending several — from the stored chain', async () => {
    /*
     * The regression. Reported as "this device doesn't have the key for this message",
     * appearing on your *own* messages moments after turning encryption on.
     *
     * Every read here uses the chain as it stands *after* the sends, which is what the store
     * holds — not a pristine copy kept aside. With the advanced key persisted, message one
     * became unreadable the instant message two was sent, and the failure looked like it was
     * about ownership because other people's chains (stored at index 0, never advanced) kept
     * working perfectly.
     */
    let chain = createChain(1, 'ana-laptop')
    const wires: string[] = []

    for (const body of ['first thing', 'second thing', 'third thing']) {
      const sent = await sendVia(chain, 1, body)
      chain = sent.chain
      wires.push(sent.wire)
    }

    expect(await readVia(chain, wires[0]!)).toBe('first thing')
    expect(await readVia(chain, wires[1]!)).toBe('second thing')
    expect(await readVia(chain, wires[2]!)).toBe('third thing')
  })

  it('never seals two messages under the same index', async () => {
    // The other half of the invariant. The counter may only move forward: two messages at one
    // index share a key, and a repeated key under AES-GCM leaks both plaintexts.
    let chain = createChain(1, 'ana-laptop')
    const indices: number[] = []

    for (let i = 0; i < 5; i++) {
      const sent = await sendVia(chain, 1, `message ${i}`)
      chain = sent.chain
      indices.push(unpackEnvelope(sent.wire)!.i)
    }

    expect(new Set(indices).size).toBe(indices.length)
    expect(indices).toEqual([1, 2, 3, 4, 5])
  })

  it('reads a run of messages in any order', async () => {
    // A timeline scrolls backwards, jumps to search results, and re-renders arbitrary
    // windows, so the reader must be able to land on message 7 without having seen 1–6.
    let chain = createChain(1, 'ana-laptop')
    const start = { ...chain }
    const wires: string[] = []

    for (const body of ['first', 'second', 'third', 'fourth']) {
      const sent = await sendVia(chain, 1, body)
      chain = sent.chain
      wires.push(sent.wire)
    }

    expect(await readVia(start, wires[3]!)).toBe('fourth')
    expect(await readVia(start, wires[0]!)).toBe('first')
    expect(await readVia(start, wires[2]!)).toBe('third')
  })

  it('keeps two senders in one channel apart', async () => {
    // Sender keys are per device, and a timeline interleaves them. Reading Ben's message
    // with Ana's chain must fail rather than produce something.
    const ana = createChain(1, 'ana-laptop')
    const ben = createChain(1, 'ben-phone')

    const fromBen = await sendVia(ben, 1, 'Ben said this')

    expect(await readVia(ben, fromBen.wire)).toBe('Ben said this')
    await expect(readVia(ana, fromBen.wire)).rejects.toThrow()
  })

  it('cannot read across an epoch boundary', async () => {
    // Encryption off and on again starts a new era with a new chain. Somebody holding the
    // old chain — a member since removed — must not be able to read the new one.
    const era1 = createChain(1, 'ana-laptop')
    const era2 = createChain(2, 'ana-laptop')

    const sent = await sendVia(era2, 2, 'said in the second era')

    expect(await readVia(era2, sent.wire)).toBe('said in the second era')
    await expect(readVia(era1, sent.wire)).rejects.toThrow()
  })

  it('leaves a plaintext body alone', () => {
    // The striped timeline: a run written before encryption was switched on sits alongside
    // ciphertext forever, and must render as itself rather than as a failed decrypt.
    expect(unpackEnvelope('just something somebody typed')).toBeNull()
    expect(unpackEnvelope(null)).toBeNull()
  })

  it('survives a body that is itself valid base64', async () => {
    // The nastiest plaintext case. Somebody pastes base64 into an unencrypted channel; the
    // reader must not mistake it for an envelope. The version and shape checks are what
    // catch it — which is why unpackEnvelope validates every field rather than trusting one.
    const looksLikeCiphertext = toBase64(utf8('this is just a base64 string someone pasted'))

    expect(unpackEnvelope(looksLikeCiphertext)).toBeNull()
  })

  it('refuses a message whose epoch was rewritten in transit', async () => {
    // The header travels in the clear so the server can route on it. Re-stamping a message
    // into another era must break it rather than silently re-attribute it.
    const chain = createChain(1, 'ana-laptop')
    const sent = await sendVia(chain, 1, 'era one')

    const envelope = unpackEnvelope(sent.wire)!
    const forged = packEnvelope({ e: 2, d: envelope.d, i: envelope.i }, sealedFrom(envelope))

    await expect(readVia({ ...chain, epoch: 2 }, forged)).rejects.toThrow()
  })

  it('chunks the plaintext, not the ciphertext', async () => {
    // A long message is split into parts *before* encryption, each part its own message with
    // its own ratchet step. Splitting afterwards would cut an envelope in half and neither
    // piece would ever decrypt.
    let chain = createChain(1, 'ana-laptop')
    const start = { ...chain }
    const parts = ['first half of a long message', 'second half of a long message']
    const wires: string[] = []

    for (const part of parts) {
      const sent = await sendVia(chain, 1, part)
      chain = sent.chain
      wires.push(sent.wire)
    }

    const read = []
    for (const wire of wires) read.push(await readVia(start, wire))

    expect(read).toEqual(parts)
  })

  it('re-encrypts an edit under the message’s own era, not the channel’s', async () => {
    // Editing a message from era 1 while the channel sits in era 2 must produce era-1
    // ciphertext. Under the current era it would land in a chain that era's readers never
    // had, and the edit would be unreadable to exactly the people who could read the original.
    const era1 = createChain(1, 'ana-laptop')
    const original = await sendVia(era1, 1, 'before the edit')
    const edited = await sendVia(original.chain, 1, 'after the edit')

    expect(await readVia(era1, edited.wire)).toBe('after the edit')
  })
})
