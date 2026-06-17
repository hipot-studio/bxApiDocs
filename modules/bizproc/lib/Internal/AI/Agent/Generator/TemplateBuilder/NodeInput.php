<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder;

use Bitrix\Bizproc\Activity\Enum\ActivityPortType;

final class NodeInput
{
	private const PORT_PRIMARY = 'i0';
	private const PORT_RETURN = 'i1';

	public function __construct(
		public readonly string $name = '',
		public readonly string $port = self::PORT_PRIMARY,
	) {}

	public static function standard(string $name): self
	{
		return new self($name, self::PORT_PRIMARY);
	}

	public static function compositeReturn(string $name): self
	{
		return new self($name, self::PORT_RETURN);
	}

	public function toPortArray(): array
	{
		return ['id' => $this->port, 'type' => ActivityPortType::Input->value];
	}
}
