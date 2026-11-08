<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;

class DestructiveBudgetException extends BuilderRestException
{
	protected const STATUS = \CRestServer::STATUS_TO_MANY_REQUESTS;

	public function getFailureCode(): FailureCode
	{
		return FailureCode::DestructiveBudget;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'BICONNECTOR_REST_BUILDER_EXCEPTION_DESTRUCTIVE_BUDGET';
	}
}
