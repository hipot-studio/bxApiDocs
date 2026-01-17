<?php

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;
class BlockTypeDetail
{
	public function __construct(
		public readonly BlockType $block,
		public readonly SettingCollection $settings,
		public readonly ReturnFieldCollection $returnFields,
		/** @var array<string, string> [type => description, ...] $describedTypes */
		public readonly array $describedTypes = [],
	) {}

	public function toArray(): array
	{
		$array = [
			'block' => $this->block->toArray(),
			'settings' => $this->settings->toArray(),
		];

		if ($this->returnFields->getIterator()->count() > 0)
		{
			$array['returnFields'] = $this->returnFields->toArray();
		}

		if (!empty($this->describedTypes))
		{
			$array['typesDescription'] = $this->describedTypes;
		}

		return $array;
	}
}