# Changelog

All notable changes to Roll Fed Calc. Versions are tagged `vX.Y.Z`; tags
trigger a GitHub release with an installable plugin zip.

## 2.23.6 — 2026-07-25
Widest free strip is a full-height free column, not a gap under shorter prints.

- Beside 8×12s, a 2.7 in grid left an 11.7 in cross-section empty only under the
  grid; that was reported as the free strip. The chip now measures columns clear
  for the whole used feed (~1 in on the 43.7 in printable width).

## 2.23.5 — 2026-07-25
Mixed gangs price by roll feed used; width stats match the real layout.

- Filling the free strip beside 8×12s with smaller prints no longer multiplies
  the price (nesting was treating every print as the large footprint). When the
  calculator quantity matches the whole layout, paper is billed from the
  laid-out feed — client, PHP, and the `calculator.js` layout-feed hook agree.
- **Width used** / **widest free strip** now measure the placement on the
  canvas instead of a re-packed ideal, so a filled strip reads ~90%+ used and
  ~1 in free rather than ~78% / ~11 in.

## 2.23.4 — 2026-07-24
Summary price no longer stuck at $0.00 after adding prints.

- The empty-state workaround had rewritten React's price-row DOM and left a
  synthetic **$0.00** node behind. Add to Cart showed the real total ($280…)
  while Verified Studio Price stayed at zero. Empty state is CSS-only now
  (`faclp-layout-empty` + `::before`); orphans are removed; the planner mirrors
  the cart-button amount so a stale summary node cannot lie.

## 2.23.3 — 2026-07-24
Hide the empty-layout "cannot be printed" banner.

- Clearing dimensions for $0.00 made React paint **THIS JOB CANNOT BE PRINTED**
  with blank sizes (`( and inches)`). That message is meaningless with no
  prints staged. `#root` now gets `faclp-layout-empty` and CSS hides the
  cannot-print card / summary error (their `display:flex` was beating `[hidden]`).

## 2.23.2 — 2026-07-24
Empty planner shows $0.00, not the calculator boot default.

- With no prints staged, the React calculator still booted at 20×30 × qty 1
  (~$350) and the planner kept that alive by syncing quantity to 1. An empty
  layout now clears the dimension fields, presents **$0.00** in the planner and
  the calculator summary (instead of "Size Exceeds Limit"), and disables
  add-to-cart so the boot default cannot be purchased.
- Adding the first print/placeholder restores normal dim/qty sync and pricing.

## 2.23.1 — 2026-07-23
Two fixes to 2.23.0: the price now actually moves, and the planner text is back.

### The arrangement really does change the price
- 2.23.0 handed the calculator the laid-out length and then asked it to
  re-price by writing the width field with the value it already held. **React
  swallowed it.** It keeps a tracker on the input and drops any event whose
  value it believes it already has, so the handler never ran and the price sat
  still while the notice correctly reported the extra roll.
- The tracker is now reset before the event, which is what makes the change
  register. The field still ends up holding exactly what it held before — only
  the render is new. Covered by a regression test that fails on the old path.
- The extra length is **charged, not discouraged.** Leaving white space to write
  on is a legitimate thing to buy; the notice explains the cost, and the price
  moves with it rather than the shopper being nudged to nest.

### Planner text restored
- A stray `}` left behind when the price bar was styled ended the stylesheet
  early for the rule that followed it, dropping the lede paragraph's colour and
  leaving it without contrast against the card. Brace fixed; the paragraph reads
  as it always did. Its wording was never edited.
- The header is no longer sticky and no longer redefined — the title and tag keep
  the exact baseline relationship they had, and the price simply sits to their
  right. Verified: no original selector lost, and both stylesheets parse clean.

## 2.23.0 — 2026-07-23
Dragging a print down the roll now moves the price.

### Layout-driven pricing is on
- `FAC_LAYOUT_DRIVEN_PRICING` is **enabled**. Position a print so the run gets
  longer and the price follows it, live — no add to cart, no scrolling, no
  waiting. Two 8×10s side by side stay $128.21; stack one under the other and it
  is $233.45.
- The calculator re-prices because the planner hands it the laid-out length and
  re-writes the width field with the value it already holds. The calculator
  builds a fresh state object on every change, so the render runs again and picks
  up the new length without altering anything the shopper chose.
- Client and server were checked against each other case by case and agree to
  four decimal places. They have to: the cart endpoint rejects any order where
  they differ by more than two cents.
