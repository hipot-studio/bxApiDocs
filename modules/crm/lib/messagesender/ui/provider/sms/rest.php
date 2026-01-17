<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Provider\Sms;

use Bitrix\Crm\MessageSender\Channel;
use Bitrix\Crm\MessageSender\UI\ConnectionsSlider;
use Bitrix\Crm\MessageSender\UI\Editor;
use Bitrix\Crm\MessageSender\UI\Provider;
use Bitrix\Crm\MessageSender\UI\Taxonomy;
use Bitrix\Crm\MessageSender\UI\ViewChannel\Appearance;

final class Rest extends Provider
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

		return [
			[
				new ConnectionsSlider\Section(
					'REST',
					array_map(
						static fn(Editor\ViewChannel $evc) => ConnectionsSlider\Section\ViewChannel::fromEditorViewChannel(
							$evc,
							'',
						),
						$editorViewChannels,
					),
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
		$usedChannels = array_filter($channels, Taxonomy::isRestSms(...));
		if (empty($usedChannels))
		{
			return [[], []];
		}

		$viewChannels = $this->createAllViewChannels($usedChannels);

		return [
			$viewChannels,
			$usedChannels,
		];
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
		$rawFromById = $this->getRawFromListMap($channel->getId());

		$viewChannels = [];
		foreach ($this->groupFromListByApp($channel->getFromList(), $rawFromById) as $appId => $fromList)
		{
			if (empty($fromList))
			{
				continue;
			}

			$firstFrom = reset($fromList);
			$firstRawFrom = $rawFromById[$firstFrom->getId()] ?? null;

			$viewChannels[] = new Editor\ViewChannel(
				$this->makeId($channel, ['appId' => $appId]),
				$channel,
				Appearance::generic($firstRawFrom['appName'] ?? '')
					->setSubtitle($this->makeViaCaption($firstRawFrom['appName'] ?? ''))
				,
				array_values($fromList),
				true,
			);
		}

		return $viewChannels;
	}

	private function getRawFromListMap(string $channelId): array
	{
		$rawFromList = \Bitrix\Crm\Integration\SmsManager::getSenderFromList($channelId);

		$rawFromById = [];
		foreach ($rawFromList as $rawFrom)
		{
			$rawFromById[$rawFrom['id']] = $rawFrom;
		}

		return $rawFromById;
	}

	/**
	 * @param Channel\Correspondents\From[] $fromList
	 * @param array[] $rawFromById
	 * @return array<string, Channel\Correspondents\From[]>
	 */
	private function groupFromListByApp(array $fromList, array $rawFromById): array
	{
		$groupedByApp = [];
		foreach ($fromList as $from)
		{
			$fromId = $from->getId();
			if (!str_contains($fromId, '|'))
			{
				continue;
			}
			[$appId, $appSenderId] = explode('|', $fromId, 2);
			if (empty($appId) || empty($appSenderId))
			{
				continue;
			}

			$fromName = $from->getName();
			if (!empty($rawFromById[$fromId]['appFromName']))
			{
				$fromName = $rawFromById[$fromId]['appFromName'];
			}

			$groupedByApp[$appId] ??= [];
			$groupedByApp[$appId][$appSenderId] = new Channel\Correspondents\From(
				$from->getId(),
				$fromName,
				$from->getDescription(),
				$from->isDefault(),
				$from->isAvailable(),
				$from->getType(),
			);
		}

		return $groupedByApp;
	}
}
