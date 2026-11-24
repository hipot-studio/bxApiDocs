<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * A publication refused for a state of the template the caller can see and act on: there is no draft to
 * publish, the draft changed after it was read, or a pilot is live on the template.
 *
 * Such a refusal is the answer to what was asked, not an incident of the portal, so it stays out of the
 * exception log. Without this split an agent that keeps asking - and one will, because "no draft" is the
 * ordinary answer to a publication whose response was lost - writes a stack trace into the log of the
 * portal on every call. The same division {@see ClientDraftSaveFailedException} draws on the write of a
 * draft, drawn here for the same reason.
 *
 * What stays with the parent and reaches the log: a draft missing a field the live template would lose,
 * and a write the engine refused. Neither is something the caller asked for, and both are worth a trace.
 */
final class ClientPublishRefusedException extends PublishRefusedException implements SkipWriteToLogException
{
	/**
	 * The code of the answer and the phrase file are those of the refusal itself: the caller branches on
	 * the refusal code, and which class carries it decides only whether the portal keeps a trace.
	 */
	protected function getClassWithPhrase(): string
	{
		return PublishRefusedException::class;
	}
}
