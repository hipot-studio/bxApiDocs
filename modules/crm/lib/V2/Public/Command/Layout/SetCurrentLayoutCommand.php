<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Layout;

use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\Context;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\ErrorCode;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\Service;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\ScopeType;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutContext;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutId;
use Bitrix\Main\Result;
use RuntimeException;

final class SetCurrentLayoutCommand extends AbstractLayoutCommand
{
	private readonly Service $service;

	public function __construct(
		private readonly int $actorUserId,
		private readonly LayoutContext $context,
		private readonly LayoutId $layoutId,
		private readonly ?int $subjectUserId = null,
	)
	{
		$this->service = new Service();
	}

	/**
	 * Sets the current layout for the subject.
	 */
	public function run(): Result
	{
		if (!$this->layoutId->belongsToContext($this->context))
		{
			return $this->failure('The layout identifier is invalid.', 'INVALID_LAYOUT_ID');
		}

		$contextResult = $this->createInternalContext($this->context);
		if (!$contextResult->isSuccess())
		{
			return $contextResult;
		}

		/** @var Context $internalContext */
		$internalContext = $contextResult->getData()['context'];
		$result = $this->service->setEditScope(
			$this->actorUserId,
			$internalContext,
			$this->getScope(),
			$this->layoutId->getCustomId(),
			$this->subjectUserId,
		);
		if (!$result->isSuccess())
		{
			return $this->mapFailure($result);
		}

		return new Result();
	}

	private function getScope(): ScopeType
	{
		return match (true) {
			$this->layoutId->isPersonal() => ScopeType::Personal,
			$this->layoutId->isCommon() => ScopeType::Common,
			default => ScopeType::Custom,
		};
	}

	private function mapFailure(Result $result): Result
	{
		$commonFailure = $this->mapCommonFailure($result);
		if ($commonFailure !== null)
		{
			return $commonFailure;
		}

		return match ($result->getError()?->getCode()) {
			ErrorCode::LAYOUT_NOT_FOUND => $this->failure(
				'The layout was not found.',
				'LAYOUT_NOT_FOUND',
			),
			ErrorCode::STORAGE_FAILED => $this->failure(
				'Could not select the layout.',
				'STORAGE_FAILED',
			),
			default => throw new RuntimeException('The internal layout service could not set the current layout.'),
		};
	}
}
