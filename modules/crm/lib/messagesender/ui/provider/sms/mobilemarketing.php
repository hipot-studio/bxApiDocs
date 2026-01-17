<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Provider\Sms;

use Bitrix\Crm\Integration\SmsManager;
use Bitrix\Crm\MessageSender\Channel;
use Bitrix\Crm\MessageSender\UI\ConnectionsSlider\Section;
use Bitrix\Crm\MessageSender\UI\Editor\ViewChannel;
use Bitrix\Crm\MessageSender\UI\Provider;
use Bitrix\Crm\MessageSender\UI\Taxonomy;
use Bitrix\Crm\MessageSender\UI\ViewChannel\Appearance;

final class MobileMarketing extends Provider
{
	/**
	 * @inheritDoc
	 */
	public function createConnectionsSliderSections(array $channels): array
	{
		[$editorViewChannels, $usedChannels] = $this->createEditorViewChannels($channels);
		if (empty($editorViewChannels))
		{
			return [[], $usedChannels];
		}

		$sections = [];

		foreach ($editorViewChannels as $editorViewChannel)
		{
			$sectionViewChannel = Section\ViewChannel::fromEditorViewChannel(
				$editorViewChannel,
				SmsManager::getSenderById($editorViewChannel->getBackend()->getId())?->getManageUrl(),
			);

			$isConnected = $sectionViewChannel->isConnected();
			$color = $isConnected ? '#62D31A' : '';
			$iconPath = $isConnected
				? '/bitrix/components/bitrix/crm.messagesender.connections/images/sms-mobilemarketing-connected.svg'
				: '/bitrix/components/bitrix/crm.messagesender.connections/images/sms-mobilemarketing-not-connected.svg';

			$sections[] = new Section(
				'mobilemarketing.by',
				[
					$sectionViewChannel,
				],
				null,
				$color,
				$iconPath,
			);
		}

		return [$sections, $usedChannels];
	}

	/**
	 * @inheritDoc
	 */
	public function createEditorViewChannels(array $channels): array
	{
		$usedChannels = array_filter($channels, static fn(Channel $c) => Taxonomy::isMobileMarketing($c));
		if (empty($usedChannels))
		{
			return [[], []];
		}

		$viewChannels = [];

		/** @var Channel[] $usedChannels */
		foreach ($usedChannels as $channel)
		{
			$viewChannels[] = new ViewChannel(
				$this->makeId($channel),
				$channel,
				Appearance::sms()
					->setTitle($channel->getShortName())
					->setSubtitle($channel->getName()),
			);
		}

		return [$viewChannels, $usedChannels];
	}
}
