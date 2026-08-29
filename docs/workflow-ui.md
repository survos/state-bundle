# Putting a workflow on screen

Written while wiring this into ssai's intake and image screens (2026-08-29).
Debugging workflows has always been the hard part — not writing them — and most
of what follows exists because a marking badge alone does not answer any of the
questions you actually have.

The component is `<twig:state:workflow-marking>`. One tag gives you the current
place, what you can do from it, what you *cannot* do and why, and (in dev) a way
to put the entity anywhere you like so you can re-run the interesting part.

## Minimum

```twig
<twig:state:workflow-marking :subject="intake" />
```

That is read-only: a badge with the place name. Fine for a list row or a search
hit. It does not need routes, and it deliberately renders no buttons — a
disabled button nobody could have clicked is noise, and this component gets
rendered once per row in result grids.

## The useful version

```twig
<twig:state:workflow-marking
    :subject="intake"
    workflowCode="IntakeWorkflow"
    globalKey="APP_ENTITY_INTAKE"
    layout="inline"
    :showSteps="true"
    :redirectUrl="url('intake_next', intake.rp)"
/>
```

**`workflowCode` is what switches the buttons on** (`canApply`). Leave it off and
you get the read-only badge — which is what a list row or search hit wants, but
is confusing if you meant to get buttons.

- **`workflowCode`** — the workflow's registered name, i.e. whatever
  `#[Workflow(name: ...)]` says. `IntakeWorkflow`, not `intake`, unless you
  named it `intake`.
- **`globalKey`** — optional. Derived from the subject's class when omitted
  (`App\Entity\Intake` → `APP_ENTITY_INTAKE`). Pass it only when you know
  better than the derivation.
- **`layout`** — `inline` (badge + buttons in a row, for a page header) or
  `table` (every transition with descriptions and blocker text, for a detail
  tab).
- **`showSteps`** — draws the whole place sequence with the current one filled.
- **`redirectUrl`** — where to land after applying. See "Where to land" below.

## What the buttons actually do

**Enabled, synchronous** — one button, POSTs, applies, redirects.

**Enabled, asynchronous** — a split button. The main half queues it, exactly as
production would. The caret offers **Run now (sync)**, which still goes through
the message bus (on the `sync` transport) so it exercises the same handler a
queued run would — you are debugging the real code path, not a parallel inline
one that can drift away from it. This is the option you want during a demo, when
waiting on a worker is not an option.

Async-ness is asked of `AsyncQueueLocator`, not read off the transition
metadata, because the locator is what actually decides routing at dispatch time.

**Blocked** — a dimmed button carrying the reason on hover. The reason comes
from `buildTransitionBlockerList()`, so it is your guards' own wording:

```php
$event->setBlocked(true, 'Image can close only after pixelsDone=true.');
```

...shows up as exactly that. Write guard messages as if someone will read them,
because now they will.

Symfony's own message for the marking case ("The marking does not enable the
transition") is replaced with the places the transition *is* available from —
`Available from: metadata` — because the dimming already told you it was
blocked, and what you wanted to know was what has to happen first.

## The step strip

`showSteps` is the one that fixes "I forget where I am":

```
new → triaged → ▶ uploaded → enriched → extracted → closed · remove · ignored
```

Note the `·` before the last two. A workflow's place list is **declaration
order, not a path** — arrowing every place together claims progressions that do
not exist, and `closed → remove → ignored` is three unrelated terminal states
drawn as a pipeline. The arrow appears only where some transition actually
connects a place to the one printed after it. If your strip is all dots, your
places are declared in an order unrelated to their flow; reorder the constants.

Place metadata drives the rest — `info` becomes the tooltip, `bgColor` the fill:

```php
#[Place(info: 'Stored in S3; ready for enrich', bgColor: 'primary')]
public const PLACE_UPLOADED = 'uploaded';
```

## When the workflow lives somewhere else

The component renders **nothing** if no workflow in the app supports the
subject. That is not a formality — it is what lets a bundle put this on a screen
it owns for an entity it owns, while the *workflow* belongs to one app:
dataset-bundle ships `DatasetInfo` and its show page, but only harvest defines a
`DatasetWorkflow`, because dataset processing was deliberately consolidated
there. Every other dataset-bundle app renders the page unchanged.

