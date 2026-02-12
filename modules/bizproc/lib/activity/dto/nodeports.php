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
		$array = self::normalizeArrayCompatible($array);

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
			...self::portsToArray($this->input, 'input'),
			...self::portsToArray($this->output, 'output'),
			...self::portsToArray($this->aux, 'aux'),
			...self::portsToArray($this->topAux, 'topAux'),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	private static function normalizeArrayCompatible(array $array): array
	{
		$normalized = [];

		if (isset($array['input']) || isset($array['output']) || isset($array['aux']) || isset($array['topAux']))
		{
			foreach ($array as $type => $ports)
			{
				if (!is_array($ports))
				{
					continue;
				}

				foreach ($ports as $port)
				{
					$port['type'] = $type;
					$normalized[$type][] = $port;
				}
			}

			return $normalized;
		}

		/** @var Port $value */
		foreach ($array as $value)
		{
			$normalized[$value['type']][] = $value;
		}

		return $normalized;
	}

	private static function portsToArray(?PortCollection $collection, string $type): array
	{
		if ($collection === null)
		{
			return [];
		}

		$ports = [];

		foreach ($collection->toArray() as $port)
		{
			$port['type'] = $type;
			$ports[] = $port;
		}

		return $ports;
	}
}
