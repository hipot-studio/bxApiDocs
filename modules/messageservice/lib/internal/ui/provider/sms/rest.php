<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Internal\UI\Provider\Sms;

use Bitrix\MessageService\Internal\UI\Provider\Provider;
use Bitrix\MessageService\Public\UI\ConnectionsSlider;
use Bitrix\MessageService\Public\UI\MessageEditor\Channel\From;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel\Appearance;
use Bitrix\MessageService\Public\UI\SenderCode;
use Bitrix\MessageService\Sender\Base;

final class Rest extends Provider
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

		return [
			[
				new ConnectionsSlider\Section(
					'REST',
					array_map(
						static fn(ViewChannel $evc) => ConnectionsSlider\Section\ViewChannel::fromEditorViewChannel(
							$evc,
							'',
						),
						$editorViewChannels,
					),
				),
			],
			$usedSenders,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function createEditorViewChannels(array $senders): array
	{
		$usedSenders = array_filter(
			$senders,
			static fn(Base $sender): bool => (string)$sender->getId() === \Bitrix\MessageService\Sender\Sms\Rest::ID,
		);
		if (empty($usedSenders))
		{
			return [[], []];
		}

		$viewChannels = $this->createAllViewChannels($usedSenders);

		return [
			$viewChannels,
			$usedSenders,
		];
	}

	/**
	 * @param Base[] $senders
	 *
	 * @return ViewChannel[]
	 */
	private function createAllViewChannels(array $senders): array
	{
		$result = [];
		foreach ($senders as $sender)
		{
			$result = [
				...$result,
				...$this->createViewChannels($sender),
			];
		}

		return $result;
	}

	private function createViewChannels(Base $sender): array
	{
		$rawFromById = $this->getRawFromListMap($sender);
		$fromList = ViewChannel::collectFromListForSender($sender);

		$viewChannels = [];
		foreach ($this->groupFromListByApp($fromList, $rawFromById) as $appId => $singleAppFromList)
		{
			if (empty($singleAppFromList))
			{
				continue;
			}

			$firstFrom = reset($singleAppFromList);
			$firstRawFrom = $rawFromById[$firstFrom->getId()] ?? null;

			$viewChannels[] = ViewChannel::fromSender(
				$this->makeId(SenderCode::SMS_PROVIDER, (string)$sender->getId(), ['appId' => $appId]),
				$sender,
				SenderCode::SMS_PROVIDER,
				Appearance::generic($firstRawFrom['appName'] ?? '')
					->setSubtitle($this->makeViaCaption($firstRawFrom['appName'] ?? ''))
				,
				true,
				array_values($singleAppFromList),
			);
		}

		return $viewChannels;
	}

	private function getRawFromListMap(Base $sender): array
	{
		$rawFromById = [];
		foreach ($sender->getFromList() as $rawFrom)
		{
			if (!is_array($rawFrom))
			{
				continue;
			}

			$rawFromById[$rawFrom['id']] = $rawFrom;
		}

		return $rawFromById;
	}

	/**
	 * @param From[] $fromList
	 * @param array[] $rawFromById
	 * @return array<string, From[]>
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
			$groupedByApp[$appId][$appSenderId] = new From(
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
