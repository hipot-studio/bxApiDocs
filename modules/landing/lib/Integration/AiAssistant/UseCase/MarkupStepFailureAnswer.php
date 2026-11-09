<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateMarkupSite;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Copilot\Generation\Step\TaskApplyMarkupPackage;
use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;
use Bitrix\Landing\Integration\AiAssistant\Dto\FailedGenerationContextDto;

/**
 * Step refusal fields of a terminal markup failure (ADR § 2). They travel next to errors[], which
 * keeps its own meaning - the refusal of the intake - so the skill tells a forbidden topic from an
 * exhausted limit and from a technical failure instead of reading one MARKUP_GENERATION_FAILED for
 * all three.
 *
 * Reading state only: the block counter, the binding and the reaction on a failed generation belong
 * to the submission path of the parent feature, never to an answer builder (ADR § 8).
 *
 * The submission and the status poll share the other parts of a markup answer here as well: the step
 * of the job and the warnings of a completed one.
 */
trait MarkupStepFailureAnswer
{
	/**
	 * @return array{errorType: string, errorCode: string|null, error: string, sliderCode: string|null}
	 */
	protected function buildStepFailureFields(Generation $generation, string $details): array
	{
		$context = $this->resolveFailedContext($generation);
		$failure = AiSiteGenerationHelper::resolveFailure($generation, $context);
		$type = (string)($failure['type'] ?? AiSiteGenerationHelper::ERROR_TYPE_TECHNICAL);
		$isTechnical = $type === AiSiteGenerationHelper::ERROR_TYPE_TECHNICAL;

		return [
			'errorType' => $type,
			// a build the file store refused names its reason by code: the skill resubmits a lost asset
			// and asks the user to free the disk on an exhausted quota (ADR § 3.5)
			'errorCode' => $isTechnical
				? (TaskApplyMarkupPackage::getBuildRefusalCode($generation) ?? $failure['code'])
				: $failure['code'],
			// A technical failure speaks with the last error of the markup path: the per-step texts of
			// the helper name steps of the legacy site scenario, which the markup map numbers otherwise.
			'error' => $isTechnical
				? $details
				: $this->resolveFailureText($generation, $context),
			'sliderCode' => AiSiteGenerationHelper::extractLimitError($generation)['sliderCode'] ?? null,
		];
	}

	/**
	 * Reason text of a refusal: the localized phrase of the moderation verdict or the text of the
	 * exhausted limit. Both belong to the helper that owns the failed answer of the legacy flow, so
	 * the markup answers repeat neither of them.
	 */
	protected function resolveFailureText(Generation $generation, FailedGenerationContextDto $context): string
	{
		return AiSiteGenerationHelper::resolveFailureMessage($generation, $context);
	}

	/**
	 * The errored step and the blocking verdict of that step, read once for the classification and
	 * for the reason text. The moderation step is asked of the scenario, so both markup scenarios
	 * answer with their own step id.
	 */
	protected function resolveFailedContext(Generation $generation): FailedGenerationContextDto
	{
		$generationId = (int)($generation->getId() ?? 0);
		$erroredStepId = AiSiteGenerationHelper::resolveErroredStepId($generationId);

		return new FailedGenerationContextDto(
			erroredStepId: $erroredStepId,
			moderationVerdict: AiSiteGenerationHelper::resolveModerationVerdictForErroredStep(
				$generationId,
				$erroredStepId,
			),
		);
	}

	/**
	 * Current step of the job in the answer of a markup tool, so a failure names the step it happened on.
	 * @return array{code: string, label: string}|null
	 */
	protected function resolveStepMeta(Generation $generation): ?array
	{
		$scenario = $generation->getScenario();
		if (!$scenario instanceof CreateMarkupSite)
		{
			return null;
		}

		$meta = $scenario->getStepMeta()[(int)($generation->getStep() ?? 0)] ?? null;
		if (!is_array($meta))
		{
			return null;
		}

		return [
			'code' => (string)($meta['code'] ?? ''),
			'label' => (string)($meta['label'] ?? ''),
		];
	}

	/**
	 * DTO-F2: the warnings of the form steps as {code, token, details}. A warning of the whole package
	 * has no token, it is null; details are always a JSON object.
	 * @return list<array{code: string, token: string|null, details: array|\stdClass}>
	 */
	protected function buildWarnings(Generation $generation): array
	{
		$warnings = [];
		foreach ((new MarkupSiteState($generation))->getWarnings() as $warning)
		{
			$code = (string)($warning['code'] ?? '');
			if ($code === '')
			{
				continue;
			}

			$token = (string)($warning['token'] ?? '');
			$details = is_array($warning['details'] ?? null) ? $warning['details'] : [];
			$warnings[] = [
				'code' => $code,
				'token' => $token !== '' ? $token : null,
				'details' => $details ?: new \stdClass(),
			];
		}

		return $warnings;
	}
}
