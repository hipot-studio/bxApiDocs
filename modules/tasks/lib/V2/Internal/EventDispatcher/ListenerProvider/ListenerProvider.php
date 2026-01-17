<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventDispatcher\ListenerProvider;

use Bitrix\Tasks\V2\Psr\EventDispatcher\ListenerProviderInterface;

class ListenerProvider implements ListenerProviderInterface
{
	public function __construct(
		private readonly AttributesListenerProvider $internalListenerProvider,
		private readonly ConfigurationListenerProvider $externalListenerProvider,
	)
	{
		$externalListenerProvider->useConfigurationKey('events');
	}

	public function getListenersForEvent(object $event): iterable
	{
		yield from $this->internalListenerProvider->getListenersForEvent($event);
		yield from $this->externalListenerProvider->getListenersForEvent($event);
	}
}
