<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;

/**
 * Binding reaction to a terminally failed create-flow generation, shared by both entry points of that
 * flow: the start handler and the status handler answer the same failure, so they must react to it
 * the same way, and the decision lives here once instead of in a copy per handler.
 *
 * Create-only: the edit flow reserves no binding, so it has nothing to react to.
 */
final class AiSiteFailedGenerationReaction
{
	private AiSiteBindingService $bindingService;

	public function __construct(?AiSiteBindingService $bindingService = null)
	{
		$this->bindingService = $bindingService ?? new AiSiteBindingService();
	}

	/**
	 * Reacts on the binding of a failed generation, before the failed answer is built.
	 *
	 * A moderation block frees the reservation and counts against the draft; any other terminal
	 * failure only frees it, and only when nothing was built - a failure that left a recoverable
	 * draft keeps the reservation, so the draft the agent is told about stays owned by this chat.
	 *
	 * Runs on every poll of the failed generation; the release is keyed on the generation id, so the
	 * calls after the first change nothing.
	 *
	 * @param int|null $erroredStepId Errored step already resolved by the caller of the failed branch;
	 *                                null makes this reaction resolve it on its own.
	 * @param bool $hasRecoverableDraft Recoverable-draft verdict of the caller: the answer names the
	 *                                  draft to the agent exactly when the reservation is kept for it,
	 *                                  so the verdict is asked of the handler that builds that answer.
	 */
	public function react(Generation $generation, ?int $erroredStepId, bool $hasRecoverableDraft): void
	{
		$generationId = (int)($generation->getId() ?? 0);
		$userId = $generation->getAuthorId();

		// Only a step that has actually refused the topic spends a consecutive block of the draft: a
		// moderation step that could not perform its check at all must free the draft like any other
		// technical failure, not count against the three blocks the chat is allowed.
		$isModerationBlock = $erroredStepId === null
			? AiSiteGenerationHelper::isModerationBlockError($generation)
			: AiSiteGenerationHelper::isModerationBlockRefusal(
				$generation,
				$erroredStepId,
				AiSiteGenerationHelper::resolveModerationVerdictForErroredStep($generationId, $erroredStepId),
			)
		;
		if ($isModerationBlock)
		{
			$this->bindingService->registerModerationBlock($generationId, $userId);

			return;
		}

		if ($hasRecoverableDraft)
		{
			return;
		}

		$this->bindingService->releaseDraftForFailedGeneration($generationId, $userId);
	}
}
