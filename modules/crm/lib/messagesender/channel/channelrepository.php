<?php

namespace Bitrix\Crm\MessageSender\Channel;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\MessageSender\Channel;
use Bitrix\Crm\MessageSender\SenderRepository;
use Bitrix\Crm\Service\Container;

final class ChannelRepository
{
	/** @var Channel[]|null */
	private ?array $channels = null;

	private function __construct(
		private readonly Correspondents\ToRepository $toRepository,
		private readonly int $userId,
		private readonly bool $checkPermissions,
	)
	{
	}

	private function __clone()
	{
	}

	public static function create(ItemIdentifier $source, ?int $userId = null): self
	{
		return self::doCreate($source, $userId, false);
	}

	public static function createWithPermissions(ItemIdentifier $source, ?int $userId = null): self
	{
		return self::doCreate($source, $userId, true);
	}

	private static function doCreate(ItemIdentifier $source, ?int $userId, bool $checkPerms): self
	{
		$userId ??= Container::getInstance()->getContext()->getUserId();

		$toRepo = Correspondents\ToRepository::create($source);
		$toRepo->setUserId($userId);
		$toRepo->setCheckPermissions($checkPerms);

		return new self($toRepo, $userId, $checkPerms);
	}

	public function getAll(): array
	{
		if (is_array($this->channels))
		{
			return $this->channels;
		}

		$this->channels = [];

		if (
			$this->checkPermissions
			&& !Container::getInstance()->getUserPermissions($this->userId)->messageSender()->canSendItemIdentifier(
				$this->toRepository->getItemIdentifier()
			)
		)
		{
			// don't show channels to user that don't have access to messages
			return $this->channels;
		}

		$senders = SenderRepository::getAllImplementationsList();
		foreach ($senders as $sender)
		{
			foreach ($sender::getChannelsList($this->toRepository->getAllSeparatedByType(), $this->userId) as $channel)
			{
				$this->channels[] = $channel;
			}
		}

		return $this->channels;
	}

	/**
	 * @param string $senderCode
	 * @return Channel[]
	 */
	public function getListBySender(string $senderCode): array
	{
		$result = [];
		foreach ($this->getAll() as $channel)
		{
			if ($channel->getSender()::getSenderCode() === $senderCode)
			{
				$result[] = $channel;
			}
		}

		return $result;
	}

	public function getById(string $senderCode, string $channelId): ?Channel
	{
		foreach ($this->getAll() as $channel)
		{
			if ($channel->getId() === $channelId && $channel->getSender()::getSenderCode() === $senderCode)
			{
				return $channel;
			}
		}

		return null;
	}

	public function getDefaultForSender(string $senderCode): ?Channel
	{
		foreach ($this->getListBySender($senderCode) as $channel)
		{
			if ($channel->isDefault())
			{
				return $channel;
			}
		}

		return null;
	}

	public function getBestUsableBySender(string $senderCode): ?Channel
	{
		$default = $this->getDefaultForSender($senderCode);
		if ($default && $default->canSendMessage())
		{
			return $default;
		}

		foreach ($this->getListBySender($senderCode) as $channel)
		{
			if ($channel->canSendMessage())
			{
				return $channel;
			}
		}

		return null;
	}

	public function getToList(): array
	{
		return $this->toRepository->getAll();
	}
}
