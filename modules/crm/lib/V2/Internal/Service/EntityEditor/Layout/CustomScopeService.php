<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout;

use Bitrix\Crm\Entity\EntityEditorConfig;
use Bitrix\Crm\Entity\EntityEditorConfigScope;
use Bitrix\Crm\V2\Internal\Access\EntityEditor\Layout\Access;
use Bitrix\Crm\V2\Internal\Integration\UI\EntityEditorScopeGateway;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\ConfigGateway;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

/**
 * @internal
 */
final class CustomScopeService
{
	private const INVALID_LAYOUT = 'CRM_ENTITY_EDITOR_LAYOUT_INVALID_LAYOUT';

	private readonly ScopeResultValidator $scopeResultValidator;

	public function __construct(
		private readonly Access $access,
		private readonly ConfigGateway $gateway,
		private readonly EntityEditorScopeGateway $scopeGateway,
	)
	{
		$this->scopeResultValidator = new ScopeResultValidator($scopeGateway);
	}

	public function get(
		int $actorUserId,
		Context $context,
		int $scopeId,
		?int $subjectUserId = null,
	): Result
	{
		$subjectUserId ??= $actorUserId;
		$authorizationResult = $this->authorize(
			$actorUserId,
			$subjectUserId,
			$context,
			$scopeId,
			'read',
		);
		if (!$authorizationResult->isSuccess())
		{
			return $authorizationResult;
		}

		return (new Result())->setData(['scope' => $authorizationResult->getData()['scope']]);
	}

