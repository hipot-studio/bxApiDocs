<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;

/**
 * BI Builder of the box is older than reading on behalf of the user requires, see Refusal::isOutdatedInstance().
 * The code is the one of BI Builder switched off on the plan - a repeat does not help either - but the plan
 * is not to blame and the phrase says what fixes it: an update of BI Builder.
 */
class BuilderInstanceOutdatedException extends BuilderRestException
{
	protected const STATUS = \CRestServer::STATUS_FORBIDDEN;

	public function getFailureCode(): FailureCode
	{
		return FailureCode::FeatureDisabled;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'BICONNECTOR_REST_BUILDER_EXCEPTION_INSTANCE_OUTDATED';
	}
}
