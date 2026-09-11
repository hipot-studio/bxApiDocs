<?php

namespace Bitrix\Sign\Service\Sign\SignersList;

/**
 * Classification of prospective signers by the reason they cannot be added to a signers list.
 */
final class SignerEligibilityResult
{
	/**
	 * @param int[] $firedUserIds users deactivated in the portal (ACTIVE = 'N')
	 * @param int[] $extranetUserIds users without a department (extranet)
	 * @param int[] $notFoundUserIds ids that do not match an existing user
	 */
	public function __construct(
		public readonly array $firedUserIds = [],
		public readonly array $extranetUserIds = [],
		public readonly array $notFoundUserIds = [],
	)
	{
	}

	public function hasIneligibleUsers(): bool
	{
		return $this->firedUserIds !== []
			|| $this->extranetUserIds !== []
			|| $this->notFoundUserIds !== [];
	}

	/**
	 * @return int[]
	 */
	public function getIneligibleUserIds(): array
	{
		return array_values(array_unique([
			...$this->firedUserIds,
			...$this->extranetUserIds,
			...$this->notFoundUserIds,
		]));
	}
}
