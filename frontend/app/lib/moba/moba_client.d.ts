/* tslint:disable */
/* eslint-disable */

export class MobaGame {
    free(): void;
    [Symbol.dispose](): void;
    constructor(canvas_id: string, url: string, ticket: string);
    /**
     * Open the socket, wire the input handlers, and start rendering.
     */
    start(): void;
}

export type InitInput = RequestInfo | URL | Response | BufferSource | WebAssembly.Module;

export interface InitOutput {
    readonly memory: WebAssembly.Memory;
    readonly __wbg_mobagame_free: (a: number, b: number) => void;
    readonly mobagame_new: (a: number, b: number, c: number, d: number, e: number, f: number) => [number, number, number];
    readonly mobagame_start: (a: number) => [number, number];
    readonly wasm_bindgen_36275c6b963d97fa___convert__closures_____invoke___f64______true_: (a: number, b: number, c: number) => void;
    readonly wasm_bindgen_36275c6b963d97fa___convert__closures_____invoke___web_sys_c0f9e6d309ceb263___features__gen_MouseEvent__MouseEvent______true_: (a: number, b: number, c: any) => void;
    readonly wasm_bindgen_36275c6b963d97fa___convert__closures_____invoke___web_sys_c0f9e6d309ceb263___features__gen_MouseEvent__MouseEvent______true__2: (a: number, b: number, c: any) => void;
    readonly wasm_bindgen_36275c6b963d97fa___convert__closures_____invoke___web_sys_c0f9e6d309ceb263___features__gen_MouseEvent__MouseEvent______true__3: (a: number, b: number, c: any) => void;
    readonly wasm_bindgen_36275c6b963d97fa___convert__closures_____invoke___web_sys_c0f9e6d309ceb263___features__gen_MouseEvent__MouseEvent______true__4: (a: number, b: number, c: any) => void;
    readonly wasm_bindgen_36275c6b963d97fa___convert__closures_____invoke___web_sys_c0f9e6d309ceb263___features__gen_MouseEvent__MouseEvent______true__5: (a: number, b: number, c: any) => void;
    readonly wasm_bindgen_36275c6b963d97fa___convert__closures_____invoke_______true_: (a: number, b: number) => void;
    readonly __wbindgen_malloc: (a: number, b: number) => number;
    readonly __wbindgen_realloc: (a: number, b: number, c: number, d: number) => number;
    readonly __wbindgen_exn_store: (a: number) => void;
    readonly __externref_table_alloc: () => number;
    readonly __wbindgen_externrefs: WebAssembly.Table;
    readonly __wbindgen_destroy_closure: (a: number, b: number) => void;
    readonly __externref_table_dealloc: (a: number) => void;
    readonly __wbindgen_start: () => void;
}

export type SyncInitInput = BufferSource | WebAssembly.Module;

/**
 * Instantiates the given `module`, which can either be bytes or
 * a precompiled `WebAssembly.Module`.
 *
 * @param {{ module: SyncInitInput }} module - Passing `SyncInitInput` directly is deprecated.
 *
 * @returns {InitOutput}
 */
export function initSync(module: { module: SyncInitInput } | SyncInitInput): InitOutput;

/**
 * If `module_or_path` is {RequestInfo} or {URL}, makes a request and
 * for everything else, calls `WebAssembly.instantiate` directly.
 *
 * @param {{ module_or_path: InitInput | Promise<InitInput> }} module_or_path - Passing `InitInput` directly is deprecated.
 *
 * @returns {Promise<InitOutput>}
 */
export default function __wbg_init (module_or_path?: { module_or_path: InitInput | Promise<InitInput> } | InitInput | Promise<InitInput>): Promise<InitOutput>;
