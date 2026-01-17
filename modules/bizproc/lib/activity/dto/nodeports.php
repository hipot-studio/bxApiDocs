<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto;

use Bitrix\Main\Type\Contract\Arrayable;

final class NodePorts implements Arrayable, \JsonSerializable
{
	public function __construct(
		public readonly ?PortCollection $input = null,
		public readonly ?PortCollection $output = null,
		public readonly ?PortCollection $aux = null,
		public readonly ?PortCollection $topAux = null,
	) {}

	public static function fromArray(array $array): self
	{
		return new self(
			is_array($array['input'] ?? null) ? PortCollection::fromArray($array['input']) : null,
			is_array($array['output'] ?? null) ? PortCollection::fromArray($array['output']) : null,
			is_array($array['aux'] ?? null) ? PortCollection::fromArray($array['aux']) : null,
			is_array($array['topAux'] ?? null) ? PortCollection::fromArray($array['topAux']) : null,
		);
	}

	public function toArray(): array
	{
		return [
			'input' => $this->input?->toArray(),
			'output' => $this->output?->toArray(),
			'aux' => $this->aux?->toArray(),
			'topAux' => $this->topAux?->toArray(),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
