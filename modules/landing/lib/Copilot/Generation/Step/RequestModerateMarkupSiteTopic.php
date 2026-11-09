<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\AI\SiteBuilder\Dto\ModerationVerdictDto;
use Bitrix\Landing\AI\SiteBuilder\Prompt\PromptCodeCatalog;
use Bitrix\Landing\Copilot\Data\Site;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Log;
use Bitrix\Landing\Copilot\Generation\Request;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\RuntimeRequestQuotaProvider;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Copilot\Generation\Type\RequestQuotaDto;
use Bitrix\Landing\Markup\Dev\FailureInjection;
use Bitrix\Landing\Markup\Moderation\PageTextExtractor;
use Bitrix\Landing\Markup\Package;

/**
 * Topic moderation of a markup package: the moderated artifact is the text of the page
 * that is about to become a site, not a user request.
 *
 * The whole text is checked, so the step is multi-pass: the text is cut into chunks that
 * fit the prompt input, one chunk travels per request, and the position lives in the
 * generation state. The step stays unfinished while chunks remain - the Scenarist runs it
 * again, exactly as it does with the repair step. The first non-allow verdict ends the step
 * with a refusal; allow is the answer of the whole page only after the last chunk.
 *
 * Verdict schema, fail-closed invariant and the zero cost of a request are inherited.
 */
class RequestModerateMarkupSiteTopic extends RequestModerateAiSiteTopic implements RuntimeRequestQuotaProvider
{
	protected function getPromptCode(): string
	{
		return PromptCodeCatalog::CREATE_MARKUP_SITE_MODERATION;
	}

	/**
	 * The last request of the step, not the first: a multi-pass step owns several.
	 */
	public function initialize(): void
	{
		$requests = Request::getByGeneration($this->generation->getId(), $this->stepId);
		if ($requests === [])
		{
			return;
		}

		usort($requests, static fn(Request $a, Request $b): int => (int)$a->getId() <=> (int)$b->getId());
		$this->request = array_pop($requests);
	}

	/**
	 * An applied chunk releases the request slot, so the pass that follows sends the next
	 * chunk the ordinary way.
	 */
	public function execute(): bool
	{
		$this->createFailureInjection()->raiseOnStep($this->generation, (int)($this->stepId ?? 0));

		if (
			isset($this->request)
			&& $this->request->isApplied()
			&& !$this->isModerationComplete()
		)
		{
			$this->request = null;
		}

		return parent::execute();
	}

	public function isFinished(): bool
	{
		return parent::isFinished() && $this->isModerationComplete();
	}

	/**
	 * The step is free, but it occupies its place in the preflight sum: one request per chunk.
	 */
	public function getRuntimeRequestQuota(Site $siteData): ?RequestQuotaDto
	{
		$chunkCount = min($this->resolveChunkCount(), PageTextExtractor::MAX_CHUNKS);

		return $chunkCount > 0 ? new RequestQuotaDto(static::getConnectorClass(), $chunkCount) : null;
	}

	protected function getRequestLogDataType(): string
	{
		return 'AI request: markup topic moderation';
	}

	protected function resolveModerationInput(): string
	{
		$chunks = $this->extractChunks();
		if (count($chunks) > PageTextExtractor::MAX_CHUNKS)
		{
			$this->getLog()->add(
				'Markup topic moderation: ' . count($chunks) . ' chunk(s) of page text exceed the limit of '
				. PageTextExtractor::MAX_CHUNKS . ', the page is not moderated'
			);

			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Moderation input exceeds the chunk limit.',
			);
		}

		$chunkIndex = $this->getState()->getModerationChunkIndex();
		if ($chunkIndex === 0)
		{
			$this->getLog()->add('Markup topic moderation: ' . count($chunks) . ' chunk(s) of page text');
		}

		// Page text cannot forge the input delimiters of the prompt.
		return str_replace(
			['<', '>'],
			['\\u003C', '\\u003E'],
			$this->encodeInputLines($chunks[$chunkIndex] ?? []),
		);
	}

	/**
	 * A passed chunk moves the position; any other verdict throws in the parent and the
	 * position stays where it was, so nothing of the page counts as checked.
	 *
	 * The whole position is written here: the pass that sends a queued request does not save
	 * the generation, the pass that applies the answer does.
	 */
	protected function applyResponse(): bool
	{
		$applied = parent::applyResponse();

		$state = $this->getState();
		$state->setModerationProgress(
			$state->getModerationChunkIndex() + 1,
			$this->countChunks(),
			ModerationVerdictDto::DECISION_ALLOW,
		);

		return $applied;
	}

	protected function getState(): MarkupSiteState
	{
		return new MarkupSiteState($this->generation);
	}

	protected function getLog(): Log
	{
		return new Log($this->generation->getId());
	}

	protected function createFailureInjection(): FailureInjection
	{
		return new FailureInjection();
	}

	private function isModerationComplete(): bool
	{
		$chunkCount = $this->resolveChunkCount();

		return $chunkCount > 0 && $this->getState()->getModerationChunkIndex() >= $chunkCount;
	}

	/**
	 * The moderated text lives in the entry, the meta and the image declaration: asset files are not read.
	 * @return array<int, array<int, string>>
	 */
	private function extractChunks(): array
	{
		$package = $this->getState()->getPackageWithoutAssets();

		return $package instanceof Package ? (new PageTextExtractor())->extractChunks($package) : [];
	}

	/**
	 * The stored count, or the count of the package: the split is deterministic, so both agree.
	 */
	private function countChunks(): int
	{
		$chunkCount = $this->getState()->getModerationChunkCount();

		return $chunkCount > 0 ? $chunkCount : count($this->extractChunks());
	}

	/**
	 * Same as countChunks(), but silent on an unreadable package: the step itself reports it
	 * when it builds the input.
	 */
	private function resolveChunkCount(): int
	{
		try
		{
			return $this->countChunks();
		}
		catch (\Throwable)
		{
			return 0;
		}
	}
}
