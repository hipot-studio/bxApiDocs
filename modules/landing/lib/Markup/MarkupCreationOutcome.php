<?php

declare(strict_types=1);

namespace Bitrix\Landing\Markup;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateMarkupSite;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Domain;
use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;
use Bitrix\Landing\Integration\AiAssistant\Service\AiPromptHandoffService;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingService;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteChatNotice;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteChatNoticeService;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteFailedGenerationReaction;
use Bitrix\Landing\Internals\SiteTable;
use Bitrix\Main\Loader;

/**
 * The one reaction of the markup creation on how it ended, owned by the scenario rather than by the
 * tools that happen to read it (ADR § 3.3): the finish step pins the chat to the created site, and every
 * other end - a terminal failure, a start that threw, a cancel - frees the chat and removes the partial
 * site the failed run has left behind.
 *
 * Only the creation itself is reacted on: the SCENARIO of the generation must be exactly
 * CreateMarkupSite. The change scenario inherits from it, and its failure must never touch the site
 * it edits.
 *
 * Every method is idempotent on the generation id: the reservation is released by a conditional write on
 * GENERATION_ID, and a chat already pinned to its site (OWNED) is never released nor its site removed.
 * The reaction never throws - the terminal hook runs before the failure of the generation is saved, and an
 * exception from here would cost the run the record of what happened; a failed removal keeps the
 * reservation, so the sweeper retries it.
 *
 * The chat of the creation is told how it ended (ADR § 3.2): «Site created» by the finish step that pinned
 * the chat, «Could not create the site» by the terminal reaction that released it. A cancel and a start that
 * threw stay silent - the agent learns about them from the answer of its tool in the same turn.
 */
class MarkupCreationOutcome
{
	private const MAX_REASON_LENGTH = 180;

	private AiSiteBindingService $bindingService;
	private PartialSiteRemover $siteRemover;
	private UploadBuffer $uploadBuffer;
	private MarkupLifecycleLog $log;
	private AiSiteChatNoticeService $noticeService;
	private AiPromptHandoffService $handoffService;

	public function __construct(
		?AiSiteBindingService $bindingService = null,
		?PartialSiteRemover $siteRemover = null,
		?UploadBuffer $uploadBuffer = null,
		?MarkupLifecycleLog $log = null,
		?AiSiteChatNoticeService $noticeService = null,
		?AiPromptHandoffService $handoffService = null,
	)
	{
		$this->bindingService = $bindingService ?? new AiSiteBindingService();
		$this->siteRemover = $siteRemover ?? new PartialSiteRemover();
		$this->uploadBuffer = $uploadBuffer ?? new UploadBuffer();
		$this->log = $log ?? new MarkupLifecycleLog();
		$this->noticeService = $noticeService ?? new AiSiteChatNoticeService();
		$this->handoffService = $handoffService ?? new AiPromptHandoffService();
	}

	/**
	 * Terminal failure of the creation: the partial site is removed, then the reservation goes - a refused
	 * topic counts against the draft and drops the buffer (K4), any other failure, an exhausted limit
	 * included, frees the draft without the counter and keeps the buffer for the resubmission (K5, K6,
	 * K11). A mark that did not reach the database is no terminal failure: the generation goes on.
	 *
	 * The chat of the released reservation is then told the creation failed; only the poll that has found
	 * the reservation does it, so a repeated poll of the same generation posts nothing.
	 */
	public function reactOnTerminal(Generation $generation): void
	{
		try
		{
			if (!$this->isCreation($generation) || !$generation->isErrorPersisted())
			{
				return;
			}

			$binding = $this->findReservation($generation);
			if ($binding === null)
			{
				return;
			}

			$generationId = (int)$generation->getId();
			$erroredStepId = $this->resolveErroredStepId($generationId);
			$isTopicRefusal = $this->isTopicRefusal($generation, $erroredStepId);

			if (!$this->removePartialSite($generation, $binding))
			{
				return;
			}

			$this->createFailedGenerationReaction()->react($generation, $erroredStepId, false);

			$uploadId = (new MarkupSiteState($generation))->getUploadId();
			if ($isTopicRefusal)
			{
				$this->dropBuffer($uploadId, $generation->getAuthorId());
			}
			else
			{
				$this->uploadBuffer->touch($uploadId);
			}

			$this->log->write(MarkupLifecycleLog::EVENT_REACTION, $this->describe($generation, $binding) + [
				'errorType' => $this->resolveErrorType($generation, $isTopicRefusal),
				'step' => $this->resolveStepCode($generation, $erroredStepId),
				'reason' => $isTopicRefusal ? 'topic_refused' : 'released',
			]);

			$this->notifyFailure($generation, $binding, $isTopicRefusal);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure(MarkupLifecycleLog::EVENT_REACTION, $generation, $exception);
		}
	}

