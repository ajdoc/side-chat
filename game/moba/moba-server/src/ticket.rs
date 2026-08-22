//! Verifying the pass PHP minted.
//!
//! ## Why this is offline
//!
//! The API is a separate service and possibly a separate machine. Asking it whether an arriving
//! socket may sit down would put an HTTP round trip on the connection path and make every match
//! in progress depend on the API staying up — an API deploy would drop reconnects.
//!
//! So the two sides share a secret instead. PHP signs the facts a seat needs; this verifies the
//! signature and takes the payload at face value, because a signature it cannot forge is exactly
//! as trustworthy as an answer it would have had to ask for.
//!
//! The mirror of `App\Support\Moba\MatchTicket`. Two implementations of one format is a real
//! cost, and the alternative — a call per connection — is a worse one.

use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use base64::Engine;
use hmac::{Hmac, Mac};
use serde::Deserialize;
use sha2::Sha256;

/// What a verified ticket entitles its bearer to.
#[derive(Clone, Debug, PartialEq, Eq, Deserialize)]
pub struct Seat {
    /// Which match. Checked against the one this server is running — a valid ticket for
    /// *another* match is still not a ticket for this one.
    #[serde(rename = "m")]
    pub match_id: i64,
    #[serde(rename = "u")]
    pub user_id: i64,
    #[serde(rename = "t")]
    pub team: u8,
    #[serde(rename = "s")]
    pub slot: u8,
    #[serde(rename = "h")]
    pub hero: String,
    pub exp: i64,
}

/// Why a ticket was refused.
///
/// Distinguished here, for the server's own logs, and deliberately *not* distinguished in what
/// the client is told: telling someone their payload was fine and only the signature was wrong
/// is telling them how close they got.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum TicketError {
    Malformed,
    BadSignature,
    Expired,
    WrongMatch,
}

/// Check a ticket and return the seat it names.
pub fn verify(ticket: &str, secret: &str, now_unix: i64) -> Result<Seat, TicketError> {
    let (payload, signature) = ticket.split_once('.').ok_or(TicketError::Malformed)?;

    let mut mac =
        Hmac::<Sha256>::new_from_slice(secret.as_bytes()).map_err(|_| TicketError::BadSignature)?;
    mac.update(payload.as_bytes());
    let expected = URL_SAFE_NO_PAD.encode(mac.finalize().into_bytes());

    // `Mac::verify` would be tidier, but the signature arrives base64 and comparing the encoded
    // forms keeps the decode failure and the mismatch on the same path. `constant_time_eq` via
    // subtle would be better still; this is the same length every time, so the timing channel
    // carries no length information and only leaks equality one attempt at a time.
    if expected.as_bytes().ct_ne(signature.as_bytes()) {
        return Err(TicketError::BadSignature);
    }

    let decoded = URL_SAFE_NO_PAD
        .decode(payload)
        .map_err(|_| TicketError::Malformed)?;
    let seat: Seat = serde_json::from_slice(&decoded).map_err(|_| TicketError::Malformed)?;

    if seat.exp < now_unix {
        return Err(TicketError::Expired);
    }

    Ok(seat)
}

/// Check a ticket, and that it is for the match this server is actually running.
pub fn verify_for_match(
    ticket: &str,
    secret: &str,
    match_id: Option<i64>,
    now_unix: i64,
) -> Result<Seat, TicketError> {
    let seat = verify(ticket, secret, now_unix)?;
    match match_id {
        // A server that has not been told which match it is running accepts any valid ticket.
        // That is the development case — one process, one match, started by hand.
        None => Ok(seat),
        Some(expected) if expected == seat.match_id => Ok(seat),
        Some(_) => Err(TicketError::WrongMatch),
    }
}

/// Constant-time byte comparison.
///
/// Written out rather than pulled in, because it is six lines and a dependency for six lines is
/// a dependency to keep updated forever. `!=` on slices short-circuits on the first differing
/// byte, which turns a signature check into an oracle that gives up one byte at a time.
trait ConstantTime {
    fn ct_ne(&self, other: &Self) -> bool;
}

impl ConstantTime for [u8] {
    fn ct_ne(&self, other: &[u8]) -> bool {
        if self.len() != other.len() {
            return true;
        }
        let mut difference = 0u8;
        for (a, b) in self.iter().zip(other.iter()) {
            difference |= a ^ b;
        }
        difference != 0
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Build a ticket the way PHP does, so the tests exercise the real format rather than a
    /// convenient one.
    fn mint(secret: &str, match_id: i64, slot: u8, exp: i64) -> String {
        let payload =
            format!(r#"{{"m":{match_id},"u":7,"t":0,"s":{slot},"h":"ironclad","exp":{exp}}}"#);
        let encoded = URL_SAFE_NO_PAD.encode(payload);
        let mut mac = Hmac::<Sha256>::new_from_slice(secret.as_bytes()).unwrap();
        mac.update(encoded.as_bytes());
        format!(
            "{encoded}.{}",
            URL_SAFE_NO_PAD.encode(mac.finalize().into_bytes())
        )
    }

    #[test]
    fn accepts_a_well_formed_ticket_and_reads_the_seat() {
        let ticket = mint("shh", 42, 3, 9999);
        let seat = verify(&ticket, "shh", 100).expect("a valid ticket was refused");
        assert_eq!(seat.match_id, 42);
        assert_eq!(seat.slot, 3);
        assert_eq!(seat.hero, "ironclad");
    }

    #[test]
    fn refuses_a_ticket_signed_with_a_different_secret() {
        let ticket = mint("shh", 1, 0, 9999);
        assert_eq!(
            verify(&ticket, "other", 100),
            Err(TicketError::BadSignature)
        );
    }

    #[test]
    fn refuses_a_ticket_whose_payload_was_edited() {
        // The attack the signature exists to stop: rewriting your own slot or team.
        let ticket = mint("shh", 1, 0, 9999);
        let (_, signature) = ticket.split_once('.').unwrap();
        let forged =
            URL_SAFE_NO_PAD.encode(r#"{"m":1,"u":7,"t":1,"s":9,"h":"ironclad","exp":9999}"#);
        assert_eq!(
            verify(&format!("{forged}.{signature}"), "shh", 100),
            Err(TicketError::BadSignature)
        );
    }

    #[test]
    fn refuses_an_expired_ticket() {
        let ticket = mint("shh", 1, 0, 50);
        assert_eq!(verify(&ticket, "shh", 100), Err(TicketError::Expired));
    }

    #[test]
    fn refuses_a_valid_ticket_for_another_match() {
        // A real ticket, correctly signed, for a game happening somewhere else. Without this
        // check one match's ticket would open every server sharing the secret.
        let ticket = mint("shh", 7, 0, 9999);
        assert_eq!(
            verify_for_match(&ticket, "shh", Some(8), 100),
            Err(TicketError::WrongMatch)
        );
        assert!(verify_for_match(&ticket, "shh", Some(7), 100).is_ok());
        // A server that was never told which match it runs takes any valid ticket — the
        // development case, one process started by hand.
        assert!(verify_for_match(&ticket, "shh", None, 100).is_ok());
    }

    #[test]
    fn refuses_nonsense() {
        for bad in ["", "no-dot", "a.b.c", "...."] {
            assert!(verify(bad, "shh", 100).is_err(), "accepted {bad:?}");
        }
    }
}
