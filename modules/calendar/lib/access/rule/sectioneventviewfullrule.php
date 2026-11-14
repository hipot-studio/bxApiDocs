<?php

namespace Bitrix\Calendar\Access\Rule;

use Bitrix\Calendar\Access\Model\SectionModel;
use Bitrix\Calendar\Access\Model\TypeModel;
use Bitrix\Calendar\Access\Rule\Traits\ExtranetUserTrait;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Calendar\Access\ActionDictionary;
use Bitrix\Calendar\Access\NativeReservationOperations;
use Bitrix\Calendar\Access\Rule\Traits\CurrentUserTrait;
use Bitrix\Calendar\Access\Rule\Traits\SectionTrait;
use CCalendarSect;

class SectionEventViewFullRule extends \Bitrix\Main\Access\Rule\AbstractRule
{
	use SectionTrait, CurrentUserTrait, ExtranetUserTrait;

	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		if (!$item instanceof SectionModel)
		{
			return false;
		}

		if ($params instanceof NativeReservationOperations)
		{
			$params->assertSection($item, $this->user->getUserId());
		}

		if ($item->getType() === Dictionary::CALENDAR_TYPE['open_event'])
		{
			return true;
		}

		if (!$this->hasCurrentUser())
		{
			return true;
		}

		if (!$this->canSeeOwnerIfExtranetUser($item, $this->user))
		{
			return false;
		}

		if ($this->user->isAdmin() || $this->user->isSocNetAdmin($item->getType()))
		{
			return true;
		}

		if ($this->isOwner($item, $this->user->getUserId()) || $this->isManager($item, $this->user->getUserId()))
		{
			return true;
		}

		$type = TypeModel::createFromSectionModel($item);
		$typeCheck = true;

		if ($item->getType() !== \Bitrix\Calendar\Core\Event\Tools\Dictionary::CALENDAR_TYPE['resource'])
		{
			$typeCheck = $this->controller->check(
				ActionDictionary::ACTION_TYPE_VIEW, $type,
				$params instanceof NativeReservationOperations ? $params : null,
			);
		}

		return
			$typeCheck
			&& in_array(
				ActionDictionary::getOldActionKeyByNewActionKey(ActionDictionary::ACTION_SECTION_EVENT_VIEW_FULL),
				$params instanceof NativeReservationOperations
					? $params->getSectionOperations($item, $this->user->getUserId())
					: CCalendarSect::GetOperations($item->getId(), $this->user->getUserId()),
				true
			);
	}
}
