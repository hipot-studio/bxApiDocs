<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Internal\UI\Provider;

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\MessageService\Integration;
use Bitrix\MessageService\Integration\ImOpenLines;
use Bitrix\MessageService\Public\UI\ConnectionsSlider;
use Bitrix\MessageService\Public\UI\MessageEditor\Channel\From;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel\Appearance;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel\Backend;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel\Icon;
use Bitrix\MessageService\Public\UI\SenderCode;

final class Notifications extends Provider
{
	/**
	 * @inheritDoc
	 */
	public function createConnectionsSliderSections(array $senders): array
	{
		if (!Integration\Notifications::isModulesInstalled())
		{
			return [[], []];
		}

		[$viewChannels] = $this->createEditorViewChannels($senders);
		if (empty($viewChannels))
		{
			return [[], []];
		}

		$connectionUrl = rtrim(ImOpenLines::getContactCenterUrl(), '/') . '/connector/?ID=notifications';
		$sliderCode = self::isLocked() ? 'limit_crm_sales_sms_whatsapp' : null;

		return [
			[
				new ConnectionsSlider\Section(
					'Notifications',
					array_map(
						static fn(ViewChannel $evc) => ConnectionsSlider\Section\ViewChannel::fromEditorViewChannel(
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
			[],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function createEditorViewChannels(array $senders): array
	{
		unset($senders);

		if (!Integration\Notifications::isModulesInstalled())
		{
			return [[], []];
		}

		// scenario relevant to editor use-case - CRM Payments
		if (!Integration\Notifications::isAvailableInRegion(Integration\Notifications::SCENARIO_CRM_PAYMENT))
		{
			return [[], []];
		}

		$viewChannels = $this->createAllViewChannels();

		return [
			$viewChannels,
			[],
		];
	}

	/**
	 * Checks only the scenario relevant to editor use-case - CRM Payments.
	 */
	private static function isLocked(): bool
	{
		return
			Integration\Notifications::isAvailableInRegion(Integration\Notifications::SCENARIO_CRM_PAYMENT)
			&& Integration\Notifications::isLimited(Integration\Notifications::SCENARIO_CRM_PAYMENT)
		;
	}

	/**
	 * @return ViewChannel[]
	 */
	private function createAllViewChannels(): array
	{
		return $this->createViewChannels();
	}

	private function createViewChannels(): array
	{
		$channelName = (string)Loc::getMessage('MESSAGESERVICE_NOTIFICATIONS_CHANNEL_NAME');
		$channelShortName = (string)Loc::getMessage('MESSAGESERVICE_NOTIFICATIONS_CHANNEL_SHORT_NAME');

		$from = new From(
			SenderCode::BITRIX24,
			$channelShortName,
			$channelName,
			true,
		);

		return [
			new ViewChannel(
				$this->makeId(
					SenderCode::BITRIX24,
					SenderCode::BITRIX24,
				),
				new Backend(
					SenderCode::BITRIX24,
					SenderCode::BITRIX24,
					$channelName,
					$channelShortName,
					false,
				),
				new Appearance(
					Icon::notifications(),
					(string)Loc::getMessage('MESSAGESERVICE_NOTIFICATIONS_CHANNEL_TITLE'),
					$this->makeViaCaption($channelShortName),
					(string)Loc::getMessage('MESSAGESERVICE_NOTIFICATIONS_CHANNEL_DESCRIPTION_MSGVER_1'),
				),
				[$from],
				[],
				Integration\Notifications::canSendMessage(Integration\Notifications::SCENARIO_CRM_PAYMENT),
				true,
			),
		];
	}

	private function getIcon(): string
	{
		$region = Application::getInstance()->getLicense()->getRegion();
		$iconPath = '/bitrix/components/bitrix/messageservice.connections/images/';

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
