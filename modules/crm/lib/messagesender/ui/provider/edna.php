<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Provider;

use Bitrix\Crm\Integration\SmsManager;
use Bitrix\Crm\MessageSender\Channel;
use Bitrix\Crm\MessageSender\UI\ConnectionsSlider;
use Bitrix\Crm\MessageSender\UI\Editor;
use Bitrix\Crm\MessageSender\UI\Editor\PromoBanner;
use Bitrix\Crm\MessageSender\UI\Provider;
use Bitrix\Crm\MessageSender\UI\Taxonomy;
use Bitrix\Crm\MessageSender\UI\ViewChannel\Appearance;
use Bitrix\Main\Localization\Loc;

final class Edna extends Provider
{
	/**
	 * @inheritDoc
	 */
	public function createConnectionsSliderSections(array $channels): array
	{
		[$viewChannels, $usedChannels] = $this->createEditorViewChannels($channels);
		if (empty($viewChannels))
		{
			return [[], $usedChannels];
		}

		$sectionViewChannels = array_map(
			static fn(Editor\ViewChannel $evc) => ConnectionsSlider\Section\ViewChannel::fromEditorViewChannel(
				$evc,
				SmsManager::getSenderById($evc->getBackend()->getId())?->getManageUrl(),
			),
			$viewChannels,
		);

		return [
			[
				new ConnectionsSlider\Section(
					(string)Loc::getMessage('CRM_MESSAGESENDER_EDNA_SECTION_TITLE'),
					$sectionViewChannels,
					null,
					'linear-gradient(270deg, #000501 0%, #135120 100%)',
					'/bitrix/components/bitrix/crm.messagesender.connections/images/edna.svg',
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
		$usedChannels = array_filter($channels, Taxonomy::isEdnaWaba(...));
		if (empty($usedChannels))
		{
			return [[], []];
		}

		$viewChannels = $this->createAllEditorViewChannels($usedChannels);

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
	private function createAllEditorViewChannels(array $channels): array
	{
		$result = [];
		foreach ($channels as $channel)
		{
			$result = [
				...$result,
				...$this->createForSingleChannel($channel),
			];
		}

		return $result;
	}

	/**
	 * @return Editor\ViewChannel[]
	 */
	private function createForSingleChannel(Channel $channel): array
	{
		return [
			new Editor\ViewChannel(
				$this->makeId($channel),
				$channel,
				Appearance::whatsappWaba()
					->setSubtitle($this->makeViaCaption($channel->getShortName()))
					->setDescription((string)Loc::getMessage('CRM_MESSAGESENDER_EDNA_CHANNEL_DESCRIPTION_MSGVER_1'))
				,
				null,
				null,
				true,
			),
		];
	}

	/**
	 * @inheritDoc
	 */
	public function createEditorPromoBanners(array $editorViewChannels): array
	{
		$edna = array_filter($editorViewChannels, static fn(Editor\ViewChannel $vc) => Taxonomy::isEdnaWaba($vc->getBackend()));
		if (empty($edna))
		{
			// edna not available here
			return [];
		}

		$channel = current($edna)->getBackend();

		return [
			new PromoBanner(
				$this->makeId($channel),
				(string)Loc::getMessage('CRM_MESSAGESENDER_EDNA_SECTION_TITLE'),
				// hack to use the message
				Appearance::whatsapp()->getTitle(),
				'linear-gradient(270deg, #000501 0%, #135120 100%)',
				null,
				'edna',
				SmsManager::getSenderById($channel->getId())?->getManageUrl(),
			)
		];
	}
}
