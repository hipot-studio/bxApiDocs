<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Create;

use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Main\Validation\Rule\NotEmpty;

final class FolderFields
{
	/**
	 * @param int[] $chatIds
	 */
	public function __construct(
		#[NotEmpty]
		#[Length(min: 1, max: 30)]
		public readonly string $title,

		#[ElementsType(Type::Integer)]
		public readonly array $chatIds = [],
	) {}

	public static function fromArray(array $data): self
	{
		$title = isset($data['title']) ? trim((string)$data['title']) : '';
		$chatIds = isset($data['chatIds']) && is_array($data['chatIds'])
			? array_values(array_unique(array_map('intval', $data['chatIds'])))
			: [];

		return new self(title: $title, chatIds: $chatIds);
	}
}
