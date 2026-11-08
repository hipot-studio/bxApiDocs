<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * A draft that was not written for an expected domain reason the caller can act on: no rights to the
 * template, a template that is gone, or a concurrent draft change.
 *
 * Such a refusal is part of the request contract, not an incident of the portal, so it stays out of the
 * exception log instead of filling it on every call of an agent that keeps asking. The failures of the
 * write itself are left to reach the log through the parent.
 */
final class ClientDraftSaveFailedException extends DraftSaveFailedException implements SkipWriteToLogException
{
	/**
	 * The code of the answer and the phrase file are those of the refusal itself: the agent branches on that
	 * code, and telling the causes apart is for the log of the portal, not for the answer.
	 */
	protected function getClassWithPhrase(): string
	{
		return DraftSaveFailedException::class;
	}
}
