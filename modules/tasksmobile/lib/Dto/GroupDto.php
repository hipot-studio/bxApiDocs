<?php

namespace Bitrix\Tasksmobile\Dto;

use Bitrix\Mobile\Dto\Dto;

class GroupDto extends Dto
{
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly ?string $image,
		public readonly ?string $resizedImage100,
		public readonly array $additionalData,
	)
	{
		parent::__construct();
	}
}
