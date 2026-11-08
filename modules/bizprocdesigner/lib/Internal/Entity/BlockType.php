<?php

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

class BlockType implements Arrayable
{
	public function __construct(
		public readonly string $type,
		public readonly string $description,
		public readonly ?string $presetId = null,
		/**
		 * Whether the block has an editable settings schema at all, so that a block without settings by nature
		 * is told apart from one whose schema could not be built. Belongs to the catalog listing, where the
		 * schema itself is not answered; null where nothing computed it and the key stays out of the payload.
		 */
		public readonly ?bool $hasSettings = null,
	) {}

	public function toArray(): array
	{
		$array = [
			'type' => $this->type,
			'description' => $this->description,
		];

		if ($this->presetId !== null)
		{
			$array['presetId'] = $this->presetId;
		}

		if ($this->hasSettings !== null)
		{
			$array['hasSettings'] = $this->hasSettings;
		}

		return $array;
	}
}