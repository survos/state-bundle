<?php

namespace Survos\StateBundle\Traits;

use Survos\StateBundle\Message\TransitionMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;
use function Symfony\Component\String\u;

trait HandleTransitionsTrait
{
    public function handleTransitionButtons(
        ?WorkflowInterface $workflow=null,
        ?string $transition=null,
        ?MarkingInterface $entity=null,
        ?MessageBusInterface $bus = null,
    ): ?string {
        if (!$transition) {
            return null;
        }
        // @todo: make a constant and inject into twig
        // reset does NOT run any other services, just the marking
        if ($transition === '_reset') {
            $entity->setMarking(null); // force reset
            $workflow->getMarking($entity);
            return "marking has been reset to " . $entity->getMarking();
        }
        $flashMessage = null;
        // use message handler instead.
        $suffix = '_async';
        if (u($transition)->endsWith($suffix) && $bus) {
            // NOT trim($transition, $suffix): trim's second argument is a character
            // LIST, not a suffix, so it strips any of _ a s y n c from both ends.
            // 'enrich_async' survived that by luck; 'scan_async' came out as '' --
            // every letter of "scan" is in "_async" -- and dispatched a nameless
            // transition. u()->beforeLast() removes the actual suffix.
            $tName = (string) u($transition)->beforeLast($suffix);

            //            $transitions  = $workflow->getDefinition()->getTransitions();
            //            $t = current(array_filter($transitions , fn(Transition $transition) => $transition->getName() === $tName));

            //            $messageClass = $workflow
            //                    ->getMetadataStore()
            //                    ->getTransitionMetadata($t)['messageClass'];
            //            ;
            //            $message = new $messageClass($entity->getId());
            $message = (new TransitionMessage($entity->getId(), $entity::class, $tName));
            $envelope = $bus->dispatch($message);
            //            dd($envelope, $message);
            $flashMessage = $message::class . ' has been dispatched, ' . $tName . " will happen in the message handler.";
        } else {
            assert($workflow->can($entity, $transition), sprintf("%s cannot apply transition %s from %s", $entity, $transition, $entity->getMarking()));
            $workflow->apply($entity, $transition);
            $flashMessage = $transition . ' has been applied.';
        }
        return $flashMessage;
    }

    public function dispatchMessage(WorkflowInterface $workflow, $transition, MarkingInterface $entity)
    {
        assert($workflow->can($entity, $transition), sprintf("%s cannot apply transition %s from %s", $entity, $transition, $entity->getMarking()));
        $workflow->apply($entity, $transition);
    }
}
