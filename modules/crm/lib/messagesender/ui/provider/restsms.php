<?php

namespace Bitrix\Crm\MessageSender\UI\Provider;

use Bitrix\Crm\MessageSender\Channel;
use Bitrix\Crm\MessageSender\UI\Provider;
use Bitrix\Crm\MessageSender\UI\Taxonomy;
use Bitrix\Crm\MessageSender\UI\ViewChannel;
use Bitrix\Crm\MessageSender\UI\ViewChannel\Appearance;
use Bitrix\Crm\MessageSender\UI\ViewChannel\Type;

final class RestSms extends Provider
{
	/**
	 * @inheritDoc
	 */
	public function createSections(array $channels): array
	{
		$usedChannels = array_filter($channels, Taxonomy::isRestSms(...));
		if (empty($usedChannels))
		{
			return [[], []];
		}

		$viewChannels = $this->createAllViewChannels($usedChannels);

		return [
			[
				new \Bitrix\Crm\MessageSender\UI\Section(
					'REST',
					$viewChannels,
				),
			],
			$usedChannels,
		];
	}

	/**
	 * @param Channel[] $channels
	 *
	 * @return ViewChannel[]
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
		return array_map(
			fn(Channel\Correspondents\From $from) => new ViewChannel(
				$this->makeId($channel, ['from' => $from->getId()]),
				$channel,
				Type::Generic,
				Appearance::generic($from->getName())
					->setSubtitle($from->getDescription())
				,
				[$from],
				true,
				'',
			),
			$channel->getFromList(),
		);
	}
}
