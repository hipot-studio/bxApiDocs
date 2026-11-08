<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;

class PublishedReportException extends BuilderRestException
{
	protected const STATUS = \CRestServer::STATUS_CONFLICT;

	public function getFailureCode(): FailureCode
	{
		return FailureCode::PublishedReport;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'BICONNECTOR_REST_BUILDER_EXCEPTION_PUBLISHED_REPORT';
	}
}
