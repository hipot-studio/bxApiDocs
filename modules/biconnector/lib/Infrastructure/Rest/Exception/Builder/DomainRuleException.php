<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;

class DomainRuleException extends BuilderRestException
{
	protected const STATUS = \CRestServer::STATUS_WRONG_REQUEST;

	public function getFailureCode(): FailureCode
	{
		return FailureCode::DomainRule;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'BICONNECTOR_REST_BUILDER_EXCEPTION_DOMAIN_RULE';
	}
}
