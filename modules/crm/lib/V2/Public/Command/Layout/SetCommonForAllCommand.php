<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Layout;

use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\ErrorCode;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\Service;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutContext;
use Bitrix\Main\Result;
use RuntimeException;

final class SetCommonForAllCommand extends AbstractLayoutCommand
{
	private readonly Service $service;

	public function __construct(
		private readonly int $actorUserId,
		private readonly LayoutContext $context,
	)
	{
		$this->service = new Service();
	}

	/**
	 * Replaces saved non-common selections with the common layout.
	 */
	public function run(): Result
	{
		$contextResult = $this->createInternalContext($this->context);
		if (!$contextResult->isSuccess())
		{
			return $contextResult;
		}

		/** @var Context $internalContext */
		$internalContext = $contextResult->getData()['context'];
		$result = $this->service->setCommonForAll($this->actorUserId, $internalContext);
		if (!$result->isSuccess())
		{
			return $this->mapFailure($result);
		}

		return new Result();
	}

	private function mapFailure(Result $result): Result
	{
		$commonFailure = $this->mapCommonFailure($result);
		if ($commonFailure !== null)
		{
			return $commonFailure;
		}

		return match ($result->getError()?->getCode()) {
			ErrorCode::STORAGE_FAILED => $this->failure(
				'Could not set the common layout.',
				'STORAGE_FAILED',
			),
			default => throw new RuntimeException('The internal layout service could not set common for all.'),
		};
	}
}
