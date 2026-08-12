<?php
namespace Bitrix\Landing\AI\SiteBuilder\Tailwind;

use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSite;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Model\GenerationsTable;
use Bitrix\Landing\Landing;

final class TailwindRuntimeGenerationSyncService
{
	private TailwindRuntimeStateService $stateService;

	public function __construct(?TailwindRuntimeStateService $stateService = null)
	{
		$this->stateService = $stateService ?? new TailwindRuntimeStateService();
	}

	public function syncByLanding(int $landingId, ?array $runtimeState = null): int
	{
		if ($landingId <= 0)
		{
			return 0;
		}

		$landing = Landing::createInstance($landingId);
		if (!$landing->exist())
		{
			return 0;
		}

		$siteId = (int)$landing->getSiteId();
		if ($siteId <= 0)
		{
			return 0;
		}

		$runtimeState = is_array($runtimeState) ? $runtimeState : $this->stateService->getLandingState($landingId);
		$result = GenerationsTable::query()
			->setSelect(['ID', 'DATA'])
			->where('SITE_ID', '=', $siteId)
			->where('SCENARIO', '=', CreateAiSite::class)
			->exec()
		;

		$updated = 0;
		while ($row = $result->fetch())
		{
			$generationId = (int)($row['ID'] ?? 0);
			if ($generationId <= 0)
			{
				continue;
			}

			$data = is_array($row['DATA']) ? $row['DATA'] : [];
			$data = CreateAiSiteState::applyTailwindRuntimeToData($data, $runtimeState);

			$updateResult = GenerationsTable::update($generationId, [
				'DATA' => $data,
			]);
			if ($updateResult->isSuccess())
			{
				$updated++;
			}
		}

		return $updated;
	}
}
