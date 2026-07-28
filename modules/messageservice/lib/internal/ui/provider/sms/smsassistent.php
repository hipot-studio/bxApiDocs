<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Internal\UI\Provider\Sms;

use Bitrix\MessageService\Internal\UI\Provider\Provider;
use Bitrix\MessageService\Public\UI\ConnectionsSlider\Section;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel\Appearance;
use Bitrix\MessageService\Public\UI\SenderCode;
use Bitrix\MessageService\Sender\Sms\SmsAssistentBy;
use Bitrix\MessageService\Sender\SmsManager;

final class SmsAssistent extends Provider
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
			$color = $isConnected ? '#10AD5B' : '';
			$iconPath = $isConnected
				? '/bitrix/components/bitrix/messageservice.connections/images/sms-assistent-connected.svg'
				: '/bitrix/components/bitrix/messageservice.connections/images/sms-assistent-not-connected.svg';

			$sections[] = new Section(
				'sms-assistent.by',
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
			static fn(\Bitrix\MessageService\Sender\Base $sender): bool => (string)$sender->getId() === SmsAssistentBy::ID,
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
