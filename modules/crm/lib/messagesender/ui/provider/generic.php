<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Provider;

use Bitrix\Crm\MessageSender\UI\ConnectionsSlider;
use Bitrix\Crm\MessageSender\UI\Editor;
use Bitrix\Crm\MessageSender\UI\Provider;
use Bitrix\Crm\MessageSender\UI\ViewChannel\Appearance;

final class Generic extends Provider
{
	/**
	 * @inheritDoc
	 */
	public function createConnectionsSliderSections(array $channels): array
	{
		[$viewChannels, $usedChannels] = $this->createEditorViewChannels($channels);

		$sections = [];

		foreach ($viewChannels as $viewChannel)
		{
			$sections[] = new ConnectionsSlider\Section(
				$viewChannel->getAppearance()->getTitle(),
				[
					ConnectionsSlider\Section\ViewChannel::fromEditorViewChannel(
						$viewChannel,
					),
				],
				$viewChannel->getBackend()->getName(),
			);
		}

		return [
			$sections,
			$usedChannels,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function createEditorViewChannels(array $channels): array
	{
		$viewChannels = [];

		foreach ($channels as $channel)
		{
			$title = $channel->getShortName() ?: $channel->getName();

			$viewChannels[] = new Editor\ViewChannel(
				$this->makeId($channel),
				$channel,
				Appearance::generic($title),
			);
		}

		return [
			$viewChannels,
			$channels,
		];
	}
}
