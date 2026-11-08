<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service;

use Bitrix\Crm\Entity\EntityEditorConfig;
use Bitrix\Crm\Entity\EntityEditorConfigScope;
use Bitrix\Crm\Entity\EntityEditorOptionBuilder;
use Bitrix\Crm\EntityForm\ScopeAccess;
use Bitrix\Crm\Integration\UI\EntityEditor\Configuration;
use Bitrix\Crm\Integration\UI\EntityEditor\Enum\MarkTarget;
use Bitrix\Crm\Integration\UI\EntityEditor\MartaAIMarksRepository;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Crm\V2\Infrastructure\Component\Details\PageLayout\PageLayoutId;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Request;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Main\UserTable;
use Bitrix\Ui\EntityForm\EO_EntityFormConfig;
use Bitrix\Ui\EntityForm\EntityFormConfigTable;
use Bitrix\Ui\EntityForm\Scope;
use CUtil;

final readonly class PageLayoutService
{
	private const MODULE_ID = 'crm';
	private const INVALID_PAGE_LAYOUT_ERROR_MESSAGE =
		'Invalid page layout: one or more element IDs are unknown. '
		. 'Use list_page_layout_elements_enum to get valid element IDs.'
	;

	public function __construct(
		private CategoryService $categoryService,
	)
	{
	}

	/**
	 * The right over the layouts of the card these tools are about. The former check of the whole
	 * surface was the contextual administrator of the contour, which is what
	 * {@see ConfigurationCallSite::McpPageLayout} answers until the operations are registered.
	 */
	private function canManageCardLayout(int $userId, int $entityTypeId, ?int $categoryId): bool
	{
		return Container::getInstance()
			->getUserPermissions($userId)
			->configuration()
			->canManageCommonViews(
				ConfigurationCallSite::McpPageLayout,
				ConfigurationScope::ofEntityType($entityTypeId, $categoryId),
			)
		;
	}

	public function getCurrentPageLayout(int $userId, int $entityTypeId, ?int $categoryId): Result
	{
		if (!Loader::includeModule('ui'))
		{
			return Result::failModuleNotInstalled('ui');
		}

		$categoryNormalizeResult = $this->categoryService->normalizeCategoryId($entityTypeId, $categoryId);
		if (!$categoryNormalizeResult->isSuccess())
		{
			return $categoryNormalizeResult;
		}

		/** @var ?int $categoryId */
		$categoryId = $categoryNormalizeResult->get('categoryId');

		if (!$this->canManageCardLayout($userId, $entityTypeId, $categoryId))
		{
			return Result::failAccessDenied();
		}

		$guid = $this->getGuid($entityTypeId, $categoryId);
		if ($guid === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$extras = [
			'USER_ID' => $userId,
			'CATEGORY_ID' => $categoryId,
		];

		$config = EntityEditorConfig::createWithCurrentScope($entityTypeId, $extras);

		$configuration = $config->getConfiguration(useDefaultIfNotExists: true);
		if ($configuration === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$title = match ($config->getScope()) {
			EntityEditorConfigScope::COMMON,
			EntityEditorConfigScope::PERSONAL => EntityEditorConfigScope::getCaption($config->getScope()),
			EntityEditorConfigScope::CUSTOM => Scope::getInstance($userId)->getById($config->getUserScopeId())['NAME'] ?? null,
			default => null,
		};

		$responsePageLayout = Response\PageLayout::fromConfiguration(
			$configuration,
			$title,
			$entityTypeId,
			$categoryId,
			$config->getScope(),
			$config->getUserScopeId(),
		);

		return Result::success(pageLayout: $responsePageLayout);
	}

	public function createCustomPageLayout(int $userId, int $entityTypeId, ?int $categoryId, Request\PageLayout $pageLayout): Result
	{
		if (!Loader::includeModule('ui'))
		{
			return Result::failModuleNotInstalled('ui');
		}

		$categoryNormalizeResult = $this->categoryService->normalizeCategoryId($entityTypeId, $categoryId);
		if (!$categoryNormalizeResult->isSuccess())
		{
			return $categoryNormalizeResult;
		}

		/** @var ?int $categoryId */
		$categoryId = $categoryNormalizeResult->get('categoryId');

		if (!$this->canManageCardLayout($userId, $entityTypeId, $categoryId))
		{
			return Result::failAccessDenied();
		}

		$guid = $this->getGuid($entityTypeId, $categoryId);
		if ($guid === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$config = $this->mapRequestPageLayout($factory, $pageLayout);
		if ($config === null)
		{
			return Result::fail(self::INVALID_PAGE_LAYOUT_ERROR_MESSAGE);
		}

		$userAccessCodes = $this->getUserAccessCodes([$userId]);
		$defaultOptions = $this->getDefaultScopeOptions();

		$userScopeIdOrErrors = Scope::getInstance()
			->setScopeConfig(
				self::MODULE_ID,
				$guid,
				$pageLayout->title,
				$userAccessCodes,
				$config,
				$defaultOptions,
			)
		;

		if (is_array($userScopeIdOrErrors))
		{
			return Result::fail(new ErrorCollection($userScopeIdOrErrors));
		}

		$configuration = Configuration::fromArray($config);
		$marksRepository = new MartaAIMarksRepository(
			$userId,
			$guid,
			EntityEditorConfigScope::CUSTOM,
			$userScopeIdOrErrors,
		);

		$marksRepository
			->mark(MarkTarget::Section, $configuration->getSectionNames())
			->mark(MarkTarget::Field, $configuration->getElementNames())
		;

		$responsePageLayout = Response\PageLayout::fromConfiguration(
			$configuration,
			$pageLayout->title,
			$entityTypeId,
			$categoryId,
			EntityEditorConfigScope::CUSTOM,
			$userScopeIdOrErrors,
		);

		return Result::success(pageLayout: $responsePageLayout);
	}

	public function updatePageLayout(int $userId, int $entityTypeId, string $pageLayoutCode, Request\PageLayout $pageLayout): Result
	{
		if (!Loader::includeModule('ui'))
		{
			return Result::failModuleNotInstalled('ui');
		}

		$pageLayoutId = PageLayoutId::fromCode($pageLayoutCode);
		if ($pageLayoutId === null)
		{
			return Result::fail(
				"Page layout ID '{$pageLayoutCode}' is invalid. Use list_page_layout to get a valid pageLayoutId."
			);
		}

		$categoryNormalizeResult = $this->categoryService->normalizeCategoryId($entityTypeId, $pageLayoutId->categoryId);
		if (!$categoryNormalizeResult->isSuccess())
		{
			return $categoryNormalizeResult;
		}

		/** @var ?int $categoryId */
		$categoryId = $categoryNormalizeResult->get('categoryId');

		$permissions = Container::getInstance()->getUserPermissions($userId);
		$canUpdate = match ($pageLayoutId->scope) {
			EntityEditorConfigScope::COMMON => $this->canManageCardLayout($userId, $entityTypeId, $categoryId),
			EntityEditorConfigScope::CUSTOM => ScopeAccess::getInstance(self::MODULE_ID, $userId)
				->canUpdate((int)$pageLayoutId->customPageLayoutId),
			default => $permissions->isAdminForEntity($entityTypeId, $categoryId),
		};
		if (!$canUpdate)
		{
			return Result::failAccessDenied();
		}

		$guid = $this->getGuid($entityTypeId, $categoryId);
		if ($guid === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$customPageLayout = null;
		if ($pageLayoutId->scope === EntityEditorConfigScope::CUSTOM)
		{
			$customPageLayout = $this->getCustomPageLayout($pageLayoutId, $guid);
			if ($customPageLayout === null)
			{
				return $this->failPageLayoutNotFound($pageLayoutCode);
			}

			if ($pageLayout->title === null)
			{
				return Result::fail('The pageLayout.title field is required for a custom page layout.');
			}
		}
		elseif ($pageLayout->title !== null)
		{
			return Result::fail(
				'The pageLayout.title field must not be passed for common or personal page layouts.'
			);
		}

		$extras = [
			'USER_ID' => $userId,
			'CATEGORY_ID' => $categoryId,
		];

		$config = new EntityEditorConfig($entityTypeId, $userId, $pageLayoutId->scope, $extras);
		$config->setUserScopeId($pageLayoutId->customPageLayoutId ?? 0);

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$pageLayoutConfig = $this->mapRequestPageLayout($factory, $pageLayout);
		if ($pageLayoutConfig === null)
		{
			return Result::fail(self::INVALID_PAGE_LAYOUT_ERROR_MESSAGE);
		}

		if ($customPageLayout !== null)
		{
			$updateResult = Scope::getInstance($userId)->updateScope(
				$customPageLayout->getId(),
				[
					'NAME' => $pageLayout->title,
					'CONFIG' => $pageLayoutConfig,
					'ON_ADD' => $customPageLayout->getOnAdd() ? 'Y' : 'N',
					'ON_UPDATE' => $customPageLayout->getOnUpdate() ? 'Y' : 'N',
					'AUTO_APPLY_SCOPE' => $customPageLayout->getAutoApplyScope() ? 'Y' : 'N',
				],
			);
			if (!$updateResult->isSuccess())
			{
				return Result::fail($updateResult->getErrorCollection());
			}

			return Result::success();
		}

		try
		{
			$config->set($pageLayoutConfig);
		}
		catch (SystemException $exception)
		{
			return Result::fail($exception->getMessage());
		}

		return Result::success();
	}

	public function listPageLayouts(int $userId, int $entityTypeId, ?int $categoryId = null): Result
	{
		if (!Loader::includeModule('ui'))
		{
			return Result::failModuleNotInstalled('ui');
		}

		$categoryNormalizeResult = $this->categoryService->normalizeCategoryId($entityTypeId, $categoryId);
		if (!$categoryNormalizeResult->isSuccess())
		{
			return $categoryNormalizeResult;
		}

		/** @var ?int $categoryId */
		$categoryId = $categoryNormalizeResult->get('categoryId');

		if (!$this->canManageCardLayout($userId, $entityTypeId, $categoryId))
		{
			return Result::failAccessDenied();
		}

		$guid = $this->getGuid($entityTypeId, $categoryId);
		if ($guid === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$extras = [
			'USER_ID' => $userId,
			'CATEGORY_ID' => $categoryId,
		];

		$pageLayouts = [];

		$commonConfig = new EntityEditorConfig($entityTypeId, $userId, EntityEditorConfigScope::COMMON, $extras);
		$commonConfiguration = $commonConfig->getConfiguration(useDefaultIfNotExists: true);
		if ($commonConfiguration !== null)
		{
			$pageLayouts[] = Response\PageLayout::fromConfiguration(
				$commonConfiguration,
				EntityEditorConfigScope::getCaption(EntityEditorConfigScope::COMMON),
				$entityTypeId,
				$categoryId,
				EntityEditorConfigScope::COMMON,
			);
		}

		$personalConfig = new EntityEditorConfig($entityTypeId, $userId, EntityEditorConfigScope::PERSONAL, $extras);
		$personalConfiguration = $personalConfig->getConfiguration(useDefaultIfNotExists: true);
		if ($personalConfiguration !== null)
		{
			$pageLayouts[] = Response\PageLayout::fromConfiguration(
				$personalConfiguration,
				EntityEditorConfigScope::getCaption(EntityEditorConfigScope::PERSONAL),
				$entityTypeId,
				$categoryId,
				EntityEditorConfigScope::PERSONAL,
			);
		}

		$scope = Scope::getInstance($userId);
		$userScopes = $scope->getUserScopeConfigs($guid, 'crm');
		foreach ($userScopes->getAll() as $userScope)
		{
			$customConfiguration = Configuration::fromArray($userScope->getConfig());

			$pageLayouts[] = Response\PageLayout::fromConfiguration(
				$customConfiguration,
				$userScope->getName(),
				$entityTypeId,
				$categoryId,
				EntityEditorConfigScope::CUSTOM,
				$userScope->getId(),
			);
		}

		return Result::success(pageLayouts: $pageLayouts);
	}

	public function listPageLayoutElementsEnum(int $userId, int $entityTypeId, ?int $categoryId): Result
	{
		$categoryNormalizeResult = $this->categoryService->normalizeCategoryId($entityTypeId, $categoryId);
		if (!$categoryNormalizeResult->isSuccess())
		{
			return $categoryNormalizeResult;
		}

		/** @var ?int $categoryId */
		$categoryId = $categoryNormalizeResult->get('categoryId');

		if (!$this->canManageCardLayout($userId, $entityTypeId, $categoryId))
		{
			return Result::failAccessDenied();
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$elements = [];

		$fields = $factory->getFieldsCollection();
		foreach ($fields as $field)
		{
			$elements[] = new Response\PageLayout\Element($field->getName(), $field->getTitle());
		}

		return Result::success(elements: $elements);
	}

	public function switchPageLayout(int $userId, int $entityTypeId, string $pageLayoutCode, array $userIds): Result
	{
		if (!Loader::includeModule('ui'))
		{
			return Result::failModuleNotInstalled('ui');
		}

		$pageLayoutId = PageLayoutId::fromCode($pageLayoutCode);
		if ($pageLayoutId === null)
		{
			return Result::fail(
				"Page layout ID '{$pageLayoutCode}' is invalid. Use list_page_layout to get a valid pageLayoutId."
			);
		}

		$categoryNormalizeResult = $this->categoryService->normalizeCategoryId($entityTypeId, $pageLayoutId->categoryId);
		if (!$categoryNormalizeResult->isSuccess())
		{
			return $categoryNormalizeResult;
		}

		/** @var ?int $categoryId */
		$categoryId = $categoryNormalizeResult->get('categoryId');

		// Assigning a layout writes the options of the named users rather than the layout of the card,
		// so it is not part of the granular right of common views ({@see ConfigurationCallSite}).
		$permissions = Container::getInstance()->getUserPermissions($userId);
		if (!$permissions->isAdminForEntity($entityTypeId, $categoryId))
		{
			return Result::failAccessDenied();
		}

		$guid = $this->getGuid($entityTypeId, $categoryId);
		if ($guid === null)
		{
			return Result::failEntityTypeNotSupported($entityTypeId);
		}

		$userIds = array_values(array_unique($userIds));
		if (empty($userIds))
		{
			return Result::fail('At least one user ID is required.');
		}

		if (
			$pageLayoutId->scope === EntityEditorConfigScope::CUSTOM
			&& $this->getCustomPageLayout($pageLayoutId, $guid) === null
		)
		{
			return $this->failPageLayoutNotFound($pageLayoutCode);
		}

		$existingUserIds = UserTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $userIds)
			->fetchCollection()
			->getIdList()
		;
		$missingUserIds = array_values(array_diff($userIds, $existingUserIds));
		if (!empty($missingUserIds))
		{
			return Result::fail('Users not found: ' . implode(', ', $missingUserIds) . '.');
		}

		$scope = Scope::getInstance($userId);
		foreach ($userIds as $targetUserId)
		{
			$scope
				->setEditScope(
					self::MODULE_ID,
					EntityEditorConfig::CATEGORY_NAME,
					$guid,
					$pageLayoutId->scope,
					$pageLayoutId->customPageLayoutId ?? 0,
					$targetUserId,
				)
			;
		}

		return Result::success();
	}

	private function getGuid(int $entityTypeId, ?int $categoryId = null): ?string
	{
		$guid = (new EntityEditorOptionBuilder($entityTypeId))
			->setCategoryId($categoryId)
			->build()
		;

		return empty($guid) ? null : $guid;
	}

	private function getCustomPageLayout(PageLayoutId $pageLayoutId, string $guid): ?EO_EntityFormConfig
	{
		return EntityFormConfigTable::query()
			->setSelect(['*'])
			->where('ID', $pageLayoutId->customPageLayoutId)
			->where('CATEGORY', self::MODULE_ID)
			->where('ENTITY_TYPE_ID', $guid)
			->fetchObject()
		;
	}

	private function failPageLayoutNotFound(string $pageLayoutCode): Result
	{
		return Result::fail(
			"Page layout '{$pageLayoutCode}' was not found for the specified CRM entity. "
			. 'Use list_page_layout to get a valid pageLayoutId.'
		);
	}

	private function mapRequestPageLayout(Factory $factory, Request\PageLayout $pageLayout): ?array
	{
		$sections = [];
		$fields = $factory->getFieldsCollection();

		foreach ($pageLayout->sections as $section)
		{
			$elements = [];
			foreach ($section->elements as $element)
			{
				$field = $fields->getField($element->id);
				if ($field === null)
				{
					return null;
				}

				$elements[] = [
					'name' => $element->id,
					'optionFlags' => 1,
					'options' => [],
				];
			}

			if (empty($elements))
			{
				continue;
			}

			$sectionSlug = CUtil::translit($section->title, Loc::getCurrentLang());

			$sections[] = [
				'name' => $sectionSlug,
				'title' => $section->title,
				'type' => 'section',
				'elements' => $elements,
			];
		}

		if (empty($sections))
		{
			return null;
		}

		return [
			[
				'name' => 'default_column',
				'type' => 'column',
				'elements' => $sections,
			]
		];
	}

	private function getUserAccessCodes(array $userIds): array
	{
		$result = [];
		foreach ($userIds as $userId)
		{
			$result[] = [
				'id' => $userId,
				'entityId' => 'user', /* @see Scope::TYPE_USER */
			];
		}

		return $result;
	}

	private function getDefaultScopeOptions(): array
	{
		return [
			'forceSetToUsers' => 'Y',
			'common' => 'N',
			'categoryName' => EntityEditorConfig::CATEGORY_NAME,
			'availableOnAdd' => 'Y',
			'availableOnUpdate' => 'Y',
		];
	}

}
