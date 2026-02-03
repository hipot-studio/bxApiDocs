<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Reducer;

use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\RecipientsResolver;

class ExcludeDndUsers implements ReducerInterface
{
	public function __invoke(RecipientsResolver $context): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$context->recipients = $context->recipients->filter(
			function (Entity\User $user): bool
			{
				$status = \CIMStatus::GetStatus($user->getId());
				
				if (null === $status)
				{
					return false;
				}

				if (!isset($status['STATUS']))
				{
					return false;
				}

				return $status['STATUS'] !== 'dnd';
			}
		);
	}
}
