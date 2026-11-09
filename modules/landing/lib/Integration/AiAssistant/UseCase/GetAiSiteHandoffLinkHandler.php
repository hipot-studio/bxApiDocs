<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Copilot\Services\CreateAiSiteChecker;
use Bitrix\Landing\Integration\AiAssistant\Dto\AiSiteChatAvailabilityResult;
use Bitrix\Landing\Integration\AiAssistant\Dto\GetAiSiteHandoffLinkDto;
use Bitrix\Landing\Integration\AiAssistant\Exception\HandoffLinkException;
use Bitrix\Landing\Integration\AiAssistant\Service\AiPromptHandoffService;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteChatAvailabilityService;
use Bitrix\Landing\Rights;

/**
 * Gives the agent a one-time link that carries the request of the user to /sites/ai/ (a new AI site) or to
 * the editor of an AI site (a change outside the editor).
 */
class GetAiSiteHandoffLinkHandler extends AbstractSiteHandler
{
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';
	public const ERROR_NOT_AI_SITE = 'NOT_AI_SITE';
	public const ERROR_AI_SITES_UNAVAILABLE = 'AI_SITES_UNAVAILABLE';
	public const ERROR_AI_CHAT_UNAVAILABLE = 'AI_CHAT_UNAVAILABLE';

	/**
	 * @return array{url: string, expiresAt: string, target: string}
	 * @throws HandoffLinkException The refusal with its ASCII code, the issue limit included.
	 */
	public function handle(GetAiSiteHandoffLinkDto $dto, int $userId): array
	{
		$this->validateInput($dto);

		$link = $dto->target === AiPromptHandoffService::TARGET_EDITOR
			? $this->issueEditorLink($dto, $userId)
			: $this->issueSitesAiLink($dto, $userId)
		;

		return [
			'url' => $link['url'],
			'expiresAt' => $link['expiresAt'],
			'target' => $dto->target,
		];
	}

	private function validateInput(GetAiSiteHandoffLinkDto $dto): void
	{
		$targets = [AiPromptHandoffService::TARGET_SITES_AI, AiPromptHandoffService::TARGET_EDITOR];
		if (!in_array($dto->target, $targets, true))
		{
			throw new \InvalidArgumentException('target must be "sites_ai" or "editor".');
		}

		$length = mb_strlen($dto->request);
		if ($length === 0 || $length > AiPromptHandoffService::MAX_PROMPT_LENGTH)
		{
			throw new \InvalidArgumentException(
				'request must be the request of the user, from 1 to '
				. AiPromptHandoffService::MAX_PROMPT_LENGTH . ' characters.',
			);
		}

		if ($dto->target === AiPromptHandoffService::TARGET_EDITOR && $dto->siteId <= 0)
		{
			throw new \InvalidArgumentException('siteId is required for the editor target.');
		}
	}

	/**
	 * @return array{url: string, expiresAt: string}
	 */
	private function issueSitesAiLink(GetAiSiteHandoffLinkDto $dto, int $userId): array
	{
		$availability = $this->checkSitesAiProductAvailability();
		if (!$availability->isAvailable())
		{
			throw new HandoffLinkException(
				self::ERROR_AI_SITES_UNAVAILABLE,
				'AI sites are unavailable: ' . $availability->getReasonCodeOrDefault('unknown') . '.',
			);
		}

		return $this->getHandoffService()->issueLink($userId, AiPromptHandoffService::TARGET_SITES_AI, $dto->request);
	}

	/**
	 * @return array{url: string, expiresAt: string}
	 */
	private function issueEditorLink(GetAiSiteHandoffLinkDto $dto, int $userId): array
	{
		if (!$this->canEditSite($dto->siteId))
		{
			$this->getHandoffService()->logRefusal(
				$userId,
				AiPromptHandoffService::TARGET_EDITOR,
				AiPromptHandoffService::REASON_NO_ACCESS,
				$dto->siteId,
			);

			throw new HandoffLinkException(
				self::ERROR_ACCESS_DENIED,
				'You do not have permission to edit this site.',
			);
		}

		if (!$this->isAiSite($dto->siteId))
		{
			throw new HandoffLinkException(
				self::ERROR_NOT_AI_SITE,
				'The site was not created by AI, the AI chat of the editor is not available for it.',
			);
		}

		$pageId = $this->resolveFirstPageId($dto->siteId);
		if ($pageId <= 0)
		{
			throw new \InvalidArgumentException('The site has no page to open in the editor.');
		}

		// The editor turns the AI chat off for a page of the generation that is not selected and then never
		// redeems the token, so such a link would be issued for nothing.
		$pageModeAvailability = $this->checkPageModeAvailability($pageId);
		if (!$pageModeAvailability->isAvailable())
		{
			$this->getHandoffService()->logRefusal(
				$userId,
				AiPromptHandoffService::TARGET_EDITOR,
				$pageModeAvailability->getReasonCodeOrDefault('unknown'),
				$dto->siteId,
			);

			throw new HandoffLinkException(
				self::ERROR_AI_CHAT_UNAVAILABLE,
				'The site belongs to the other generation of AI sites, the AI chat of its editor is off.',
			);
		}

		return $this->getHandoffService()->issueLink(
			$userId,
			AiPromptHandoffService::TARGET_EDITOR,
			$dto->request,
			$dto->siteId,
			$pageId,
		);
	}

	protected function getHandoffService(): AiPromptHandoffService
	{
		return new AiPromptHandoffService();
	}

	protected function checkSitesAiProductAvailability(): AiSiteChatAvailabilityResult
	{
		return (new AiSiteChatAvailabilityService())->checkSitesAiProductAvailability();
	}

	protected function checkPageModeAvailability(int $pageId): AiSiteChatAvailabilityResult
	{
		return (new AiSiteChatAvailabilityService())->checkPageModeAvailability($pageId);
	}

	protected function canEditSite(int $siteId): bool
	{
		return Rights::hasAccessForSite($siteId, Rights::ACCESS_TYPES['edit']);
	}

	protected function isAiSite(int $siteId): bool
	{
		return (new CreateAiSiteChecker())->isSiteCreated($siteId);
	}
}
