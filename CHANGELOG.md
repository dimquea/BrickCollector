# Changelog

Notable changes, newest first. Versions follow [semantic versioning](https://semver.org):
the middle number moves when something is added, the last one when something is fixed.

## 1.4.2 — 2026-09-15

### Fixed

- **Filtering loose parts by a tag looked as if it did nothing.** The filter picks the parts that
  have a lot with that tag, and the row went on counting the whole part — right for the total, but
  a part with two loose lots, only one of them tagged, showed every loose piece as though all were
  tagged. Under a tag the loose column now reads "11 (12)": what the tagged lots hold, then
  everything loose, with a hint on the cell. The row still adds up to its total, and an export
  under a tag takes only the tagged lots, so filtering "for sale" no longer puts an untagged lot up
  for sale as well.
- **A hint with two similar placeholders came out garbled.** Translations filled their
  placeholders one after another, so a short one ate the start of a longer one: ":tag" inside
  ":tagged" turned a hint into nonsense. They are filled in one pass now, longest first, the way
  Laravel does it on the server.

## 1.4.1 — 2026-09-15

### Fixed

- **The import could not reach an assembly.** 1.4.0 read a BrickLink XML file into the
  collection or the wishlist, but not into the third place parts live — which is exactly where a
  parts list for a model of your own belongs. The switch gains a way into an assembly, with the
  assemblies beside it and a new one at the top. A new assembly takes the name of the file, since
  a parts list is usually named after its model. Parts go in the way they already do from the
  catalogue, entered as a lot and moved, so the same part in the same colour folds into one line
  and the loose pile is left exactly as it was. Only parts go in, and a new assembly is created
  only once one of them does: an import where nothing fits leaves no empty assembly behind.

## 1.4.0 — 2026-09-15

### Added

- **Import from a BrickLink XML file** — a section of its own, and two steps on purpose. The
  file is read and shown first, every line with its picture, name, colour and quantity, and
  nothing is created until the rows are ticked and the button pressed. Both kinds of file are
  understood, an inventory and a wanted list, and a switch says where the rows go: into the
  collection or into the wishlist. Details filled in above apply to every entry created and a
  row can override them field by field; a remark from the file becomes that entry's note. A set
  or a minifigure with a quantity becomes that many copies, a part becomes one lot. What the
  catalogue does not know, and what the collection does not hold, is shown all the same but
  cannot be ticked, with the reason beside it.
- **Finding a part by photograph** — photograph a brick or pick a picture and the catalogue
  finds it, through Brickognize. It stays off until it is switched on, and the reason sits in
  the settings beside the switch: the photo goes to an outside service and, by that service's
  terms, stays with it. The browser shrinks the image to a thousand pixels before it goes — a
  printed part lives by its print, so no smaller than that — and the candidates come back as
  rows of our own catalogue, with the colour it guessed carried into the card that opens.

### Fixed

- **A composite part was counted twice.** Adding a torso or a pair of legs from the catalogue
  spread its own inventory into the entry, so the same plastic was held as the whole thing and
  again as its pieces: an assembly of seven parts announced fourteen, because the card summed
  every row while the table listed only the tops. The catalogue never expanded assembled parts
  inside a set, for exactly this reason; that rule now reaches the item being added, and a
  migration clears the rows written before it did.
- **A redirect under the Home Assistant panel led to the root.** Adding a picture to an assembly
  saved the picture and then landed on the home page, which reads as a failure; a day earlier
  the same request answered 404 and still saved it. Redirects name where they go now, since
  "back" has nowhere to go under the panel.

## 1.3.0 — 2026-09-14

### Added

- **Export to BrickLink XML** — a button beside the ordering in every list, and what comes
  out is exactly what the filter shows. What you hold goes out as an inventory counted in
  QTY; what is missing goes out as a wanted list asked for in MINQTY, carrying the sets it
  is missing from in the remarks. Sets, minifigures, parts and the wishlist each export
  their own list; an assembly exports what it is made of, and separately what it still
  needs. The hint on the button says which of the two is about to happen — the files look
  alike and BrickLink reads them very differently.
- **Two more filters** — minifigures a set is missing, and parts by a tag put on a loose
  lot. Both were recorded and neither could be asked for: a missing figure showed as a
  badge, but finding one meant opening sets one by one, and a tag showed only on the page
  of the lot it was put on.
- **Lot tags on the part page** — the "loose" tab names what is on each lot, so what is
  second-hand or set aside is visible without opening the lot itself.

### Changed

- **A colour option shows its colour.** Every colour select carries the same swatch as the
  tables do, in the dropdown and in the field: Dark Bluish Gray and Light Bluish Gray tell
  apart by name only on a second reading.
- Adding or removing a wish no longer navigates anywhere — the button changes on the
  answer, and the page stays where it was.

### Fixed

- **The catalogue card had no colour of its own.** Opening a part from its own page landed
  on the card in the colour the part is drawn in, not the one just being looked at; and the
  select on the card was read by the wish button alone, so choosing a colour there changed
  neither the picture, nor the links out, nor the colour the add dialog opened in. The
  colour now lives in the address and governs all of them.
- Under Home Assistant every redirect went out as an absolute address, which the panel
  refuses. Adding to the wishlist failed there, and the message about what had happened sat
  in the session until some later page showed it. Redirects are relative now, including the
  one that carries no path at all.
- Removing a wish twice answered 404 for an action that had in fact succeeded.

## 1.2.0 — 2026-09-13

### Added

- **A wishlist** — a section for what the collection does not hold but you want. A list and
  nothing more: no quantity, no price, no storage, since those describe a thing you own. A
  row leads back to the catalogue, where everything about the item already is. A part is
  wanted in a colour; a set or a minifigure is not. Pictures of wished items are cached the
  way the collection's are.
- **Lists can be ordered** — by item number, name, year, and by how much is held where that
  is cheap to count, with a direction beside it. The choice lives in the address, so it
  survives filtering and paging and can be sent to someone as a link; an absent parameter
  means the list's own order, and old links are untouched.
- **A dark interface** — system, light or dark, chosen in the settings. System follows what
  the device is set to and changes with it, including on a schedule of its own.

### Fixed

- Adding a part offered a new lot and the lots already held, but never the assemblies,
  though 1.1.0 says it does: the catalogue page had the list and the dialog knew how to draw
  it, and nothing carried it from one to the other.
- A part a set is missing only as a counterpart — the same brick listed twice, with a
  sticker and without — was counted nowhere. The parts section did not list it, its page
  answered 404, the shortage filter could not find it, and the set was not marked incomplete
  unless something else was gone. The one place it showed was the set's own page.
- Options in a select's dropdown took a fixed dark grey rather than the theme's colour, so
  on a dark interface everything but the highlighted row looked disabled.

## 1.1.0 — 2026-09-12

### Added

- **Assemblies** — a section of their own for custom models: a group of loose parts with a
  name, metadata and a photo of your own. Parts move in from the loose pile and back out
  again, so the collection holds the same bricks before and after; deleting an assembly
  returns its parts rather than eating them.
- **A page for every loose lot** — what was paid for it, when, and where it is kept, with
  the quantity editable. Lots used to open as if they were sets.
- **Adding a part now asks** — how many, in which colour, and whether it starts a new lot,
  tops up one already held, or goes straight into an assembly. Each colour in the element
  code list has a "+" of its own.
- **"Part of"** on catalogue pages — every set, minifigure or part an item turns up in,
  narrowed by colour for a part.
- **Parts in assemblies** are counted as a place of their own, in the list filter, on the
  part page and in the analytics.
- **The parts list shows every place** — loose and in assemblies alongside sets and
  minifigures, so a row adds up to the total instead of leaving pieces unaccounted for.
- **Shortage as its own filter** in the parts list, combined with where the part is: "gone
  from a set" and "still needed for a model" are different questions.
- **An assembly says what it is short of** — a badge in the list, a filter, and a line of
  its own in the analytics, apart from what was lost out of sets.
- **A licence** — MIT.

### Changed

- Every select in a list filter is a search select now, short lists included; a plain one
  among them read as a different kind of control.
- An owned copy opens on the page of its kind: `/sets/{id}` sends loose lots, standalone
  minifigures and assemblies to their own pages.
- A lot that does not count — an alternate, a counterpart, or what sits inside one — opens
  in the catalogue. It used to lead to a page that answered 404.
- A picture in a table row keeps its size on a narrow screen instead of shrinking away, and
  the layout uses the full width below 992px rather than leaving empty margins.

### Fixed

- List filter switches did nothing. A browser sends `incomplete=true`, and the `boolean`
  rule refuses that string, so "Incomplete" and "Missing figures" quietly filtered nothing.
- A nonsense value in a filter no longer throws the page "back" — it is dropped, and the
  rest of the filters still work. Under Home Assistant that redirect landed on an image.
- Item pictures left the session, so they stopped being remembered as "the previous page".

## 1.0.0 — 2026-09-11

First release. The BrickLink catalogue with search and filters; sets in the collection with
their whole contents copied in, down to the parts of their minifigures; what went missing,
per lot; minifigures collapsed to one card per item number, with a page for every standalone
copy; parts counted across the collection; purchase details, storages, sources, tags and
statuses; analytics by theme and by year; links to outside catalogues; English and Russian;
and a Home Assistant add-on that runs the whole thing behind the panel.
