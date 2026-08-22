//! Telling the API how a match ended.
//!
//! The second and last crossing between the two halves, and like the first it is one-way and
//! signed. The API is not consulted during a match and is not required to be up for one to be
//! played — it is told afterwards.
//!
//! ## Why it retries
//!
//! A result that never arrives is a match that silently never happened: no rating, no history,
//! and ten people who will notice. The API being briefly unavailable — a deploy, a restart — is
//! the ordinary case rather than the exotic one, so this retries with a widening delay. The
//! endpoint is idempotent precisely so that this can.

use serde::Serialize;
use std::time::Duration;

use hmac::{Hmac, Mac};
use sha2::Sha256;

#[derive(Serialize)]
pub struct PlayerResult {
    pub slot: u8,
    pub kills: u32,
    pub deaths: u32,
    pub assists: u32,
    pub gold: u32,
    pub damage: u32,
}

#[derive(Serialize)]
pub struct MatchResult {
    pub winning_team: u8,
    pub players: Vec<PlayerResult>,
}

/// Where to report, and what to sign with.
#[derive(Clone)]
pub struct Reporter {
    /// The API's base URL. `None` disables reporting entirely — the development case, where a
    /// match is started by hand and nothing is keeping score.
    pub api_base: Option<String>,
    pub secret: String,
    pub match_id: Option<i64>,
}

impl Reporter {
    /// Post the result, retrying a few times before giving up.
    ///
    /// Errors are logged rather than returned: there is nobody above this to handle them. The
    /// match is over, the players have gone, and the only useful thing left is a line in the log
    /// saying the result did not land.
    pub async fn send(&self, result: &MatchResult) {
        let (Some(base), Some(match_id)) = (self.api_base.as_ref(), self.match_id) else {
            return;
        };

        let Ok(body) = serde_json::to_string(result) else {
            eprintln!("moba: could not serialise the match result");
            return;
        };

        let mut mac = match Hmac::<Sha256>::new_from_slice(self.secret.as_bytes()) {
            Ok(mac) => mac,
            Err(_) => return,
        };
        mac.update(body.as_bytes());
        let signature = hex(&mac.finalize().into_bytes());

        let url = format!(
            "{}/api/moba/matches/{match_id}/result",
            base.trim_end_matches('/')
        );
        let client = reqwest::Client::new();

        // Widening delays: an API that is down for a deploy is usually back inside a minute, and
        // hammering it while it boots helps nobody.
        for attempt in 0..5u32 {
            let response = client
                .post(&url)
                .header("X-Moba-Signature", &signature)
                .header("Content-Type", "application/json")
                .body(body.clone())
                .timeout(Duration::from_secs(10))
                .send()
                .await;

            match response {
                Ok(r) if r.status().is_success() => return,
                Ok(r) => eprintln!(
                    "moba: result rejected with {} (attempt {})",
                    r.status(),
                    attempt + 1
                ),
                Err(e) => eprintln!("moba: result post failed: {e} (attempt {})", attempt + 1),
            }

            tokio::time::sleep(Duration::from_secs(2u64.pow(attempt))).await;
        }

        eprintln!("moba: gave up reporting match {match_id}; the result is lost");
    }
}

fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|b| format!("{b:02x}")).collect()
}
