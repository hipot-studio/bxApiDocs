<?php

namespace Bitrix\Mobile\Internal\Onboarding\Conditions;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Mobile\Internal\Onboarding\Checkers\PortalAgeChecker;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

/**
 * Two conditions of the portal, and they answer to different scopes.
 *
 * Its creation date has to be known at all: every series counts its days from that date, and
 * without it there is no schedule to run - this stops every region.
 *
 * Its age, on the other hand, is a condition of entering the campaign, not a condition of every
 * send. The series of a campaign region lasts as long as the user keeps completing steps
 * (AC-022), so a gate asked on every pass would cut a running series in the middle - the
 * colleague completes a step and gets nothing back. A campaign region therefore asks the age of
 * each user before their first push (OnboardingService::canEnterCampaign), and the pass of the
 * portal is not stopped by it. A legacy region has no such notion: its series is a fixed week of
 * absolute days, and the portal-wide gate is what it always ran on.
 */
readonly class PortalAgeCondition implements PushSendCondition
{
	public const ERROR_DATE_UNDEFINED = 'Portal creation date is undefined';
	public const ERROR_TOO_OLD = 'Portal too old';

	public const CODE_DATE_UNDEFINED = 'portal_date_undefined';
	public const CODE_TOO_OLD = 'portal_too_old';

	public function __construct(private PortalAgeChecker $portalAgeChecker)
	{}

	public function check(RegionConfig $config): Result
	{
		$result = new Result();

		if (!$this->portalAgeChecker->isPortalDateKnown())
		{
			return $result->addError(new Error(self::ERROR_DATE_UNDEFINED, self::CODE_DATE_UNDEFINED));
		}

		if (!$config->isCampaignDriven() && !$this->portalAgeChecker->isEligible($config->getMaxPortalAgeDays()))
		{
			$result->addError(new Error(self::ERROR_TOO_OLD, self::CODE_TOO_OLD));
		}

		return $result;
	}
}
