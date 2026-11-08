<?php

namespace Bitrix\Im\V2\Integration\HumanResources;

use Bitrix\Main\Localization\Loc;

class Error extends \Bitrix\Im\V2\Error
{
	public const LINK_ERROR = 'LINK_TO_STRUCTURE_NODE_ERROR';
	public const UNLINK_ERROR = 'UNLINK_STRUCTURE_NODE_ERROR';
	public const MALFORMED_ENTITY_LIST = 'MALFORMED_ENTITY_LIST';
	public const TOO_MANY_NODES = 'TOO_MANY_NODES';
	public const CHAT_TYPE_NOT_SUPPORTED = 'CHAT_TYPE_NOT_SUPPORTED';
	public const NODE_NOT_AVAILABLE = 'NODE_NOT_AVAILABLE';
	public const STRUCTURE_NOT_AVAILABLE = 'STRUCTURE_NOT_AVAILABLE';

	/**
	 * Wire contract with the mobile client: only an error carrying `customData.public` is shown to
	 * the user in the words of the server, everything else becomes the common internal error.
	 * The reader is `isPublicError()` of the mobile `error` extension.
	 */
	private const PUBLIC_CUSTOM_DATA_KEY = 'public';

	/**
	 * ERR-006: codes that have a user-facing phrase of their own and name the part that failed.
	 * A code kept for the log has no phrase, falls back to itself as the message and stays internal.
	 */
	private const PUBLIC_CODES = [
		self::MALFORMED_ENTITY_LIST,
		self::TOO_MANY_NODES,
		self::CHAT_TYPE_NOT_SUPPORTED,
		self::NODE_NOT_AVAILABLE,
		self::STRUCTURE_NOT_AVAILABLE,
	];

	public function __construct(string $code, ...$args)
	{
		parent::__construct($code, ...$args);

		if ($this->hasPublicPhrase($code, $args))
		{
			$this->customData = array_merge(
				(array)($this->getCustomData() ?? []),
				[self::PUBLIC_CUSTOM_DATA_KEY => true],
			);
		}
	}

	/**
	 * The mark promises the message is the phrase of the code. The caller may pass a text of its own
	 * as the first argument (see the base constructor), and a code without a phrase falls back to
	 * itself as the message: neither text is one to hand the user.
	 */
	private function hasPublicPhrase(string $code, array $args): bool
	{
		if (!in_array($code, self::PUBLIC_CODES, true))
		{
			return false;
		}

		$messageFromCaller = isset($args[0]) && is_string($args[0]);

		return !$messageFromCaller && $this->getMessage() !== $code;
	}

	protected function loadErrorMessage($code, $replacements): string
	{
		return Loc::getMessage("ERROR_CHAT_HUMAN_RESOURCES_{$code}", $replacements) ?: '';
	}

	protected function loadErrorDescription($code, $replacements): string
	{
		return Loc::getMessage("ERROR_CHAT_HUMAN_RESOURCES_{$code}_DESC", $replacements) ?: '';
	}
}