<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\EntityEditor;

use Bitrix\Crm\Entity\EntityEditorConfig;
use Bitrix\Crm\Entity\EntityEditorConfigScope;
use Bitrix\Crm\Entity\EntityEditorOptionBuilder;
use Bitrix\Crm\CustomerType;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\Context;
use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\ErrorCode;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CUserOptions;
use Throwable;

/**
 * @internal
 */
class ConfigGateway
{
	public function getConfigId(Context $context): Result
	{
		$result = new Result();

		try
		{
			$configId = (new EntityEditorOptionBuilder($context->entityTypeId))
				->setCategoryId($context->categoryId)
				->setCustomerType($context->customerType ?? CustomerType::UNDEFINED)
				->build()
			;
		}
		catch (Throwable)
		{
			return $this->storageFailure('Could not resolve the CRM entity editor layout identifier.');
		}

		if ($configId === '')
		{
			return $result->addError(
				new Error(
					'The CRM entity editor layout is not supported.',
					ErrorCode::UNSUPPORTED_ENTITY_TYPE,
				),
			);
		}

		$result->setData(['configId' => $configId]);

		return $result;
	}

	public function get(
		Context $context,
		int $configurationUserId,
		string $scope,
	): Result
	{
		$configResult = $this->createConfig($context, $configurationUserId, $scope);
		if (!$configResult->isSuccess())
		{
			return $configResult;
		}

		/** @var EntityEditorConfig $config */
		$config = $configResult->getData()['config'];

		try
		{
			$layout = $config->get();
			$configId = $config->getConfigId();
		}
		catch (Throwable)
		{
			return $this->storageFailure('Could not read the CRM entity editor layout.');
		}

		$result = new Result();
		$result->setData([
			'configId' => $configId,
			'layout' => $layout,
		]);

		return $result;
	}

	public function getDefault(Context $context, int $configurationUserId, string $scope): Result
	{
		$configResult = $this->createConfig($context, $configurationUserId, $scope);
		if (!$configResult->isSuccess())
		{
			return $configResult;
		}

		/** @var EntityEditorConfig $config */
		$config = $configResult->getData()['config'];

		try
		{
			$layout = $config->getDefault();
			$configId = $config->getConfigId();
		}
		catch (Throwable)
		{
			return $this->storageFailure('Could not read the default CRM entity editor layout.');
		}

		return (new Result())->setData([
			'configId' => $configId,
			'layout' => $layout,
		]);
	}

	/**
	 * @param array<int, array<string, mixed>> $layout
	 */
	public function set(
		Context $context,
		int $configurationUserId,
		string $scope,
		array $layout,
	): Result
	{
		$configResult = $this->createConfig($context, $configurationUserId, $scope);
		if (!$configResult->isSuccess())
		{
			return $configResult;
		}

		/** @var EntityEditorConfig $config */
		$config = $configResult->getData()['config'];
		$layoutWasWritten = false;

		try
		{
			$layout = $config->normalize($layout);
			$validationErrors = [];
			if (!$config->check($layout, $validationErrors))
			{
				return $this->validationFailure();
			}

			$layout = $config->sanitize($layout);
			if ($layout === [])
			{
				return $this->validationFailure();
			}

			$configId = $config->getConfigId();
			$config->set($layout);
			$layoutWasWritten = true;
			if ($config->get() !== $layout)
			{
				return $this->storageFailure('Could not write the CRM entity editor layout.', true);
			}
		}
		catch (Throwable)
		{
			return $this->storageFailure('Could not write the CRM entity editor layout.', $layoutWasWritten);
		}

		$result = new Result();
		$result->setData(['configId' => $configId]);

		return $result;
	}

