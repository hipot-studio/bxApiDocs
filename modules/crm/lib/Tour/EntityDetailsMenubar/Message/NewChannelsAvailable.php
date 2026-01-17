<?php

declare(strict_types=1);

namespace Bitrix\Crm\Tour\EntityDetailsMenubar\Message;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Tour\Base;
use Bitrix\Crm\Tour\OtherTourChecker;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

final class NewChannelsAvailable extends Base
{
	use OtherTourChecker;

	protected const OPTION_NAME = 'entity-details-menubar-message-new-channels-available';

	/**
	 * @inheritDoc
	 */
	protected function canShow(): bool
	{
		return
			!$this->isUserSeenTour()
			&& Feature::enabled(Feature\MessageSenderEditor::class)
			&& Feature::enabled(Feature\TelegramActivity::class)
			&& \Bitrix\Crm\Integration\SmsManager::canUse()
			// on the frontend, we will check whether there are promo banners for channels
		;
	}

	protected function getShowDeadline(): ?DateTime
	{
		return new DateTime('01.04.2026', 'd.m.Y');
	}

	protected function getSteps(): array
	{
		return [
			[
				'id' => 'menubar-message-new-channels-available',
				'title' => Loc::getMessage('CRM_TOUR_ENTITY_DETAILS_MENUBAR_MESSAGE_NEW_CHANS_AVAILABLE_TITLE'),
				'text' => Loc::getMessage('CRM_TOUR_ENTITY_DETAILS_MENUBAR_MESSAGE_NEW_CHANS_AVAILABLE_TEXT'),
				'position' => 'top',
				'useDynamicTarget' => true,
				'eventName' => 'BX.Crm.Timeline.MenuBar.Message:ShowNewChannelsAvailableTour',
				'iconSrc' => '/bitrix/images/crm/whats_new/message_channels.png',
				'buttons' => [
					[
						'text' => Loc::getMessage('CRM_TOUR_ENTITY_DETAILS_MENUBAR_MESSAGE_NEW_CHANS_AVAILABLE_BUTTON'),
						'onclick' => [
							'closeAfterClick' => true,
							'code' =>
								<<<JS
(function() {
	BX.Runtime.loadExtension('crm.router').then((exports) => {
		/** @see BX.Crm.Router.openMessageSenderConnectionsSlider */
		return exports.Router.Instance.openMessageSenderConnectionsSlider({c_section: 'aha_moment'});
	}).then(() => {
		BX.Event.EventEmitter.emit('BX.Crm.Tour.EntityDetailsMenubar.Message:onConnectionsSliderClose');
	});
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
