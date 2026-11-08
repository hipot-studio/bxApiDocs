<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

use Bitrix\Landing\AI\SiteBuilder\Dto\ModerationVerdictDto;
use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Integration\AiAssistant\AiSiteChangeGenerationHelper;
use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;

/**
 * What the failed branch of a use case has already read about the failure: the errored step of the
 * generation and the blocking moderation verdict of that very step.
 *
 * The two are one fact, not two: the verdict only means anything for the moderation step of the
 * flow (DTO-03), and the step id only classifies a moderation refusal together with the verdict
 * that carries its code. While they travelled as two optional arguments, "pass both together, or
 * neither" was a docblock rule, and handing over the moderation step id alone silently produced a
 * moderation refusal with no error code and the static step text instead of the verdict message.
 * Here the pair cannot be halved: both halves are required to build it, and a consumer either gets
 * the whole context or gets nothing and resolves the failure itself.
 *
 * A null verdict inside a context is a real state, not a forgotten argument: it means the blocking
 * verdict could not be read, and the consumers answer for it exactly as the persisted-read path
 * does.
 *
 * The two factories differ only by the moderation step id of their flow - 15 for create, 25 for
 * change - which is why each of them asks its own helper for the verdict.
 */
final readonly class FailedGenerationContextDto
{
	public function __construct(
		public int $erroredStepId,
		public ?ModerationVerdictDto $moderationVerdict,
	)
	{
	}

	/**
	 * Context of a failed create generation, read once for the reaction, the reason text and the
	 * classification.
	 */
	public static function forCreateGeneration(Generation $generation): self
	{
		$generationId = (int)($generation->getId() ?? 0);
		$erroredStepId = AiSiteGenerationHelper::resolveErroredStepId($generationId);

		return new self(
			erroredStepId: $erroredStepId,
			moderationVerdict: AiSiteGenerationHelper::resolveModerationVerdictForErroredStep(
				$generationId,
				$erroredStepId,
			),
		);
	}

	/**
	 * Same context for a failed change generation: the same errored-step query, the change flow's own
	 * moderation step.
	 */
	public static function forChangeGeneration(Generation $generation): self
	{
		$generationId = (int)($generation->getId() ?? 0);
		$erroredStepId = AiSiteGenerationHelper::resolveErroredStepId($generationId);

		return new self(
			erroredStepId: $erroredStepId,
			moderationVerdict: AiSiteChangeGenerationHelper::resolveModerationVerdictForErroredStep(
				$generationId,
				$erroredStepId,
			),
		);
	}
}
