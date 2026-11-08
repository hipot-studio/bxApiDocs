<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator\Dto;

final class SqlExecutionContext
{
	public const CHANNEL_AI = 'ai';
	public const CHANNEL_REST = 'rest';

	private function __construct(
		public readonly string $channel,
		public readonly User $user,
	)
	{
		if ($user->id <= 0)
		{
			throw new \InvalidArgumentException('SQL execution context requires a positive user ID.');
		}
	}

	public static function forAi(User $user): self
	{
		return new self(self::CHANNEL_AI, $user);
	}

	public static function forRest(User $user): self
	{
		return new self(self::CHANNEL_REST, $user);
	}

	public function isRest(): bool
	{
		return $this->channel === self::CHANNEL_REST;
	}
}
