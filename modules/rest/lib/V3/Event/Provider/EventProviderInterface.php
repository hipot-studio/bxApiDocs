<?php

namespace Bitrix\Rest\V3\Event\Provider;

use Bitrix\Rest\V3\Event\EventDescriptor;

interface EventProviderInterface
{
	/**
	 * @return EventDescriptor[]
	 */
	public static function getDescriptors(): array;
}