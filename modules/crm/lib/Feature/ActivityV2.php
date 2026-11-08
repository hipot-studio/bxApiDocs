<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature\Category\Activities;
use Bitrix\Crm\Feature\Category\BaseCategory;
use Bitrix\Main\Localization\Loc;

/**
 * Delegation of the legacy write contract of an activity to the lifecycle service of V2.
 *
 * ONE FLAG FOR THE WHOLE DELEGATION. The legacy facade and the pilot of the to-do items share it: two switches
 * would let the portal run one half of the write path on V2 and the other on legacy, which is a state nobody
 * would be able to reason about in production.
 *
 * IT REGISTERS NOTHING WHEN SWITCHED ON. The state of the flag is the core feature engine record and nothing
 * else, so switching it takes effect on the very next operation and switching it back leaves nothing behind.
 * The consistency probe of the observability phase is an agent of the module, registered when the module is
 * installed, not here.
 */
final class ActivityV2 extends AbstractCrmFlag
{
	public function getName(): string
	{
		return Loc::getMessage('CRM_FEATURE_ACTIVITY_V2_NAME');
	}

	public function getCategory(): BaseCategory
	{
		return Activities::getInstance();
	}
}
