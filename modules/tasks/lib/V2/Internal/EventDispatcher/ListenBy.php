<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventDispatcher;

#[\Attribute(\Attribute::TARGET_CLASS)]
class ListenBy
{
	/**
	 * @var array<class-string<ListenerInterface>|string|callable|array{0:class-string<ListenerInterface>|string|callable, 1: int}>
	 */
	public readonly array $listeners;

	/**
	 * @param class-string<ListenerInterface>|string|callable|array{0:class-string<ListenerInterface>|string|callable, 1: int} ...$listeners
	 */
	public function __construct(array|string|callable ...$listeners)
	{
		$this->listeners = $listeners;
	}
}
