<?php

declare(strict_types=1);

namespace Bitrix\IntranetMobile\ActionFilter;

use Bitrix\IntranetMobile\Qualification\DefaultMobileQualificationPresetAccessChecker;
use Bitrix\IntranetMobile\Qualification\MobileQualificationPresetAccessChecker;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class MobileQualificationPresetAccess extends Base
{
	private readonly MobileQualificationPresetAccessChecker $accessChecker;

	public function __construct(?MobileQualificationPresetAccessChecker $accessChecker = null)
	{
		parent::__construct();

		$this->accessChecker = $accessChecker ?? new DefaultMobileQualificationPresetAccessChecker();
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		if (!$this->accessChecker->isAdmin())
		{
			$this->addError(new Error('Access denied.', 'access_denied'));

			return new EventResult(type: EventResult::ERROR, handler: $this);
		}

		if ($this->accessChecker->isExternalUser())
		{
			$this->addError(new Error('External users cannot apply qualification preset.', 'access_denied'));

			return new EventResult(type: EventResult::ERROR, handler: $this);
		}

		if (!$this->accessChecker->isPortalEligible())
		{
			$this->addError(new Error('Portal is too old.', 'access_denied'));

			return new EventResult(type: EventResult::ERROR, handler: $this);
		}

		return null;
	}
}
