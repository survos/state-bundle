<?php
declare(strict_types=1);

namespace Survos\StateBundle\Messenger\Contract;

/**
 * Implement this on any Message (or on a Subject wrapper message) to provide context values
 * that should land in a ContextStamp on dispatch.
 *
 * Return formats accepted:
 *  - string:        'euro'             → key defaults to 'context'
 *  - array<string,string|int>: ['agg' => 'euro', 'inst' => 'smithsonian']
 *  - array<int,string>:       ['euro','ddb']  (treated as multiple values, key='context')
 */
interface ContextStampProviderInterface
{
    /** @return string|array<string,string|int>|array<int,string> */
    public function getContextStamp();
}