	public function reset(
		Context $context,
		int $configurationUserId,
		string $scope,
	): Result
	{
		$configResult = $this->createConfig($context, $configurationUserId, $scope);
		if (!$configResult->isSuccess())
		{
			return $configResult;
		}

		/** @var EntityEditorConfig $config */
		$config = $configResult->getData()['config'];

		try
		{
			$configId = $config->getConfigId();
			if (!$config->reset())
			{
				return $this->storageFailure('Could not reset the CRM entity editor layout.');
			}
		}
		catch (Throwable)
		{
			return $this->storageFailure('Could not reset the CRM entity editor layout.');
		}

		$result = new Result();
		$result->setData(['configId' => $configId]);

		return $result;
	}

	public function getSelectedScope(Context $context, int $subjectUserId): Result
	{
		if ($subjectUserId <= 0)
		{
			return $this->accessFailure();
		}

		$configIdResult = $this->getConfigId($context);
		if (!$configIdResult->isSuccess())
		{
			return $configIdResult;
		}

		$configId = (string)$configIdResult->getData()['configId'];
		$optionName = $this->getSelectionOptionName($context, $configId);

		try
		{
			$scope = CUserOptions::GetOption(
				Context::OPTION_CATEGORY,
				$optionName,
				false,
				$subjectUserId,
			);
			if ($scope === false)
			{
				$scope = CUserOptions::GetOption(
					Context::OPTION_CATEGORY,
					$configId . '_scope',
					EntityEditorConfigScope::COMMON,
					$subjectUserId,
				);
			}
		}
		catch (Throwable)
		{
			return $this->storageFailure('Could not read the current CRM entity editor layout scope.');
		}

		$scopeId = is_array($scope) ? (int)($scope['userScopeId'] ?? 0) : null;
		$scope = is_array($scope) ? ($scope['scope'] ?? null) : $scope;

		if (!in_array(
			$scope,
			[EntityEditorConfigScope::PERSONAL, EntityEditorConfigScope::COMMON, EntityEditorConfigScope::CUSTOM],
			true,
		))
		{
			$scope = EntityEditorConfigScope::COMMON;
			$scopeId = null;
		}
		elseif ($scope === EntityEditorConfigScope::CUSTOM && $scopeId <= 0)
		{
			$scope = EntityEditorConfigScope::COMMON;
			$scopeId = null;
		}

		$result = new Result();
		$result->setData([
			'configId' => $configId,
			'scope' => $scope,
			'scopeId' => $scopeId,
			'userId' => $subjectUserId,
			'optionName' => $optionName,
		]);

		return $result;
	}

	public function setSelectedScope(
		Context $context,
		int $subjectUserId,
		string $scope,
		?int $scopeId = null,
	): Result
	{
		if (
			$subjectUserId <= 0
			|| !in_array(
				$scope,
				[EntityEditorConfigScope::PERSONAL, EntityEditorConfigScope::COMMON, EntityEditorConfigScope::CUSTOM],
				true,
			)
			|| ($scope === EntityEditorConfigScope::CUSTOM) !== ($scopeId !== null && $scopeId > 0)
		)
		{
			return $this->validationFailure();
		}

		$configIdResult = $this->getConfigId($context);
		if (!$configIdResult->isSuccess())
		{
			return $configIdResult;
		}

		$configId = (string)$configIdResult->getData()['configId'];
		$optionName = $this->getSelectionOptionName($context, $configId);
		$value = $scope === EntityEditorConfigScope::CUSTOM
			? [
				'scope' => $scope,
				'userScopeId' => $scopeId,
				'onAdd' => $context->mode === Context::MODE_CREATE,
				'onUpdate' => $context->mode === Context::MODE_UPDATE,
			]
			: $scope;

		$writeResult = $this->writeSelection($subjectUserId, $optionName, $value);
		if (!$writeResult->isSuccess())
		{
			return $writeResult;
		}

		$result = new Result();
		$result->setData([
			'configId' => $configId,
			'scope' => $scope,
			'scopeId' => $scopeId,
			'userId' => $subjectUserId,
			'optionName' => $optionName,
		]);

		return $result;
	}