So a shared bundle can adopt this unconditionally. It lights up wherever a
matching workflow is registered and stays invisible everywhere else.

## Force place (dev)

`⚡ Force` sets the marking directly. **No transition fires, so no guard runs and
no listener sees it** — the entity simply *is* somewhere else now.

That is the whole value: re-run triage on an already-triaged image without a
manual `UPDATE`, or park an entity mid-pipeline to reproduce a bug. It is also
the whole danger: the side effects a transition would have performed have not
happened, so the marking can now disagree with the entity's data. An image
forced to `uploaded` that was never uploaded will fail at the next step, and the
failure will not look like your fault.

Off outside dev:

```yaml
# config/packages/survos_state.yaml
survos_state:
    allow_force_place: '%kernel.debug%'   # the default
```

If you turn it on in production, gate the surrounding template on a role as
well. The flag is a kill switch, not an authorization system. A page can also
opt out with `:showForcePlace="false"` — that can hide the control where the
bundle allows it, never reveal it where the bundle said no.

The target place is checked against the definition, so this cannot write a
marking the workflow has never heard of. That state would be unrecoverable
through the UI, since every transition's `from` would miss it.

## Where to land

`redirectUrl` defaults to redirecting back to the debug transitions page, which
is almost never what you want on an app screen. Two patterns:

**Stay put** (`app.request.uri`) — right when the entity has one screen. Image
transitions do this: you watch the ▶ move along the strip instead of losing the
thing you were looking at.

**Go where the new state belongs** — right when different states mean different
screens. ssai has an `intake_next` route that matches the marking to a screen
(`scanning` → capture, `metadata` → narrate, `closed` → summary) and the widget
points at it, so finishing capture takes you to the narrate screen rather than
leaving you on the one you just finished with.

Keep that map in the app, not in the workflow definition. A definition says what
states exist and what may move between them; which template a human should look
at is your UI, and a CLI or API client consuming the same workflow has no use
for a route name. If the map outgrows a `match` arm, a `screen` key in the
`#[Place]` metadata is the next step — metadata is already the escape hatch for
things about a place that are not the state machine.

For an **async** transition, "where the new state belongs" is where you already
are, because the marking has not moved yet. The `"x" queued.` flash is what
explains why nothing appeared to happen — so make sure the layout renders
flashes, or async transitions will look broken. Tabler's `page_content_start`
does this already.

## Adding it to an app: checklist

1. Entity implements `MarkingInterface` (`MarkingTrait` gives you this).
2. Entity is Doctrine-mapped with a **single** identifier. The apply route does
   `find($entityId)`, so it needs the primary key — *not* a route identity.
   Those coincide for a ULID-keyed entity, which is why the difference goes
   unnoticed until an entity keyed by something else shows up. ssai's `Intake`
   resolves to `{tenant}/{code}`, which cannot even be generated into a route
   segment.

   A **secondary entity manager is fine** — both the class lookup and the entity
   load walk every registered manager. dataset-bundle's `DatasetInfo` lives on
   its own `dataset` registry.

   A primary key containing a **slash is fine too** — `DatasetInfo` is keyed by
   `nara/rg_105`. That is why `entityId` is the last, greedy segment on both
   routes and the transition comes first (`.../t/{transition}/{entityId}`).
3. The layout renders flashes.
4. Drop the tag on the detail screen with `workflowCode` + `globalKey`.
5. Check the strip's arrows match the real flow.
6. Decide `redirectUrl`.

## Gotcha: inherited layouts double up

If a child template's base already puts this component in a block, adding a
second one for a different entity gives you two `▶` markers and two sets of
buttons — and the one you did not mean is the dangerous one. ssai hit this
exactly: `image/base` extends `intake/base`, so image screens inherited the
*intake's* buttons, including a "Close" that closes the whole intake.

The fix that reads well: keep the parent entity visible as context but strip its
controls, with `:showTransitions="false"`, and link it to wherever that entity
should be resumed.

## The standalone debug page

`survos_state_debug_transitions` — every transition with froms/tos, metadata,
blockers, and Queue/Sync buttons, for one entity:

```
/state/debug/{globalKey}/{workflowCode}/{entityId}
```

Useful when an entity has no detail screen yet, or when you want the full table
without embedding anything.
