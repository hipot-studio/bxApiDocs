<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm;

use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;

class DealDataProvider
{
	public function getByIds(array $dealIds): array
	{
		if (empty($dealIds))
		{
			return [];
		}

		$dealFactory = Container::getInstance()->getFactory(\CCrmOwnerType::Deal);
		if (!$dealFactory)
		{
			return [];
		}

		$deals = $dealFactory->getItems([
			'select' => [
				Item::FIELD_NAME_ID,
				Item::FIELD_NAME_OPPORTUNITY,
				Item::FIELD_NAME_CURRENCY_ID,
				Item::FIELD_NAME_CREATED_TIME,
				Item::FIELD_NAME_COMPANY,
				Item::FIELD_NAME_CONTACT_IDS,
			],
			'filter' => [
				'=ID' => $dealIds,
			],
		]);

		$result = [];
		foreach ($deals as $deal)
		{
			$result[$deal->getId()] = $deal;
		}

		return $result;
	}
}