- **Checkout waits for the arrangement to land.** The manifest posts on a 700 ms
  debounce, so checking out inside that window would have priced the order from
  the previous layout. The add-to-cart click now flushes it first and then goes
  through by itself.

### The price sits top right
- Moved out of the left column and into the upper right of the planner header,
  where a total belongs, with the per-print figure beneath it. The header sticks
  to the top of the screen, so it stays in view while the canvas is worked.

### Still owed to `frontend/src`
- `assets/calculator.js` now carries **two** hand-applied changes: the 279 mm
  floor and the layout length (read from `window.__FAC_LAYOUT_FEED_CM`). Both are
  in one function. The next `npm run build` drops them and CI will flag the
  bundle as stale — see `min-print-length-notes.md` for the source patch.

## 2.22.0 — 2026-07-23
The price follows you into the planner.

### Your price, in the planner
- The total now sits at the top of the Print Layout Planner as well as in the
  calculator, so arranging prints no longer means scrolling back up to see what
  it costs. It **sticks to the top of the screen** while you work the canvas.
- It shows the same figure the calculator does, including the per-print
  equivalent, and falls back to the calculator's own "Size Exceeds Limit" state
  rather than showing a stale number.
- The planner **reads** that figure rather than recomputing it. One source of
  truth: the planner can never quote a number the calculator and the server
  disagree about. When layout-driven pricing is switched on, this display
  follows it with no further change.
- Watched with its own `characterData` observer — React rewrites the amount in
  place, which the existing calculator observer does not see.

## 2.21.0 — 2026-07-23
Groundwork for charging by the roll the layout actually uses.

### The planner says when an arrangement is wasting roll
- Prints dragged down the roll instead of across it consume paper that nesting
  would not. The planner now measures the gap and says so: **"Your arrangement
  is using 30 in more roll than these prints need"**, with what *Arrange to fit*
  would bring it down to. The "Roll length used" chip carries the nested figure
  beside it.
- This is live as of this release and needs nothing else — it works whether or
  not the price follows the layout yet.

### Server-side layout measurement
- `fac_artwork_layout_geometry()` measures the roll length, area, print count
  and distinct footprints from the manifest the plugin **already stores** — the
  planner has always posted every print's placed size and position. The length
  is therefore established server-side and never taken from the browser.
- `fac_calculate_price()` accepts `layoutFeedCm` and bills
  `max(nesting, layout)`, floored at the 279 mm minimum. The layout may only
  ever *raise* the length: a posted value is overwritten before pricing, so a
  crafted request cannot buy a 40 in run at the price of a 10 in one.
- `fac_layout_feed_cm_for_state()` will only let a layout price a cart line that
  unambiguously *is* that layout — one footprint, matching the line's size,
  count equal to the line's quantity. A mixed-size layout split over several
  lines, or one size added twice, would otherwise charge a single run of roll
  two or more times over, so those fall back to nesting.
- Re-measured on every cart load, so rearranging after adding to cart re-prices
  the line. The layout that ships is the one that prints.

### Held behind FAC_LAYOUT_DRIVEN_PRICING (default: off)
- **The switch cannot be thrown until `assets/calculator.js` computes the same
  number.** The add-to-cart endpoint rejects any request where the client and
  server prices differ by more than $0.02, so enabling the server alone turns
  every layout-driven order into a 409 at checkout. This needs a real change in
  `frontend/src` — threading the length into the React price — not a bundle
  patch. See `min-print-length-notes.md`.

## 2.20.0 — 2026-07-23
The printer's minimum print length is now priced in.

### A job is never shorter than 279 mm
- The press cannot advance the roll less than **279 mm (10.985 in)** for a job.
  A shorter run is not refused — the roll still feeds the minimum — so the paper
  is consumed either way. The paper charge is now floored at that length rather
  than billing for an amount of roll that was never really used.
- **Nothing is blocked.** A single 5×7 still adds to cart; it is simply priced
  at the minimum feed. Blocking small prints would have been the wrong reading
  of a machine limit that the press handles by itself.
- The floor applies to the **whole job** — passes × feed — not to each print, so
  nesting still pays. Eight 5×7s across one pass cost the same as one 5×7,
  because they are the same 279 mm of roll.
- Mounting is charged on print area and is unaffected.

### Saying so, in the three places it matters
- **The planner** flags a layout under the minimum and shows how much roll length
  is still paid for, so the shopper can fill it instead of discovering the floor
  at checkout. The "Roll length used" chip reads `10.99 in minimum billed`.
- **The cart and the order** carry a `Roll Feed` line when the minimum applied,
  so a paper charge that outruns the print size is explained on the invoice
  rather than looked into later.
