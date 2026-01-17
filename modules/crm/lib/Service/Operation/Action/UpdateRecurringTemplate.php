<?php

namespace Bitrix\Crm\Service\Operation\Action;

use Bitrix\Crm\Component\EntityDetails\TimelineMenuBar\Communications;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Multifield\Type\Email;
use Bitrix\Crm\Recurring\Entity\Item\DynamicExist;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Operation\Action;
use Bitrix\Main\Result;

class UpdateRecurringTemplate extends Action
{
	public function process(Item $item): Result
	{
		$result = new Result();

		$factory = Container::getInstance()->getFactory($item->getEntityTypeId());
		if (!$factory?->isRecurringSupported() || !$item->getIsRecurring())
		{
			return $result;
		}

		$recurring = DynamicExist::loadByItemIdentifier(new ItemIdentifier($item->getEntityTypeId(), $item->getId()));

		if ($recurring === null)
		{
			return $result;
		}

		$recurringEmailIds = array_map('intval', $recurring->getField('EMAIL_IDS') ?? []);
		if (empty($recurringEmailIds))
		{
			return $result;
		}

		$communications = (new Communications($item->getEntityTypeId(), $item->getId()))->get(Email::ID);
		$emailIds = [];
		foreach ($communications as $communication)
		{
			$emails = $communication['emails'] ?? [];
			$emailIds = [...$emailIds, ...array_column($emails, 'id')];
		}

		$diff = array_diff($recurringEmailIds, $emailIds);
		if (!empty($diff))
		{
			$intersectEmailIds = array_values(array_intersect($recurringEmailIds, $emailIds));
			$recurring->setField('EMAIL_IDS', $intersectEmailIds);

			$params = $recurring->getField('PARAMS');
			$params['EMAIL_IDS'] = $intersectEmailIds;
			$recurring->setField('PARAMS', $params);

			if (empty($intersectEmailIds))
			{
				$recurring->setField('IS_SEND_EMAIL', 'N');
			}

			$recurring->save();
		}

		return $result;
	}
}
