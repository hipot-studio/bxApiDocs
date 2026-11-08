<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

/**
 * The refusal of biconnector.builder.dashboard.delete on a published dashboard: the code is the one
 * of an edit, but a copy would not help here, so the phrase sends the caller to a person instead.
 */
class PublishedReportDeletionException extends PublishedReportException
{
	protected function getMessagePhraseCode(): string
	{
		return 'BICONNECTOR_REST_BUILDER_EXCEPTION_PUBLISHED_REPORT_DELETE';
	}
}
