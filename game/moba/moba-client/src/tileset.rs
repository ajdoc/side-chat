//! Loading the terrain textures, and handing back a fill for each.
//!
//! Deliberately callback-free. An `HtmlImageElement` already reports its own readiness through
//! `complete`, so a frame that finds a texture loaded uses it and a frame that does not falls
//! back — no `onload` closure to keep alive, no "loaded" flag that can disagree with the image,
//! and no loading screen for something that should simply appear when it appears.

use std::cell::RefCell;
use std::collections::BTreeMap;

use wasm_bindgen::JsCast;
use web_sys::{CanvasPattern, CanvasRenderingContext2d, HtmlCanvasElement, HtmlImageElement};

use crate::sprites::Sprite;
use crate::terrain::{Tile, TILE_WORLD};

/// One texture, and the pattern built from it once it has arrived.
struct Slot {
    image: HtmlImageElement,
    /// Built on the first frame the image is complete, then kept. Creating a pattern per frame
    /// is cheap but not free, and this runs six times a frame.
    pattern: Option<CanvasPattern>,
    /// Set once we have decided this one is never coming — a 404, or a file that is not an
    /// image. Without it a broken URL is retried every frame forever.
    failed: bool,
}

pub struct TileSet {
    slots: RefCell<BTreeMap<u8, Slot>>,
}

/// Whether an image has arrived and is usable.
///
/// `complete` is also true for one that failed to load, and the two are only told apart by the
/// natural size being zero.
fn ready(image: &HtmlImageElement) -> bool {
    image.complete() && image.natural_width() > 0
}

fn key(tile: Tile) -> u8 {
    Tile::ALL.iter().position(|t| *t == tile).unwrap_or(0) as u8
}

impl TileSet {
    /// Start every texture downloading. Returns immediately; nothing here blocks a first frame.
    ///
    /// `base` is the directory the files are served from, with no trailing slash.
    pub fn load(base: &str) -> TileSet {
        let mut slots = BTreeMap::new();
        for tile in Tile::ALL {
            if let Ok(image) = HtmlImageElement::new() {
                image.set_src(&format!("{base}/{}", tile.file()));
                slots.insert(
                    key(tile),
                    Slot {
                        image,
                        pattern: None,
                        failed: false,
                    },
                );
            }
        }
        TileSet {
            slots: RefCell::new(slots),
        }
    }

    /// Set the context's fill to this tile, and report whether the real texture was used.
    ///
    /// The caller needs the answer because a pattern is anchored to the *current transform*: a
    /// texture fill only lands in the right place while the world transform is applied, whereas
    /// the fallback colour does not care. Returning a bool keeps that decision at the one call
    /// site that can see the transform, rather than hiding it in here.
    pub fn fill(&self, context: &CanvasRenderingContext2d, tile: Tile) -> bool {
        if let Some(pattern) = self.pattern(context, tile) {
            context.set_fill_style_canvas_pattern(&pattern);
            true
        } else {
            context.set_fill_style_str(tile.fallback());
            false
        }
    }

    /// As [`fill`], for a stroked path. The lanes are drawn as a thick stroke.
    pub fn stroke(&self, context: &CanvasRenderingContext2d, tile: Tile) -> bool {
        if let Some(pattern) = self.pattern(context, tile) {
            context.set_stroke_style_canvas_pattern(&pattern);
            true
        } else {
            context.set_stroke_style_str(tile.fallback());
            false
        }
    }

    /// The raw texture for a tile that is stamped rather than tiled, once it has loaded.
    pub fn image(&self, tile: Tile) -> Option<HtmlImageElement> {
        let slots = self.slots.borrow();
        let slot = slots.get(&key(tile))?;
        if slot.failed || !ready(&slot.image) {
            return None;
        }
        Some(slot.image.clone())
    }

    fn pattern(&self, context: &CanvasRenderingContext2d, tile: Tile) -> Option<CanvasPattern> {
        let mut slots = self.slots.borrow_mut();
        let slot = slots.get_mut(&key(tile))?;
        if let Some(pattern) = &slot.pattern {
            return Some(pattern.clone());
        }
        if slot.failed || !slot.image.complete() {
            return None;
        }
        if !ready(&slot.image) {
            slot.failed = true;
            return None;
        }
        let width = slot.image.natural_width();

        // A pattern repeats at the source image's pixel size, and web-sys can only rescale one
        // through an `SvgMatrix` — which means conjuring an SVG element purely to hold a scale
        // factor. Copying the texture into a scratch canvas `TILE_WORLD` pixels square is the
        // same result with none of that: one texture pixel becomes one world unit, so the
        // pattern needs no transform and the world transform alone places it. At the textures'
        // native 512 it is not even a resample.
        let scratch = scaled_copy(&slot.image, width)?;
        let pattern = match context.create_pattern_with_html_canvas_element(&scratch, "repeat") {
            Ok(Some(pattern)) => pattern,
            _ => {
                slot.failed = true;
                return None;
            }
        };

        slot.pattern = Some(pattern.clone());
        Some(pattern)
    }
}

/// The image redrawn into a canvas `TILE_WORLD` pixels square.
fn scaled_copy(image: &HtmlImageElement, width: u32) -> Option<HtmlCanvasElement> {
    let side = TILE_WORLD as u32;
    let document = web_sys::window()?.document()?;
    let canvas: HtmlCanvasElement = document.create_element("canvas").ok()?.dyn_into().ok()?;
    canvas.set_width(side);
    canvas.set_height(side);
    let context: CanvasRenderingContext2d = canvas.get_context("2d").ok()??.dyn_into().ok()?;
    let height = image.natural_height().max(1);
    context
        .draw_image_with_html_image_element_and_sw_and_sh_and_dx_and_dy_and_dw_and_dh(
            image,
            0.0,
            0.0,
            width as f64,
            height as f64,
            0.0,
            0.0,
            side as f64,
            side as f64,
        )
        .ok()?;
    Some(canvas)
}

/// The unit art: one image per sprite, and nothing else.
///
/// Simpler than [`TileSet`] because a unit is stamped rather than tiled — there is no pattern to
/// build, so an image element is the whole of it.
pub struct SpriteBank {
    images: BTreeMap<u8, HtmlImageElement>,
}

impl SpriteBank {
    pub fn load(base: &str) -> SpriteBank {
        let mut images = BTreeMap::new();
        for (index, sprite) in Sprite::ALL.iter().enumerate() {
            if let Ok(image) = HtmlImageElement::new() {
                image.set_src(&format!("{base}/{}/{}", sprite.directory(), sprite.file()));
                images.insert(index as u8, image);
            }
        }
        SpriteBank { images }
    }

    /// The picture for a sprite, or `None` while it is still downloading or if it is not there.
    ///
    /// A `None` is not a failure to report. It means this unit is drawn as the disc it has
    /// always been drawn as, which is what lets the art arrive one file at a time.
    pub fn get(&self, sprite: Sprite) -> Option<&HtmlImageElement> {
        let index = Sprite::ALL.iter().position(|s| *s == sprite)? as u8;
        let image = self.images.get(&index)?;
        ready(image).then_some(image)
    }
}