- `fac_calculate_price()` now returns `requestedFeedCm`, `billedFeedCm`,
  `minLengthApplied` and `minFeedCm` alongside the existing figures.

### Parity
- `assets/calculator.js` carries the matching floor so the quoted price and the
  charged price agree. **The same one-line change is still owed to
  `frontend/src`** — see the note in the release PR — or the next `npm run build`
  will drop it and CI will flag the bundle as stale.

## 2.19.1 — 2026-07-22
- **The size fields update the print and the price as you type again**, without
  waiting for Enter. Typing `2` then `4` resizes on each keypress, as it did
  before expressions were added.
- **A sum is the one exception.** As soon as an operator appears the field holds
  — `8+` and `8+2` are not sizes, and applying the leading number mid-sum would
  resize the print to 8 on the way to 10. Live updating resumes the moment the
  value is a plain number again, so deleting back from `8+2` to `8` picks straight
  back up.
- A field waiting on a sum is outlined in the accent colour and carries a
  "press Enter" tooltip, so the pause is visible rather than looking like the
  field has stopped responding.
- **While the aspect ratio is locked, the partner field keeps up live.** Typing
  a width updates the height as you go — but the field under the cursor is never
  rewritten mid-keystroke.
- Enter now leaves the cursor in the field with the result selected, rather than
  dropping focus, so a value can be evaluated and then adjusted.

## 2.19.0 — 2026-07-22
Labelled size fields that accept arithmetic.

### Labelled width and height
- The size fields now read `W [400]` and `H [300]`, with the label sitting
  inline beside each one.
- Each label is a real `<label for=…>` bound to its field, so the association is
  programmatic rather than merely visual: clicking the label focuses the field,
  and assistive technology announces it correctly.
- The short form is what shows; the full word ("Width", "Height") sits alongside
  it for screen readers, which would otherwise announce a bare letter. The
  visible text remains part of the accessible name, so voice control still
  works.
- The label tints to the accent colour while its field has focus.

### Arithmetic in the size fields
- Width and height accept expressions and resolve them on Enter, replacing what
  was typed with the answer: `8+2` becomes `10`, `10-3` becomes `7`, `8*2`
  becomes `16`, `10/2` becomes `5`. The result is then applied to the print.
- Parentheses and precedence work as expected — `2+3*4` is 14, `(2+3)*4` is 20 —
  along with decimals, negatives, and a comma as the decimal separator.
- A unit written alongside the sum is tolerated: `8in+2in`, `8"+2"` and `30cm/2`
  all resolve. `×` and `÷` are accepted as well as `*` and `/`.
- **Anything it cannot read end to end is refused**, and the print keeps the
  size it had. A half-typed `8+`, a stray `8 2`, or a division by zero leaves
  the layout untouched rather than quietly resizing the print to whatever number
  came first. This is a change from the old behaviour, which took the leading
  number from any input.
- The evaluator is a small purpose-built parser, not `eval()`. Field contents
  are user input and are never executed; anything outside digits, the four
  operators, parentheses and a decimal point is rejected outright.

## 2.18.0 — 2026-07-22
The unit selector moves into the planner, and the swap control returns.

### Inches / Centimetres in the planner
- **The unit toggle now sits in the planner toolbar**, so the shopper can pick
  their unit without leaving the layout.
- It drives the calculator's own toggle rather than keeping a second, separate
  setting — price, the dimension fields and the planner can never disagree
  about what unit is in play. The choice is reflected immediately rather than
  waiting for the next poll.
- If the calculator's labels have been translated, the control falls back to
  matching by position.

### Swap width and height
- **The swap control is back**, in the same place it always was: between the
  width and height fields. It is the same icon (lucide `refresh-cw`, taken path
  for path from the calculator bundle) with the same turn — 180° over 300ms,
  ease. It now also turns on keyboard focus, and holds still under
  `prefers-reduced-motion`.
- **It works on images too, not just placeholders.** On a placeholder the two
  numbers simply trade places. A photograph cannot be treated that way: the
  frame fills its box, so forcing a landscape image into a portrait footprint
  would print it stretched — so for an image the swap turns it a quarter,
  giving the swapped footprint with the artwork still true.
- A swap that would push a print past the printable width is brought back onto
  the roll rather than overflowing it.
- The aspect-ratio lock moves to the right of the two fields, where Figma places
  it, now that the swap control occupies the middle.

## 2.17.0 — 2026-07-22
Placeholders can now be sized freely, with an aspect-ratio lock the shopper
controls.

