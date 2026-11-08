<?php

declare(strict_types=1);

namespace Bitrix\Crm\Agent\Copilot;

use Bitrix\Crm\Agent\AgentBase;
use Bitrix\Crm\Copilot\CallAssessment\Backfill\BackfillService;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\CallScoringV2;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\Analytics\Builder\CallAssessment\PrefillCriteriaEvent;
use CTimeZone;

final class CallScoringV2BackfillAgent extends AgentBase
{
	private const AGENT_CONTINUE = true;
	private const AGENT_STOP = false;

	public const AGENT_NAME = 'Bitrix\\Crm\\Agent\\Copilot\\CallScoringV2BackfillAgent::run();';

	public static function doRun(): bool
	{
		if (!Feature::enabled(CallScoringV2::class) || !AIManager::isCallScoringV2BackfillPending())
		{
			return self::AGENT_STOP;
		}

		if (!AIManager::isAvailable())
		{
			return self::AGENT_CONTINUE;
		}

		$service = new BackfillService();
		$completed = $service->tick();

		if ($completed)
		{
			(new PrefillCriteriaEvent())->buildEvent()->send();

			// the guard of the preparation is what publishes the enabled state here, so the whole activation is due
			(new CallScoringV2())->activateAfterDataPreparation();

			return self::AGENT_STOP;
		}

		return self::AGENT_CONTINUE;
	}

	public static function register(): void
	{
		if (\CAgent::GetList([], ['NAME' => self::AGENT_NAME])->Fetch())
		{
			return;
		}

		\CAgent::AddAgent(
			self::AGENT_NAME,
			'crm',
			'N',
			60,
			'',
			'Y',
			\ConvertTimeStamp(time() + CTimeZone::GetOffset() + 60, 'FULL'),
		);
	}
}
