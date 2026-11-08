<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

use Bitrix\BIConnector\Integration\Superset\Model;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Digest\Provider\DigestSetupPortalConditions;
use Bitrix\BIConnector\Internal\Digest\Provider\DigestSetupRightChecker;

/**
 * Whether the item "Set up an AI digest" is offered, asked on every render of the grid, the report screen and
 * the onboarding. Only cheap checks are made here; the system template is resolved in full on launch.
 */
final class DigestSetupAvailabilityService
{
	public const SYSTEM_CODE = 'bitrix_ai_bi_dashboard';

	private readonly DigestSetupSubscriptionService $subscriptionService;
	private readonly DigestSetupPortalConditions $portalConditions;
	private readonly DigestSetupRightChecker $rightChecker;

	private ?DigestSetupAvailability $portalAvailability = null;
	private ?int $portalUserId = null;

	public function __construct(
		?DigestSetupSubscriptionService $subscriptionService = null,
		?DigestSetupPortalConditions $portalConditions = null,
		?DigestSetupRightChecker $rightChecker = null,
	)
	{
		$this->subscriptionService = $subscriptionService ?? new DigestSetupSubscriptionService();
		$this->portalConditions = $portalConditions ?? new DigestSetupPortalConditions();
		$this->rightChecker = $rightChecker ?? new DigestSetupRightChecker();
	}

	public function check(?Model\Dashboard $dashboard, int $userId): DigestSetupAvailability
	{
		// Older bizproc has no system AI agent templates, the biconnector update does not require a newer one
		if (
			!\Bitrix\Main\Loader::includeModule('bizproc')
			|| !class_exists(\Bitrix\Bizproc\Public\Service\AiAgent\SystemTemplateSetupAvailabilityService::class)
		)
		{
			return new DigestSetupAvailability(DigestSetupAvailability::HIDDEN);
		}

		$portalAvailability = $this->checkPortal($userId);
		if (
			$portalAvailability->state === DigestSetupAvailability::HIDDEN
			|| ($dashboard !== null && !$this->isDashboardAvailable($dashboard, $userId))
		)
		{
			return new DigestSetupAvailability(DigestSetupAvailability::HIDDEN);
		}

		return $portalAvailability;
	}

	/**
	 * The portal half of the answer does not depend on the report, while a grid asks for every row: one
	 * instance of the service answers it once per user and keeps the report checks per row. The composition
	 * root shares the instance, so the grid, the report screen and the onboarding of one hit ask it once.
	 */
	private function checkPortal(int $userId): DigestSetupAvailability
	{
		if ($this->portalAvailability !== null && $this->portalUserId === $userId)
		{
			return $this->portalAvailability;
		}

		$this->portalUserId = $userId;
		$this->portalAvailability = $this->resolvePortalAvailability($userId);

		return $this->portalAvailability;
	}

	private function resolvePortalAvailability(int $userId): DigestSetupAvailability
	{
		if (
			$userId <= 0
			|| !$this->portalConditions->isCloudPortal()
			|| !$this->portalConditions->isBuilderEnabled()
			|| !$this->portalConditions->isAiEnabled()
			|| !$this->portalConditions->isBiReady()
			|| !$this->portalConditions->isSystemTemplateAvailable(self::SYSTEM_CODE, $userId)
		)
		{
			return new DigestSetupAvailability(DigestSetupAvailability::HIDDEN);
		}

		if ($this->subscriptionService->isRequired())
		{
			return new DigestSetupAvailability(
				DigestSetupAvailability::SUBSCRIPTION_REQUIRED,
				DigestSetupSubscriptionService::PROMOTER_CODE,
			);
		}

		return new DigestSetupAvailability(DigestSetupAvailability::READY);
	}

	private function isDashboardAvailable(Model\Dashboard $dashboard, int $userId): bool
	{
		return in_array(
			$dashboard->getStatus(),
			[
				SupersetDashboardTable::DASHBOARD_STATUS_READY,
				SupersetDashboardTable::DASHBOARD_STATUS_DRAFT,
			],
			true,
		)
			&& ($dashboard->getExternalId() ?? 0) > 0
			&& $this->rightChecker->canViewDashboard($userId, $dashboard)
		;
	}
}
