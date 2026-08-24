# Adding a workflow to an app

Written after doing it in priceit (2026-08-24). Everything here is a thing that
went wrong or nearly did, in the order it went wrong. The happy path is short;
these are the parts that are not obvious from the attributes.

## The shape

Two classes in `src/Workflow/`:

- **`FooFlow.php`** — the graph. Places and transitions as class constants
  carrying `#[Place]` / `#[Transition]`. No behaviour.
- **`FooWorkflow.php`** — the behaviour. `#[AsTransitionListener]` methods that
  do the work when a transition fires.

The entity gets `MarkingTrait`, which supplies `$marking` plus everything the
workflow engine expects. Replace any `status` enum outright — a marking is the
same information in the place the engine already looks.

```php
#[Workflow(supports: [Item::class], name: self::WORKFLOW_NAME)]
class ItemFlow
{
    public const WORKFLOW_NAME = 'item';

    #[Place(initial: true, next: [self::TRANSITION_SUGGEST])]
    public const PLACE_NEW = 'new';
}
```

## Set the initial marking in the constructor

```php
public function __construct(string $clientId)
{
    $this->marking = ItemFlow::PLACE_NEW;
}
```

`MarkingTrait` declares `?string $marking = null`. An entity persisted with a
null marking is in no place at all, and the kickoff below has nothing to work
from.

## `next` on the initial place is the whole point

`#[Place(initial: true, next: [...])]` means "when an entity is created here,
dispatch the first of these whose guard passes". `InitialPlaceKickoffListener`
implements it: collect on `postPersist`, dispatch on `postFlush`.

**Do not hand-roll this.** The instinct is to `$bus->dispatch(...)` from
whatever created the entity — an upload handler, a controller, an import
command. That works, and then the decision about what happens next lives in
three places and drifts. It is also how mediary#7 happened: a hand-rolled
kickoff hardcoded one transition, so adding a guard to it stalled every asset in
`new` with no fallback.

postFlush, not postPersist, matters: a `TransitionMessage` carries only an id,
so the row has to be committed before a worker can find it. With a Doctrine
transport the message and the entity would otherwise race inside one flush.

## Each async transition gets its own transport

This is the one that costs an hour.

`async: true` on a transition creates a **dynamic transport named
`<workflow>.<transition>`** — `item.suggest`, `dataset.normalize`. It is not
`async`. A worker started on `async` sits there reporting no messages while the
kickoff messages pile up next door, which looks exactly like the workflow never
firing.

Run the workers with the supervisor rather than naming transports by hand:

```bash
bin/console survos:supervisor -w item --no-tui
```

`-w item` starts one `messenger:consume` per transport matching `item`, so a
transition added next month gets a worker without anyone editing a list.
`--no-tui` when it runs headless — under `symfony server` workers, in systemd —
because the dashboard is otherwise drawing to nobody. (It lives in
`survos/supervisor-bundle`, a separate package from this one.)

In `.symfony.local.yaml`:

```yaml
workers:
    workflow:
        cmd: ['symfony', 'console', 'survos:supervisor', '-w', 'item', '--no-tui']
        watch: ['config', 'src']
```

Confirm what exists with `bin/console messenger:stats` — the transport list is
the ground truth, and `<workflow>.<transition>` will be in it.

## Injecting the workflow

```php
public function __construct(
    #[Target(ItemFlow::WORKFLOW_NAME)]
    private WorkflowInterface $itemWorkflow,
) {}
```

Without `#[Target]`: *"references interface WorkflowInterface but no such
service exists. Did you mean to target 'item' instead?"* — a good error, but
only if you read past the first line.

## Give failure a place

A transition that can fail wants a `failed` place with its own
`next: [TRANSITION_*]` back into the pipeline. Then a retry is applying the same
transition again rather than special-case code, and a stuck item is visibly
stuck instead of sitting in the initial place looking untouched.

In the listener, route the failure into the graph and block the transition,
rather than throwing:

```php
if (!$result['ok']) {
    if ($this->itemWorkflow->can($item, WF::TRANSITION_SUGGEST_FAILED)) {
        $this->itemWorkflow->apply($item, WF::TRANSITION_SUGGEST_FAILED);
    }
    $this->em->flush();
    $event->setBlocked($result['message']);   // don't land in `suggested` with nothing suggested
}
```

Transitions that are naturally repeated — printing a label, re-pricing — should
list their own destination in `from`, so they are re-enterable. Reprinting a
label is normal; needing a special transition to do it is not.

## `queue_driver` is a hard requirement

```yaml
survos_state:
    workflow_paths: ['%kernel.project_dir%/src/Workflow']
    queue_driver: doctrine          # or rabbitmq
    async_transport_dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
```

The bundle picks its queue strategy from `queue_driver` alone and deliberately
does **not** infer it from the DSN, which is an unresolved `%env()%` at compile
time. Set the DSN to AMQP without setting the driver and you get Doctrine-shaped
queues against a broker.

## Migrating off an existing status column

`doctrine:migrations:diff` will add `marking` and drop `status` **without
carrying the data** — every existing row comes out stateless. Patch the
generated migration to select the old column into the temp table and map it:

```sql
CREATE TEMPORARY TABLE __temp__item AS SELECT id, ..., status FROM item;
INSERT INTO item (id, ..., marking)
SELECT id, ...,
       CASE status WHEN 'captured' THEN 'new' ELSE status END
FROM __temp__item;
```

## Documenting it

`survos/doc-bundle` renders the graph:

```bash
bin/console doc:workflows
```

writes `docs/workflow/<name>.md` with a Mermaid diagram and an SVG. Worth
committing — a reviewer reads the diagram far faster than the attributes.

There is also a live dashboard at `/state/` from this bundle.

## Quick checklist

1. `FooFlow` with places/transitions; initial place declares `next`.
2. Entity: `use MarkingTrait`, set the initial marking in the constructor.
3. `FooWorkflow` with `#[AsTransitionListener]`; `#[Target]` on any injected workflow.
4. `survos_state.yaml`: `workflow_paths`, `queue_driver`, `async_transport_dsn`.
5. Migration carries old status values across.
6. Workers via `survos:supervisor -w <workflow> --no-tui`.
7. `messenger:stats` to confirm `<workflow>.<transition>` exists.
8. `doc:workflows` and commit the diagram.
