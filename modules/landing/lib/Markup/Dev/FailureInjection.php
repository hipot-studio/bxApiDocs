<?php

declare(strict_types=1);

namespace Bitrix\Landing\Markup\Dev;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateMarkupSite;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Copilot\Services\AiSiteDevBypassService;
use Bitrix\Main\Config\Option;

/**
 * Failures of the markup scenario on demand, for the stand cases of the checklist that no real package
 * provokes: a technical failure before and after the site is created and during an edit, an exhausted
 * disk quota of the build, an exhausted AI request limit on the moderation step.
 *
 * Every option is read only while the dev bypass option of the portal is on
 * (AiSiteDevBypassService::OPTION_ENABLED). The gate is that option, not the environment: a portal
 * without the dev bypass ignores the failure options whatever their values are, and only an administrator,
 * who owns the module options, can turn the bypass and the failures on.
 */
class FailureInjection
{
	/**
	 * Step code of the markup scenario (getStepMeta) on whose entry the step fails as a technical failure.
	 */
	public const OPTION_FAIL_STEP = 'landing_markup_dev_fail_step';

	/**
	 * 'Y': the file store refuses every asset of the build as the exhausted disk quota does.
	 */
	public const OPTION_DISK_QUOTA = 'landing_markup_dev_disk_quota';

	/**
	 * 'Y': the moderation step answers as the preflight check of an exhausted AI request limit does.
	 */
	public const OPTION_AI_LIMIT = 'landing_markup_dev_ai_limit';

	/**
	 * The slider of the limit is read from the text, as from the text of a real limit refusal.
	 */
	public const AI_LIMIT_TEXT = 'Dev failure injection: the AI request limit is exhausted. '
		. '/online/?FEATURE_PROMOTER=limit_copilot_requests';

	private const OPTION_ON = 'Y';

	public function isActive(): bool
	{
		return $this->readOption(AiSiteDevBypassService::OPTION_ENABLED) === self::OPTION_ON;
	}

	/**
	 * Fails the entry of a markup scenario step when an option asks for it: the step named by the fail
	 * option with a technical failure, the moderation step with an exhausted limit.
	 * @throws GenerationException
	 */
	public function raiseOnStep(Generation $generation, int $stepId): void
	{
		$scenario = $generation->getScenario();
		if (!$scenario instanceof CreateMarkupSite || !$this->isActive())
		{
			return;
		}

		if ($stepId === $scenario->getModerationStepId() && $this->isOn(self::OPTION_AI_LIMIT))
		{
			$this->fail($generation, new GenerationException(
				GenerationErrors::requestQuotaExceeded,
				self::AI_LIMIT_TEXT,
				['errorText' => self::AI_LIMIT_TEXT],
			));
		}

		$stepCode = (string)($scenario->getStepMeta()[$stepId]['code'] ?? '');
		if ($stepCode !== '' && $stepCode === trim($this->readOption(self::OPTION_FAIL_STEP)))
		{
			$this->fail($generation, new GenerationException(
				GenerationErrors::default,
				'Dev failure injection on the step "' . $stepCode . '"',
			));
		}
	}

	public function isDiskQuotaExhausted(): bool
	{
		return $this->isActive() && $this->isOn(self::OPTION_DISK_QUOTA);
	}

	protected function readOption(string $name): string
	{
		return (string)Option::get('landing', $name, '', '');
	}

	private function isOn(string $name): bool
	{
		return $this->readOption($name) === self::OPTION_ON;
	}

	/**
	 * Not every step of the map records its failure text itself; the answers of the tools show this one.
	 * @throws GenerationException
	 */
	private function fail(Generation $generation, GenerationException $exception): never
	{
		(new MarkupSiteState($generation))->setLastError($exception->getMessage());

		throw $exception;
	}
}
