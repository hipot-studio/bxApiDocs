<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Update;

use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Main\Validation\Rule\NotEmpty;

final class UpdateFields
{
	public function __construct(
		#[NotEmpty]
		#[Length(min: 1, max: 30)]
		public readonly string $title,
	) {}

	public static function fromArray(array $data): self
	{
		return new self(
			title: isset($data['title']) ? trim((string)$data['title']) : '',
		);
	}
}
