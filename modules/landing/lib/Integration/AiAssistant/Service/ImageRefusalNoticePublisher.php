<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;

/**
 * Tells the chat of a site once that the image provider refused pictures of the generation, from the final
 * step of the creation or the edit. The outcome of the attempt stays in the generation: the answer to the
 * agent reads it to avoid repeating the hint. A failure here never reaches the generation.
 */
class ImageRefusalNoticePublisher
{
	private readonly AiSiteChatNoticeService $noticeService;

	public function __construct(?AiSiteChatNoticeService $noticeService = null)
	{
		$this->noticeService = $noticeService ?? new AiSiteChatNoticeService();
	}

	/**
	 * @param array{ID: int|string, USER_ID: int|string}|null $bindingRow The row of the chat, null without one.
	 */
	public function publish(Generation $generation, ?array $bindingRow): void
	{
		try
		{
			$state = new MarkupSiteState($generation);
			$refusal = $state->getImageRefusal();
			if ($refusal === null || $state->getImageNoticeStatus() !== MarkupSiteState::NOTICE_PENDING)
			{
				return;
			}

			$isSent = $bindingRow !== null && $this->send($bindingRow, $refusal['provider']);
			$state->setImageNoticeStatus($isSent ? MarkupSiteState::NOTICE_SENT : MarkupSiteState::NOTICE_SKIPPED);
		}
		catch (\Throwable)
		{
			// the hint must not change the outcome of the generation
		}
	}

	private function send(array $bindingRow, string $provider): bool
	{
		try
		{
			return $this->noticeService->notifyBinding(
				$bindingRow,
				new AiSiteChatNotice(AiSiteChatNotice::TYPE_IMAGES_REFUSED, providerName: $provider),
			);
		}
		catch (\Throwable)
		{
			return false;
		}
	}
}
