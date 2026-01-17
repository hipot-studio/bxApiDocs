<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto;

use Bitrix\Main\Type\Contract\Arrayable;

final class PortCollection implements Arrayable, \JsonSerializable
{
	public array $ports = [];
	public function __construct(
		Port ...$ports,
	)
	{
		$this->ports = $ports;
	}

	public static function fromArray(array $array): self
	{
		return new self(
			...array_map(static fn(array $port) => Port::fromArray($port), $array),
		);
	}

	public function toArray(): array
	{
		return array_map(static fn(Port $port) => $port->toArray(), $this->ports);
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
