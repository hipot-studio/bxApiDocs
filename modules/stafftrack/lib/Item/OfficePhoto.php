<?php

namespace Bitrix\StaffTrack\Item;

use Bitrix\Main\Type\Contract\Arrayable;

class OfficePhoto implements Arrayable
{
	public function __construct(
		public int $id,
		public string $url,
		public string $previewUrl,
	) {}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'url' => $this->url,
			'previewUrl' => $this->previewUrl,
		];
	}
}
