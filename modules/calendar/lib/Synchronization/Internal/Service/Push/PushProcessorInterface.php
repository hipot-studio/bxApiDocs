<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Internal\Service\Push;

use Bitrix\Calendar\Synchronization\Internal\Entity\Push\Push;
use Bitrix\Calendar\Synchronization\Internal\Exception\PushException;

interface PushProcessorInterface
{
	/**
	 * @throws PushException
	 */
	public function processPush(Push $push): void;
}
