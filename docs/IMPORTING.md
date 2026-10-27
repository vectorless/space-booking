# Importing bookings from somewhere else

**Space Booking: Skedda import** (`space_booking_skedda`) reads a Skedda
booking export and creates the rooms and bookings in Space Booking.

It is deliberately not a bespoke importer. It is two ordinary
[Migrate API](https://www.drupal.org/docs/drupal-apis/migrate-api) migrations
plus four process plugins, which means you get rollback, re-running, per-row
error reporting and a documented way to change the mapping — none of which a
hand-written importer would have given you.

> The same module is published on its own at
> **[space-booking-csv-import-example](https://github.com/vectorless/space-booking-csv-import-example)**,
> for people adapting it to a system that is not Skedda. That copy's README is
> written as a template guide; this page is the one to read if you are
> importing from Skedda into this project.

## What you need

```bash
composer require drupal/migrate_source_csv drupal/migrate_tools
drush en space_booking_skedda migrate_tools
```

`migrate` is core. `migrate_source_csv` reads the file. `migrate_tools` is
only needed for the drush commands — the migrations themselves do not depend
on it.

## Getting the file out of Skedda

In Skedda: **List view** → the dropdown beside **Filters** → **Export** → CSV.

Two things to watch:

- **Widen the date filter first.** The export honours what the list is showing,
  so a default view exports a fortnight and nothing else.
- **The file has personal data in it** — names, emails, telephone numbers. Put
  it in a private directory, not `sites/default/files`. The shipped migrations
  expect `private://skedda-export.csv`.

## Running it

```bash
drush migrate:import space_booking_skedda_spaces,space_booking_skedda_bookings
drush migrate:messages space_booking_skedda_bookings   # anything it refused
drush migrate:rollback space_booking_skedda_bookings,space_booking_skedda_spaces
```

A real run looks like this:

```
Processed 1 item  (1 created, 0 updated, 0 failed, 0 ignored) - space_booking_skedda_spaces
Processed 7 items (6 created, 0 updated, 1 failed, 0 ignored) - space_booking_skedda_bookings
```

One room was created because the other two already existed; one booking was
refused, and `migrate:messages` says why in the module's own words:

> *Main Hall is already booked between 5 Jan 2027, 19:00 and 5 Jan 2027, 20:30.*

**The booking rules run on every row.** The destination has `validate: true`,
so an import cannot create a clash, a backwards time range or a booking in a
room that is not bookable. A refused row is reported and skipped; the rest
still import.

## How the columns are used

| Skedda column | Becomes |
|---|---|
| `Scheduled start`, `End` | the booking's start and end |
| `Spaces`, `Assigned space` | the rooms, matched by name |
| `Title` | the booking's title |
| `Created` | when the booking was made |
| `Type` | the booking type, via a mapping you control |
| `Holder *` columns | the notes field — **not** a Drupal account, see below |
| `Duration (minutes)`, `Spaces count` | ignored, both are derivable |
| `Price`, `Payment Status`, `Gateway charge reference` | dropped: this module has no payments |
| `Seen on-site` | dropped: no check-in |
| `Holder tags`, custom fields | not mapped; add a line to the migration if you want them |

### Rooms

The export has no list of rooms, only bookings that name them, so
`space_booking_skedda_spaces` reads the booking file and creates each distinct
room once. A room whose name already exists is left alone — it keeps its
capacity, tags and overlap mapping, and the bookings join the room that is
already there rather than creating a second column on the schedule.

Imported rooms get a name and nothing else. Capacity, type, approval and which
rooms physically overlap are things only the venue knows: set them afterwards.

### Holders

Imported bookings have **no holder**, and the name, organisation, email and
telephone are written to the booking's notes instead.

Creating Drupal accounts for a venue's entire membership is not something an
import should do quietly: the accounts can be emailed, they count as users,
and deleting them later is a chore. If you do want it, add `migrate_plus` and
an `entity_lookup`/`entity_generate` step on the `holder` property — that is a
deliberate decision, made once, in your own copy of the migration.

### Status

Every imported booking is **confirmed**. A venue's existing diary is already
agreed; routing years of history into an approval queue would be absurd.

## Changing the mapping

Copy `modules/space_booking_skedda/migrations/*.yml` into your own module's
`migrations/` directory, give them new IDs, and edit. Nothing here is special:
they are plain migration plugins.

The four process plugins exist because the export is loosely specified:

| Plugin | What it is for |
|---|---|
| `skedda_datetime` | reads a date in a configurable list of formats, in a configurable timezone |
| `skedda_space_list` | splits one cell into several room names, on a configurable delimiter |
| `skedda_space_lookup` | matches a room name to an existing space, ignoring case |
| `skedda_booking_type` | maps the venue's own type names onto `user`, `internal` and `unavailable` |

For example, to treat Skedda's own type names properly:

```yaml
process:
  booking_type:
    plugin: skedda_booking_type
    source: 'Type'
    map:
      'Member booking': user
      'Private hire': user
      'Maintenance': unavailable
      'Staff': internal
    default: internal
```

**Configuration → Content → Space booking → Skedda import** sets the defaults
those plugins fall back to — path, delimiters, timezone, date formats — so a
site builder can adjust an import without touching YAML.

## Three things to check against your own export

The columns are documented by Skedda; these are not, and all three can
silently distort an import:

1. **The date format.** It follows the account's locale. `skedda_datetime`
   tries nine common formats and, when none of them round-trips exactly,
   fails the row with the raw value in the message — that message is the
   fastest way to learn the real format. Then set it explicitly.
2. **How several rooms are separated** inside the `Spaces` cell. The default
   assumes a comma inside a quoted field.
3. **Recurring bookings.** If Skedda exports one row per occurrence, you are
   fine. If it exports one row per series, the occurrences are not in the file
   at all, and no importer can invent them — export a date-filtered view
   instead, or use the iCal feed for the series.

Also worth knowing: there is **no status column** in the export, so a
cancelled booking may well be in the file looking exactly like a live one.
Check a few against Skedda before trusting the lot.

## Importing from something that is not Skedda

The same shape works for any export with a start, an end and a room name.
Write your own migration YAML, use `skedda_datetime` and `skedda_space_lookup`
for the fiddly parts, and point the destination at
`entity:space_booking_booking` with `validate: true`.

For a point-and-click mapping instead of YAML, [Feeds](https://www.drupal.org/project/feeds)
is the usual alternative and can target any content entity; Migrate is the
better fit here because the rollback and the per-row error log matter when the
thing you are importing is a venue's diary.
