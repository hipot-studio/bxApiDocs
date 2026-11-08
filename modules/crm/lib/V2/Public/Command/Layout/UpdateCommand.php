<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Layout;

use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\Context;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\ErrorCode;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\Scope;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\Service;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\ScopeType;
use Bitrix\Crm\V2\Public\Entity\Layout\Layout;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutAudience;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutConfiguration;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutContext;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutId;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutName;
use Bitrix\Main\Result;
use RuntimeException;

final class UpdateCommand extends AbstractLayoutCommand
{
	public function __construct(
		private readonly int $actorUserId,
		private readonly LayoutContext $context,
		private readonly LayoutId $layoutId,
		private readonly ?LayoutConfiguration $configuration = null,
		private readonly ?LayoutName $name = null,
		private readonly ?LayoutAudience $audience = null,
		private readonly ?int $subjectUserId = null,
	)
	{
	}

	/**
	 * Updates a system or custom layout.
	 */
	public function run(): Result
	{
		if (
			($this->configuration === null && $this->name === null && $this->audience === null)
			|| $this->configuration?->getSections() === []
		)
		{
			return $this->failure('The layout configuration is invalid.', 'INVALID_LAYOUT_CONFIGURATION');
		}
		if (!$this->layoutId->belongsToContext($this->context))
		{
			return $this->failure('The layout identifier is invalid.', 'INVALID_LAYOUT_ID');
		}
		if (!$this->layoutId->isCustom() && $this->name !== null)
		{
			return $this->failure('The layout configuration is invalid.', 'INVALID_LAYOUT_CONFIGURATION');
		}
		if (!$this->layoutId->isCustom() && $this->audience !== null)
		{
			return $this->failure('The layout audience is invalid.', 'INVALID_LAYOUT_AUDIENCE');
		}
		if ($this->layoutId->isCustom() && $this->audience?->getAccessCodes() === [])
		{
			return $this->failure('The layout audience is invalid.', 'INVALID_LAYOUT_AUDIENCE');
		}

		$service = new Service();
		$contextResult = $this->createInternalContext($this->context);
		if (!$contextResult->isSuccess())
		{
			return $contextResult;
		}

		/** @var Context $internalContext */
		$internalContext = $contextResult->getData()['context'];

		return $this->layoutId->isCustom()
			? $this->updateCustom($service, $internalContext)
			: $this->updateSystem($service, $internalContext);
	}

	private function updateSystem(Service $service, Context $context): Result
	{
		$scope = $this->layoutId->isPersonal()
			? ScopeType::Personal
			: ScopeType::Common;
		$result = $service->setConfiguration(
			$this->actorUserId,
			$scope === ScopeType::Personal ? $this->subjectUserId ?? $this->actorUserId : null,
			$context,
			$scope,
			$this->configuration->toArray(),
		);
		if (!$result->isSuccess())
		{
			return $this->mapFailure($result);
		}

		return (new Result())->setData([
			'layout' => new Layout($this->layoutId, $this->configuration),
		]);
	}

	private function updateCustom(Service $service, Context $context): Result
	{
		$scopeId = $this->layoutId->getCustomId();
		if ($scopeId === null)
		{
			return $this->failure('The layout identifier is invalid.', 'INVALID_LAYOUT_ID');
		}

		$scopeResult = $service->getCustomScope(
			$this->actorUserId,
			$context,
			$scopeId,
			$this->subjectUserId,
		);
		if (!$scopeResult->isSuccess())
		{
			return $this->mapFailure($scopeResult);
		}

		$scope = $scopeResult->getData()['scope'] ?? null;
		if (!$scope instanceof Scope)
		{
			throw new RuntimeException('The internal layout service returned an invalid custom layout.');
		}
		if ($scope->autoApplyScope && $this->audience !== null)
		{
			return $this->failure('The layout audience is invalid.', 'INVALID_LAYOUT_AUDIENCE');
		}
		if ($scope->autoApplyScope && $this->name !== null)
		{
			return $this->failure('The layout configuration is invalid.', 'INVALID_LAYOUT_CONFIGURATION');
		}

		$configuration = $this->configuration ?? $this->restoreConfiguration($scope->layout);
		$name = $this->name ?? $this->restoreName($scope->name);
		$audience = $this->audience ?? $this->restoreAudience($scope->accessCodes);
		$changes = [];
		if ($this->configuration !== null)
		{
			$changes['layout'] = $this->configuration->toArray();
		}
		if ($this->name !== null)
		{
			$changes['name'] = $this->name->getValue();
		}
		if ($this->audience !== null)
		{
			$changes['accessCodes'] = $this->audience->toArray();
		}

		$result = $service->updateCustomScope(
			$this->actorUserId,
			$context,
			$scopeId,
			$changes,
			$this->subjectUserId,
		);
		if (!$result->isSuccess())
		{
			return $this->mapFailure($result);
		}

		return (new Result())->setData([
			'layout' => new Layout($this->layoutId, $configuration, $name, $audience),
		]);
	}

	private function restoreConfiguration(array $sections): LayoutConfiguration
	{
		$result = LayoutConfiguration::restore($sections);
		if (!$result->isSuccess())
		{
			throw new RuntimeException('The internal layout service returned an invalid custom layout configuration.');
		}

		return $result->getData()['configuration'];
	}

	private function restoreName(string $value): LayoutName
	{
		$result = LayoutName::create($value);
		if (!$result->isSuccess())
		{
			throw new RuntimeException('The internal layout service returned an invalid custom layout name.');
		}

		return $result->getData()['name'];
	}

	/**
	 * @param list<string> $values
	 */
	private function restoreAudience(array $values): LayoutAudience
	{
		$result = LayoutAudience::fromValues($values);
		if (!$result->isSuccess())
		{
			throw new RuntimeException('The internal layout service returned an invalid custom layout audience.');
		}

		return $result->getData()['audience'];
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
			ErrorCode::LAYOUT_NOT_FOUND => $this->failure(
				'The layout was not found.',
				'LAYOUT_NOT_FOUND',
			),
			ErrorCode::STORAGE_FAILED => $this->failure(
				'Could not update the layout.',
				'STORAGE_FAILED',
			),
			default => throw new RuntimeException('The internal layout service could not update the layout.'),
		};
	}
}
