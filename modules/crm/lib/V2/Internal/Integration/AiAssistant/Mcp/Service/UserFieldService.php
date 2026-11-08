<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service;

use Bitrix\Crm\Entity\EntityEditorConfig;
use Bitrix\Crm\Integration\Analytics\Builder\Userfield\Context\CreateContext;
use Bitrix\Crm\Integration\UI\EntityEditor\Configuration\Element;
use Bitrix\Crm\Integration\UI\EntityEditor\Enum\MarkTarget;
use Bitrix\Crm\Integration\UI\EntityEditor\MartaAIMarksRepository;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\UserField\FieldNameGenerator;
use Bitrix\Crm\UserField\UserFieldLimitError;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Request\UserField;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\UserField\Types;
use CLanguage;
use CUserFieldEnum;
use CUserTypeEntity;

final class UserFieldService
{
	private const DEFAULT_DOUBLE_PRECISION = 2;
	private const DEFAULT_ENUM_DISPLAY = 'UI';

	public function __construct(
		private readonly FieldNameGenerator $fieldNameGenerator,
		private readonly CategoryService $categoryService,
	)
	{
	}

	public function createUserField(
		int $userId,
		int $entityTypeId,
		?int $categoryId,
		UserField $userField,
	): Result
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$categoryNormalizeResult = $this->categoryService->normalizeCategoryId($entityTypeId, $categoryId);
		if (!$categoryNormalizeResult->isSuccess())
		{
			return $categoryNormalizeResult;
		}

		$categoryId = $categoryNormalizeResult->get('categoryId');
		$permissions = Container::getInstance()->getUserPermissions($userId);
		if (!$permissions->isAdminForEntity($entityTypeId, $categoryId))
		{
			return Result::failAccessDenied();
		}

		$saveUserFieldResult = $this->saveUserField($factory, $userField);
		if (!$saveUserFieldResult->isSuccess())
		{
			return Result::fail($saveUserFieldResult->getErrorCollection());
		}

		if ($userField->type === Types\EnumType::USER_TYPE_ID)
		{
			$enumSaveResult = $this->saveEnum($saveUserFieldResult->get('fieldId'), $userField);
			if (!$enumSaveResult->isSuccess())
			{
				return Result::fail($enumSaveResult->getErrorCollection());
			}
		}

		$this->addUserFieldToCardView(
			$userId,
			$entityTypeId,
			$categoryId,
			$saveUserFieldResult->get('fieldName'),
		);