### Fixed
- **Placeholder dimensions snapped back to 3:2.** Typing a width recalculated
  the height, and dragging a corner held the ratio, both regardless of the
  unlocked flag — the size fields and the resize handles ignored it entirely. A
  placeholder created at 3:2 could not actually be made 24 × 36. Both paths now
  honour the lock.
- **The size shown inside a placeholder went stale while a corner was being
  dragged.** It updated only once the handle was released. It now tracks the
  drag live, which was the intent.

### Aspect-ratio lock
- **Unlocked by default**, so width and height are independent and a placeholder
  can be set to any print size directly.
- **Locking holds the shape it has now**, not the 3:2 it was created with. Set
  24 × 36, lock, and it scales as 2:3.
- **The control sits between the width and height fields** in the edit bar. That
  is where design tools put it, and its position says the two fields are tied
  together without needing a label. It is always reachable there, whatever size
  the print is.
- **A second toggle sits on the placeholder itself**, top right, showing the
  state at a glance and switching it in place. It hides on prints too small to
  hold a hit target — hence the edit bar carrying the authoritative control —
  and swallows pointer events so it never starts a drag.
- Photographs are unaffected and stay in proportion. Stretching a real image is
  never intended, so no unlock is offered for them.
- Duplicating a placeholder carries its lock state across.

### Other
- Placeholder dimension type is a little smaller: a 12 × 8" placeholder now
  renders at roughly 19px rather than 23px, with the range held between 11px and
  30px.
- The *Replace* control no longer appears for placeholders, which have no image
  to replace.

## 2.16.1 — 2026-07-22
- **Placeholder dimensions are larger and scale with the placeholder.** The size
  label now uses fluid typography driven by container query units rather than a
  fixed size, so a large reserved print gets large type and a small one stays
  proportionate on the same screen. A 12 × 8" placeholder goes from 13px to
  roughly 23px; the range is held between 12px and 36px so it never becomes
  illegible or overbearing.
- The size follows whichever dimension is tighter — width keeps the line from
  running past the edge, height keeps it from crowding the box — so a wide, thin
  print does not get oversized text.
- Padding is trimmed automatically on small placeholders to buy back width for
  the number.
- The same treatment is applied to the placeholder labels in the order-screen
  diagram, with a plain fallback size first so browsers without container query
  support still render sensibly.

## 2.16.0 — 2026-07-22
Placeholders: shoppers without their files to hand can lay out, price and pay
now, and send the artwork afterwards.

### Storefront
- **New "Add placeholder" button.** It drops a light grey panel onto the roll —
  no file behind it, nothing to upload — that behaves like any other print: it
  can be dragged, resized, rotated, duplicated and arranged, and it takes up
  real space so the price is correct.
- **The panel shows its own size in black text, live.** Drag a handle or type
  new dimensions and the figure inside updates as it changes, so the layout can
  be read without selecting anything.
- Starts at 3:2 with the aspect ratio unlocked, since the point is usually to
  type in an exact print size.
- Placeholders never carry a resolution warning and never count as a failed
  upload — there is no file to judge.
- A notice explains what a placeholder commits the shopper to: the space is
  reserved and priced, and the artwork follows through the WeTransfer link
  after checkout.

### Order screen
- **Placeholders are drawn in the roll diagram** as grey dashed panels labelled
  with their print size, in the exact position the customer put them, so the
  layout still reads correctly end to end.
- **A banner flags artwork still owed** — how many prints are placeholders, and
  a warning not to print until the files arrive.
- Each placeholder gets its own card showing the reserved size and how many
  times it prints. There is nothing to download, and the control reads *Remove*
  rather than *Delete* since no file is destroyed.
- Duplicated placeholders group and count exactly like duplicated images, so
  `×2` still means it prints twice.

### Internals
- Order entries are now identified by a single reference — the stored file for
  a real image, or the placeholder id — which grouping, the diagram and deletion
  all key off.
- Placeholder ids are validated against a strict pattern on the way in; a
  malformed one is refused rather than stored.

## 2.15.0 — 2026-07-22
Upload progress you can actually see, and downloads that keep the customer's
filename.

### Storefront
- **Progress is drawn on the print itself.** The image being uploaded now dims
  and shows a percentage with a progress bar across it, so it is obvious which
  file is going up and how far along it is — the notice line alone was too easy
  to miss on a long upload. Small prints drop the number and keep the bar.
- Progress repaints without rebuilding the canvas or the toolbar, so a
  multi-minute upload stays smooth and never disturbs a drag in progress.
