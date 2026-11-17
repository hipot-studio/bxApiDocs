<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingService;
use Bitrix\Landing\Integration\AiAssistant\Service\ImageRefusalNoticePublisher;
use Bitrix\Landing\Markup\Dev\FailureInjection;

/**
 * Final step of the markup page edit. An edit has no chat binding to pin, so the step finishes as the common
 * one does; it lets the dev failure injection stop the edit on its last step, as on the creation, and tells
 * the chat of the site about the pictures the image provider refused.
 */
class TaskFinishChangeMarkupSite extends Finish
{
	public function execute(): bool
	{
		$this->createFailureInjection()->raiseOnStep($this->generation, (int)($this->stepId ?? 0));

		$this->publishImageRefusalNotice();

		return parent::execute();
	}

	protected function createFailureInjection(): FailureInjection
	{
		return new FailureInjection();
	}

	protected function createImageRefusalNoticePublisher(): ImageRefusalNoticePublisher
	{
		return new ImageRefusalNoticePublisher();
	}

	protected function createBindingService(): AiSiteBindingService
	{
		return new AiSiteBindingService();
	}

	private function publishImageRefusalNotice(): void
	{
		$state = new MarkupSiteState($this->generation);
		if ($state->getImageRefusal() === null)
		{
			return;
		}

		$this->createImageRefusalNoticePublisher()->publish(
			$this->generation,
			$this->findEditBinding($state->getEditBindingId()),
		);
	}

	private function findEditBinding(int $bindingId): ?array
	{
		try
		{
			return $this->createBindingService()->getByIdForUser($bindingId, $this->generation->getAuthorId());
		}
		catch (\Throwable)
		{
			// a message about the pictures does not cost the edit its finish
			return null;
		}
	}
}
