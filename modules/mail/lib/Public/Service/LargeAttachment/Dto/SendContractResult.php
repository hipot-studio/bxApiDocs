<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\LargeAttachment\Dto;

/**
 * Immutable validated large attachment send contract.
 */
final class SendContractResult
{
	/**
	 * @param int[] $fileIds
	 * @param int[] $externalLinkIds
	 */
	public function __construct(
		public readonly array $fileIds,
		public readonly array $externalLinkIds,
	)
	{
	}
}