	public function list(
		int $actorUserId,
		Context $context,
		?int $subjectUserId = null,
		int $limit = 50,
		int $offset = 0,
	): Result
	{
		$subjectUserId ??= $actorUserId;
		if (!$this->access->canActForSubject($actorUserId, $subjectUserId))
		{
			return FailureResultFactory::accessDenied();
		}

		$configIdResult = $this->prepareOperation($context);
		if (!$configIdResult->isSuccess())
		{
			return $configIdResult;
		}
		if (
			$limit <= 0
			|| $offset < 0
			|| !in_array($context->mode, [Context::MODE_CREATE, Context::MODE_UPDATE], true)
		)
		{
			return $this->validationFailure();
		}

		return $this->scopeGateway->getScopesPage(
			$subjectUserId,
			(string)$configIdResult->getData()['configId'],
			$context->mode,
			$limit,
			$offset,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $layout
	 * @param array<int, array{type?: mixed, id?: mixed}|object> $accessCodes
	 */
	public function create(
		int $actorUserId,
		Context $context,
		string $name,
		array $layout,
		array $accessCodes,
		bool $autoApplyScope = false,
		bool $onAdd = true,
		bool $onUpdate = true,
	): Result
	{
		$configIdResult = $this->prepareOperation($context);
		if (!$configIdResult->isSuccess())
		{
			return $configIdResult;
		}
		$configId = (string)$configIdResult->getData()['configId'];
		if (!$this->access->canCreateCustom($actorUserId, $configId))
		{
			return FailureResultFactory::accessDenied();
		}

		$layoutResult = $this->prepareLayout($actorUserId, $context, null, $layout);
		if (!$layoutResult->isSuccess())
		{
			return $layoutResult;
		}

		$accessCodes[] = ['type' => 'user', 'id' => $actorUserId];
		$accessCodesResult = $this->scopeGateway->canonicalizeInputAccessCodes($accessCodes);
		if (!$accessCodesResult->isSuccess())
		{
			return $accessCodesResult;
		}
		$accessCodes = $accessCodesResult->getData()['audience'];
		$nameResult = $this->scopeGateway->normalizeInputName($name);
		if (!$nameResult->isSuccess())
		{
			return $nameResult;
		}
		$name = $nameResult->getData()['name'];

		return FailureResultFactory::normalizePartialMutationFailure($this->scopeGateway->createScope(
			$actorUserId,
			$configId,
			$name,
			$layoutResult->getData()['layout'],
			$accessCodes,
			$autoApplyScope,
			$context->mode === Context::MODE_CREATE,
			$context->mode === Context::MODE_UPDATE,
		));
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	public function update(
		int $actorUserId,
		Context $context,
		int $scopeId,
		array $changes,
		?int $subjectUserId = null,
	): Result
	{
		$subjectUserId ??= $actorUserId;
		if (array_key_exists('onAdd', $changes) || array_key_exists('onUpdate', $changes))
		{
			return $this->invalidLayoutFailure();
		}

		$authorizationResult = $this->authorize(
			$actorUserId,
			$subjectUserId,
			$context,
			$scopeId,
			'update',
		);
		if (!$authorizationResult->isSuccess())
		{
			return $authorizationResult;
		}

		if (
			$changes === []
			|| array_diff(array_keys($changes), ['layout', 'name', 'accessCodes', 'autoApplyScope']) !== []
		)
		{
			return $this->validationFailure();
		}

		$layout = null;
		if (array_key_exists('layout', $changes))
		{
			if (!is_array($changes['layout']))
			{
				return $this->validationFailure();
			}
			$layoutResult = $this->prepareLayout($subjectUserId, $context, $scopeId, $changes['layout']);
			if (!$layoutResult->isSuccess())
			{
				return $layoutResult;
			}
			$layout = $layoutResult->getData()['layout'];
		}

		$metadata = [];
		if (array_key_exists('name', $changes))
		{
			if (!is_string($changes['name']))
			{
				return $this->validationFailure();
			}
			$nameResult = $this->scopeGateway->normalizeInputName($changes['name']);
			if (!$nameResult->isSuccess())
			{
				return $nameResult;
			}
			$metadata['name'] = $nameResult->getData()['name'];
		}
		if (array_key_exists('autoApplyScope', $changes))
		{
			if (!is_bool($changes['autoApplyScope']))
			{
				return $this->validationFailure();
			}
			$metadata['autoApplyScope'] = $changes['autoApplyScope'];
		}

		$accessCodes = null;
		if (array_key_exists('accessCodes', $changes))
		{
			if (!is_array($changes['accessCodes']))
			{
				return $this->validationFailure();
			}
			$accessCodesResult = $this->scopeGateway->canonicalizeInputAccessCodes($changes['accessCodes']);
			if (!$accessCodesResult->isSuccess())
			{
				return $accessCodesResult;
			}
			if ($accessCodesResult->getData()['accessCodes'] === [])
			{
				return $this->validationFailure();
			}
			$accessCodes = $accessCodesResult->getData()['audience'];
		}

		$configId = (string)$authorizationResult->getData()['configId'];
		$mutationWasApplied = false;
		$autoApplyScheduled = false;
		if ($layout !== null)
		{
			$result = $this->scopeGateway->updateScopeConfig($subjectUserId, $scopeId, $configId, $layout);
			if (!$result->isSuccess())
			{
				return FailureResultFactory::normalizePartialMutationFailure($result);
			}
			$mutationWasApplied = true;
		}
		if ($metadata !== [])
		{
			$result = $this->scopeGateway->updateScope($subjectUserId, $scopeId, $configId, $metadata);
			if (!$result->isSuccess())
			{
				return FailureResultFactory::normalizePartialMutationFailure($result, $mutationWasApplied);
			}
			$mutationWasApplied = true;
			$autoApplyScheduled = (bool)($result->getData()['autoApplyScheduled'] ?? false);
		}
		if ($accessCodes !== null)
		{
			$result = $this->scopeGateway->replaceAccessCodes($subjectUserId, $scopeId, $configId, $accessCodes);
			if (!$result->isSuccess())
			{
				return FailureResultFactory::normalizePartialMutationFailure($result, $mutationWasApplied);
			}
			$autoApplyScheduled = $autoApplyScheduled || (bool)($result->getData()['autoApplyScheduled'] ?? false);
		}

		return (new Result())->setData(['autoApplyScheduled' => $autoApplyScheduled]);
	}

	/**
	 * @param array<int, array<string, mixed>> $layout
	 */
	public function updateConfig(
		int $actorUserId,
		Context $context,
		int $scopeId,
		array $layout,
		?int $subjectUserId = null,
	): Result
	{
		return $this->update(
			$actorUserId,
			$context,
			$scopeId,
			['layout' => $layout],
			$subjectUserId,
		);
	}

	/**
	 * @param array<int, array{type?: mixed, id?: mixed}|object> $accessCodes
	 */
	public function replaceAccessCodes(
		int $actorUserId,
		Context $context,
		int $scopeId,
		array $accessCodes,
		?int $subjectUserId = null,
	): Result
	{
		return $this->update(
			$actorUserId,
			$context,
			$scopeId,
			['accessCodes' => $accessCodes],
			$subjectUserId,
		);
	}

	public function copy(int $actorUserId, Context $context, int $scopeId): Result
	{
		$configIdResult = $this->prepareOperation($context);
		if (!$configIdResult->isSuccess())
		{
			return $configIdResult;
		}
		$configId = (string)$configIdResult->getData()['configId'];
		if (
			!$this->access->canCreateCustom($actorUserId, $configId)
			|| !$this->access->canReadCustom($actorUserId, $scopeId)
		)
		{
			return FailureResultFactory::accessDenied();
		}
		$scopeResult = $this->scopeResultValidator->ensureMatchesMode(
			$this->scopeGateway->readScope($actorUserId, $scopeId, $configId),
			$context,
		);
		if (!$scopeResult->isSuccess())
		{
			if (($scopeResult->getError()?->getCustomData()['reason'] ?? null) === 'invalid_access_code')
			{
				return (new Result())->addError(
					new Error(
						'The CRM entity editor custom layout input is invalid.',
						ErrorCode::VALIDATION_FAILED,
					),
				);
			}

			return $scopeResult;
		}

		return FailureResultFactory::normalizePartialMutationFailure(
			$this->scopeGateway->copyScope($actorUserId, $scopeId, $configId),
		);
	}

	public function delete(
		int $actorUserId,
		Context $context,
		int $scopeId,
		?int $subjectUserId = null,
	): Result
	{
		$subjectUserId ??= $actorUserId;
		$authorizationResult = $this->authorize(
			$actorUserId,
			$subjectUserId,
			$context,
			$scopeId,
			'delete',
		);
		if (!$authorizationResult->isSuccess())
		{
			return $authorizationResult;
		}

		return FailureResultFactory::normalizePartialMutationFailure($this->scopeGateway->deleteScope(
			$subjectUserId,
			$scopeId,
			(string)$authorizationResult->getData()['configId'],
		));
	}

	private function authorize(
		int $actorUserId,
		int $subjectUserId,
		Context $context,
		int $scopeId,
		string $operation,
	): Result
	{
		$configIdResult = $this->prepareOperation($context);
		if (!$configIdResult->isSuccess())
		{
			return $configIdResult;
		}
		if (!$this->access->canActForSubject($actorUserId, $subjectUserId))
		{
			return FailureResultFactory::accessDenied();
		}

		if (!$this->access->canReadCustom($subjectUserId, $scopeId))
		{
			return FailureResultFactory::layoutNotFound();
		}

		$actorHasAccess = match ($operation) {
			'read' => true,
			'update' => $this->access->canUpdateCustom($actorUserId, $scopeId),
			'delete' => $this->access->canDeleteCustom($actorUserId, $scopeId),
			default => false,
		};
		if (!$actorHasAccess)
		{
			return FailureResultFactory::layoutNotFound();
		}

		$scopeResult = $this->scopeResultValidator->ensureMatchesMode(
			$this->scopeGateway->readScope(
				$subjectUserId,
				$scopeId,
				(string)$configIdResult->getData()['configId'],
			),
			$context,
		);
		if (!$scopeResult->isSuccess())
		{
			return $scopeResult;
		}

		return (new Result())->setData([
			'configId' => $configIdResult->getData()['configId'],
			'scope' => $scopeResult->getData()['scope'],
		]);
	}

	private function prepareOperation(Context $context): Result
	{
		$configIdResult = $this->gateway->getConfigId($context);
		if (!$configIdResult->isSuccess())
		{
			return $configIdResult;
		}

		$availabilityResult = $this->scopeGateway->ensureAvailable();
		if (!$availabilityResult->isSuccess())
		{
			return $availabilityResult;
		}

		return $configIdResult;
	}

	/**
	 * @param array<int, array<string, mixed>> $layout
	 */
	private function prepareLayout(
		int $actorUserId,
		Context $context,
		?int $scopeId,
		array $layout,
	): Result
	{
		try
		{
			$config = new EntityEditorConfig(
				$context->entityTypeId,
				$actorUserId,
				EntityEditorConfigScope::CUSTOM,
				$this->buildExtras($context),
			);
			if ($scopeId !== null)
			{
				$config->setUserScopeId($scopeId);
			}

			$layout = $config->normalize($layout);
			$errors = [];
			if (!$config->check($layout, $errors))
			{
				return $this->invalidLayoutFailure();
			}

			$layout = $config->sanitize($layout);
			if ($layout === [])
			{
				return $this->invalidLayoutFailure();
			}
		}
		catch (Throwable)
		{
			return $this->storageFailure('Could not validate the CRM entity editor custom layout.');
		}

		return (new Result())->setData(['layout' => $layout]);
	}

	/**
	 * @return array<string, int>
	 */
	private function buildExtras(Context $context): array
	{
		$extras = [];
		if ($context->categoryId !== null)
		{
			$extras[Context::CRM_CATEGORY_ID_KEY] = $context->categoryId;
		}
		if ($context->customerType !== null)
		{
			$extras['LEAD_CUSTOMER_TYPE'] = $context->customerType;
		}

		return $extras;
	}

	private function invalidLayoutFailure(): Result
	{
		return (new Result())->addError(
			new Error('The CRM entity editor custom layout is invalid.', self::INVALID_LAYOUT),
		);
	}

	private function storageFailure(string $message): Result
	{
		return (new Result())->addError(new Error($message, ErrorCode::STORAGE_FAILED));
	}

	private function validationFailure(): Result
	{
		return (new Result())->addError(new Error(
			'The CRM entity editor custom layout input is invalid.',
			ErrorCode::VALIDATION_FAILED,
		));
	}
}
