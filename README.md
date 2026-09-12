# BrickCollector

*[Русская версия](README.ru.md)*

A self-hosted manager for a LEGO® collection, with the BrickLink catalogue
inside it. Runs on your own machine or as a Home Assistant add-on. Nothing
leaves the house.

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

**Your own notes.** Purchase date, price, source and storage place; tags and
statuses you define yourself; a free-form note. Only the two statuses that ship
with the application — box and instructions — cannot be deleted.

**Analytics.** What the collection holds, what it cost, and both broken down by
theme and by release year. The numbers are links: click one and the section
opens filtered to exactly what was counted.

**Elsewhere.** Buttons to BrickLink, Rebrickable and Brickset on every detail
page, plus two blocks for whatever else you use. The addresses are patterns you
can edit.

**Two languages.** English and Russian, switchable in the settings. Catalogue
names stay as BrickLink writes them — that is the data, and that is what search
matches.

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
