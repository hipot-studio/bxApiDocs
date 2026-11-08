<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Project;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Socialnetwork\V2\Internal\Entity\File;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;

class MemberResponse implements Arrayable
{
	public function __construct(
		public readonly int $id,
		public readonly MemberEntityType $type,
		public readonly ?bool $withChildNodes = null,
		public readonly ?string $name = null,
		public readonly ?File $image = null,
		public readonly ?array $selectorItem = null,
	)
	{
	}

	public function toArray(): array
	{
		$result = [
			'id' => $this->id,
			'type' => $this->type->value,
			'withChildNodes' => $this->type === MemberEntityType::User
				? null
				: $this->withChildNodes,
			'name' => $this->name,
			'image' => $this->image === null
				? null
				: ['src' => $this->image->src],
		];

		if ($this->selectorItem !== null)
		{
			$result['selectorItem'] = $this->selectorItem;
		}

		return $result;
	}
}
