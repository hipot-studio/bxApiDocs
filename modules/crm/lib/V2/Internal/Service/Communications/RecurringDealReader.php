<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Communications;

use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use CCrmOwnerType;

/**
 * Whether a deal is a template of the deals to come rather than a deal that happened.
 *
 * Asked about a deal named by the identifier alone. The deals of a client need no such question: the
 * selection that reads them drops the templates by a condition of the query, so one never reaches the
 * history that way.
 *
 * @internal
 */
class RecurringDealReader
{
	/**
	 * One row of the primary key and one column of it - the cheapest read that answers the question.
	 *
	 * The flag arrives as the storage keeps it and not as a boolean: a row fetched into an array carries
	 * the value of the column, and only an objectified read casts a `BooleanField` to `true` or `false`.
	 *
	 * A deal that is not there is no template: the gate of the rights has already refused whoever asked
	 * about it.
	 */
	public function isRecurring(int $dealId): bool
	{
		$factory = Container::getInstance()->getFactory(CCrmOwnerType::Deal);
		if ($factory === null)
		{
			return false;
		}

		$dataClass = $factory->getDataClass();
		$deal = $dataClass::getRow([
			'select' => [Item::FIELD_NAME_IS_RECURRING],
			'filter' => ['=' . Item::FIELD_NAME_ID => $dealId],
		]);

		return $deal !== null && ($deal[Item::FIELD_NAME_IS_RECURRING] ?? null) === 'Y';
	}
}
