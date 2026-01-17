<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Provider;

use Bitrix\Crm\Integration\NotificationsManager;
use Bitrix\Crm\MessageSender\Channel;
use Bitrix\Crm\MessageSender\UI\ConnectionsSlider;
use Bitrix\Crm\MessageSender\UI\Editor;
use Bitrix\Crm\MessageSender\UI\Provider;
use Bitrix\Crm\MessageSender\UI\Taxonomy;
use Bitrix\Crm\MessageSender\UI\ViewChannel\Appearance;
use Bitrix\Crm\MessageSender\UI\ViewChannel\Icon;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;

final class Notifications extends Provider
{
	/**
	 * @inheritDoc
	 */
	public function createConnectionsSliderSections(array $channels): array
	{
		[$viewChannels, $usedChannels] = $this->createEditorViewChannels($channels);
		if (empty($viewChannels))
		{
			return [[], $usedChannels];
		}

		$connectionUrl = Container::getInstance()->getRouter()->getContactCenterUrl()?->getUri() . 'connector/?ID=notifications';
		$sliderCode = self::isLocked() ? 'limit_crm_sales_sms_whatsapp' : null;

		return [
			[
				new ConnectionsSlider\Section(
					'Notifications',
					array_map(
						static fn(Editor\ViewChannel $evc) => ConnectionsSlider\Section\ViewChannel::fromEditorViewChannel(
							$evc,
							$connectionUrl,
							$sliderCode,
						),
						$viewChannels,
					),
					null,
					null,
					$this->getIcon(),
				),
			],
			$usedChannels,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function createEditorViewChannels(array $channels): array
	{
		// no channel if not enough modules
		$usedChannels = array_filter($channels, Taxonomy::isNotifications(...));
		if (empty($usedChannels))
		{
			return [[], []];
		}

		if (!self::isAvailableInRegion())
		{
			return [
				[],
				$usedChannels,
			];
		}

		$viewChannels = $this->createAllViewChannels($usedChannels);

		return [
			$viewChannels,
			$usedChannels,
		];
	}

	/**
	 * Checks only the scenario relevant to editor use-case - CRM Payments.
	 */
	private static function isAvailableInRegion(): bool
	{
		return NotificationsManager::isCrmPaymentScenarioAvailableInRegion();
	}

	/**
	 * Checks only the scenario relevant to editor use-case - CRM Payments.
	 */
	private static function isLocked(): bool
	{
		return NotificationsManager::isCrmPaymentScenarioAvailableInRegion() && NotificationsManager::isCrmPaymentScenarioLimited();
	}

	/**
	 * @param Channel[] $channels
	 *
	 * @return Editor\ViewChannel[]
	 */
	private function createAllViewChannels(array $channels): array
	{
		$result = [];
		foreach ($channels as $channel)
		{
			$result = [
				...$result,
				...$this->createViewChannels($channel),
			];
		}

		return $result;
	}

	private function createViewChannels(Channel $channel): array
	{
		return array_map(
			fn(Channel\Correspondents\From $from) => new Editor\ViewChannel(
				$this->makeId($channel),
				$channel,
				new Appearance(
					Icon::notifications(),
					(string)Loc::getMessage('CRM_MESSAGESENDER_NOTIFICATIONS_CHANNEL_TITLE'),
					$this->makeViaCaption($channel->getShortName()),
					(string)Loc::getMessage('CRM_MESSAGESENDER_NOTIFICATIONS_CHANNEL_DESCRIPTION'),
				),
				[$from],
				NotificationsManager::canSendMessage(),
			),
			$channel->getFromList(),
		);
	}

	private function getIcon(): string
	{
		$region = Application::getInstance()->getLicense()->getRegion();
		$iconPath = '/bitrix/components/bitrix/crm.messagesender.connections/images/';

		if ($region === 'ru' || $region === 'kz')
		{
			return $iconPath . 'bitrix24-ru.svg';
		}

		if ($region === 'by')
		{
			return $iconPath . 'bitrix24-by.svg';
		}

		return $iconPath . 'bitrix24-en.svg';
	}
}
