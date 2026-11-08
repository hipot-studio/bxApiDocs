<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\GraphErrorCode;
use Bitrix\Main\Result;

/**
 * Refusal that belongs to the description context of a catalog read, not to the block that was asked for.
 *
 * A type of its own because the two are answered differently: a block the catalog cannot answer with is a
 * statement about the resource, while a context the block cannot be described in is a statement about the
 * request. Told apart by type rather than by the wording of an error, so a reworded message cannot turn one
 * answer into the other.
 */
final class BlockContextRejectionResult extends Result
{
	/**
	 * Whether the context was refused because the server could not build the description for it, as opposed
	 * to the caller having sent a name or a value the schema does not hold. The two are separate answers: the
	 * second names what to correct, the first says the correction is not the caller's to make.
	 */
	public function isResolutionFailure(): bool
	{
		foreach ($this->getErrors() as $error)
		{
			$customData = $error->getCustomData();
			if (
				is_array($customData)
				&& ($customData['errorCode'] ?? null) === GraphErrorCode::ContextResolutionFailed->value
			)
			{
				return true;
			}
		}

		return false;
	}
}