	/**
	 * A start that threw: whatever the generation managed to write about itself, nothing is going to
	 * report its end, so the partial site is removed and the reservation released right away.
	 */
	public function onLaunchFailure(Generation $generation): void
	{
		$this->releaseStopped($generation, 'launch_failure', true);
	}

	/**
	 * A cancelled creation: the partial site is removed and the reservation released without the counter;
	 * the buffer is the cancel's own business (K7) and is not touched here.
	 */
	public function onCancelled(Generation $generation): void
	{
		$this->releaseStopped($generation, 'cancelled', false);
	}

	/**
	 * Pins the chat binding reserved by the generation to the site it has created. True when the chat is
	 * pinned to that site - now or already - or when the generation reserved no chat at all (the dev
	 * entry); false when the reservation is gone or belongs to another site.
	 *
	 * Called by the finish step, while the generation is not finished yet: the finalizer of the tools
	 * waits for a finished generation, so the same conditional write is made here directly.
	 *
	 * «Site created» is posted only by the call whose write has pinned the chat: a repeated finish step or
	 * a finalizer that came second finds the site already there and posts nothing.
	 */
	public function finalize(Generation $generation): bool
	{
		if (CreateAiSiteState::getAiSiteBindingId($generation) <= 0)
		{
			return true;
		}

		$generationId = (int)($generation->getId() ?? 0);
		$userId = $generation->getAuthorId();
		$siteData = $generation->getSiteData();
		$siteId = (int)($siteData->getSiteId() ?? 0);
		$pageId = (int)($siteData->getLandingId() ?? 0);
		if ($generationId <= 0 || $userId <= 0 || $siteId <= 0 || $pageId <= 0)
		{
			return false;
		}

		$binding = $this->bindingService->findByGenerationId($generationId);
		if ($binding === null || (int)$binding['USER_ID'] !== $userId)
		{
			return false;
		}

		$currentSiteId = (int)($binding['SITE_ID'] ?? 0);
		if ($currentSiteId > 0)
		{
			return $currentSiteId === $siteId;
		}

		$isPinnedNow = $this->bindingService->completeGeneration($generationId, $userId, $siteId);
		if (!$isPinnedNow)
		{
			$binding = $this->bindingService->findByGenerationId($generationId);
			if ((int)($binding['SITE_ID'] ?? 0) !== $siteId)
			{
				return false;
			}
		}

		$this->log->write(MarkupLifecycleLog::EVENT_FINALIZE, $this->describe($generation, $binding) + [
			'siteId' => $siteId,
		]);

		if ($isPinnedNow)
		{
			$this->notifyCreated($generation, $binding, $siteId, $pageId);
		}

		return true;
	}

	protected function createFailedGenerationReaction(): AiSiteFailedGenerationReaction
	{
		return new AiSiteFailedGenerationReaction($this->bindingService);
	}

	protected function resolveErroredStepId(int $generationId): int
	{
		return AiSiteGenerationHelper::resolveErroredStepId($generationId);
	}

	protected function isTopicRefusal(Generation $generation, int $erroredStepId): bool
	{
		return AiSiteGenerationHelper::isModerationBlockRefusal(
			$generation,
			$erroredStepId,
			AiSiteGenerationHelper::resolveModerationVerdictForErroredStep(
				(int)$generation->getId(),
				$erroredStepId,
			),
		);
	}

