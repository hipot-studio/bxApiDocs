<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Internal\UI\Provider;

use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;
use Bitrix\MessageService\Public\UI\MessageEditor\Channel\From;
use Bitrix\MessageService\Public\UI\MessageEditor\PromoBanner;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel\Appearance;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel\Backend;
use Bitrix\MessageService\Public\UI\SenderCode;
use Bitrix\MessageService\Sender\Base;
use Bitrix\MessageService\Sender\SmsManager;

final class Wazzup extends Provider
{
	private const TYPE_TGAPI = \Bitrix\MessageService\Sender\Sms\Wazzup::CHANNEL_TYPE_TGAPI;
	private const TYPE_TELEGRAM = 'telegram';
	private const TYPE_WHATSAPP = \Bitrix\MessageService\Sender\Sms\Wazzup::CHANNEL_TYPE_WHATSAPP;
	private const PERSONAL_CHANNEL_ID = \Bitrix\MessageService\Sender\Sms\Wazzup::ID;

	/**
	 * @inheritDoc
	 * @throws InvalidOperationException
	 */
	public function createConnectionsSliderSections(array $senders): array
	{
		$telegramSender = null;
		$whatsappSender = null;

		$usedSenders = [];
		foreach ($senders as $sender)
		{
			if (self::isWazzupPersonal($sender))
			{
				$telegramSender = $sender;
				$whatsappSender = $sender;

				$usedSenders[] = $sender;
			}
			elseif (self::isWazzupWaba($sender))
			{
				$whatsappSender = $sender;

				$usedSenders[] = $sender;
			}
		}

		if (empty($usedSenders))
		{
			return [[], []];
		}

		if ($telegramSender === null && $whatsappSender === null)
		{
			throw new InvalidOperationException('usedSenders is not empty, but both telegram and whatsapp are unavailable. Should be impossible');
		}

		$telegramFrom = [];
		$whatsappFrom = [];

		foreach ($usedSenders as $sender)
		{
			$fromByType = $this->separateFromByType(ViewChannel::collectFromListForSender($sender));

			$telegramFrom = array_merge(
				$telegramFrom,
				$fromByType[self::TYPE_TELEGRAM] ?? [],
				$fromByType[self::TYPE_TGAPI] ?? [],
			);

			$whatsappFrom = array_merge(
				$whatsappFrom,
				$fromByType[self::TYPE_WHATSAPP] ?? [],
			);
		}

		$sectionViewChannels = [];

		if ($telegramSender)
		{
			$isConnected = !empty($telegramFrom);

			$sectionViewChannels[] = new \Bitrix\MessageService\Public\UI\ConnectionsSlider\Section\ViewChannel(
				$this->makeId(SenderCode::SMS_PROVIDER, (string)$telegramSender->getId(), ['type' => self::TYPE_TELEGRAM]),
				new Backend(
					SenderCode::SMS_PROVIDER,
					(string)$telegramSender->getId(),
					(string)$telegramSender->getName(),
					(string)$telegramSender->getShortName(),
					$telegramSender->isConfigurable() && $telegramSender->isTemplatesBased(),
				),
				$this->makeTelegramAppearance($telegramSender),
				$isConnected,
				$isConnected
					? $this->makeLastCreatedLineConnectionUrl((string)$telegramSender->getId(), $telegramFrom)
					: $this->getConnectionUrl((string)$telegramSender->getId()),
				true,
			);
		}

		if ($whatsappSender)
		{
			$isConnected = !empty($whatsappFrom);

			$sectionViewChannels[] = new \Bitrix\MessageService\Public\UI\ConnectionsSlider\Section\ViewChannel(
				$this->makeId(SenderCode::SMS_PROVIDER, (string)$whatsappSender->getId(), ['type' => self::TYPE_WHATSAPP]),
				// yes, channel can be different in different cases. for example when there are both waba and personal.
				// for now it's ok. if Pages change, maybe we will need to separate channels here too
				new Backend(
					SenderCode::SMS_PROVIDER,
					(string)$whatsappSender->getId(),
					(string)$whatsappSender->getName(),
					(string)$whatsappSender->getShortName(),
					$whatsappSender->isConfigurable() && $whatsappSender->isTemplatesBased(),
				),
				$this->makeWhatsAppAppearance($whatsappSender),
				$isConnected,
				$isConnected
					? $this->makeLastCreatedLineConnectionUrl((string)$whatsappSender->getId(), $whatsappFrom)
					: $this->getConnectionUrl((string)$whatsappSender->getId()),
				true,
			);
		}

		if (empty($sectionViewChannels))
		{
			throw new InvalidOperationException('Empty sectionViewChannels. Should be impossible');
		}

		return [
			[
				new \Bitrix\MessageService\Public\UI\ConnectionsSlider\Section(
					(string)Loc::getMessage('MESSAGESERVICE_WAZZUP_SECTION_TITLE'),
					$sectionViewChannels,
					(string)Loc::getMessage('MESSAGESERVICE_WAZZUP_SECTION_DESCRIPTION'),
					'linear-gradient(270deg, rgba(40, 210, 159, 0.9) 0%, rgba(35, 189, 110, 0.9) 75.96%, rgba(33, 177, 81, 0.9) 100%);',
					'/bitrix/components/bitrix/messageservice.connections/images/wazzup.svg',
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
		$usedSenders = [];
		$editorViewChannels = [];

		foreach ($senders as $sender)
		{
			if (self::isWazzupPersonal($sender))
			{
				$usedSenders[] = $sender;

				$editorViewChannels = [
					...$editorViewChannels,
					...$this->createPersonalEditorViewChannels($sender),
				];
			}
			elseif (self::isWazzupWaba($sender))
			{
				$usedSenders[] = $sender;

				$editorViewChannels[] = ViewChannel::fromSender(
					$this->makeId(SenderCode::SMS_PROVIDER, (string)$sender->getId(), ['type' => self::TYPE_WHATSAPP, 'subtype' => 'waba']),
					$sender,
					SenderCode::SMS_PROVIDER,
					Appearance::whatsappWaba()
						->setSubtitle($this->makeViaWazzupCaption($sender))
					,
					$sender->canUse(),
					null,
					true,
				);
			}
		}

		return [
			$editorViewChannels,
			$usedSenders,
		];
	}

	private function createPersonalEditorViewChannels(Base $sender): array
	{
		$fromByType = $this->separateFromByType(ViewChannel::collectFromListForSender($sender));

		$whatsappFrom = $fromByType[self::TYPE_WHATSAPP] ?? [];
		$telegramFrom = array_merge(
			$fromByType[self::TYPE_TELEGRAM] ?? [],
			$fromByType[self::TYPE_TGAPI] ?? [],
		);

		return [
			ViewChannel::fromSender(
				$this->makeId(SenderCode::SMS_PROVIDER, (string)$sender->getId(), ['type' => self::TYPE_TELEGRAM]),
				$sender,
				SenderCode::SMS_PROVIDER,
				$this->makeTelegramAppearance($sender),
				!empty($telegramFrom),
				$telegramFrom,
				true,
			),
			ViewChannel::fromSender(
				$this->makeId(SenderCode::SMS_PROVIDER, (string)$sender->getId(), ['type' => self::TYPE_WHATSAPP]),
				$sender,
				SenderCode::SMS_PROVIDER,
				$this->makeWhatsAppAppearance($sender),
				!empty($whatsappFrom),
				$whatsappFrom,
				true,
			),
		];
	}

	/**
	 * @param From[] $fromList
	 *
	 * @return array<string, From[]>
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

	private function makeTelegramAppearance(Base $sender): Appearance
	{
		return Appearance::telegram()
			->setSubtitle($this->makeViaWazzupCaption($sender))
			->setDescription((string)Loc::getMessage('MESSAGESERVICE_WAZZUP_TELEGRAM_CHANNEL_DESCRIPTION_MSGVER_1'))
		;
	}

	private function makeWhatsAppAppearance(Base $sender): Appearance
	{
		return Appearance::whatsapp()
			->setSubtitle($this->makeViaWazzupCaption($sender))
			->setDescription((string)Loc::getMessage('MESSAGESERVICE_WAZZUP_WHATSAAP_CHANNEL_DESCRIPTION_MSGVER_1'))
		;
	}


	private function makeViaWazzupCaption(Base $sender): string
	{
		return $this->makeViaCaption((string)$sender->getShortName());
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

		$allFromIds = array_map(static fn(From $singleFrom) => $singleFrom->getId(), $fromList);

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
			static fn(ViewChannel $vc): bool => self::isWazzupPersonalByBackend(
				$vc->getBackend(),
			) || self::isWazzupWabaByBackend(
				$vc->getBackend(),
			),
		);
		if (empty($wazzup))
		{
			// wazzup not available here
			return [];
		}

		$channel = current($wazzup);

		return [
			new PromoBanner(
				$this->makeId($channel->getBackend()->getSenderCode(), $channel->getBackend()->getId()),
				(string)Loc::getMessage('MESSAGESERVICE_WAZZUP_SECTION_TITLE'),
				(string)Loc::getMessage('MESSAGESERVICE_WAZZUP_BANNER_SUBTITLE'),
				'linear-gradient(270deg, rgba(40, 210, 159, 0.9) 0%, rgba(35, 189, 110, 0.9) 75.96%, rgba(33, 177, 81, 0.9) 100%)',
				null,
				'wazzup',
				$this->getConnectionUrl($channel->getBackend()->getId()),
			)
		];
	}

	private function getConnectionUrl(string $channelId): string
	{
		return (string)SmsManager::getSenderById($channelId)?->getManageUrl();
	}

	private static function isWazzupPersonal(Base $sender): bool
	{
		return (string)$sender->getId() === self::PERSONAL_CHANNEL_ID;
	}

	private static function isWazzupWaba(Base $sender): bool
	{
		return false;
	}

	private static function isWazzupPersonalByBackend(Backend $backend): bool
	{
		return $backend->getSenderCode() === SenderCode::SMS_PROVIDER && $backend->getId() === self::PERSONAL_CHANNEL_ID;
	}

	private static function isWazzupWabaByBackend(Backend $backend): bool
	{
		return false;
	}
}
