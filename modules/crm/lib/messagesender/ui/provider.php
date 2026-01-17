<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI;

use Bitrix\Crm\MessageSender\Channel;
use Bitrix\Crm\MessageSender\UI\ConnectionsSlider\Section;
use Bitrix\Crm\MessageSender\UI\Editor\PromoBanner;
use Bitrix\Crm\MessageSender\UI\Editor\ViewChannel;
use Bitrix\Main\Localization\Loc;

abstract class Provider
{
	/**
	 * @param Channel[] $channels
	 *
	 * @return array{
	 *     0: Section[],
	 *     1: Channel[]
	 * }
	 */
	abstract public function createConnectionsSliderSections(array $channels): array;

	/**
	 * @param Channel[] $channels
	 *
	 * @return array{
	 *     0: ViewChannel[],
	 *     1: Channel[]
	 * }
	 */
	abstract public function createEditorViewChannels(array $channels): array;

	/**
	 * @param ViewChannel[] $editorViewChannels
	 *
	 * @return PromoBanner[]
	 */
	public function createEditorPromoBanners(array $editorViewChannels): array
	{
		return [];
	}

	final protected function makeId(Channel $channel, array $additionalParams = []): string
	{
		if (array_is_list($additionalParams))
		{
			sort($additionalParams);
		}
		else
		{
			ksort($additionalParams);
		}

		$parts = [
			$channel->getSender()::getSenderCode(),
			$channel->getId(),
			...array_values($additionalParams),
		];

		return implode('~~~', $parts);
	}

	final protected function makeViaCaption(string $channelName): string
	{
		return (string)Loc::getMessage('CRM_MESSAGESENDER_UI_PROVIDER_VIA', ['#CHANNEL#' => $channelName]);
	}
}
