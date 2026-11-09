<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;

/**
 * The general rules of the agent guide are broken in this edition of the module, so the table of contents
 * is not served. Unlike an unavailable dashboard, retrying does not help; the sections keep working.
 */
class GuideUnavailableException extends BuilderRestException
{
	protected const STATUS = \CRestServer::STATUS_CONFLICT;

	public function getFailureCode(): FailureCode
	{
		return FailureCode::Unavailable;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'BICONNECTOR_REST_BUILDER_EXCEPTION_GUIDE_UNAVAILABLE';
	}
}
