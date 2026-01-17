<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Provider;

use Bitrix\Crm\Integration\SmsManager;
use Bitrix\Crm\MessageSender\Channel;
use Bitrix\Crm\MessageSender\UI\Editor;
use Bitrix\Crm\MessageSender\UI\Editor\PromoBanner;
use Bitrix\Crm\MessageSender\UI\Editor\ViewChannel;
use Bitrix\Crm\MessageSender\UI\Provider;
use Bitrix\Crm\MessageSender\UI\Taxonomy;
use Bitrix\Crm\MessageSender\UI\ViewChannel\Appearance;
use Bitrix\Crm\MessageSender\UI\ViewChannel\Type;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;

final class Wazzup extends Provider
{
	public const TYPE_TGAPI = 'tgapi';

	/**
	 * @inheritDoc
	 * @throws InvalidOperationException
	 */
	public function createConnectionsSliderSections(array $channels): array
	{
		$telegramChannel = null;
		$whatsappChannel = null;

		$usedChannels = [];
		foreach ($channels as $channel)
		{
			if (Taxonomy::isWazzupPersonal($channel))
			{
				$telegramChannel = $channel;
				$whatsappChannel = $channel;

				$usedChannels[] = $channel;
			}
			elseif (Taxonomy::isWazzupWaba($channel))
			{
				$whatsappChannel = $channel;

				$usedChannels[] = $channel;
			}
		}

		if (empty($usedChannels))
		{
			return [[], []];
		}

		if ($telegramChannel === null && $whatsappChannel === null)
		{
			throw new InvalidOperationException('usedChannels is not empty, but both telegram and whatsapp are unavailable. Should be impossible');
		}

		$telegramFrom = [];
		$whatsappFrom = [];

		foreach ($usedChannels as $channel)
		{
			$fromByType = $this->separateFromByType($channel->getFromList());

			$telegramFrom = array_merge(
				$telegramFrom,
				$fromByType[Type::Telegram->value] ?? [],
				$fromByType[self::TYPE_TGAPI] ?? [],
			);

			$whatsappFrom = array_merge(
				$whatsappFrom,
				$fromByType[Type::Whatsapp->value] ?? [],
			);
		}

		$sectionViewChannels = [];

		if ($telegramChannel)
		{
			$isConnected = !empty($telegramFrom);

			$sectionViewChannels[] = new \Bitrix\Crm\MessageSender\UI\ConnectionsSlider\Section\ViewChannel(
				$this->makeId($telegramChannel, ['type' => Type::Telegram->value]),
				$telegramChannel,
				$this->makeTelegramAppearance($telegramChannel),
				$isConnected,
				$isConnected ? $this->makeLastCreatedLineConnectionUrl($telegramChannel->getId(), $telegramFrom) : $this->getConnectionUrl($telegramChannel->getId()),
				true,
			);
		}

		if ($whatsappChannel)
		{
			$isConnected = !empty($whatsappFrom);

			$sectionViewChannels[] = new \Bitrix\Crm\MessageSender\UI\ConnectionsSlider\Section\ViewChannel(
				$this->makeId($whatsappChannel, ['type' => Type::Whatsapp->value]),
				// yes, channel can be different in different cases. for example when there are both waba and personal.
				// for now it's ok. if Pages change, maybe we will need to separate channels here too
				$whatsappChannel,
				$this->makeWhatsAppAppearance($whatsappChannel),
				$isConnected,
				$isConnected ? $this->makeLastCreatedLineConnectionUrl($whatsappChannel->getId(), $whatsappFrom) : $this->getConnectionUrl($whatsappChannel->getId()),
				true,
			);
		}

		if (empty($sectionViewChannels))
		{
			throw new InvalidOperationException('Empty sectionViewChannels. Should be impossible');
		}

		return [
			[
				new \Bitrix\Crm\MessageSender\UI\ConnectionsSlider\Section(
					(string)Loc::getMessage('CRM_MESSAGESENDER_WAZZUP_SECTION_TITLE'),
					$sectionViewChannels,
					(string)Loc::getMessage('CRM_MESSAGESENDER_WAZZUP_SECTION_DESCRIPTION'),
					'linear-gradient(270deg, rgba(40, 210, 159, 0.9) 0%, rgba(35, 189, 110, 0.9) 75.96%, rgba(33, 177, 81, 0.9) 100%);',
					'/bitrix/components/bitrix/crm.messagesender.connections/images/wazzup.svg',
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
		$usedChannels = [];
		$editorViewChannels = [];

		foreach ($channels as $channel)
		{
			if (Taxonomy::isWazzupPersonal($channel))
			{
				$usedChannels[] = $channel;

				$editorViewChannels = [
					...$editorViewChannels,
					...$this->createPersonalEditorViewChannels($channel),
				];
			}
			elseif (Taxonomy::isWazzupWaba($channel))
			{
				$usedChannels[] = $channel;

				$editorViewChannels[] = new Editor\ViewChannel(
					$this->makeId($channel, ['type' => Type::Whatsapp->value, 'subtype' => 'waba']),
					$channel,
					Appearance::whatsappWaba()
						->setSubtitle($this->makeViaWazzupCaption($channel))
					,
					null,
					null,
					true,
				);
			}
		}

		return [
			$editorViewChannels,
			$usedChannels,
		];
	}

	private function createPersonalEditorViewChannels(Channel $channel): array
	{
		$fromByType = $this->separateFromByType($channel->getFromList());

		$whatsappFrom = $fromByType[Type::Whatsapp->value] ?? [];
		$telegramFrom = array_merge(
			$fromByType[Type::Telegram->value] ?? [],
			$fromByType[self::TYPE_TGAPI] ?? [],
		);

		return [
			new Editor\ViewChannel(
				$this->makeId($channel, ['type' => Type::Telegram->value]),
				$channel,
				$this->makeTelegramAppearance($channel),
				$telegramFrom,
				!empty($telegramFrom),
				true,
			),
			new Editor\ViewChannel(
				$this->makeId($channel, ['type' => Type::Whatsapp->value]),
				$channel,
				$this->makeWhatsAppAppearance($channel),
				$whatsappFrom,
				!empty($whatsappFrom),
				true,
			),
		];
	}

	/**
	 * @param Channel\Correspondents\From[] $fromList
	 *
	 * @return array<string, Channel\Correspondents\From[]>
	 */
	private function separateFromByType(array $fromList): array
	{
		$byType = [];
		foreach ($fromList as $from)
		{
			if ($from->getType() === null)
			{
				continue;
			}

			$byType[$from->getType()] ??= [];
			$byType[$from->getType()][] = $from;
		}

		return $byType;
	}

	private function makeTelegramAppearance(Channel $channel): Appearance
	{
		return Appearance::telegram()
			->setSubtitle($this->makeViaWazzupCaption($channel))
			->setDescription((string)Loc::getMessage('CRM_MESSAGESENDER_WAZZUP_TELEGRAM_CHANNEL_DESCRIPTION_MSGVER_1'))
		;
	}

	private function makeWhatsAppAppearance(Channel $channel): Appearance
	{
		return Appearance::whatsapp()
			->setSubtitle($this->makeViaWazzupCaption($channel))
			->setDescription((string)Loc::getMessage('CRM_MESSAGESENDER_WAZZUP_WHATSAAP_CHANNEL_DESCRIPTION_MSGVER_1'))
		;
	}


	private function makeViaWazzupCaption(Channel $channel): string
	{
		return $this->makeViaCaption($channel->getShortName());
	}

	private function makeLastCreatedLineConnectionUrl(string $channelId, array $fromList): string
	{
		$connectionUrl = $this->getConnectionUrl($channelId);

		$lineId = $this->getLastCreatedLineId($fromList);
		if ($lineId === null)
		{
			return $connectionUrl;
		}

		return (new Uri($connectionUrl))
			->addParams([
				'LINE' => $lineId,
			])
			->getUri()
		;
	}

	private function getLastCreatedLineId(array $fromList): ?int
	{
		if (!Loader::includeModule('imconnector'))
		{
			return null;
		}

		$wazzupStatuses = \Bitrix\ImConnector\Status::getInstanceAllLine(\Bitrix\ImConnector\Library::ID_WAZZUP_CONNECTOR);
		usort(
			$wazzupStatuses,
			// sort by line id desc, null in the end
			static function (\Bitrix\ImConnector\Status $left, \Bitrix\ImConnector\Status $right): int {
				$leftLineId = $left->getLine() ?? -PHP_INT_MAX;
				$rightLineId = $right->getLine() ?? -PHP_INT_MAX;

				return $rightLineId <=> $leftLineId;
			},
		);

		$allFromIds = array_map(static fn(Channel\Correspondents\From $singleFrom) => $singleFrom->getId(), $fromList);

		foreach ($wazzupStatuses as $status)
		{
			$lineChannelId = $status->getData()['channelId'] ?? null;
			if (!empty($lineChannelId) && in_array($lineChannelId, $allFromIds, true))
			{
				return $status->getLine();
			}
		}

		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function createEditorPromoBanners(array $editorViewChannels): array
	{
		$wazzup = array_filter(
			$editorViewChannels,
			static fn(ViewChannel $vc): bool => Taxonomy::isWazzupPersonal($vc->getBackend()) || Taxonomy::isWazzupWaba($vc->getBackend()),
		);
		if (empty($wazzup))
		{
			// wazzup not available here
			return [];
		}

		$channel = current($wazzup)->getBackend();

		return [
			new PromoBanner(
				$this->makeId($channel),
				(string)Loc::getMessage('CRM_MESSAGESENDER_WAZZUP_SECTION_TITLE'),
				(string)Loc::getMessage('CRM_MESSAGESENDER_WAZZUP_BANNER_SUBTITLE'),
				'linear-gradient(270deg, rgba(40, 210, 159, 0.9) 0%, rgba(35, 189, 110, 0.9) 75.96%, rgba(33, 177, 81, 0.9) 100%)',
				null,
				'wazzup',
				$this->getConnectionUrl($channel->getId()),
			)
		];
	}

	private function getConnectionUrl(string $channelId): string
	{
		return (string)SmsManager::getSenderById($channelId)?->getManageUrl();
	}
}
