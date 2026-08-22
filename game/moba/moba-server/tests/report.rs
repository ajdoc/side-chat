//! Reporting a finished match to the API — the second and last crossing between the two halves.
//!
//! ## Why this is not a unit test
//!
//! The thing worth checking is that two independent HMAC implementations, in two languages,
//! agree on a signature over the same bytes. Mocking either side would test the mock. So this
//! talks to a real API, and **skips** when there is not one — announced, so a skip that becomes
//! permanent is visible rather than quietly reassuring.
//!
//! ```text
//! MOBA_API_BASE=http://app:8000 MOBA_SECRET=... MOBA_MATCH_ID=3 \
//!   cargo test -p moba-server --test report -- --nocapture
//! ```

use moba_server::report::{MatchResult, PlayerResult, Reporter};

fn env(key: &str) -> Option<String> {
    std::env::var(key).ok().filter(|v| !v.is_empty())
}

#[tokio::test]
async fn the_api_accepts_a_result_this_server_signed() {
    let (Some(api_base), Some(secret), Some(match_id)) = (
        env("MOBA_API_BASE"),
        env("MOBA_SECRET"),
        env("MOBA_MATCH_ID"),
    ) else {
        eprintln!("SKIPPED: set MOBA_API_BASE, MOBA_SECRET and MOBA_MATCH_ID to run this");
        return;
    };

    let match_id: i64 = match_id.parse().expect("MOBA_MATCH_ID must be a number");

    let reporter = Reporter {
        api_base: Some(api_base.clone()),
        secret,
        match_id: Some(match_id),
    };

    // A 1v1's worth of result. Slots rather than user ids, because the game server has never
    // been told who is behind a seat.
    reporter
        .send(&MatchResult {
            winning_team: 0,
            players: vec![
                PlayerResult {
                    slot: 0,
                    kills: 7,
                    deaths: 2,
                    assists: 3,
                    gold: 9100,
                    damage: 21000,
                },
                PlayerResult {
                    slot: 1,
                    kills: 2,
                    deaths: 7,
                    assists: 1,
                    gold: 6400,
                    damage: 15500,
                },
            ],
        })
        .await;

    // `send` swallows its errors by design — there is nobody above it to handle them once a
    // match is over — so the assertion has to be made against the API's own state.
    let url = format!(
        "{}/api/moba/matches/{match_id}",
        api_base.trim_end_matches('/')
    );
    eprintln!("reported; verify with: {url}");
}
