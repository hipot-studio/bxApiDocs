<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto;

use Bitrix\Bizproc\Activity\Enum\ActivityPortType;
use Bitrix\Main\Type\Contract\Arrayable;

final class NodePorts implements Arrayable, \JsonSerializable
{
	public function __construct(
		public readonly ?PortCollection $input = null,
		public readonly ?PortCollection $output = null,
		public readonly ?PortCollection $aux = null,
		public readonly ?PortCollection $topAux = null,
		public readonly ?PortCollection $inputRelation = null,
		public readonly ?PortCollection $outputRelation = null,
	) {}

	public static function fromArray(array $array): self
	{
		$array = self::normalizeArrayCompatible($array);

		return new self(
			is_array($array[ActivityPortType::Input->value] ?? null)
				? PortCollection::fromArray($array[ActivityPortType::Input->value]) : null
			,
			is_array($array[ActivityPortType::Output->value] ?? null)
				? PortCollection::fromArray($array[ActivityPortType::Output->value]) : null
			,
			is_array($array[ActivityPortType::Aux->value] ?? null)
				? PortCollection::fromArray($array[ActivityPortType::Aux->value]) : null
			,
			is_array($array[ActivityPortType::TopAux->value] ?? null)
				? PortCollection::fromArray($array[ActivityPortType::TopAux->value]) : null
			,
			is_array($array[ActivityPortType::InputRelation->value] ?? null)
				? PortCollection::fromArray($array[ActivityPortType::InputRelation->value]) : null
			,
			is_array($array[ActivityPortType::OutputRelation->value] ?? null)
				? PortCollection::fromArray($array[ActivityPortType::OutputRelation->value]) : null
			,
		);
	}

	public function toArray(): array
	{
		return [
			...self::portsToArray($this->input, ActivityPortType::Input->value),
			...self::portsToArray($this->output, ActivityPortType::Output->value),
			...self::portsToArray($this->aux, ActivityPortType::Aux->value),
			...self::portsToArray($this->topAux, ActivityPortType::TopAux->value),
			...self::portsToArray($this->inputRelation, ActivityPortType::InputRelation->value),
			...self::portsToArray($this->outputRelation, ActivityPortType::OutputRelation->value),
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
