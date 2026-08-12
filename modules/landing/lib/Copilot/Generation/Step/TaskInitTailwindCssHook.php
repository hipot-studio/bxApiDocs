<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\AI\SiteBuilder\Tailwind\TailwindRuntimeInitService;
use Bitrix\Landing\AI\SiteBuilder\Tailwind\TailwindRuntimeStateService;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;

class TaskInitTailwindCssHook extends TaskStep
{
	public function execute(): bool
	{
		parent::execute();

		$landingId = (int)$this->siteData->getLandingId();
		$this->assertLandingId($landingId);

		$tailwindRuntime = (new TailwindRuntimeInitService())->initializeLanding($landingId);

		CreateAiSiteState::setTailwindRuntime($this->generation, $tailwindRuntime);
		$this->assertRuntimeInitialized($tailwindRuntime);
		$this->changed = true;

		return true;
	}

	private function assertLandingId(int $landingId): void
	{
		if ($landingId <= 0)
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Landing ID is required to initialize Tailwind runtime.',
			);
		}
	}

	private function assertRuntimeInitialized(array $runtimeState): void
	{
		$success = !empty($runtimeState['success']);
		$stage = trim((string)($runtimeState['stage'] ?? ''));
		$isSuccessfulStage = in_array($stage, [
			TailwindRuntimeStateService::STAGE_RUNTIME_INITIALIZED,
			TailwindRuntimeStateService::STAGE_CSS_SAVED,
		], true);
		if ($success || $isSuccessfulStage)
		{
			return;
		}

		$error = trim((string)($runtimeState['error'] ?? ''));
		$message = trim((string)($runtimeState['message'] ?? ''));
		$details = array_filter([
			$stage !== '' ? "stage={$stage}" : '',
			$error !== '' ? "error={$error}" : '',
			$message,
		]);
		$reason = implode('; ', $details);

		throw new GenerationException(
			GenerationErrors::dataValidation,
			$reason !== ''
				? 'Tailwind runtime initialization failed: ' . $reason
				: 'Tailwind runtime initialization failed.',
		);
	}
}
