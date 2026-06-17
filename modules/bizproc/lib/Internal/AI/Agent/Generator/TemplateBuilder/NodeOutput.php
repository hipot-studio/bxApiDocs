<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder;

use Bitrix\Bizproc\Activity\Enum\ActivityPortType;

final class NodeOutput
{
	private const PORT_PRIMARY = 'o0';
	private const PORT_SECONDARY = 'o1';

	public function __construct(
		public readonly string $name = '',
		public readonly string $port = self::PORT_PRIMARY,
	) {}

	public static function sequential(string $name): self
	{
		return new self($name, self::PORT_PRIMARY);
	}

	public static function conditionTrue(string $name): self
	{
		return new self($name, self::PORT_PRIMARY);
	}

	public static function conditionFalse(string $name): self
	{
		return new self($name, self::PORT_SECONDARY);
	}

	public static function compositeExit(string $name): self
	{
		return new self($name, self::PORT_SECONDARY);
	}

	public function toPortArray(): array
	{
		return ['id' => $this->port, 'type' => ActivityPortType::Output->value];
	}
}
