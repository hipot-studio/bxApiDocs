<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Internal\UI\Provider\Sms;

use Bitrix\MessageService\Internal\UI\Provider\Provider;
use Bitrix\MessageService\Public\UI\ConnectionsSlider\Section;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel\Appearance;
use Bitrix\MessageService\Public\UI\SenderCode;
use Bitrix\MessageService\Sender\SmsManager;

final class SmsRu extends Provider
{
	/**
	 * @inheritDoc
	 */
	public function createConnectionsSliderSections(array $senders): array
	{
		[$editorViewChannels, $usedSenders] = $this->createEditorViewChannels($senders);
		if (empty($editorViewChannels))
		{
			return [[], $usedSenders];
		}

		$sections = [];

		foreach ($editorViewChannels as $editorViewChannel)
		{
			$sectionViewChannel = Section\ViewChannel::fromEditorViewChannel(
				$editorViewChannel,
				SmsManager::getSenderById($editorViewChannel->getBackend()->getId())?->getManageUrl(),
			);

			$isConnected = $sectionViewChannel->isConnected();
			$color = $isConnected ? '#2E62A5' : '';
			$iconPath = $isConnected
				? '/bitrix/components/bitrix/messageservice.connections/images/sms-smsru-connected.svg'
				: '/bitrix/components/bitrix/messageservice.connections/images/sms-smsru-not-connected.svg';

			$sections[] = new Section(
				'sms.ru',
				[
					$sectionViewChannel,
				],
				null,
				$color,
				$iconPath
			);
		}

		return [$sections, $usedSenders];
	}

	/**
	 * @inheritDoc
	 */
	public function createEditorViewChannels(array $senders): array
	{
		$usedSenders = array_filter(
			$senders,
			static fn(\Bitrix\MessageService\Sender\Base $sender): bool => (string)$sender->getId() === \Bitrix\MessageService\Sender\Sms\SmsRu::ID,
		);
		if (empty($usedSenders))
		{
			return [[], []];
		}

		$viewChannels = [];

		foreach ($usedSenders as $sender)
		{
			$viewChannels[] = ViewChannel::fromSender(
				$this->makeId(SenderCode::SMS_PROVIDER, (string)$sender->getId()),
				$sender,
				SenderCode::SMS_PROVIDER,
				Appearance::sms()
					->setTitle((string)$sender->getShortName())
					->setSubtitle((string)$sender->getName()),
				$sender->canUse(),
			);
		}

		return [$viewChannels, $usedSenders];
	}
}
