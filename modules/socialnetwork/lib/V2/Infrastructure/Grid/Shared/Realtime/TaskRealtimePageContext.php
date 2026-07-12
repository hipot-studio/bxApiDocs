<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Realtime;

use Bitrix\Main\Type\Contract\Arrayable;

class TaskRealtimePageContext implements Arrayable
{
	public const VERSION = 1;
	public const MODE_PROJECT = 'project';
	public const MODE_SCRUM = 'scrum';
	public const STRATEGY_RELOAD_ONLY = 'reload_only';
	public const STRATEGY_SELF_NARROW = 'self_narrow';

	public function __construct(
		public readonly int $version = self::VERSION,
		public readonly string $mode = '',
		public readonly string $taskRealtimeStrategy = '',
		public readonly int $contextUserId = 0,
		public readonly int $subjectId = 0,
		public readonly string $gridId = '',
		public readonly string $filterId = '',
		public readonly array $urlContext = [],
	)
	{
	}

	public static function mapFromArray(array $props): self
	{
		$gridId = trim((string)($props['gridId'] ?? ''));
		$filterId = trim((string)($props['filterId'] ?? ''));

		return new self(
			version: (int)($props['version'] ?? 0),
			mode: self::normalizeMode($props['mode'] ?? null),
			taskRealtimeStrategy: self::normalizeTaskRealtimeStrategy($props['taskRealtimeStrategy'] ?? null),
			contextUserId: max(0, (int)($props['contextUserId'] ?? 0)),
			subjectId: max(0, (int)($props['subjectId'] ?? 0)),
			gridId: $gridId,
			filterId: ($filterId !== '' ? $filterId : $gridId),
			urlContext: self::normalizeUrlContext($props['urlContext'] ?? []),
		);
	}

	public function toArray(): array
	{
		return [
			'version' => $this->version,
			'mode' => $this->mode,
			'taskRealtimeStrategy' => $this->taskRealtimeStrategy,
			'contextUserId' => $this->contextUserId,
			'subjectId' => $this->subjectId,
			'gridId' => $this->gridId,
			'filterId' => $this->filterId,
			'urlContext' => $this->urlContext,
		];
	}

	public function allowsSelfNarrowFor(int $currentUserId, string $expectedMode): bool
	{
		return (
			$this->version === self::VERSION
			&& $this->mode === $expectedMode
			&& $this->taskRealtimeStrategy === self::STRATEGY_SELF_NARROW
			&& $currentUserId > 0
			&& $this->contextUserId === $currentUserId
			&& $this->gridId !== ''
			&& $this->filterId !== ''
		);
	}

	private static function normalizeMode(mixed $mode): string
	{
		$mode = trim((string)$mode);

		return in_array($mode, [self::MODE_PROJECT, self::MODE_SCRUM], true)
			? $mode
			: ''
		;
	}

	private static function normalizeTaskRealtimeStrategy(mixed $taskRealtimeStrategy): string
	{
		$taskRealtimeStrategy = trim((string)$taskRealtimeStrategy);

		return in_array($taskRealtimeStrategy, [self::STRATEGY_RELOAD_ONLY, self::STRATEGY_SELF_NARROW], true)
			? $taskRealtimeStrategy
			: ''
		;
	}

	private static function normalizeUrlContext(mixed $urlContext): array
	{
		if (!is_array($urlContext))
		{
			return [];
		}

		$normalizedUrlContext = [];
		foreach ($urlContext as $key => $value)
		{
			if (!is_string($key) || $key === '')
			{
				continue;
			}

			if (is_scalar($value) || $value === null)
			{
				$normalizedUrlContext[$key] = (string)$value;
			}
		}

		return $normalizedUrlContext;
	}
}
