<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;

/**
 * A section of the agent guide is broken in this edition of the module: a phrase is missing or empty.
 * Unlike an unavailable dashboard, retrying does not help; the other sections and methods keep working.
 */
class GuideSectionUnavailableException extends BuilderRestException
{
	protected const STATUS = \CRestServer::STATUS_CONFLICT;

	public function getFailureCode(): FailureCode
	{
		return FailureCode::Unavailable;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'BICONNECTOR_REST_BUILDER_EXCEPTION_GUIDE_SECTION_UNAVAILABLE';
	}
}