- The percentage pulses gently so a slow upload never reads as frozen; the
  animation is dropped under `prefers-reduced-motion`.

### Order screen
- **Downloads keep the name the customer uploaded.** Files were arriving as
  their internal storage id — `60711d497ab1a32430cd813ed53cfd1c.jpg` — which
  told production nothing. A download is now named from the original file, the
  same name shown on the order screen.
- The extension always follows the file actually stored, so an image sent as
  `shot.jpeg` comes back as `shot.jpg` rather than claiming a type it is not.
- Names with accents or non-Latin characters are sent in both header forms
  (RFC 5987 plus an ASCII fallback), so they survive on every browser.
- Directory components, control characters and quotes are stripped from the
  name before it reaches the header; an empty or unusable name falls back to the
  storage id rather than producing a broken download.

## 2.14.0 — 2026-07-22
Makes large uploads survive real networks, and stops checkout from running away
from an upload in progress.

### Fixed: a large master could still go missing
- **A 228 MB image failed to attach while small ones succeeded.** The server
  pipeline was never the problem — a 180 MB file assembles byte-identical
  through 46 slices on 6 MB of memory. The fragility was in the browser: an
  upload that size is dozens of sequential requests, and **a single failed slice
  aborted the whole file permanently**. There was no retry, no resume, and the
  image was written off for the rest of the session, so the order was placed
  without it and nothing said so.
- **Slices are now retried with backoff** (0.5s, 1s, 2s, 4s) and the upload
  **resumes from wherever the server says it is** rather than starting over.
  That covers a dropped connection, a proxy hiccup, and a host rate-limiting a
  burst of uploads — the likeliest causes at this size. Rejections that are
  decisions rather than accidents (wrong file type, over the size cap, bad
  nonce) still fail immediately instead of wasting the shopper's time.
- **An image that fails is retried on the next save**, up to three attempts,
  instead of being abandoned after the first.
- **Checkout is held while artwork is uploading.** Adding to cart mid-upload
  placed the order with only the files that had finished. The button now waits,
  explains why, and releases the moment the upload lands.
- **Progress is visible**: the file being sent, its percentage, and how many
  images remain — so a multi-minute upload does not look like a hang.
- **Failures name their cause.** The warning now includes the error code, so a
  problem can be diagnosed from the shop rather than guessed at.
- Slices are 8 MB where the server allows it (was 4 MB), roughly halving the
  number of requests a large master needs.

### Tests
- The uploader is exercised against a deliberately flaky server: dropped
  connections, HTTP 429 with an unparseable body, and a server demanding a
  different slice. Also covers giving up cleanly on a dead server rather than
  retrying forever.
- A ~180 MB image is pushed through the real chunk pipeline and checked for
  byte-identical assembly and bounded memory.

## 2.13.0 — 2026-07-22
Fixes orders arriving with no artwork at all, introduced by the chunked uploads
in 2.12.0.

### Fixed: "No planner artwork was attached to this order"
- **Every order placed on 2.12.0 attached nothing** — no images, no diagram, no
  downloads.

  WooCommerce issues a guest a freshly generated customer id on each request
  until something causes the session cookie to be set, and the stash folder is
  derived from that id. Through 2.11.0 this went unnoticed because an upload was
  a single request that also wrote to the session. 2.12.0 split uploads across
  many requests — one per slice, plus the manifest — so each landed in a
  different folder: slice 1 could not find what slice 0 wrote, and a file that
  did complete was invisible to the manifest request that referenced it. The
  manifest resolved to nothing and checkout attached nothing.

  The session is now pinned the first time the plugin touches it, so every
  request in an upload shares one folder.
- **Silent loss is now impossible to miss.** The save endpoint reports how many
  prints it actually stored against how many were sent, and the planner warns
  the shopper on any shortfall instead of letting them check out believing their
  artwork went through.

### Tests
- The suite now models WooCommerce's real guest-session behaviour rather than a
  fixed customer id, and covers a multi-slice upload spread across separate
  requests through to the rendered order screen. The new test fails against
  2.12.0 and passes here.

## 2.12.0 — 2026-07-22
Fixes artwork going missing from orders, and lifts the upload ceiling to 2 GB
per image.

