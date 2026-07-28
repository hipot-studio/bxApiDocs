<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\MessageEditor\Preferences;

use Bitrix\Main\Validation\Rule\NotEmpty;

final readonly class ChannelPosition implements \JsonSerializable
{
	public function __construct(
		#[NotEmpty]
		public string $channelId,
		public bool $isHidden,
	)
	{
	}

	public static function fromArray(array $data): ?self
	{
		$channelId = $data['channelId'] ?? null;
		$isHidden = $data['isHidden'] ?? null;

		if (!is_string($channelId) || !is_bool($isHidden))
		{
			return null;
		}

		return new self($channelId, $isHidden);
	}

	public function jsonSerialize(): array
	{
		return [
			'channelId' => $this->channelId,
			'isHidden' => $this->isHidden,
		];
	}
}
