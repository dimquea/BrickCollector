# BrickCollector

*[Русская версия](README.ru.md)*

A self-hosted manager for a LEGO® collection, with the BrickLink catalogue
inside it. Runs on your own machine or as a Home Assistant add-on. Your
collection stays there.

It answers the questions a collection actually raises. Do I already have this
set? How many black 2×4 bricks are there, and which boxes are they in? Which
minifigure is missing from that set, and what did the whole shelf cost?

## What it does

**The catalogue.** Every LEGO set, minifigure and part BrickLink knows about —
175 thousand items with their contents, colours, themes and release years.
Search by name or item number, filter by type, theme and year. Updated from the
interface whenever you want it updated.

**Sets you own.** Adding a set copies its whole contents: parts, minifigures,
the parts inside those minifigures, and boxed sub-sets down the chain. Every
copy is its own record, so three of the same set are three sets with three
prices, three storage places and three stories.

**What went missing.** Any lot can be marked short, down to a single part
inside a minifigure inside a set. A set then knows it is incomplete without
your having to open it. A spare part is not a missing one — losing a spare
leaves the set complete.

**Minifigures.** A figure built into a set and one bought on its own are the
same figure, so a single card counts both. Open it to see which sets hold it,
what it is made of, and which copies you own loose. Each loose copy keeps its
own purchase details.

**Parts.** Counted across the collection rather than listed box by box: how
many you have in total, how many sit in sets, how many inside minifigures, how
many loose — and which copies each of them came from.

**Loose parts.** A part bought on its own is a lot: a date, a price, a drawer, a
page of its own and a quantity you can change. Adding one asks how many, in
which colour, and whether it starts a new lot or tops up one already held.

**Assemblies.** A model of your own making — a group of loose parts with a name,
a photo and notes. Parts move in from the loose pile and back out again, so the
collection holds the same bricks either way, and an assembly knows what it is
still short of.

**Your own notes.** Purchase date, price, source and storage place; tags and
statuses you define yourself; a free-form note. Only the two statuses that ship
with the application — box and instructions — cannot be deleted.

**Analytics.** What the collection holds, what it cost, and both broken down by
theme and by release year. The numbers are links: click one and the section
opens filtered to exactly what was counted.

**A wishlist.** What the collection does not hold but you want, added from the
catalogue — a part in a colour, since that is how a part is wanted. A list and
nothing more: a price and a storage place describe something you already own.

**Export.** Any list, exactly as you have filtered it, as BrickLink XML: what
you hold as an inventory, what you are missing as a wanted list with the sets it
is missing from written in the remarks. An assembly exports both — what it is
made of, and what it still needs.

**Import.** The other direction: a BrickLink XML file, read and shown before
anything is created. Tick what to take and say where it goes — into the
collection, the wishlist or an assembly; for the collection, fill in what you
know about the purchase, once for everything or line by line.

**Search by photo.** Photograph a brick or pick a picture, and the catalogue
finds it. It works through [Brickognize][bg], an outside service: the image is
sent there, so the feature is off by default. Read [its terms][bgterms] before
turning it on.

**Elsewhere.** Buttons to BrickLink, Rebrickable and Brickset on every detail
page, plus two blocks for whatever else you use. The addresses are patterns you
can edit.

**Two languages.** English and Russian, switchable in the settings. Catalogue
names stay as BrickLink writes them — that is the data, and that is what search
matches.

**The look.** Light, dark, or whatever the device is set to. Lists order
themselves by item number, name, year or how much is held, and the order lives
in the address, so a filtered and sorted list is a link you can send to someone.

## Installing

As a Home Assistant add-on — the simplest way, and the one it is built for:

1. Add the repository `https://github.com/dimquea/hassio` in **Settings →
   Add-ons → Add-on store → ⋮ → Repositories**.
2. Install **BrickCollector** and start it. The first start downloads the
   catalogue and unpacks it; the log says what it is doing.
3. Open **BrickCollector** in the sidebar.

On your own machine you need PHP 8.2 or newer with SQLite, and a web server
pointed at `public/`:

```bash
git clone https://github.com/dimquea/BrickCollector.git
cd BrickCollector
composer install
npm ci && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan catalog:import --download
```

## A word about access

BrickCollector has no accounts and no passwords, on purpose: it is built for a
home network, or for the Home Assistant panel, which does the guarding. The
add-on exposes no port of its own — the panel is the only way in. Do not put it
on the open internet as it stands.

## Thanks

The catalogue comes from [rgriebl/brickstore-database][db], which republishes
the BrickLink catalogue several times a day. Item pictures come from BrickLink.
Recognising an item from a picture is [Brickognize][bg].

LEGO® is a trademark of the LEGO Group, which does not sponsor, authorise or
endorse this project.

## Licence

MIT — see [LICENSE](LICENSE). Fork it, change it, run it, ship it; keep the
copyright notice with the code.

The licence covers the code and nothing else. The catalogue is not part of this
repository: the application downloads it onto your own machine from
[rgriebl/brickstore-database][db], and item pictures come from BrickLink. That
data belongs to its owners and travels under their terms, not under this
licence.

A built copy — the Home Assistant add-on image included — carries Bootstrap,
Vue, Inertia, Tom Select and the Material Design Icons font inside it. They are
MIT and Apache-2.0, and their own licences come along with them.

[db]: https://github.com/rgriebl/brickstore-database
[bg]: https://brickognize.com
[bgterms]: https://brickognize.com/terms-of-service/