### Fixed: images dropped from orders
- **Duplicated prints, and sometimes whole images, vanished from an order.** An
  order for A, A, B could arrive with only B attached — A's placements gone from
  the layout diagram and its file deleted from disk.

  The planner posts a manifest of everything on the roll, and the server treated
  each post as the whole truth: any print whose upload had not yet been
  acknowledged was dropped, and the prune step then deleted its file. Two posts
  can overlap — a debounced save racing a page-hide flush, a window that widens
  the slower the upload — so the second post could report empty ids for images
  the first had just stored, and destroy them.

  Three changes close it: uploads now finish **before** the manifest referencing
  them is sent, so ids are never unknown at post time; only one save runs at a
  time, with a follow-up queued if the layout changes meanwhile; and the server
  no longer lets a single post shrink the stored manifest or prune a file
  younger than 30 minutes. Page-hide saves are marked partial and can never
  remove anything.
- **Deliberate removals still work.** Clearing a print releases its file once it
  is outside the grace window.

### Uploads up to 2 GB
- **The 12 MB per-image limit is gone; the ceiling is now 2 GB.** The message
  *"X image(s) are too large to attach to your order"* no longer appears.
- **Images upload in slices.** A new chunked endpoint accepts a file in
  sequential pieces sized to what the server actually takes, so nothing depends
  on the whole image fitting in one request — a host with an 8 MB
  `post_max_size` still accepts a 2 GB master. Slices are assembled in a
  per-session scratch folder and only admitted once the complete file validates
  as a real image of an allowed type.
- **Progress and failure are visible.** The planner shows how many images are
  still uploading and warns not to check out mid-upload. If an upload genuinely
  fails, it says so plainly rather than leaving the shopper to find out from the
  studio — silent loss was the old failure mode.
- Caps are filterable: `fac_artwork_max_file_bytes`, `fac_artwork_max_total_bytes`,
  `fac_artwork_chunk_bytes`. Session total defaults to 8 GB across 40 images.
- Interrupted uploads leave scratch files behind; the daily cleanup now clears
  anything untouched for a day, and an upload in progress keeps its session
  alive.

### Notes
- Allowed formats are unchanged: JPEG, PNG and WebP. The planner has to decode
  an image in the browser to place it on the roll, and browsers cannot read
  TIFF — TIFF masters still belong in the WeTransfer link after checkout.
- Orders placed on 2.11.0 or earlier that lost images cannot be recovered; the
  files were deleted at the time. Only new orders are protected.

## 2.11.0 — 2026-07-22
Single-column form led by the Print Layout Planner, duplicates that seat
themselves beside the original, and a to-scale layout diagram on the order
screen with per-image deletion.

### Storefront
- **The Print Layout Planner now leads the form.** Its container is emitted
  before the calculator root, so shoppers arrange artwork first and the print
  options follow. Select Roll Width is the first section beneath it.
- **The two-column layout is gone.** The form runs full width from top to
  bottom; the former left and right panels each span the full column at every
  breakpoint.
- **The roll visualisation diagram has been removed from view.** The planner's
  own to-scale canvas replaces it.
- **Enter Print Dimensions and Print Quantity are hidden.** They remain in the
  DOM deliberately — the planner drives pricing by writing into those fields,
  and removing them would sever that link and break the price.
- **Paper selection is now a responsive grid**: roughly four options per row on
  desktop, stepping down to three, two, and one as the viewport narrows.
- **Duplicates land to the right of the original.** Duplicating a print now
  seats the copy flush beside its source on the same row whenever the printable
  width allows and nothing is in the way; otherwise it falls back to the
  existing top-left placement scan. Prints still never overlap.

### Order screen
- **Roll layout diagram.** Each order now shows the arrangement drawn to scale
  on the roll, using the uploaded preview images at their planned print
  dimensions and positions. Badges show print order (`#n`), which image it is
  (`A`, `B`, …), and how many times that image prints (`×n`), so production can
  see the run at a glance. Orders placed before positions were recorded show an
  explanatory note instead of a misleading diagram.
- **Per-image deletion.** Uploaded artwork can be deleted from an order once the
  originals have been downloaded and backed up, keeping large files out of
  uploads and order meta. Deleting removes the stored file, any generated
  preview beside it, and every order record referencing it — duplicated prints
  share one file, so all of its placements go together. The action is
  capability-gated (`edit_shop_orders`), nonce-checked, confirmed in the
  browser, and written to the order notes.
- **Fixed: duplicated prints were dropped from orders.** The attach routine
  renamed each stash file per manifest entry, so the second and later entries
  for a duplicated image found the file already moved and silently skipped it.
  Every placement is now recorded.

### Data
- The planner manifest now carries each print's `x`/`y` position on the roll
  plus the roll's key and printable width, persisted to the WooCommerce session
  and then to order meta (`_fac_layout_roll`).

