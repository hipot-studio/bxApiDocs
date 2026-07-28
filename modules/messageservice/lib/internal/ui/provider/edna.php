<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Internal\UI\Provider;

use Bitrix\Main\Localization\Loc;
use Bitrix\MessageService\Public\UI\ConnectionsSlider;
use Bitrix\MessageService\Public\UI\MessageEditor\PromoBanner;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel\Appearance;
use Bitrix\MessageService\Public\UI\SenderCode;
use Bitrix\MessageService\Sender\Base;
use Bitrix\MessageService\Sender\Sms\Ednaru;
use Bitrix\MessageService\Sender\SmsManager;

final class Edna extends Provider
{
	/**
	 * @inheritDoc
	 */
	public function createConnectionsSliderSections(array $senders): array
	{
		[$viewChannels, $usedSenders] = $this->createEditorViewChannels($senders);
		if (empty($viewChannels))
		{
			return [[], $usedSenders];
		}

		$sectionViewChannels = array_map(
			static fn(ViewChannel $evc) => ConnectionsSlider\Section\ViewChannel::fromEditorViewChannel(
				$evc,
				SmsManager::getSenderById($evc->getBackend()->getId())?->getManageUrl(),
			),
			$viewChannels,
		);

		return [
			[
				new ConnectionsSlider\Section(
					(string)Loc::getMessage('MESSAGESERVICE_EDNA_SECTION_TITLE'),
					$sectionViewChannels,
					null,
					'linear-gradient(270deg, #000501 0%, #135120 100%)',
					'/bitrix/components/bitrix/messageservice.connections/images/edna.svg',
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
			static fn(Base $sender): bool => (string)$sender->getId() === Ednaru::ID,
		);
		if (empty($usedSenders))
		{
			return [[], []];
		}

		$viewChannels = $this->createAllEditorViewChannels($usedSenders);

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
	private function createAllEditorViewChannels(array $senders): array
	{
		$result = [];
		foreach ($senders as $sender)
		{
			$result = [
				...$result,
				...$this->createForSingleSender($sender),
			];
		}

		return $result;
	}

	/**
	 * @return ViewChannel[]
	 */
	private function createForSingleSender(Base $sender): array
	{
		return [
			ViewChannel::fromSender(
				$this->makeId(SenderCode::SMS_PROVIDER, (string)$sender->getId()),
				$sender,
				SenderCode::SMS_PROVIDER,
				Appearance::whatsappWaba()
					->setSubtitle($this->makeViaCaption((string)$sender->getShortName()))
					->setDescription((string)Loc::getMessage('MESSAGESERVICE_EDNA_CHANNEL_DESCRIPTION_MSGVER_1'))
				,
				$sender->canUse(),
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
		$edna = array_filter(
			$editorViewChannels,
			static fn(ViewChannel $vc): bool => $vc->getBackend()->getSenderCode() === SenderCode::SMS_PROVIDER
				&& $vc->getBackend()->getId() === Ednaru::ID,
		);
		if (empty($edna))
		{
			// edna not available here
			return [];
		}

		$channel = current($edna);

		return [
			new PromoBanner(
				$this->makeId($channel->getBackend()->getSenderCode(), $channel->getBackend()->getId()),
				(string)Loc::getMessage('MESSAGESERVICE_EDNA_SECTION_TITLE'),
				// hack to use the message
				Appearance::whatsapp()->getTitle(),
				'linear-gradient(270deg, #000501 0%, #135120 100%)',
				null,
				'edna',
				SmsManager::getSenderById($channel->getBackend()->getId())?->getManageUrl() ?? '',
			)
		];
	}
}
