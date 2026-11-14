<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum;

/**
 * Context of a write operation, passed through $options of write methods.
 * The format restriction policy is applied depending on it.
 */
enum WriteOperationContext: string
{
	case UserUpload = 'user_upload';
	case ProductGenerated = 'product_generated';
	case EditingSession = 'editing_session';
	/**
	 * Content already stored in Disk and carried back into use: a move of a file or a folder to
	 * another place and a restore out of the trash can. It is judged by the policy of the one moving
	 * or restoring, while the write it leads to is not judged again.
	 */
	case StoredContentMove = 'stored_content_move';

	public const OPTION_KEY = 'writeContext';
	public const OPTION_KEY_EDITING_SESSION_STARTED_AT = 'editingSessionStartedAt';
	/**
	 * The participant who opened the editing session: the version of the session is judged by
	 * their policy, while the author of the last edit stays the author of the version.
	 */
	public const OPTION_KEY_EDITING_SESSION_OWNER_ID = 'editingSessionOwnerId';
	public const OPTION_KEY_SIGNATURE_CHECKED = 'signatureChecked';
	/**
	 * Files of a tree whose stored content a check before the write has judged, as a map of the id of
	 * the object to the id of the content judged. A copy of the tree marks as checked only the file
	 * that still keeps the content judged; a file added or changed after the check is read anew.
	 */
	public const OPTION_KEY_SIGNATURE_CHECKED_FILES = 'signatureCheckedFiles';
	/**
	 * The user whose policy judges a new file when that is not its author; null judges it by the
	 * global rule, as a write on behalf of no user that keeps the author of the source.
	 */
	public const OPTION_KEY_POLICY_ACTOR_ID = 'policyActorId';
	/**
	 * Stored content written anew - a copy of a file - is refused when the read budget is spent before
	 * its beginning is read, instead of being left to the name.
	 */
	public const OPTION_KEY_CONTENT_CHECK_REQUIRED = 'contentCheckRequired';

	/**
	 * Resolves the write context from options of a write method.
	 * A missing or unknown value falls back to the strictest context.
	 *
	 * @param array $options
	 * @return self
	 */
	public static function createFromOptions(array $options): self
	{
		$value = $options[self::OPTION_KEY] ?? null;

		if ($value instanceof self)
		{
			return $value;
		}

		if (is_string($value))
		{
			return self::tryFrom($value) ?? self::UserUpload;
		}

		return self::UserUpload;
	}
}
