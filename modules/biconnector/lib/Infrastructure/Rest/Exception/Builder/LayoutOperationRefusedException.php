<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;

final class LayoutOperationRefusedException extends BuilderRestException
{
	protected const STATUS = \CRestServer::STATUS_WRONG_REQUEST;

	public function getFailureCode(): FailureCode
	{
		return FailureCode::InvalidArgument;
	}

	protected function getMessagePhraseReplacement(): array
	{
		$data = $this->getData();
		$replacements = [];
		foreach (['index', 'operation', 'reason'] as $field)
		{
			$value = $data[$field] ?? null;
			$replacements['#' . strtoupper($field) . '#'] = is_scalar($value) ? (string)$value : '';
		}

		return $replacements;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'BICONNECTOR_REST_BUILDER_EXCEPTION_LAYOUT_OPERATION_REFUSED';
	}
}
