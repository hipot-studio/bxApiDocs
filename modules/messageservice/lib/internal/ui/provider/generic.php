<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Internal\UI\Provider;

use Bitrix\MessageService\Public\UI\ConnectionsSlider;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel\Appearance;
use Bitrix\MessageService\Public\UI\SenderCode;

final class Generic extends Provider
{
	/**
	 * @inheritDoc
	 */
	public function createConnectionsSliderSections(array $senders): array
	{
		[$viewChannels, $usedSenders] = $this->createEditorViewChannels($senders);

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
			$usedSenders,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function createEditorViewChannels(array $senders): array
	{
		$viewChannels = [];

		foreach ($senders as $sender)
		{
			$title = (string)$sender->getShortName();
			if ($title === '')
			{
				$title = (string)$sender->getName();
			}

			$viewChannels[] = ViewChannel::fromSender(
				$this->makeId(SenderCode::SMS_PROVIDER, (string)$sender->getId()),
				$sender,
				SenderCode::SMS_PROVIDER,
				Appearance::generic($title),
				$sender->canUse(),
			);
		}

		return [
			$viewChannels,
			$senders,
		];
	}
}
