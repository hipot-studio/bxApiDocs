<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Update;

use Bitrix\Im\V2\Chat\ChatIds;
use Bitrix\Im\V2\Chat\DialogChatIdResolver;
use Bitrix\Im\V2\Folder\Error\IneligibleChatsException;
use Bitrix\Im\V2\Folder\FolderLimits;
use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Main\Validation\Rule\NotEmpty;

final class UpdateFields
{
	private const CLEAR_SENTINEL = 'N';

	/**
	 * Left UNINITIALIZED when not provided: ValidationService validates a property only when
	 * ReflectionProperty::isInitialized(), so an omitted (uninitialized, nullable) title is skipped —
	 * partial-update (PATCH) semantics. Read via isset(); validated by the attributes when provided.
	 */
	#[NotEmpty]
	#[Length(min: 1, max: FolderLimits::MAX_TITLE_LENGTH)]
	public readonly ?string $title;

	/**
	 * @param ?ChatIds $chatIds null = composition not provided (no-op); empty = clear; non-empty = set.
	 */
	public function __construct(?string $title, public readonly ?ChatIds $chatIds = null)
	{
		if ($title !== null)
		{
			$this->title = $title;
		}
	}

	public static function fromArray(array $data, DialogChatIdResolver $resolver, int $currentUserId = 0): self
	{
		$title = array_key_exists('title', $data) ? trim((string)$data['title']) : null;

		return new self($title, self::resolveComposition($data, $resolver, $currentUserId));
	}

	private static function resolveComposition(array $data, DialogChatIdResolver $resolver, int $userId): ?ChatIds
	{
		if (!array_key_exists('chatIds', $data) && !array_key_exists('dialogIds', $data))
		{
			return null;
		}

		$rawChatIds = $data['chatIds'] ?? null;
		$rawDialogIds = $data['dialogIds'] ?? null;

		$safeChatIds = is_array($rawChatIds) ? $rawChatIds : [];
		$safeDialogIds = is_array($rawDialogIds) ? $rawDialogIds : [];

		// Reject an over-limit raw composition before resolving (see FolderFields): the set-composition
		// path would otherwise create surplus private chats before the downstream capacity check.
		if (FolderLimits::exceedsChatComposition($safeChatIds, $safeDialogIds))
		{
			throw new IneligibleChatsException('Folder chat composition exceeds the per-folder limit.');
		}

		$resolved = $resolver->resolve($safeChatIds, $safeDialogIds, $userId);
		if (!$resolved->isEmpty())
		{
			return $resolved;
		}

		// Scalar sentinel 'N' is the only way the form transport can signal "clear".
		if (self::isClearSentinel($rawChatIds) || self::isClearSentinel($rawDialogIds))
		{
			return new ChatIds();
		}

		if ((is_array($rawChatIds) && $rawChatIds !== []) || (is_array($rawDialogIds) && $rawDialogIds !== []))
		{
			// Non-empty input that resolved to nothing — reject, never a silent wipe.
			throw new IneligibleChatsException('Folder chat composition resolved to no eligible chats.');
		}

		return null;
	}

	/**
	 * @param mixed $value
	 */
	private static function isClearSentinel($value): bool
	{
		return is_scalar($value) && (string)$value === self::CLEAR_SENTINEL;
	}
}
