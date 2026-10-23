# Changelog

Notable changes, newest first. Versions follow [semantic versioning](https://semver.org):
the middle number moves when something is added, the last one when something is fixed.

## 1.10.0 — 2026-10-23

### Added

- **What sits inside a composite part.** A torso lies in the collection whole, one row: its arms
  and hands are the same plastic, and counting them apart would count a part twice — so a hand
  inside a torso could not be found anywhere, and "do I have this hand in dark grey" had no
  answer. Now, for reference and never added to the counts:
  - the page of a composite part shows what it is made of, by the catalogue, each part linking to
    its own page in the collection;
  - the page of a part has a tab "Inside other parts" — which composites of the collection hold it
    and how many — and opens even for a part held only inside them;
  - "Where it is" in the parts section gains "Inside composite parts", with a column of its own; the
    search and the colour work as usual, and an export under it counts what is inside.
- **Sorting and filtering the parts of a set.** Above the parts of a copy: "Missing only", when
  anything is missing; a colour from those in the set; an order by number, colour or quantity,
  remembered between visits. All in place, with no reload — the open groups stay open.
- **Money by kind.** "Total" is still everything the collection cost, assemblies and gear
  included. Below it sets and loose minifigures each show their total, how many have a price, the
  average and the dearest one, and loose parts their total. An average over a set for eighty
  thousand and a lot for one rouble told nothing.

### Changed

- **Cards read row by row.** The card lists were columns filled top to bottom, so the third card
  sat under the first and a list sorted by year read in columns. Cards are dealt into the columns in
  turn now: the first row is the first cards. The columns may end at different heights.
- **An export button with a label shrinks to its icon on a phone.**

## 1.9.0 — 2026-10-19

### Added

- **Finding a set by a handful of its parts.** At the bottom of the catalogue filters: rows of an
  item number and a colour, with [+] for another. What comes back are the sets that hold at least
  one of each part in that colour anywhere in their tree — loose in the box, inside a minifigure,
  in a sub-set. Spares count, since they are in the box; alternates and counterparts do not. The
  item field takes an element number from the instructions too, and 4243815 turns into 3031 in
  blue by itself. It combines with the search, theme and year, and lives in the address like
  every other filter.
- **New or used, as a field of a copy.** Condition was kept as a tag, and a tag gets deleted,
  renamed and dropped on parting out. Now a switch in the management block of a set, a loose lot
  or a loose figure says it, and a badge on the card shows it. The import reads `CONDITION` from a
  BrickLink file. It travels with the bricks: a used set parts out into used lots, and a set built
  from loose parts is used if anything it took was. A "Used" filter in sets, parts and
  minifigures.
- **Stock for a store.** Two new kinds in the export menu — a BrickStore document and a BrickLink
  Mass-Upload file — list the lots themselves rather than totals: each loose lot, loose figure and
  set with its quantity at hand, unit price, condition, completeness for a set, and its note as the
  remark. The BrickStore file carries the currency and our lot number as the row's marker;
  Mass-Upload has no currency, and the menu says so. Parts inside sets are not stock — they are
  sold with the box.

### Changed

- **"BrickStore basket" is "BrickStore document".** A basket in BrickStore is a cart imported from
  BrickLink; the file was never that.

### Fixed

- **A list's value could be lost when its options arrived with it.** A colour chosen in the same
  moment its list of colours was replaced fell back to the empty choice.

## 1.8.0 — 2026-10-17

### Added

- **"Pick from the collection" on the catalogue card of a set or a minifigure.** Whether a set
  can be built from what is already here was a question the application could not answer without
  opening the part page of every lot. A dialog now lists what the set needs beside what the
  collection holds — everywhere, in other sets, or loose, switched in place — with the total, the
  shortfall and the share at hand on top. What is needed is every counted part of the tree with
  sub-sets unfolded and quantities multiplied, minus the parts inside minifigures: a figure is
  taken whole, and the figures have a list of their own. What is short exports as a wanted list,
  an inventory or a BrickStore basket.
- **Adding a set built from loose parts.** From the same dialog. Only loose lots are taken from —
  a brick in another set belongs to that set — oldest first, the way an assembly takes them, and a
  lot spent to the last brick is deleted. What is not found is marked missing on the new copy,
  spares included, after what is needed. A loose minifigure moves into the set only when ticked,
  and brings the losses of its parts and its note with it; an unticked one stays loose and counts
  as lost in the set. A CSV downloaded beforehand says which lots will go, with their storage
  places and tags, sorted by storage so the bricks can be collected drawer by drawer — it comes
  from the same code as the move itself, so it promises exactly what will happen.
- **Parting a set out.** "Part out" in the danger zone of a set lays its contents out into the
  loose pile: parts, counted and spare, as one new lot per part and colour; minifigures as copies
  of their own, with the losses of their parts; gear, a book, a catalogue as copies of their
  type. Sub-sets are laid out the same way, however deep. What is missing is not carried — it was
  not in the box either. The new copies take the storage place and purchase date of the set; the
  price and the tags stay behind, since one price for the whole box divided by its bricks would be
  a number made up. A confirmation says beforehand what goes where.
- **The shortage of a set exports from its page.** Each group of a set — parts, minifigures,
  sub-sets — has an export button once something in it is missing, and it takes the whole subtree,
  however deep: a sub-set holds packets, a packet holds a figure. A figure lost whole goes as one
  line and its parts do not, or it would be ordered twice; a figure short a piece goes as that
  piece.
- **A note on a minifigure or a sub-set inside a set** — a crack in a leg, a head that is not its
  own. A figure inside a set has no copy of its own, so there was nowhere to say such a thing. The
  note sits next to "Open", saves by itself, and shows as an icon on the row without opening it.
  It survives everything done to the figure: refreshing the inventories carries it like a loss
  mark and names it if it has nowhere to go, parting out turns it into the note of the figure's
  own copy, and the figure's card lists it where the figure lies.
- **A camera button in the photo search**, under Settings → Search by photo, off by default. It
  opens the phone camera straight away. Inside the Home Assistant panel the camera is not
  available — the panel shows the application in a frame that may not use it — so the button
  works when the application is opened directly, which the add-on can now allow.

### Fixed

- **Under the "missing" filter an inventory and a basket exported what is held.** Only the wanted
  list counted what is missing; the other two kinds took the quantity in the collection, so one
  lost brick out of four exported as four. In the parts and minifigure sections every kind
  carries the shortage now.
- **Opening a section sent two identical requests.** Restoring the remembered order changed the
  form, which the page re-sends by itself, and then sent it again. Besides the waste, the second
  answer arrived without the message the first one carried, so "set removed" vanished as soon as
  it appeared. One request now, and the message stays.

## 1.7.0 — 2026-10-16

### Added

- **The number printed in the instructions finds the part.** A LEGO element number — 4243815 —
  is what a building instruction and the Pick a Brick shelf call a part, and the catalogue
  answered nothing to it, although it has known 111 thousand of them all along. The same for
  BrickLink's alternate item numbers, another 17 thousand sitting unread. Both are matched exactly
  now, in the catalogue and in the sections of the collection, where the question usually is "do I
  have this brick". In the parts list the match is exact to the row: an element number names a
  part in a colour, which is just what a row there is, so 4243814 finds Plate 4 x 4 in red and
  says how many are in sets, loose or in assemblies. In the catalogue what it finds is shown in
  that colour and opens in it.
- **Narrowing a theme to a sub-theme.** "Star Wars" is a thousand sets in the catalogue and half
  the shelf at home, and it was as narrow as the filter went. A second list appears under the
  first once a theme is chosen: flat, each entry written as a path from the root — 403 pairs of
  sub-themes inside one root share a name, and "Star Wars Episode 4/5/6" alone says nothing about
  which of the three it is. The catalogue offers every sub-theme there is; a section of the
  collection offers only what it holds, intermediate levels included. The address still carries
  one theme, so links and bookmarks are untouched.
- **A colour for everything that has one.** Colour was treated as a property of parts, which held
  until 1.6.0 started showing gear in the contents of a set: the Throwbot storage case comes in a
  different colour in each of 8500 through 8507, and five hundred such items appear in inventories
  with a colour. The card now offers the colours an item is recorded in, outside links follow the
  choice, and a copy added to the collection remembers it instead of being drawn in the one colour
  the catalogue pictures it in.

### Fixed

- **A long value made a select stand twice as tall as its neighbours.** The chosen value and the
  search box share one wrapping line, and on focus the box was pushed onto a second one. The value
  is clipped with an ellipsis now and the room to type stays where it was.

## 1.6.0 — 2026-10-15

### Added

- **What a set holds besides parts and figures.** A set can list gear — the storage case a
  Throwbot came in, a keyring — and a book or a catalogue too. Those lines were written into the
  collection and then shown nowhere: the page of a set knew three kinds of thing and silently
  dropped the rest. Every kind in the box now has its own group on the page and its own number on
  the catalogue card, with its colour: the case in 8500 through 8507 comes in a different one each
  time, and all eight used to look alike.
- **"Refresh the inventories".** An inventory is copied into the collection when something is
  added and lives on its own after that: a set on the shelf does not change because BrickLink
  edited its page. Sometimes the edit is the truth, though — a missing lot found, a colour
  corrected — and a button under Settings → Catalogue now rebuilds the inventories of sets and
  figures from the catalogue as it is now. By hand, never by itself. Marks for missing pieces are
  carried to their places by where they sit in the tree and trimmed to what is left; any that have
  nowhere to go are listed by copy, lot and colour rather than quietly dropped. Assemblies and
  loose lots, which no catalogue ever described, are left alone.
- **"Serve pictures through the application", under Settings → Appearance.** On, as before: a
  picture comes from our cache, so the collection is visible even on a day BrickLink will not
  answer. Off: the browser fetches it from the source itself and it appears at once, without
  waiting for the background download. The cache fills either way, so the switch can be turned
  back.
- **"Incomplete" in the minifigure filter** — figures missing a piece of their own. Until now such
  a figure could be found only by opening the set holding it; the switch beside it, "Missing", is
  a different question: that one is about a figure a set of yours is missing.
- **Three kinds of export, chosen by name.** The export button became a menu: an inventory and a
  wanted list, both BrickLink XML as before, and a BrickStore basket (.bsx) — BrickStore's own
  document, which opens by double click instead of going through an import dialog. The kind used
  to be guessed from the filter, which was right more often than not but left no choice.
- **A picture zooms by wheel and by pinch.** The single "twice as big" of a double click was a
  step where a dial was wanted: what one does with an open picture is look closely at the print on
  a tile. The wheel does it on a desktop, two fingers on a phone, and what no longer fits is
  dragged; the point under the cursor stays where it is.
- **Each section remembers how it was last sorted.** The order still lives in the address, so
  "parts by how many I have" is a link worth sending, but a bookmark and the menu carry a bare
  address and the choice had to be made again on every visit. Kept in the browser and separately
  per section: sets go by year while parts go by count, and neither decides for the other.
- **A Dockerfile and a compose file in the repository.** `docker compose up -d` and the
  application is at `http://localhost:8080`, with the collection, the image cache and the
  catalogue archive in a volume. For a machine of your own; the Home Assistant add-on is
  unchanged and still builds from a released tag.

### Fixed

- **A minifigure inside a minifigure was counted twice.** BrickLink describes a Technic figure
  wearing a helmet as the bare figure plus the helmet, so a set with one such figure in the box
  reported two, and the inner figure stood in the minifigure section as a card of its own. A
  figure inside a figure is a part of it now; its pieces still count, because they are in the box
  either way.
- **Two columns of the analytics tables counted different things under similar headings.** By
  theme and by year, "Sets" counted copies while "Minifigures" counted distinct item numbers —
  the figure column is split into "Distinct" and "In total" now, so neither number has to be
  guessed at.

## 1.5.1 — 2026-10-01

### Fixed

- **An accordion row ran off the card on a phone.** The row of a minifigure inside a set is a
  button declared full width, and it would not shrink below its own contents: on a 390-point
  screen it ran past the card by exactly the width of the picture beside it, carrying the chevron
  off the edge. The row now holds the item number and the counts, and the name moved into the
  body, where there is room to write it out in full instead of cutting it with an ellipsis. On a
  narrow screen the badges for spares and losses keep their numbers and drop the word before
  them — the word is four times the length of the number, and the colour and the sign already say
  which is which.
- **The magnifier appeared on one picture and not on the next.** 1.5.0 offered to open a picture
  only where the file was larger than what is drawn, which is known only once it has loaded — so
  in a table of a figure's parts some rows had it and some did not, with nothing on screen to
  explain the difference. A picture inside a card opens now, and whether the file is worth the
  trip is the looker's business.

## 1.5.0 — 2026-10-01

### Added

- **A picture opens at its own size.** In a table row a picture is drawn 56 points wide and in a
  card about three hundred, while the file behind it is 400 to 700: the pattern on a printed tile
  could not be made out anywhere. A click on a picture inside a card — the main one, or a
  thumbnail in a table of what something is made of or of where it turns up — now shows it on a
  dimmed layer, at its own size, up to 95% of the window; a double click doubles that and the
  layer scrolls. Esc, a click past the picture or the cross closes it. Lists and grids keep what
  they had: there a picture is how one opens the thing. Where the file is no larger than what is
  already drawn — a part's picture is 200 points wide and the card draws it at 414 — nothing is
  offered, because "larger" would mean smaller.
- **"In the collection" on a catalogue card.** The card said what a thing is and what it is part
  of, but not whether it is here; that could be asked only from inside the collection's own
  sections, so one had to know the answer to get it. A block at the end of the card now answers
  it, with a tab per place and none for a place that holds nothing: a part lists its loose lots,
  the sets and figures it is built into and the assemblies it went to, a figure its own copies and
  the sets holding it, a set its copies and anything it sits inside. For a part the block follows
  the colour of the card, as the picture, the links out and the add dialog already do.

### Fixed

- **The second page of "part of" landed on the home page.** That list builds its paging by hand,
  and such a paginator takes no address of its own: it fell back to the site root, so the link to
  page two read "/?in_page=2". A common brick is in tens of thousands of inventories and every one
  past the first fifty was unreachable.
- **A minifigure holding a part opened in the catalogue.** On the part page the "minifigures" tab
  led out to the catalogue card while the tabs beside it — sets, assemblies, colours — led into
  the collection. It is our own part, so the figure it is built into is ours too. An alternate
  figure still goes to the catalogue: the section knows only what is in the box.

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
