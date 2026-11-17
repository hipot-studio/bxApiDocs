<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\ActionFilter;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Feature\RegularTasksSectionFeature;

/**
 * Гейт раздела «Регулярные задачи» по релизному флагу раздела
 * ({@see RegularTasksSectionFeature}). Флаг off ≡ действия раздела недоступны.
 */
class RegularTasksSectionEnabled extends Base
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		if (Feature::isEnabled(RegularTasksSectionFeature::class))
		{
			return null;
		}

		$this->addError(
			new Error((string)Loc::getMessage('TASKS_V2_REGULAR_TASKS_SECTION_DISABLED')),
		);

		return new EventResult(type: EventResult::ERROR, handler: $this);
	}
}
