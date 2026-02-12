<?php

namespace Bitrix\Bizproc\Activity\Dto\Complex;

use Bitrix\Main\Type\Contract\Arrayable;

final class NodeAction implements Arrayable, \JsonSerializable
{
	public function __construct(
		public readonly string $activityCode,
		public readonly ?string $customName = null,
		public readonly int $sort = 0,
		public readonly ?string $presetId = null,
	) {}

	public function toArray(): array
	{
		return [
			'activityCode' => $this->activityCode,
			'customName' => $this->customName,
			'sort' => $this->sort,
			'presetId' => $this->presetId,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	public function toPreset(): array
	{
		return [
			'NAME' => $this->customName,
			'SORT' => $this->sort,
		];
	}
}