		return Result::success(fieldName: $saveUserFieldResult->get('fieldName'));
	}

	private function saveUserField(Factory $factory, UserField $userField): Result
	{
		$entityId = $factory->getUserFieldEntityId();
		$fieldName = $this->fieldNameGenerator->generate($entityId);
		$settings = $this->getSettings($userField->type);
		$labelFields = $this->getLabelFields($userField->title);
		$analytics = new CreateContext(source: CreateContext::SOURCE_MCP_TOOL);

		$fields = [
			'ENTITY_ID' => $entityId,
			'FIELD_NAME' => $fieldName,
			'MULTIPLE' => $userField->multiple ? 'Y' : 'N',
			'USER_TYPE_ID' => $userField->type,
			'SETTINGS' => $settings,
			'ANALYTICS' => $analytics,
			...$labelFields,
		];

		$compatibilityMode = !method_exists(\CAllUserTypeEntity::class, 'syncColumnsAgent');
		$connection = Application::getConnection();
		$lockKey = 'crm_uf_add_' . $entityId;
		if ($compatibilityMode)
		{
			$connection->lock($lockKey, -1);
		}

		$userTypeEntity = new CUserTypeEntity();
		try
		{
			$id = $userTypeEntity->Add($fields);
		}
		catch (\Throwable $exception)
		{
			if ($compatibilityMode)
			{
				$connection->unlock($lockKey);
			}

			if (UserFieldLimitError::isCreateLimitError($exception))
			{
				return Result::fail(UserFieldLimitError::getError());
			}

			throw $exception;
		}

		$this->cleanUfCache($entityId);
		if ($compatibilityMode)
		{
			$connection->unlock($lockKey);
		}

		if ($id === false)
		{
			$result = Result::failFromApplication();
			if (UserFieldLimitError::hasCreateLimitError($result->getErrors()))
			{
				return Result::fail(UserFieldLimitError::getError());
			}

			return $result;
		}

		$dataClass = $factory->getDataClass();
		$connection->clearCaches($dataClass::getTableName());
		$factory->clearFieldsCollectionCache();

		return Result::success(fieldName: $fieldName, fieldId: $id);
	}

	private function saveEnum(int $fieldId, UserField $userField): Result
	{
		$enums = [];
		foreach ($userField->enumList as $index => $enumItem)
		{
			$enums['n' . $index] = [
				'DEF' => $enumItem->default ? 'Y' : 'N',
				'VALUE' => $enumItem->value,
				'SORT' => $enumItem->sort,
			];
		}

		$manager = new CUserFieldEnum();
		$isSuccess = $manager->setEnumValues($fieldId, $enums);

		return $isSuccess ? Result::success() : Result::failFromApplication();
	}

	private function getSettings(string $type): array
	{
		return match ($type) {
			Types\DoubleType::USER_TYPE_ID => [
				'PRECISION' => self::DEFAULT_DOUBLE_PRECISION,
			],
			Types\EnumType::USER_TYPE_ID => [
				'DISPLAY' => self::DEFAULT_ENUM_DISPLAY,
			],
			default => [],
		};
	}

	private function getLabelFields(string $label): array
	{
		$result = [];
		$fields = [
			'EDIT_FORM_LABEL',
			'LIST_COLUMN_LABEL',
			'LIST_FILTER_LABEL',
		];

		$languageIds = [];
		$languageResult = CLanguage::GetList();
		while ($language = $languageResult->Fetch())
		{
			$languageIds[] = $language['LID'];
		}

		foreach ($fields as $field)
		{
			foreach ($languageIds as $languageId)
			{
				$result[$field][$languageId] = $label;
			}
		}

		return $result;
	}

	private function cleanUfCache(string $entityId): void
	{
		$sort = [];

		$filter = [
			'ENTITY_ID' => $entityId,
		];
		$cacheId = 'b_user_type' . md5(serialize($sort) . "." . serialize($filter));
		$GLOBALS['CACHE_MANAGER']->Clean($cacheId, 'b_user_field');

		$filter = [
			'ENTITY_ID' => $entityId,
			'LANG' => LANGUAGE_ID,
		];
		$cacheId = 'b_user_type' . md5(serialize($sort) . "." . serialize($filter));
		$GLOBALS['CACHE_MANAGER']->Clean($cacheId, 'b_user_field');

		$GLOBALS['CACHE_MANAGER']->CleanDir('b_user_field');
	}

	private function addUserFieldToCardView(
		int $userId,
		int $entityTypeId,
		?int $categoryId,
		string $fieldName,
	): void
	{
		if (!Loader::includeModule('ui'))
		{
			return;
		}

		$extras = [
			'USER_ID' => $userId,
			'CATEGORY_ID' => $categoryId,
		];

		$config = EntityEditorConfig::createWithCurrentScope($entityTypeId, $extras);
		$configuration = $config->getConfiguration(useDefaultIfNotExists: true);
		if ($configuration === null || !$configuration->hasSections())
		{
			return;
		}

		$element = $configuration->getElement($fieldName);
		if ($element !== null)
		{
			$element->setShowAlways(true);
		}
		else
		{
			$section = $configuration->getSection('additional') ?? $configuration->getSectionFirst();
			$section?->addElement(new Element(name: $fieldName, isShowAlways: true));
		}

		$configuration->save();

		MartaAIMarksRepository::fromEntityEditorConfig($configuration->entityEditorConfig())
			->mark(MarkTarget::Field, [$fieldName])
		;
	}
}
