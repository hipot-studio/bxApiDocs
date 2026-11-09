<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;

/**
 * The dashboard cannot be used right now: it is still loading, has failed or is not synced with Superset yet.
 *
 * BI Builder switched off on the portal plan is FailureCode::FeatureDisabled, not this conflict.
 */
class ReportUnavailableException extends BuilderRestException
{
	protected const STATUS = \CRestServer::STATUS_CONFLICT;

	public function getFailureCode(): FailureCode
	{
		return FailureCode::Unavailable;
	}

	protected function getMessagePhraseCode(): string
	{
		if (($this->getData()['reason'] ?? null) === 'write_unknown')
		{
			return 'BICONNECTOR_REST_BUILDER_EXCEPTION_LAYOUT_WRITE_UNKNOWN';
		}

		return 'BICONNECTOR_REST_BUILDER_EXCEPTION_REPORT_UNAVAILABLE';
	}
}
