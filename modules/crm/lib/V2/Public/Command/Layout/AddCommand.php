<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Layout;

use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\ErrorCode;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\Service;
use Bitrix\Crm\V2\Public\Entity\Layout\AccessCode;
use Bitrix\Crm\V2\Public\Entity\Layout\Layout;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutAudience;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutConfiguration;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutContext;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutId;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutName;
use Bitrix\Main\Result;
use RuntimeException;

final class AddCommand extends AbstractLayoutCommand
{
	public function __construct(
		private readonly int $actorUserId,
		private readonly LayoutContext $context,
		private readonly LayoutName $name,
		private readonly LayoutConfiguration $configuration,
		private readonly LayoutAudience $audience,
	)
	{
	}

	/**
	 * Creates a custom layout.
	 */
	public function run(): Result
	{
		if ($this->configuration->getSections() === [])
		{
			return $this->failure('The layout configuration is invalid.', 'INVALID_LAYOUT_CONFIGURATION');
		}

		$service = new Service();
		$contextResult = $this->createInternalContext($this->context);
		if (!$contextResult->isSuccess())
		{
			return $contextResult;
		}

		/** @var Context $internalContext */
		$internalContext = $contextResult->getData()['context'];
		$result = $service->createCustomScope(
			$this->actorUserId,
			$internalContext,
			$this->name->getValue(),
			$this->configuration->toArray(),
			$this->audience->toArray(),
		);
		if (!$result->isSuccess())
		{
			return $this->mapFailure($result);
		}

		$scopeId = $result->getData()['scopeId'] ?? null;
		if (!is_int($scopeId) || $scopeId <= 0)
		{
			throw new RuntimeException('The internal layout service returned an invalid layout identifier.');
		}

		$idResult = LayoutId::fromString((string)$scopeId);
		if (!$idResult->isSuccess())
		{
			throw new RuntimeException('The internal layout service returned an invalid layout identifier.');
		}
		$audience = $this->createStoredAudience();

		return (new Result())->setData([
			'layout' => new Layout(
				$idResult->getData()['layoutId'],
				$this->configuration,
				$this->name,
				$audience,
			),
		]);
	}

	private function createStoredAudience(): LayoutAudience
	{
		$actorAccessCodeResult = AccessCode::create('user', $this->actorUserId);
		if (!$actorAccessCodeResult->isSuccess())
		{
			throw new RuntimeException('The internal layout service returned an invalid layout audience.');
		}

		$storedAccessCodes = [
			...$this->audience->getAccessCodes(),
			$actorAccessCodeResult->getData()['accessCode'],
		];
		$audienceResult = LayoutAudience::fromValues(array_map(
			static fn(AccessCode $accessCode): string => $accessCode->getValue(),
			$storedAccessCodes,
		));
		if (!$audienceResult->isSuccess())
		{
			throw new RuntimeException('The internal layout service returned an invalid layout audience.');
		}

		return $audienceResult->getData()['audience'];
	}

	private function mapFailure(Result $result): Result
	{
		$commonFailure = $this->mapCommonFailure($result);
		if ($commonFailure !== null)
		{
			return $commonFailure;
		}

		return match ($result->getError()?->getCode()) {
			self::INTERNAL_INVALID_LAYOUT => $this->failure(
				'The layout configuration is invalid.',
				'INVALID_LAYOUT_CONFIGURATION',
			),
			ErrorCode::VALIDATION_FAILED => $this->failure(
				'The layout audience is invalid.',
				'INVALID_LAYOUT_AUDIENCE',
			),
			ErrorCode::STORAGE_FAILED => $this->failure(
				'Could not create the layout.',
				'STORAGE_FAILED',
			),
			default => throw new RuntimeException('The internal layout service could not create the layout.'),
		};
	}
}
