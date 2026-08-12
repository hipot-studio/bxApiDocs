<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\AI\SiteBuilder\Tailwind\TailwindRuntimeStateService;
use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\AI\SiteBuilder\Tailwind\TailwindRuntimeInitService;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;

class TaskInitChangeAiSiteTailwindCssHook extends TaskStep
{
	public function execute(): bool
	{
		parent::execute();

		if (!$this->hasChangedBlocks())
		{
			return true;
		}

		$landingId = ChangeAiSiteState::resolveLandingId($this->generation);
		$this->assertLandingId($landingId);

		$tailwindRuntime = $this->initializeTailwindRuntime($landingId);
		ChangeAiSiteState::setTailwindRuntime($this->generation, $tailwindRuntime);
		$this->assertRuntimeInitialized($tailwindRuntime);
		$this->changed = true;

		return true;
	}

	private function hasChangedBlocks(): bool
	{
		$result = ChangeAiSiteState::getResult($this->generation);

		return !empty($result['changedIds']);
	}

	protected function initializeTailwindRuntime(int $landingId): array
	{
		try
		{
			// force=true is used only for runtime/managed-file re-init; custom CSS is preserved by service policy.
			return (new TailwindRuntimeInitService())->initializeLanding($landingId, true);
		}
		catch (GenerationException $exception)
		{
			throw $exception;
		}
		catch (\Throwable $exception)
		{
			$message = trim((string)$exception->getMessage());
			throw new GenerationException(
				GenerationErrors::dataValidation,
				$message !== ''
					? 'Failed to switch landing to Tailwind CSS runtime rebuild mode: ' . $message
					: 'Failed to switch landing to Tailwind CSS runtime rebuild mode.',
			);
		}
	}

	private function assertLandingId(int $landingId): void
	{
		if ($landingId > 0)
		{
			return;
		}

		throw new GenerationException(
			GenerationErrors::dataValidation,
			'Landing ID is required to initialize Tailwind runtime.',
		);
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
