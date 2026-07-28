<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\MessageEditor\Preferences;

use Bitrix\Main\Validation\Rule\NotEmpty;

final readonly class ChannelLastUsedFrom implements \JsonSerializable
{
	public function __construct(
		#[NotEmpty]
		public string $channelId,
		#[NotEmpty]
		public string $fromId,
	)
	{
	}

	public static function fromArray(array $data): ?self
	{
		$channelId = $data['channelId'] ?? null;
		$fromId = $data['fromId'] ?? null;

		if (!is_string($channelId) || !is_string($fromId))
		{
			return null;
		}

		return new self($channelId, $fromId);
	}

	public function jsonSerialize(): array
	{
		return [
			'channelId' => $this->channelId,
			'fromId' => $this->fromId,
		];
	}
}
