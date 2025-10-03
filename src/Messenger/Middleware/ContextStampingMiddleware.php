<?php
declare(strict_types=1);

namespace Survos\StateBundle\Messenger\Middleware;

use Survos\StateBundle\Messenger\Contract\ContextStampProviderInterface;
use Survos\StateBundle\Messenger\Stamp\ContextStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * If a dispatched message implements ContextStampProviderInterface, attach ContextStamp(s).
 * Idempotent: if a ContextStamp already exists, we do NOT add duplicates.
 */
final class ContextStampingMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        if ($message instanceof ContextStampProviderInterface) {
            $payload = $message->getContextStamp();

            $existing = $envelope->all(ContextStamp::class);
            $already = [];
            foreach ($existing as $s) {
                /** @var ContextStamp $s */
                $already[$s->key.':'.$s->value] = true;
            }

            $toAdd = [];
            if (is_string($payload) || is_int($payload)) {
                $toAdd[] = new ContextStamp($payload);
            } elseif (is_array($payload)) {
                // numeric array → values under default key 'context'
                if (array_is_list($payload)) {
                    foreach ($payload as $val) {
                        if (is_string($val) || is_int($val)) {
                            $toAdd[] = new ContextStamp($val);
                        }
                    }
                } else {
                    // assoc array → key/value pairs
                    foreach ($payload as $key => $val) {
                        if ((is_string($key) && (is_string($val) || is_int($val)))) {
                            $toAdd[] = new ContextStamp($val, $key);
                        }
                    }
                }
            }

            foreach ($toAdd as $stamp) {
                $k = $stamp->key.':'.$stamp->value;
                if (!isset($already[$k])) {
                    $envelope = $envelope->with($stamp);
                    $already[$k] = true;
                }
            }
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
