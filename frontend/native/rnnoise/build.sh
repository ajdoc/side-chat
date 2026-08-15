#!/usr/bin/env bash
#
# Build public/worklets/rnnoise.wasm.
#
# In Docker, and that is the point of this script rather than a line in the README: the artifact
# it produces is *committed*, so almost nobody who touches this repo needs a Rust toolchain, and
# the few who regenerate it should get the same bytes as everyone else rather than whatever
# their machine's rustc happens to be. Nothing is installed on the host.
#
#   ./build.sh
#
# Two things here exist to keep a root-in-a-container build from leaving a mess in a checkout
# owned by a person: it runs as the invoking user, and the 29MB target directory is kept inside
# the container rather than in the source tree. Only the wasm comes out.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
out="$here/../../public/worklets"

# Pinned, not `rust:latest`. A committed binary artifact whose compiler drifts underneath it is
# a diff nobody can review.
image="rust:1-slim"

# The registry cache lives in a named volume, so a re-run after a source edit is seconds rather
# than a fresh download of the crate index. It has to be made writable by the unprivileged user
# we then build as — a fresh volume is created root-owned. Idempotent, and cheap enough to
# repeat rather than try to detect.
docker volume create side-rnnoise-cargo >/dev/null
docker run --rm -v side-rnnoise-cargo:/cargo "$image" \
  chown -R "$(id -u):$(id -g)" /cargo

docker run --rm \
  --user "$(id -u):$(id -g)" \
  -v "$here:/src" \
  -v "$out:/out" \
  -v side-rnnoise-cargo:/cargo \
  -e CARGO_HOME=/cargo \
  -e CARGO_TARGET_DIR=/tmp/build \
  -w /src \
  "$image" \
  sh -euc '
    rustup target add wasm32-unknown-unknown
    cargo build --release --target wasm32-unknown-unknown
    cp /tmp/build/wasm32-unknown-unknown/release/side_rnnoise.wasm /out/rnnoise.wasm
  '

echo
echo "wrote $out/rnnoise.wasm ($(du -h "$out/rnnoise.wasm" | cut -f1))"
echo
# The whole design rests on this module importing nothing — see lib.rs. If a dependency ever
# pulls in something that needs a host function, the worklet's `new WebAssembly.Instance(m, {})`
# starts throwing at runtime, in a call, on someone else's machine. Better to fail here, loudly,
# than to ship it.
#
# Checked in a container too, rather than with a host `node` that may not exist — an
# availability-dependent guard is one that passes by being skipped on the machine that needed
# it. This is also the closest thing to a real test of the artifact available without a browser:
# it instantiates with no imports and runs a frame, exactly as the worklet does.
check='
  const fs = require("fs")
  WebAssembly.compile(fs.readFileSync("/out/rnnoise.wasm")).then(m => {
    const imports = WebAssembly.Module.imports(m)
    if (imports.length) {
      console.error("FAIL: module wants host functions:", JSON.stringify(imports))
      console.error("The worklet instantiates with {} and cannot supply any. See lib.rs.")
      process.exit(1)
    }
    const api = new WebAssembly.Instance(m, {}).exports
    api.rnnoise_init()
    const n = api.rnnoise_frame_size()
    const wIn = new Float32Array(api.memory.buffer, api.rnnoise_input_ptr(), n)
    for (let i = 0; i < n; i++) wIn[i] = Math.sin(i / 8) * 8000
    const vad = api.rnnoise_process()
    if (!Number.isFinite(vad) || vad < 0) {
      console.error("FAIL: process() returned", vad, "- state was never built")
      process.exit(1)
    }
    console.log(`imports: 0, frame: ${n}, ran a frame ok`)
  }).catch(err => { console.error("FAIL:", err.message); process.exit(1) })
'

docker run --rm -v "$out:/out:ro" node:22-alpine node -e "$check"
