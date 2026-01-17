<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Provider\Sms;

use Bitrix\Crm\Integration\SmsManager;
use Bitrix\Crm\MessageSender\Channel;
use Bitrix\Crm\MessageSender\UI\ConnectionsSlider;
use Bitrix\Crm\MessageSender\UI\Editor;
use Bitrix\Crm\MessageSender\UI\Provider;
use Bitrix\Crm\MessageSender\UI\Taxonomy;
use Bitrix\Crm\MessageSender\UI\ViewChannel\Appearance;

final class Edna extends Provider
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

		/** @var Editor\ViewChannel[] $editorViewChannels */
		foreach ($editorViewChannels as $viewChannel)
		{
			$sectionViewChannel = ConnectionsSlider\Section\ViewChannel::fromEditorViewChannel(
				$viewChannel,
				SmsManager::getSenderById($viewChannel->getBackend()->getId())?->getManageUrl(),
			);

			$isConnected = $sectionViewChannel->isConnected();

			$color = $isConnected ? '#1AEA76' : '';
			$iconPath = $isConnected
				? '/bitrix/components/bitrix/crm.messagesender.connections/images/sms-edna-connected.svg'
				: '/bitrix/components/bitrix/crm.messagesender.connections/images/sms-edna-not-connected.svg';

			$sections[] = new ConnectionsSlider\Section(
				'sms edna.ru',
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
		$usedChannels = array_filter($channels, static fn(Channel $c) => Taxonomy::isSmsEdnaRu($c));
		if (empty($usedChannels))
		{
			return [[], []];
		}

		$viewChannels = [];

		/** @var Channel[] $usedChannels */
		foreach ($usedChannels as $channel)
		{
			$viewChannels[] = new Editor\ViewChannel(
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
