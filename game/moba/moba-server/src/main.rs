//! `moba-server` — one process, one match.
//!
//! Configuration is two environment variables, because there is nothing else to configure yet
//! and a config file for two values is a file to keep in sync:
//!
//! ```text
//! MOBA_ADDR=0.0.0.0:930   MOBA_TEAM_SIZE=1   cargo run -p moba-server
//! ```
//!
//! `MOBA_TEAM_SIZE` is the one that matters day to day: a 5v5 needs ten people, and `1` gives a
//! 1v1 that one person with two browser tabs can actually play. See MOBA.md.
//!
//! The rest are the seam with the API, and all three are optional so the harness keeps working:
//!
//! - `MOBA_SECRET` — shared with PHP. **Present means tickets are required**; absent means the
//!   server runs open, which is the development harness and never a deployment.
//! - `MOBA_MATCH_ID` — which match this process is running, so a valid ticket for a *different*
//!   match is refused.
//! - `MOBA_API_BASE` — where to report the result. Unset keeps no score.

use moba_server::server;
use moba_sim::sim::MatchConfig;

#[tokio::main]
async fn main() -> std::io::Result<()> {
    let addr = std::env::var("MOBA_ADDR").unwrap_or_else(|_| "0.0.0.0:930".to_string());
    let team_size: u8 = env_parse("MOBA_TEAM_SIZE").unwrap_or(5).clamp(1, 5);

    // Tickets are required whenever a secret is present, and absent otherwise. There is no flag
    // to turn them off: a deployment that forgets to set the secret gets a server that says so
    // in its startup line, rather than one silently running open because someone left a
    // `--no-auth` in a systemd unit.
    let secret = std::env::var("MOBA_SECRET").ok().filter(|s| !s.is_empty());
    let match_id = env_parse::<i64>("MOBA_MATCH_ID");
    let api_base = std::env::var("MOBA_API_BASE")
        .ok()
        .filter(|s| !s.is_empty());

    server::run(server::ServerConfig {
        addr,
        match_config: MatchConfig {
            team_size,
            ..MatchConfig::default()
        },
        secret,
        match_id,
        api_base,
    })
    .await
}

fn env_parse<T: std::str::FromStr>(key: &str) -> Option<T> {
    std::env::var(key).ok().and_then(|v| v.parse().ok())
}
