<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Markup\MarkupPage;

class TaskInitChangeAiSiteContext extends TaskStep
{
	private const EVENT_CHANGE_AI_SITE_START = 'onChangeAiSiteStart';
	private const SCENARIO_CHANGE_AI_SITE = 'change_ai_site';
	private const EVENT_TYPE_START = 'start';
	private const STEP_CODE_INIT_CONTEXT = 'init_context';

	public function execute(): bool
	{
		parent::execute();

		$landingId = $this->resolveRequiredLandingId();
		$this->assertNotMarkupLanding($landingId);
		$inputLines = $this->resolveRequiredInputLines();

		ChangeAiSiteState::setInput($this->generation, [
			'landingId' => $landingId,
			'input' => $inputLines,
		]);
		$this->siteData->setLandingId($landingId);
		ChangeAiSiteState::resetRuntimeData($this->generation);
		$this->sendStartEventIfRequired($landingId, $inputLines);
		$this->changed = true;

		return true;
	}

	private function sendStartEventIfRequired(int $landingId, array $inputLines): void
	{
		if (ChangeAiSiteState::isStartEventSent($this->generation))
		{
			return;
		}

		try
		{
			$this->getEvent()
				->setLandingId($landingId)
				->send(self::EVENT_CHANGE_AI_SITE_START, [
					'scenario' => self::SCENARIO_CHANGE_AI_SITE,
					'eventType' => self::EVENT_TYPE_START,
					'stepCode' => self::STEP_CODE_INIT_CONTEXT,
					'input' => array_values($inputLines),
				])
			;
		}
		catch (\Throwable)
		{
			return;
		}

		ChangeAiSiteState::markStartEventSent($this->generation);
	}

	/**
	 * Markup package pages are excluded from change_ai_site: the re-markup pipeline
	 * would destroy the custom source. Edits go through the package intake only.
	 */
	private function assertNotMarkupLanding(int $landingId): void
	{
		if (MarkupPage::isMarkupLanding($landingId))
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'ChangeAiSite is not available on a markup package page.',
			);
		}
	}

	private function resolveRequiredLandingId(): int
	{
		$landingId = ChangeAiSiteState::resolveLandingId($this->generation);
		if ($landingId > 0)
		{
			return $landingId;
		}

		throw new GenerationException(
			GenerationErrors::dataValidation,
			'ChangeAiSite landingId is empty.',
		);
	}

	private function resolveRequiredInputLines(): array
	{
		$inputLines = ChangeAiSiteState::resolveInputLines($this->generation);
		if ($inputLines !== [])
		{
			return $inputLines;
		}

		throw new GenerationException(
			GenerationErrors::dataValidation,
			'ChangeAiSite input is empty.',
		);
	}
}