	public function clearSavedSelections(Context $context): Result
	{
		$configIdResult = $this->getConfigId($context);
		if (!$configIdResult->isSuccess())
		{
			return $configIdResult;
		}

		$configId = (string)$configIdResult->getData()['configId'];
		$optionName = $this->getSelectionOptionName($context, $configId);
		$legacyOptionName = $configId . '_scope';
		$selectionWasCleared = false;

		try
		{
			if (!$this->deleteSelectionsByOptionName($optionName))
			{
				return $this->storageFailure('Could not clear all CRM entity editor layout selections.');
			}
			$selectionWasCleared = true;

			if (!$this->deleteSelectionsByOptionName($legacyOptionName))
			{
				return $this->storageFailure(
					'Could not clear all CRM entity editor layout selections.',
					true,
				);
			}
		}
		catch (Throwable)
		{
			return $this->storageFailure(
				'Could not clear all CRM entity editor layout selections.',
				$selectionWasCleared,
			);
		}

		return new Result();
	}

	protected function deleteSelectionsByOptionName(string $optionName): bool
	{
		return CUserOptions::DeleteOptionsByName(Context::OPTION_CATEGORY, $optionName);
	}

	protected function writeSelection(int $subjectUserId, string $optionName, mixed $value): Result
	{
		$selectionWasWritten = false;
		try
		{
			CUserOptions::SetOption(
				Context::OPTION_CATEGORY,
				$optionName,
				$value,
				false,
				$subjectUserId,
			);
			$selectionWasWritten = true;
			$savedValue = CUserOptions::GetOption(
				Context::OPTION_CATEGORY,
				$optionName,
				false,
				$subjectUserId,
			);
		}
		catch (Throwable)
		{
			return $this->storageFailure(
				'Could not select the current CRM entity editor layout scope.',
				$selectionWasWritten,
			);
		}

		if ($savedValue !== $value)
		{
			return $this->storageFailure(
				'Could not select the current CRM entity editor layout scope.',
				true,
			);
		}

		return new Result();
	}

	private function getSelectionOptionName(Context $context, string $configId): string
	{
		return $configId . '_scope_' . ($context->mode === Context::MODE_CREATE ? 'on_add' : 'on_update');
	}

	private function createConfig(
		Context $context,
		int $configurationUserId,
		string $scope,
	): Result
	{
		if (
			$configurationUserId <= 0
			|| !in_array($scope, [EntityEditorConfigScope::PERSONAL, EntityEditorConfigScope::COMMON], true)
		)
		{
			return $this->validationFailure();
		}

		try
		{
			$config = new EntityEditorConfig(
				$context->entityTypeId,
				$configurationUserId,
				$scope,
				$this->buildExtras($context),
			);
		}
		catch (Throwable)
		{
			return $this->storageFailure('Could not initialize the CRM entity editor layout storage.');
		}

		$result = new Result();
		$result->setData(['config' => $config]);

		return $result;
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

	private function validationFailure(): Result
	{
		return (new Result())->addError(
			new Error(
				'The CRM entity editor layout is invalid.',
				ErrorCode::VALIDATION_FAILED,
			),
		);
	}

	private function unsupportedScopeFailure(): Result
	{
		return (new Result())->addError(
			new Error(
				'Custom CRM entity editor layouts require the custom scope gateway.',
				ErrorCode::UNSUPPORTED_VARIANT,
			),
		);
	}

	private function accessFailure(): Result
	{
		return (new Result())->addError(
			new Error(
				'Access to the CRM entity editor layout is denied.',
				ErrorCode::ACCESS_DENIED,
			),
		);
	}

	private function storageFailure(string $message, bool $partialStatePossible = false): Result
	{
		return (new Result())->addError(
			new Error(
				$message,
				ErrorCode::STORAGE_FAILED,
				$partialStatePossible ? ['partialStatePossible' => true] : null,
			),
		);
	}
}
