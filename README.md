# Survos State Bundle

`survos/state-bundle` adds attribute-based workflow definitions, queued transitions,
chaining, commands and visualization to Symfony Workflow.

## Symfony 8.2 roadmap: native attributes and smaller bundles

**We expect to deprecate state-bundle in favor of native Symfony workflow
attributes plus smaller optional bundles once the replacements are validated and
have a documented migration path. State-bundle is not deprecated today.**
Existing applications can continue using its current attributes and services;
there is no immediate migration requirement or removal date.

Symfony 8.2 is taking over functionality that this bundle previously supplied.
Our intended direction is to let Symfony own workflow definitions, discovery,
registration, marking stores, guards and events, and retain only our additional
behavior. This is a selective extraction, not a copy of state-bundle under a new
name. String constants remain supported; adopting enums is optional.

### Upstream alignment

Status checked on **9 October 2026**; these links are the source of truth as the
8.2 development branch evolves:

- [#61935: native workflow attributes](https://github.com/symfony/symfony/pull/61935)
  is merged: `AsWorkflow`, `Place` and `Transition` define native workflows.
- [#66722: `Place(initial: true)`](https://github.com/symfony/symfony/pull/66722)
  is merged, following [our initial-place proposal](https://github.com/symfony/symfony/issues/66701).
  Explicit `AsWorkflow(initialMarking: ...)` takes precedence.
- [#66687: extensible workflow attributes](https://github.com/symfony/symfony/pull/66687)
  is merged. Native `AsWorkflow`, `Place` and `Transition` can now be extended,
  with subclass discovery and validation rejecting ambiguous multiple workflow/place
  attributes. No PR patch is needed on a containing 8.2 development revision.
- [#66726: workflow attribute descriptions](https://github.com/symfony/symfony/issues/66726)
  is a proposal, not an available API. Today descriptions can be carried in native
  metadata. Bundle-specific convenience arguments need not become core features.

The target replacements are **Symfony 8.2-only**, starting with exact development
revisions for compatibility testing. Experimental scaffolds now exist in mono for
[workflow-async](https://github.com/survos/mono/tree/main/bu/workflow-async-bundle) and
[workflow-extras](https://github.com/survos/mono/tree/main/bu/workflow-extras-bundle),
with a locked packages-inspired example. They are not stable replacements yet. A release must require a Symfony version containing
its necessary upstream changes; merely selecting an arbitrary 8.2 development
revision does not guarantee attribute extensibility.

### Proposed package boundaries

| Package | Intended responsibility |
| --- | --- |
| Symfony Workflow | Native `AsWorkflow`, `Place`, `Transition`, initial marking, definition registration and normal workflow execution. |
| `survos/workflow-async-bundle` (working name) | Explicitly dispatch transition requests through Messenger; resolve subjects in workers, apply native transitions and select configured transports. Optional transport provisioning is a separate capability. |
| `survos/workflow-extras-bundle` (working name) | Ordered `next` chaining, workflow iteration commands, metadata conveniences and optional workflow explorer/diagram integration. Delegate queued execution to workflow-async when installed. |
| `survos/state-bundle` | Continue serving existing applications during migration, preserving current attributes and message compatibility. |

`state:iterate` belongs with workflow extras, not the async execution package.
Async must be usable without adopting our UI, subject traits, workflow definition
reader or Doctrine conventions. Shared transports are supported; one queue per
transition is useful for independent worker limits and retries, but not required.

The async contract is explicit: dispatch queues a request; a worker loads the
subject and invokes Symfony's workflow, so ordinary transition listeners run in
the worker. Native `Workflow::apply()` stays synchronous. Messenger delivery is
at least once, and guards are evaluated against the subject at consumption time.
Applications still need idempotent external effects and an appropriate
persistence/transaction strategy; queuing is not an exactly-once guarantee.

### Compatibility work and first migration

Our first pilot is the packages application and its single package workflow:
fetch package metadata, run inexpensive guarded validation, then fetch the README
only for valid Symfony 8 bundles. The new standalone example demonstrates metadata
and README fetching on separate queues; the live packages application has not yet
been migrated or given a README transition.

Before recommending migration, we need to demonstrate:

- Native definition registration exactly once, with derived attributes discovered
  correctly and native initial-marking, guard, context and event behavior preserved.
- Real Messenger enqueue/consume behavior, including stale requests, failures and
  independent transition routing.
- A minimal application without FrameworkBundle. Symfony 8.2 provides component-owned
  WorkflowBundle and MessengerBundle with `workflow:` and `messenger:` configuration;
  our replacement must not depend on scanning `framework.workflows`.
- Optional persistence and UI integrations, with the async core working without
  Doctrine, Twig or Tabler.
- A migration that preserves workflow/transition names and existing queued message
  handling until old queues drain. Do not enable both definition registrars for
  the same workflow.

Validation so far includes [state-bundle's regression tests](tests/) and the upstream
Workflow component suite after resolving #66687 against the new initial-place
support, including inherited place metadata and `initial: true`. **This does not
establish end-to-end 8.2 compatibility for state-bundle.** The new scaffolds add
a separate minimal-kernel and Messenger worker test suite; production persistence,
automatic chaining and UI migration remain future work. Native attribute discovery happens during compilation;
reading raw framework configuration alone will not discover these definitions.
The current [attribute builder](src/Config/AttributesWorkflowConfigBuilder.php),
[workflow helper](src/Service/WorkflowHelperService.php) and
[queue locator](src/Service/AsyncQueueLocator.php) show the existing implementation;
they are migration inputs, not proof of the proposed package boundaries.

Useful extras may become focused upstream proposals after working examples and
tests establish their value. When Symfony adopts a capability, the corresponding
extra can delegate to core and eventually be deprecated. The goal is a smaller
maintenance surface while keeping application-specific policies outside Symfony.

## Current state-bundle usage

The examples below describe the existing state-bundle API, not the proposed
8.2 successor APIs. In particular, its `Workflow(initialPlace: ...)` remains
supported; native Symfony uses `AsWorkflow(initialMarking: ...)`.

Configure a workflow using PHP attributes.  Prefer separating the durable workflow definition from the event listener/orchestrator:

- `*Flow` is the attribute definition class, for example `ImageFlow` or `SubmissionFlow`.
- `*Workflow` is the listener/service class that reacts to transitions, queues work, and applies app policy, for example `ImageWorkflow`.

Older apps may still use `*WF`, `*WorkflowInterface`, or a single class that both defines and handles the workflow. New code should use `*Flow` for the definition because it is short, readable, and leaves `Workflow` for the runtime service.

auto-registration!

## Docs

- **[Putting a workflow on screen](docs/workflow-ui.md)** — the
  `<twig:state:workflow-marking>` component: place strip, transition buttons,
  why a transition is blocked, running an async transition synchronously, and
  the dev-only force-place control. Start here if you are debugging a workflow.
- [Adding a workflow to an app](docs/adding-a-workflow.md) — the definition +
  listener split, and the parts that are not obvious from the attributes.
- [DynamicRoutingMiddleware](docs/DynamicRoutingMiddleware.md)

## Workflow Constants In Twig

The bundle now exposes additive Twig helpers for resolving workflow definition constants without hard-coding raw place or transition strings in templates.

```twig
{% set removePlace = workflow_const(image, 'PLACE_REMOVE') %}

{% if image.marking != removePlace %}
    ...
{% endif %}
```

You can also resolve by workflow name:

```twig
{% set removeTransition = workflow_const('ImageFlow', 'TRANSITION_REMOVE') %}
```

Available helpers:

- `workflow_const(subjectOrWorkflow, constantName)`: resolves a PHP constant from the workflow definition class
- `workflow_name(subjectOrWorkflow)`: resolves the workflow name from a subject or returns the provided workflow name
- `survos_workflow_metadata(workflowName, key, metadataSubject)`: existing metadata helper for workflow/place/transition metadata

This is additive. Existing metadata helpers and app-level Twig extensions can remain in place.

## How It Works

During bundle prepend/compile time, `AttributesWorkflowConfigBuilder` now publishes an internal map of:

- `workflow name => workflow definition class`
- `supported entity class => workflow definition class[]`

`WorkflowHelperService` uses that map to resolve the workflow definition class for either:

- a workflow name like `ImageFlow`
- an entity instance like `App\Entity\Image`

That lets Twig resolve constants from the actual PHP workflow definition instead of relying on brittle string literals in templates.

## Tests

The bundle now includes PHPUnit 13-compatible unit tests covering:

- compile-time workflow definition mapping
- constant resolution in `WorkflowHelperService`
- Twig helper exposure in `WorkflowExtension`

Run them from the bundle root:

```bash
composer install
vendor/bin/phpunit
```

## Choosing an initial place

The existing `Workflow` attribute accepts an explicit initial place as a string,
constant, or string-backed enum case:

```php
#[Workflow(supports: [Submission::class], initialPlace: self::PLACE_NEW)]
```

`initialPlace` takes precedence over `#[Place(initial: true)]`. The existing
`initial` argument remains supported, including arrays for workflows with multiple
initial places. Supply either `initial` or `initialPlace`, not both. Place-level
`initial: true` remains supported and is not deprecated.

Symfony 8.2 calls its native workflow option `AsWorkflow(initialMarking: ...)`;
the Survos `initialPlace` option maps to the workflow configuration's
`initial_marking`. Enums are optional.

Run the bundle tests from the monorepo root with:

```sh
vendor/bin/phpunit -c bu/state-bundle/phpunit.xml.dist
```

## Vibing 

Doctrine-free jsonl workflow: https://claude.ai/share/9c89f52c-1655-44b6-bb86-d773d29bc20b



@todo: https://joppe.dev/2024/10/11/dynamic-workflows-with-symfony-workflow-component/

for easyadmin integration, also see https://github.com/WandiParis/EasyAdminPlusBundle


```php
<?php
// SubmissionFlow.php

namespace App\Workflow;

use App\Entity\Submission;
use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Transition;
use Survos\StateBundle\Attribute\Workflow;

#[Workflow(supports: [Submission::class], name: self::WORKFLOW_NAME)]
final class SubmissionFlow
{
    const WORKFLOW_NAME='SubmissionFlow';

    #[Place(initial: true, metadata: ['description' => "starting place after submission"])]
    const PLACE_NEW='new';
    #[Place(metadata: ['description' => "waiting for admin approval"])]
    const PLACE_WAITING='waiting';
    const PLACE_APPROVED='approved';
    const PLACE_REJECTED='rejected';
    const PLACE_WITHDRAWN='withdrawn';

    #[Transition(from:[self::PLACE_NEW], to: self::PLACE_WAITING)]
    const TRANSITION_SUBMIT='submit';
    #[Transition(from:[self::PLACE_NEW], to: self::PLACE_APPROVED, guard: "is_granted('ROLE_ADMIN')")]
    const TRANSITION_APPROVE='approve';
    #[Transition(from:[self::PLACE_NEW], to: self::PLACE_REJECTED, guard: "is_granted('ROLE_ADMIN')")]
    const TRANSITION_REJECT='reject';

    #[Transition(from:[self::PLACE_NEW, self::PLACE_APPROVED], to: self::PLACE_WITHDRAWN, guard: "is_granted('ROLE_USER')")]
    const TRANSITION_WITHDRAW='withdrawn';

    #[Transition(from:[self::PLACE_REJECTED, self::PLACE_APPROVED], to: self::PLACE_NEW)]
    const TRANSITION_RESET='reset';

}
```

Now create a separate `SubmissionWorkflow` service/listener that uses these constants and acts on workflow events. The definition class stays declarative; the workflow class owns behavior.



```bash
symfony new workflow-demo  --webapp --php=8.4 && cd workflow-demo 
composer config extra.symfony.allow-contrib true
bin/console importmap:require d3-graphviz

composer config minimum-stability beta
bin/console make:controller d3 -i
symfony server:start -d
symfony open:local --path=/d3



../survos/bin/lb.sh workflow-helper
# composer req survos/state-bundle
bin/console make:controller d3 -i
cat > templates/d3  .html.twig <<END
{% extends 'base.html.twig' %}

{% block body %}
workflow here.

{% endblock %}
END
symfony server:start -d
symfony open:local --path=/d3

```

## Notes

Since the workflow may use a message bus, a reminder on how to configure that with the Symfony CLI: https://symfony.com/doc/current/setup/symfony_server.html#symfony-server_configuring-workers

https://github.com/survos/SurvosWorkflowHelperBundle/network/dependents
https://github.com/codereviewvideos/symfony-workflow-example

### Workflow diagram assets

For existing AssetMapper applications (including applications linked to mono), run
`php bin/console importmap:require 'd3-graphviz@^5.6'` to install the renderer and
its dependencies. New Flex installations read this dependency from
`assets/package.json` under `symfony.importmap`. Enable the `workflow` controller
under `@survos/state-bundle` in `assets/controllers.json`.

The diagram mounts through Stimulus, including after Turbo navigation. Rendering
errors display a message instead of leaving an empty card; places and transitions
remain available beside the diagram.

### Guard labels and diagram navigation

Use `guardLabel` on a transition to explain its guard in plain language:

```php
#[Transition(
    from: self::PLACE_PHP_OKAY,
    to: self::PLACE_SYMFONY_OKAY,
    guard: "subject.type == 'symfony-bundle' and subject.hasValidSymfonyVersion",
    guardLabel: 'Symfony 8 compatible bundle',
)]
public const TRANSITION_SYMFONY_OKAY = 'symfony_okay';
```

The diagram displays the label in italics beneath the transition name. If no label
is supplied, it falls back to a compact expression: `subject.` is omitted and
logical operators use `&&`, `||`, and `!`. Neither presentation changes the guard
that Symfony evaluates. The original expression remains available on hover and
in the selected state's transition details. Keep labels accurate when changing
expressions; labels are documentation, not executable conditions.

The attribute config builder retains the guard at the top level for execution
and copies it into transition metadata for the diagram. `guardLabel` is metadata
only.

Select a state to highlight its incoming and outgoing paths, or filter the state
list to find it. Drag the diagram to pan. Hold **Option on Mac / Alt elsewhere**
while scrolling to zoom around the pointer; trackpad pinch is supported through
Ctrl+wheel events. Ordinary scrolling still scrolls the page. The **+ / −** buttons
zoom around the center, **Fit** restores the original view, and **Show all** clears
the selected state and filter.
