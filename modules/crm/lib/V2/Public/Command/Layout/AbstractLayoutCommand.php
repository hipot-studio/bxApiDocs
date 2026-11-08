<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Layout;

use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\Context;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\ErrorCode;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutContext;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutVariant;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

abstract class AbstractLayoutCommand implements CommandInterface
{
	protected const INTERNAL_INVALID_LAYOUT = 'CRM_ENTITY_EDITOR_LAYOUT_INVALID_LAYOUT';

	protected function createInternalContext(LayoutContext $context): Result
	{
		$result = Context::createForLayout(
			$context->getEntityTypeId(),
			$context->getCategoryId(),
			$context->getMode()->value,
			$context->getVariant() === LayoutVariant::Returning ? $context->getVariant()->value : null,
		);

		return $result->isSuccess()
			? $result
			: $this->unsupportedContextFailure();
	}

	protected function mapCommonFailure(Result $result): ?Result
	{
		return match ($result->getError()?->getCode()) {
			ErrorCode::UNSUPPORTED_ENTITY_TYPE,
			ErrorCode::INVALID_CATEGORY,
			ErrorCode::UNSUPPORTED_VARIANT => $this->unsupportedContextFailure(),
			ErrorCode::ACCESS_DENIED => $this->failure(
				'Access to the layout is denied.',
				'PERMISSION_DENIED',
			),
			ErrorCode::CONSISTENCY_FAILURE => $this->consistencyFailure(),
			default => null,
		};
	}

	protected function consistencyFailure(): Result
	{
		return $this->failure(
			'The layout operation was only partially applied.',
			'CONSISTENCY_FAILURE',
		);
	}

	protected function failure(string $message, string $code): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}

	private function unsupportedContextFailure(): Result
	{
		return $this->failure('The layout context is not supported.', 'UNSUPPORTED_CONTEXT');
	}
}
