<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventDispatcher\ListenerProvider;

use Bitrix\Tasks\V2\Internal\EventDispatcher\ListenBy;
use Bitrix\Tasks\V2\Internal\EventDispatcher\ListenerProvider\AbstractListenerProvider;

class AttributesListenerProvider extends AbstractListenerProvider
{
	protected function resolveListeners(object $event): iterable
	{
		$listeners = array_map(
			fn (\ReflectionAttribute $attribute): string|array|callable => $attribute->newInstance()->listeners,
			(new \ReflectionClass($event::class))->getAttributes(ListenBy::class),
		);

		// Return flattened array.
		return array_merge(...$listeners);
	}
}
