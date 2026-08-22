# Toolbox container for the MOBA's Rust crates.
#
# Not a long-running service — it sleeps, and `docker compose exec rust cargo ...` does the
# work. That matches how the PHP and Node toolchains are already reached in this repo, so a
# `cargo test` is verified the same way a `php artisan test` is and nobody needs rustup on
# the host.
FROM rust:1-slim-bookworm

# `wasm32-unknown-unknown` is the client's target. It costs nothing to add now and saves a
# rebuild of this image the day moba-client appears.
RUN rustup target add wasm32-unknown-unknown \
    && rustup component add clippy rustfmt

# pkg-config/libssl are what most crates reach for when they gain a network dependency;
# moba-server wants them. python3 is only here to serve the client's dev harness as static
# files — see the `moba-play` make target.
RUN apt-get update \
    && apt-get install -y --no-install-recommends pkg-config libssl-dev python3 \
    && rm -rf /var/lib/apt/lists/*

# The tool that turns a raw .wasm into something a browser can import. Its version must match
# the `wasm-bindgen` crate the client depends on — a mismatch fails at generate time with a
# clear message, which is the good case; the bad case is nobody pinning it at all.
ARG WASM_BINDGEN_VERSION=0.2.127
RUN cargo install wasm-bindgen-cli --version ${WASM_BINDGEN_VERSION} --locked

WORKDIR /game
