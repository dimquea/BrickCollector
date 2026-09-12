# Changelog

Notable changes, newest first. Versions follow [semantic versioning](https://semver.org):
the middle number moves when something is added, the last one when something is fixed.

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