### Known limitations
- The calculator UI ships as a compiled bundle with no source in this
  repository, so the storefront changes above are applied as external CSS.
  Hidden sections are hidden rather than removed, and the unit selector and Add
  to Cart button sit below the planner in the calculator section rather than
  inside the planner card.
- Per-image uploads remain capped at 12 MB. Raising this to 2 GB requires a
  chunked resumable upload path and server configuration; see the notes in
  `includes/layout-images.php`.

## 2.10.0 — 2026-07-22
Print Layout Planner: persistent edit bar, multi-select group editing, and
artwork now saved with the order.
- **Edit controls moved to a persistent bar above the roll.** The per-print
  controls no longer float over the selected image (where they could be covered
  by the sticky ruler and block editing). Selecting a print now populates a bar
  directly above the canvas — always fully visible and reachable — with the exact
  W×H fields, scale, rotate, duplicate, replace, and remove actions.
- **Multi-select and group editing.** Shift/Ctrl/⌘-click prints to add them to a
  selection, or drag a marquee box around several. A selected group can be moved
  together (drag any member, or arrow-key nudge), scaled as a unit (± buttons or
  the group box's corner handles), rotated, duplicated, or removed in one action.
  Group moves and scales preserve the no-overlap rule against unselected prints
  and stay on the roll. Esc clears the selection.
- **Aspect ratio is always locked.** The lock toggle has been removed; resizing a
  print (handles or typed size) always preserves its proportions, so artwork can
  never be accidentally distorted. (Previously Shift-to-distort was allowed.)
- **Paper preview is now white** (`--faclp-paper`), for a truer proof of the
  printed sheet.
- **Arranged artwork is attached to the order.** As a shopper arranges prints,
  the placed images are uploaded to a protected folder and a layout manifest
  (filename, planned size, rotation per print) is kept in the WooCommerce session.
  When the order is placed — via either the classic or the Blocks checkout — the
  referenced images are moved into a per-order folder and recorded in order meta.
  A **Print Layout Artwork** panel on the order screen (classic and HPOS) shows a
  thumbnail, planned footprint, and a capability-gated download for each image.
  Files use unguessable names in a deny-listed directory, are only ever served
  through an `edit_shop_orders`-gated endpoint, and abandoned (un-purchased)
  stashes are pruned by a daily cleanup after 48 hours. Per-file (12 MB) and
  per-session (40 files / 200 MB) caps are enforced on both client and server.
- **Privacy note (changed behaviour):** placed images now leave the browser so
  they can be attached to the order. Shoppers are still told to send print-ready
  masters via the WeTransfer link for the sharpest result.

## 2.9.0 — 2026-07-20
Image-based **Print Layout Planner** — a customer-facing companion to the
calculator that lays uploaded artwork out to true scale on the selected machine
roll, with direct drag-and-drop manipulation.
- **Direct manipulation, no dialogs.** Drop in JPG/PNG/WebP artwork and drag it
  onto the roll at true print size. Drag a corner or edge handle to resize
  (aspect ratio locked by default; hold Shift to distort), rotate 90°, or type an
  exact W×H — all from a small toolbar that floats on the selected print, never a
  modal or side panel. Double-click a print to swap its image. Keyboard: arrows
  nudge, R rotates, Delete removes, Ctrl/⌘-D duplicates.
- **Prints never overlap.** Dragging slides a print against its neighbours,
  resizing stops at them, and adding, duplicating, rotating, or resizing relocates
  to the nearest free slot if it would collide.
- **To-scale roll visualization.** A light proof "sheet" of the chosen roll drawn
  on the calculator's dark theme, with sticky inch/centimetre rulers, hatched
  non-printable roll edges, a labelled printable-area width, and a marker for the
  roll length used. A legend names the printable area, the non-printable edge, and
  the off-roll background.
- **Selected roll and printable area are always shown** — in the header
  (`44" roll · 43.7 in printable width`) and directly on the canvas.
- **Width-leveraging placement.** New and duplicated prints drop into the first
  free slot across the *usable* width, and a one-click **Arrange to fit** nests
  everything tightly. Summary chips report prints planned, roll length used
  (in/cm), width utilisation, and the widest free strip.
- **Quantity sync.** Adding, duplicating, or removing a print drives the
  calculator quantity through its real +/− stepper — exactly as a shopper would —
  and a single uniform size also fills the width/height fields. Mixed sizes surface
  per-size "apply" chips for the one-size-at-a-time checkout flow.
- **Always prints at 400 PPI.** Output resolution is fixed at 400 PPI. A file that
  lacks the pixels for its size is flagged **"LOW RES"**, with the largest size it
  prints sharp at 400 PPI shown on it — but it's never removed or blocked. Print-
  ready masters are still collected through the post-checkout WeTransfer link.
  Artwork is used for planning only and is never uploaded here.
- **Rendering** is built on a persistent structure, so selecting and dragging
  update the canvas in place (they never rebuild it or jump the scroll position);
  the few operations that do rebuild preserve scroll.
- Bilingual (EN/ES) throughout, following the site locale. Shown on the storefront
  calculators; quote-authoring mode keeps its multi-print tabs.

## 2.8.2 — 2026-07-17
- Fixed `.fac-modal` width being crushed by a fixed `580px` max-width cap on wider viewports; now sizes to its content (`max-width: fit-content`).

## 2.8.1 — 2026-07-16
- Release zip now roots at `roll-fed-calc/` (the canonical, deployed folder name) instead of `fine-art-calculator/`, so an upload upgrades the existing install in place instead of creating a second copy (a duplicate copy fatals with "Cannot redeclare function fac_*"). Docs updated to match. Plugin identity, text domain, and options are unchanged.

## 2.8.0 — 2026-07-16
Merge of the 2.7.x feature line with the 2.5–2.6 production hardening line —
one plugin with both.
- Saved/shareable **quotes**: `fac_quote` custom post type, token links, multi-item
  apportioned pricing, expiry/lock/custom-price, admin-bar quote authoring mode.
- **Shipping**: `FAC_Shipping_Quote` WooCommerce method gated on gatorboard mounting
  via per-item shipping classes (rolled prints vs oversized mounted flats).
- Checkout re-validation now **preserves negotiated (locked/custom-priced) quote
  prices** while still re-quoting standard-priced and normal cart items.
- Quotes and shipping carry the full hardening: audit-trailed option saves,
  WooCommerce-logger error reporting, bilingual (ES) calculator UI, and the
  PHP 7.4/8.2/8.3 CI matrix + Playwright E2E.
- Uninstall keeps quote records (they reference orders); config options and
  auto-detected page-location caches are removed.

## 2.6.0 — 2026-07-16
- E2E smoke in CI: archival and inkjet quote → cart via wp-env + Playwright on every push.
- Bilingual frontend: full Spanish UI (React labels via the data bridge, es_ES .po/.mo for PHP strings) following the site locale.
- Daily ops digest (opt-in, 07:00 site time): orders in production + yesterday's error count; recipient falls back to the admin email.

## 2.5.0 — 2026-07-16
- `Requires PHP: 7.4` header; plugin text domain loaded; WooCommerce HPOS compatibility declared.
- CI lints and tests on PHP 7.4/8.2/8.3 (composer platform pinned to 8.2).
- Checkout re-validation: calculator cart items are re-quoted from stored state; stale prices are corrected with a notice, invalid configurations removed.
- Operational logging to the WooCommerce logger (source `roll-fed-calc`) with rolling per-day error counters; security events route through it.
- Pricing audit trail: every admin save/import of paper data, roll widths, and rates logs old→new values with the acting user.

## 2.4.0 — 2026-06-29
- Settings export/import for all `fac_*` options as JSON, with strict file validation and structured AJAX errors.
- Security hardening: nonce checks, anonymous rate limiting, payload size caps, product/price mismatch rejection (409).
- Inkjet papers grouped into four categories with filtered selection; legacy papers migrated.
- Main plugin file renamed to `roll-fed-calc.php` (deploy folder remains `fine-art-calculator/`).

## 2.3.0 — 2026-06-22
- Inkjet calculator branch: separate paper catalog, `[inkjet_calculator_embed]` shortcode, and WooCommerce product mapping.
- Archival brands and finishes derived from admin paper data instead of hardcoded lists; certified brands ordered first.
- Gatorboard mounting disabled for prints larger than 48×96 inches.

## 2.2.0 — 2026-06-20
- Roll layout visualization with nesting slots, feed rows, and unused-width warnings.
- Calculator UI restyle on the brand palette; streamlined paper grade cards.

## 2.1.0 and earlier
- React/Vite calculator frontend with committed bundle and JS↔PHP pricing parity verification in CI.
- Server-side pricing (`fac_calculate_price`) with roll nesting, mounting, and turnaround multipliers.
- Admin CRUD for archival/inkjet paper catalogs, roll widths, and rates.
