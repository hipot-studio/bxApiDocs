<?php

declare(strict_types=1);

namespace Bitrix\Crm\Tour\EntityDetailsMenubar\GoToChat;

use Bitrix\Crm\Settings\Crm;
use Bitrix\Crm\Tour\Base;
use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

final class Whatsapp extends Base
{
	protected const OPTION_NAME = 'entity-details-menubar-gotochat-whatsapp';

	/**
	 * @inheritDoc
	 */
	protected function canShow(): bool
	{
		return
			!$this->isUserSeenTour()
			&& \Bitrix\Crm\Integration\ImOpenLines\GoToChat::isActive()
			&& !Crm::isBox()
			&& $this->getRegion() === 'ru'
		;
	}

	private function getRegion(): string
	{
		return (string)(Application::getInstance()->getLicense()->getRegion() ?? Context::getCurrent()->getLanguage());
	}

	protected function getShowDeadline(): ?DateTime
	{
		return new DateTime('01.04.2026', 'd.m.Y');
	}

	protected function getSteps(): array
	{
		$timelineMenuItemSelector = '.crm-entity-stream-container-content .crm-entity-stream-section-menu .main-buttons-item';

		return [
			[
				'id' => 'menubar-gotochat-whatsapp',
				'title' => Loc::getMessage('CRM_TOUR_ENTITY_DETAILS_MENUBAR_GOTOCHAT_WHATSAPP_TITLE'),
				'text' => Loc::getMessage('CRM_TOUR_ENTITY_DETAILS_MENUBAR_GOTOCHAT_WHATSAPP_TEXT'),
				'position' => 'top',
				'target' => $timelineMenuItemSelector . '[data-id="gotochat"]',
				'reserveTargets' => [
					$timelineMenuItemSelector . '.main-buttons-item-more'
				],
				'ignoreIfTargetNotFound' => true,
				'article' => 18114500,
			],
		];
	}

	protected function getOptions(): array
	{
		return [
			'hideTourOnMissClick' => true,
			'steps' => [
				'popup' => [
					'width' => 420,
				],
			],
		];
	}
}
