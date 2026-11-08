<?php

namespace Bitrix\StaffTrack\Access\Rule;

use Bitrix\Main\Access\AccessibleController;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\StaffTrack\Access\OfficeCustody;

/**
 * The one verdict behind every office write action: the caller belongs to the circle of custody or does
 * not. The office itself takes no part in it. The concrete rules stay empty - the factory of the core
 * finds them by the name of the action.
 */
abstract class AbstractOfficeRule extends AbstractRule
{
	private ?OfficeCustody $officeCustody;

	public function __construct(AccessibleController $controller, ?OfficeCustody $officeCustody = null)
	{
		parent::__construct($controller);

		$this->officeCustody = $officeCustody;
	}

	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		return $this->getOfficeCustody()->isMember($this->user->getUserId());
	}

	/**
	 * The factory of the core builds a rule from the controller alone, so the predicate shared within the
	 * request is taken here when the caller handed none in.
	 */
	protected function getOfficeCustody(): OfficeCustody
	{
		return $this->officeCustody ??= OfficeCustody::getInstance();
	}
}
