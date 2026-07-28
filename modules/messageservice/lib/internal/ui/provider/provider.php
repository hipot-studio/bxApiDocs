<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Internal\UI\Provider;

use Bitrix\Main\Localization\Loc;
use Bitrix\MessageService\Public\UI\ConnectionsSlider\Section;
use Bitrix\MessageService\Public\UI\MessageEditor\PromoBanner;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel;
use Bitrix\MessageService\Sender\Base;

abstract class Provider
{
	/**
	 * @param Base[] $senders
	 *
	 * @return array{
	 *     0: Section[],
	 *     1: Base[]
	 * }
	 */
	abstract public function createConnectionsSliderSections(array $senders): array;

	/**
	 * @param Base[] $senders
	 *
	 * @return array{
	 *     0: ViewChannel[],
	 *     1: Base[]
	 * }
	 */
	abstract public function createEditorViewChannels(array $senders): array;

	/**
	 * @param ViewChannel[] $editorViewChannels
	 *
	 * @return PromoBanner[]
	 */
	public function createEditorPromoBanners(array $editorViewChannels): array
	{
		return [];
	}

	final protected function makeId(string $senderCode, string $senderId, array $additionalParams = []): string
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
			$senderCode,
			$senderId,
			...array_values($additionalParams),
		];

		return implode('~~~', $parts);
	}

	final protected function makeViaCaption(string $channelName): string
	{
		return (string)Loc::getMessage('MESSAGESERVICE_UI_PROVIDER_VIA', ['#CHANNEL#' => $channelName]);
	}
}
