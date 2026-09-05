<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Create;

use Bitrix\Im\V2\Chat\DialogChatIdResolver;
use Bitrix\Im\V2\Folder\Error\IneligibleChatsException;
use Bitrix\Im\V2\Folder\FolderLimits;
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
		#[Length(min: 1, max: FolderLimits::MAX_TITLE_LENGTH)]
		public readonly string $title,

		#[ElementsType(Type::Integer)]
		public readonly array $chatIds = [],
	) {}

	public static function fromArray(array $data, DialogChatIdResolver $resolver, int $currentUserId = 0): self
	{
		$title = isset($data['title']) ? trim((string)$data['title']) : '';

		$rawChatIds = is_array($data['chatIds'] ?? null) ? $data['chatIds'] : [];
		$rawDialogIds = is_array($data['dialogIds'] ?? null) ? $data['dialogIds'] : [];

		// Reject an over-limit raw composition before resolving: resolving with createMissing would
		// otherwise spawn a private chat per surplus dialogId that can never fit the folder.
		if (FolderLimits::exceedsChatComposition($rawChatIds, $rawDialogIds))
		{
			throw new IneligibleChatsException('Folder chat composition exceeds the per-folder limit.');
		}

		$chatIds = $resolver->resolve($rawChatIds, $rawDialogIds, $currentUserId);

		return new self(title: $title, chatIds: $chatIds->values);
	}
}
