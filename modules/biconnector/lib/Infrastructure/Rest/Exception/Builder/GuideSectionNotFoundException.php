<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;

/**
 * The agent guide has no section with the requested id. The data names the requested section and the
 * allowed ids: a section has no numeric id, so the framework phrase naming a record does not fit.
 */
class GuideSectionNotFoundException extends BuilderRestException
{
	protected const STATUS = \CRestServer::STATUS_NOT_FOUND;

	public function getFailureCode(): FailureCode
	{
		return FailureCode::NotFound;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'BICONNECTOR_REST_BUILDER_EXCEPTION_GUIDE_SECTION_NOT_FOUND';
	}
}
