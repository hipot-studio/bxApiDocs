<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Layout;

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
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutVariant;
use Bitrix\Main\Error;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\Result;
use RuntimeException;

final class Provider
{
	private const PERSONAL_SCOPE = 'personal';
	private const COMMON_SCOPE = 'common';
	private const CUSTOM_SCOPE = 'custom';
	private const LEGACY_PAGE_SIZE = 50;

	private readonly Service $service;

	public function __construct(private readonly int $actorUserId)
	{
		$this->service = new Service();
	}

	/**
	 * Returns the current layout of the subject or the common fallback.
	 */
	public function getCurrent(LayoutContext $context, ?int $subjectUserId = null): Result
	{
		$subjectUserId ??= $this->actorUserId;
		$internalContextResult = $this->createInternalContext($context);
		if (!$internalContextResult->isSuccess())
		{
			return $internalContextResult;
		}

		/** @var Context $internalContext */
		$internalContext = $internalContextResult->getData()['context'];
		$selectionResult = $this->service->getEditScope(
			$this->actorUserId,
			$internalContext,
			$subjectUserId,
		);
		if (!$selectionResult->isSuccess())
		{
			return $this->mapFailure($selectionResult);
		}

		$internalScope = $selectionResult->getData()['scope'] ?? null;
		if (!$internalScope instanceof ScopeType)
		{
			throw new RuntimeException('The internal layout service returned an invalid scope.');
		}
		if ($internalScope === ScopeType::Custom)
		{
			$layoutResult = $this->getCustomLayout(
				$internalContext,
				(int)$selectionResult->getData()['scopeId'],
				$subjectUserId,
			);
			if ($layoutResult->isSuccess() || $layoutResult->getError()?->getCode() !== 'LAYOUT_NOT_FOUND')
			{
				return $layoutResult;
			}

			$internalScope = ScopeType::Common;
		}
		$scope = $this->toPublicScope($internalScope);

		return $this->getSystemLayout($context, $internalContext, $scope, $subjectUserId);
	}

	/**
	 * Returns the canonical identifier of the personal layout.
	 */
	public function getPersonalLayoutId(LayoutContext $context): Result
	{
		return $this->getSystemLayoutId($context, self::PERSONAL_SCOPE);
	}

	/**
	 * Returns the canonical identifier of the common layout.
	 */
	public function getCommonLayoutId(LayoutContext $context): Result
	{
		return $this->getSystemLayoutId($context, self::COMMON_SCOPE);
	}

	/**
	 * Returns one layout visible to the subject.
	 */
	public function get(
		LayoutContext $context,
		LayoutId $layoutId,
		?int $subjectUserId = null,
	): Result
	{
		$subjectUserId ??= $this->actorUserId;
		if (!$layoutId->belongsToContext($context))
		{
			return $this->failure('The layout identifier is invalid.', 'INVALID_LAYOUT_ID');
		}

		$internalContextResult = $this->createInternalContext($context);
		if (!$internalContextResult->isSuccess())
		{
			return $internalContextResult;
		}

		/** @var Context $internalContext */
		$internalContext = $internalContextResult->getData()['context'];
		if ($layoutId->isCustom())
		{
			return $this->getCustomLayout(
				$internalContext,
				(int)$layoutId->getCustomId(),
				$subjectUserId,
			);
		}

		if ($layoutId->isCommon() && $subjectUserId !== $this->actorUserId)
		{
			$authorizationResult = $this->service->getEditScope(
				$this->actorUserId,
				$internalContext,
				$subjectUserId,
			);
			if (!$authorizationResult->isSuccess())
			{
				return $this->mapFailure($authorizationResult);
			}
		}

		$scope = $layoutId->isPersonal() ? self::PERSONAL_SCOPE : self::COMMON_SCOPE;

		return $this->getSystemLayout($context, $internalContext, $scope, $subjectUserId);
	}

	/**
	 * Returns all custom layouts visible to the subject, or one page when a pager is provided.
	 */
	public function list(
		LayoutContext $context,
		?int $subjectUserId = null,
		?PagerInterface $pager = null,
	): Result
	{
		$subjectUserId ??= $this->actorUserId;
		$internalContextResult = $this->createInternalContext($context);
		if (!$internalContextResult->isSuccess())
		{
			return $internalContextResult;
		}

		/** @var Context $internalContext */
		$internalContext = $internalContextResult->getData()['context'];
		$listResult = $pager === null
			? $this->listAllCustomScopes($internalContext, $subjectUserId)
			: $this->service->listCustomScopes(
				$this->actorUserId,
				$internalContext,
				$subjectUserId,
				$pager->getLimit(),
				$pager->getOffset(),
			)
		;
		if (!$listResult->isSuccess())
		{
			return $this->mapFailure($listResult);
		}

		$layouts = [];
		foreach ($listResult->getData()['scopes'] as $scope)
		{
			if (!$scope instanceof Scope)
			{
				throw new RuntimeException('The internal layout service returned an invalid custom layout.');
			}

			$layouts[] = $this->createCustomLayout($scope);
		}

		return (new Result())->setData(['layouts' => $layouts]);
	}

	private function listAllCustomScopes(Context $context, int $subjectUserId): Result
	{
		$scopes = [];
		$offset = 0;
		do
		{
			$pageResult = $this->service->listCustomScopes(
				$this->actorUserId,
				$context,
				$subjectUserId,
				self::LEGACY_PAGE_SIZE,
				$offset,
			);
			if (!$pageResult->isSuccess())
			{
				return $pageResult;
			}

			$page = $pageResult->getData()['scopes'];
			array_push($scopes, ...$page);
			$offset += count($page);
		}
		while (count($page) === self::LEGACY_PAGE_SIZE);

		return (new Result())->setData(['scopes' => $scopes]);
	}