	protected function resolveSiteTitle(Generation $generation, int $siteId): string
	{
		$row = SiteTable::query()
			->setSelect(['TITLE'])
			->where('ID', $siteId)
			->setLimit(1)
			->fetch()
		;
		$title = trim((string)($row['TITLE'] ?? ''));

		return $title !== '' ? $title : trim($generation->getSiteData()->getSiteTitle());
	}

	protected function isChatModuleAvailable(): bool
	{
		return Loader::includeModule('aiassistant');
	}

	private function notifyCreated(Generation $generation, array $binding, int $siteId, int $pageId): void
	{
		try
		{
			$this->publish($binding, new AiSiteChatNotice(
				AiSiteChatNotice::TYPE_CREATED,
				$this->resolveSiteTitle($generation, $siteId),
				rtrim(Domain::getHostUrl(), '/') . '/sites/site/' . $siteId . '/view/' . $pageId . '/',
			));
		}
		catch (\Throwable $exception)
		{
			$this->logFailure(MarkupLifecycleLog::EVENT_FINALIZE, $generation, $exception);
		}
	}

	/**
	 * Posts «Could not create the site» into the chat of the released reservation. The retry button leads to
	 * /sites/ai/ with the row of this chat, so the retry comes back here (C6); a refused topic or a link that
	 * could not be issued (the limit) leave the message without the button.
	 */
	private function notifyFailure(Generation $generation, array $binding, bool $isTopicRefusal): void
	{
		// the reservation still held means the release did not happen, so there is nothing to tell
		if ($this->bindingService->findByGenerationId((int)$generation->getId()) !== null)
		{
			return;
		}

		// checked before the retry link: a token issued for a message that cannot be posted takes the hourly limit
		$this->assertChatModule();

		$this->publish($binding, new AiSiteChatNotice(
			AiSiteChatNotice::TYPE_FAILED,
			buttonUrl: $isTopicRefusal ? null : $this->issueRetryLink($generation, $binding),
		));
	}

	/**
	 * The chat and its trigger action live in aiassistant: the module is loaded before the notice is
	 * handed over, whoever publishes it. Without the module there is no chat to post into: the notice is
	 * skipped and the caller logs the reason, the outcome of the generation is not affected.
	 */
	private function publish(array $binding, AiSiteChatNotice $notice): void
	{
		$this->assertChatModule();

		$this->noticeService->notifyBinding($binding, $notice);
	}

	private function assertChatModule(): void
	{
		if (!$this->isChatModuleAvailable())
		{
			throw new \RuntimeException('The aiassistant module is not installed: the chat notice is skipped.');
		}
	}

	/**
	 * The markup creation keeps no brief - the agent hands in a ready package - so the link carries only the
	 * row of this chat: the retry opens the same chat on /sites/ai/ and sends nothing into it.
	 */
	private function issueRetryLink(Generation $generation, array $binding): ?string
	{
		try
		{
			return $this->handoffService->issueLink(
				$generation->getAuthorId(),
				AiPromptHandoffService::TARGET_SITES_AI,
				'',
				bindingId: (int)$binding['ID'],
			)['url'];
		}
		catch (\Throwable)
		{
			// a link that could not be issued (the limit included) leaves the message without the button
			return null;
		}
	}

	private function releaseStopped(Generation $generation, string $reason, bool $touchBuffer): void
	{
		try
		{
			if (!$this->isCreation($generation))
			{
				return;
			}

			$binding = $this->findReservation($generation);
			if ($binding === null || !$this->removePartialSite($generation, $binding))
			{
				return;
			}

			$this->bindingService->releaseDraftForFailedGeneration(
				(int)$generation->getId(),
				$generation->getAuthorId(),
			);

			if ($touchBuffer)
			{
				$this->uploadBuffer->touch((new MarkupSiteState($generation))->getUploadId());
			}

			$this->log->write(
				MarkupLifecycleLog::EVENT_REACTION,
				$this->describe($generation, $binding) + ['reason' => $reason],
			);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure(MarkupLifecycleLog::EVENT_REACTION, $generation, $exception);
		}
	}

