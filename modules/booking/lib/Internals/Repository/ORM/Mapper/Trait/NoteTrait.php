<?php

namespace Bitrix\Booking\Internals\Repository\ORM\Mapper\Trait;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\WaitListItem\WaitListItem;
use Bitrix\Booking\Internals\Model\EO_Booking;
use Bitrix\Booking\Internals\Model\EO_WaitListItem;

trait NoteTrait
{
	private function setNote(Booking|WaitListItem $entity, EO_Booking|EO_WaitListItem $ormEntity): void
	{
		$ormNote = $ormEntity->getNote();
		if ($ormNote === null)
		{
			return;
		}

		$entity->setNote($ormNote->getDescription());
	}

	private function setClientNote(Booking|WaitListItem $entity, EO_Booking|EO_WaitListItem $ormEntity): void
	{
		$ormNote = $ormEntity->getClientNote();
		if ($ormNote === null)
		{
			return;
		}

		$entity->setClientNote($ormNote->getDescription());
	}
}
