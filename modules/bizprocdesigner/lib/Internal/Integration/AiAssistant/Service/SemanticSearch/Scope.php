<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\SemanticSearch;

final class Scope
{
	/**
	 * @param ScopeType $type
	 * @param array<string|int> $context
	 */
	public function __construct(
		public readonly ScopeType $type,
		public readonly array $context = []
	)
	{
		foreach ($this->context as $item)
		{
			if (!is_string($item) && !is_int($item))
			{
				throw new \InvalidArgumentException('Context items must be strings or integers.');
			}
		}
	}

	public function __toString(): string
	{
		if (empty($this->context))
		{
			return $this->type->value;
		}

		return $this->type->value . ':' . implode(':', $this->context);
	}
}