<?php

declare(strict_types=1);

namespace Bitrix\Crm\Tour\EntityDetailsMenubar;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Tour\Base;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

final class Message extends Base
{
	protected const OPTION_NAME = 'entity-details-menubar-message';

	protected function canShow(): bool
	{
		return
			!$this->isUserSeenTour()
			&& Feature::enabled(Feature\MessageSenderEditor::class)
			&& \Bitrix\Crm\Integration\SmsManager::canUse()
		;
	}

	protected function getPortalMaxCreatedDate(): ?DateTime
	{
		return new DateTime('01.12.2025', 'd.m.Y');
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
				'id' => 'menubar-message',
				'title' => Loc::getMessage('CRM_TOUR_ENTITY_DETAILS_MENUBAR_MESSAGE_TITLE'),
				'text' => Loc::getMessage('CRM_TOUR_ENTITY_DETAILS_MENUBAR_MESSAGE_TEXT'),
				'position' => 'top',
				'target' => $timelineMenuItemSelector . '[data-id="message"]',
				'reserveTargets' => [
					$timelineMenuItemSelector . '.main-buttons-item-more'
				],
				'ignoreIfTargetNotFound' => true,
				'iconSrc' => '/bitrix/images/crm/whats_new/message.png',
				'buttons' => [
					[
						'text' => Loc::getMessage('CRM_TOUR_ENTITY_DETAILS_MENUBAR_MESSAGE_BUTTON'),
						'onclick' => [
							'closeAfterClick' => true,
							'code' =>
								<<<JS
(function() {
	if (BX && BX.Crm && BX.Crm.Timeline && BX.Crm.Timeline.MenuBar)
	{
		const menubar = BX.Crm.Timeline.MenuBar.getDefault();
		if (menubar)
		{
			menubar.scrollIntoView();
			menubar.setActiveItemById('message');
		}
	}
})();
JS,
						],
					]
				],
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