	private function getSystemLayoutId(LayoutContext $context, string $scope): Result
	{
		$internalContextResult = $this->createInternalContext($context);
		if (!$internalContextResult->isSuccess())
		{
			return $internalContextResult;
		}

		/** @var Context $internalContext */
		$internalContext = $internalContextResult->getData()['context'];
		$configurationResult = $this->service->getConfiguration(
			$this->actorUserId,
			$scope === self::PERSONAL_SCOPE ? $this->actorUserId : null,
			$internalContext,
			$this->toInternalScope($scope),
		);
		if (!$configurationResult->isSuccess())
		{
			return $this->mapFailure($configurationResult);
		}

		return (new Result())->setData([
			'layoutId' => $this->createSystemLayoutId($context, $scope),
		]);
	}

	private function getSystemLayout(
		LayoutContext $context,
		Context $internalContext,
		string $scope,
		int $subjectUserId,
	): Result
	{
		$configurationResult = $this->service->getConfiguration(
			$this->actorUserId,
			$scope === self::PERSONAL_SCOPE ? $subjectUserId : null,
			$internalContext,
			$this->toInternalScope($scope),
		);
		if (!$configurationResult->isSuccess())
		{
			return $this->mapFailure($configurationResult);
		}

		$layout = $configurationResult->getData()['layout'] ?? null;
		if ($layout === null)
		{
			return $this->failure('The system layout is not available.', 'LAYOUT_NOT_FOUND');
		}
		$configuration = $this->createConfiguration($layout);

		return (new Result())->setData([
			'layout' => new Layout(
				$this->createSystemLayoutId($context, $scope),
				$configuration,
			),
		]);
	}

	private function getCustomLayout(
		Context $context,
		int $scopeId,
		int $subjectUserId,
	): Result
	{
		$scopeResult = $this->service->getCustomScope(
			$this->actorUserId,
			$context,
			$scopeId,
			$subjectUserId,
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

		return (new Result())->setData([
			'layout' => $this->createCustomLayout($scope),
		]);
	}

	private function createCustomLayout(Scope $scope): Layout
	{
		$idResult = LayoutId::fromString((string)$scope->id);
		$nameResult = LayoutName::create($scope->name);
		$audienceResult = LayoutAudience::fromValues($scope->accessCodes);
		if (
			!$idResult->isSuccess()
			|| !$nameResult->isSuccess()
			|| !$audienceResult->isSuccess()
			|| $scope->accessCodes === []
		)
		{
			throw new RuntimeException('The internal layout service returned an invalid custom layout.');
		}

		return new Layout(
			$idResult->getData()['layoutId'],
			$this->createConfiguration($scope->layout),
			$nameResult->getData()['name'],
			$audienceResult->getData()['audience'],
		);
	}

	private function createConfiguration(mixed $layout): LayoutConfiguration
	{
		if (!is_array($layout))
		{
			throw new RuntimeException('The internal layout service returned an invalid configuration.');
		}

		$result = LayoutConfiguration::restore($layout);
		if (!$result->isSuccess())
		{
			throw new RuntimeException('The internal layout service returned an invalid configuration.');
		}

		return $result->getData()['configuration'];
	}

	private function createSystemLayoutId(LayoutContext $context, string $scope): LayoutId
	{
		if ($context->getVariant() === LayoutVariant::Returning)
		{
			$scope .= '_returning_customer';
		}

		$value = $context->getCategoryId() === null
			? $scope
			: 'C' . $context->getCategoryId() . ':' . $scope;
		$result = LayoutId::fromString($value);
		if (!$result->isSuccess())
		{
			throw new RuntimeException('Could not create the canonical system layout identifier.');
		}

		return $result->getData()['layoutId'];
	}

	private function toInternalScope(string $scope): ScopeType
	{
		return match ($scope) {
			self::PERSONAL_SCOPE => ScopeType::Personal,
			self::COMMON_SCOPE => ScopeType::Common,
			default => throw new RuntimeException('The public layout scope is invalid.'),
		};
	}

	private function toPublicScope(ScopeType $scope): string
	{
		return match ($scope) {
			ScopeType::Personal => self::PERSONAL_SCOPE,
			ScopeType::Common => self::COMMON_SCOPE,
			default => throw new RuntimeException('The internal layout service returned an invalid scope.'),
		};
	}

	private function createInternalContext(LayoutContext $context): Result
	{
		$result = Context::createForLayout(
			$context->getEntityTypeId(),
			$context->getCategoryId(),
			$context->getMode()->value,
			$context->getVariant() === LayoutVariant::Returning ? $context->getVariant()->value : null,
		);
		if (!$result->isSuccess())
		{
			return $this->failure('The layout context is not supported.', 'UNSUPPORTED_CONTEXT');
		}

		return $result;
	}

	private function mapFailure(Result $result): Result
	{
		$code = $result->getError()?->getCode();

		return match ($code) {
			ErrorCode::UNSUPPORTED_ENTITY_TYPE,
			ErrorCode::INVALID_CATEGORY,
			ErrorCode::UNSUPPORTED_VARIANT => $this->failure(
				'The layout context is not supported.',
				'UNSUPPORTED_CONTEXT',
			),
			ErrorCode::ACCESS_DENIED => $this->failure(
				'Access to the layout is denied.',
				'PERMISSION_DENIED',
			),
			ErrorCode::LAYOUT_NOT_FOUND => $this->failure(
				'The layout was not found.',
				'LAYOUT_NOT_FOUND',
			),
			ErrorCode::STORAGE_FAILED => $this->failure(
				'Could not read the layout.',
				'STORAGE_FAILED',
			),
			default => throw new RuntimeException('The internal layout service could not complete the read.'),
		};
	}

	private function failure(string $message, string $code): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