	private function isCreation(Generation $generation): bool
	{
		$scenario = $generation->getScenario();

		return $scenario !== null
			&& get_class($scenario) === CreateMarkupSite::class
			&& (int)($generation->getId() ?? 0) > 0
		;
	}

	/**
	 * The chat binding still reserved by the generation, or null when there is nothing to release: the
	 * generation reserved no chat, the reservation is already released, or the chat is pinned to its site.
	 */
	private function findReservation(Generation $generation): ?array
	{
		$binding = $this->bindingService->findByGenerationId((int)$generation->getId());
		if (
			$binding === null
			|| (int)$binding['USER_ID'] !== $generation->getAuthorId()
			|| (int)($binding['SITE_ID'] ?? 0) > 0
		)
		{
			return null;
		}

		return $binding;
	}

	/**
	 * True when no site of the generation is left: removed now or never created. False keeps the
	 * reservation, so the removal is retried by the sweeper.
	 */
	private function removePartialSite(Generation $generation, array $binding): bool
	{
		$siteId = $this->resolveCreatedSiteId($generation);
		if ($siteId <= 0)
		{
			return true;
		}

		$removal = $this->siteRemover->remove($siteId, (int)$generation->getId());
		if (!$removal->isSuccess())
		{
			$this->log->write(MarkupLifecycleLog::EVENT_REMOVAL, $this->describe($generation, $binding) + [
				'siteId' => $siteId,
				'reason' => $this->shorten('failed: ' . implode('; ', $removal->getErrorMessages())),
			]);

			return false;
		}

		if (!empty($removal->getData()['removed']))
		{
			$this->log->write(MarkupLifecycleLog::EVENT_REMOVAL, $this->describe($generation, $binding) + [
				'siteId' => $siteId,
				'reason' => 'removed',
			]);
		}

		return true;
	}

	private function resolveCreatedSiteId(Generation $generation): int
	{
		$siteId = (int)($generation->getSiteData()->getSiteId() ?? 0);
		if ($siteId > 0)
		{
			return $siteId;
		}

		return max(0, (int)(CreateAiSiteState::getCreationJournal($generation)['siteId'] ?? 0));
	}

	private function dropBuffer(string $uploadId, int $userId): void
	{
		if ($uploadId !== '')
		{
			$this->uploadBuffer->drop($uploadId, $userId);
		}
	}

	private function resolveErrorType(Generation $generation, bool $isTopicRefusal): string
	{
		if (AiSiteGenerationHelper::extractLimitError($generation) !== null)
		{
			return AiSiteGenerationHelper::ERROR_TYPE_LIMIT;
		}

		return $isTopicRefusal
			? AiSiteGenerationHelper::ERROR_TYPE_MODERATION
			: AiSiteGenerationHelper::ERROR_TYPE_TECHNICAL
		;
	}

	private function resolveStepCode(Generation $generation, int $stepId): ?string
	{
		$scenario = $generation->getScenario();
		if (!$scenario instanceof CreateMarkupSite)
		{
			return null;
		}

		$code = $scenario->getStepMeta()[$stepId]['code'] ?? null;

		return is_string($code) ? $code : null;
	}

	private function describe(Generation $generation, ?array $binding): array
	{
		return [
			'jobId' => (int)($generation->getId() ?? 0),
			'bindingId' => (int)($binding['ID'] ?? CreateAiSiteState::getAiSiteBindingId($generation)),
			'uploadId' => (new MarkupSiteState($generation))->getUploadId(),
			'userId' => $generation->getAuthorId(),
		];
	}

	/**
	 * A reason fits the journal line whole: the journal masks longer strings as possible package content.
	 */
	private function shorten(string $reason): string
	{
		return mb_strcut($reason, 0, self::MAX_REASON_LENGTH);
	}

	private function logFailure(string $event, Generation $generation, \Throwable $exception): void
	{
		try
		{
			$this->log->write($event, $this->describe($generation, null) + [
				'reason' => $this->shorten('exception: ' . get_class($exception) . ': ' . $exception->getMessage()),
			]);
		}
		catch (\Throwable)
		{
			// the reaction never throws, not even about itself
		}
	}
}
