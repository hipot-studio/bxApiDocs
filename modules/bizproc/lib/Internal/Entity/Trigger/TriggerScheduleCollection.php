<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Trigger;

use Bitrix\Bizproc\Internal\Entity\BaseEntityCollection;

/**
 * @method TriggerSchedule|null getFirstCollectionItem()
 * @method \ArrayIterator<TriggerSchedule> getIterator()
 */
class TriggerScheduleCollection extends BaseEntityCollection
{
	public function __construct(TriggerSchedule ...$triggerSchedules)
	{
		foreach ($triggerSchedules as $triggerSchedule)
		{
			$this->collectionItems[] = $triggerSchedule;
		}
	}
}