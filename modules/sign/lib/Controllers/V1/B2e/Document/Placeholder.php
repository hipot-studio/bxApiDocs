<?php

namespace Bitrix\Sign\Controllers\V1\B2e\Document;

use Bitrix\Main;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Attribute\ActionAccess;
use Bitrix\Sign\Config\Feature;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Engine\Controller;
use Bitrix\Sign\Item;
use Bitrix\Sign\Repository\Grid\PlaceholderRepository;
use Bitrix\Sign\Service\Document\FieldAccessService;
use Bitrix\Sign\Service\Document\Placeholder\PlaceholderCollectorService;
use Bitrix\Sign\Service\Placeholder\Restriction\ScenarioContext;
use Bitrix\Sign\Service\Providers\LegalInfoProvider;
use Bitrix\Sign\Attribute;
use Bitrix\Sign\Type\Field\FrontFieldCategory;

class Placeholder extends Controller
{
	public const ERROR_UNKNOWN_FIELD_CODE = 'unknownFieldCode';
	public const ERROR_FIELD_DELETE_ACCESS_DENIED = 'fieldDeleteAccessDenied';
	public const ERROR_FIELD_DELETE_UNKNOWN_FIELD = 'fieldDeleteUnknownField';
	public const ERROR_FIELD_DELETE_DEFAULT_LEGAL_FIELD = 'fieldDeleteDefaultLegalField';
	public const ERROR_FIELD_DELETE_FAILED = 'fieldDeleteFailed';

	/**
	 * Phrases of the refusals the repository spells by code only.
	 */
	private const REPOSITORY_ERROR_PHRASES = [
		PlaceholderRepository::ERROR_EMPTY_FIELD_CODE
			=> 'SIGN_CONTROLLERS_V1_B2E_DOCUMENT_PLACEHOLDER_ERROR_EMPTY_FIELD_CODE',
		PlaceholderRepository::ERROR_NOT_ALLOWED_FIELD_CODE
			=> 'SIGN_CONTROLLERS_V1_B2E_DOCUMENT_PLACEHOLDER_ERROR_NOT_ALLOWED_FIELD_CODE',
	];

	/**
	 * Actions that change state answer to POST only; the default filters of the controller allow GET
	 * as well.
	 */
	private const POST_ONLY_ACTIONS = ['saveFieldUsage', 'deleteField'];

	public function configureActions(): array
	{
		$actions = parent::configureActions();
		foreach (self::POST_ONLY_ACTIONS as $action)
		{
			$actions[$action]['+prefilters'][] = new Main\Engine\ActionFilter\HttpMethod([
				Main\Engine\ActionFilter\HttpMethod::METHOD_POST,
			]);
		}

		return $actions;
	}

	#[Attribute\Access\LogicOr(
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_TEMPLATE_EDIT),
	)]
	public function listAction(
		PlaceholderCollectorService $placeholderService,
		bool $clearCache = false,
		?string $uid = null,
	): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId < 1)
		{
			$this->addError(new Main\Error('Current user not found'));

			return [];
		}

		$result = $placeholderService->loadPlaceholdersByUserId(
			$currentUserId,
			$clearCache,
			$this->resolveScenarioContext($uid),
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return $result->getData();
	}

	#[Attribute\Access\LogicOr(
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_TEMPLATE_EDIT),
	)]
	public function listByHcmLinkIdAction(
		int $hcmLinkCompanyId,
		PlaceholderCollectorService $placeholderService,
		?string $uid = null,
	): array
	{
		$scenarioContext = $this->resolveScenarioContext($uid)?->withHcmLinkCompanyId($hcmLinkCompanyId);
		$result = $placeholderService->loadPlaceholdersByHcmLinkCompanyId(
			$hcmLinkCompanyId,
			$scenarioContext,
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return $result->getData();
	}

	#[Attribute\Access\LogicOr(
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_TEMPLATE_EDIT),
	)]
	public function saveLastSelectionBySelectorTypeAction(
		string $selectorType,
		int $value,
		PlaceholderRepository $placeholderRepository,
	): void
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId < 1)
		{
			$this->addError(new Main\Error('Current user not found'));

			return;
		}

		$placeholderRepository->saveLastSelectionBySelectorTypeAction($selectorType, $value, $currentUserId);
	}

	#[Attribute\Access\LogicOr(
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_TEMPLATE_EDIT),
	)]
	public function getLastSelectionBySelectorTypeAction(
		string $selectorType,
		PlaceholderRepository $placeholderRepository,
	): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId < 1)
		{
			$this->addError(new Main\Error('Current user not found'));

			return [];
		}

		$lastSelectionResult = $placeholderRepository->getLastSelectionBySelectorTypeAction($selectorType, $currentUserId);
		if (!$lastSelectionResult->isSuccess())
		{
			$this->addError(new Main\Error('Could not get last selection'));

			return [];
		}

		return $lastSelectionResult->getData();
	}

	/**
	 * Recently copied field codes of the current user. Codes missing from the current fields
	 * collection are kept: the panel filters them itself and the history keeps a reserve on purpose.
	 */
	#[Attribute\Access\LogicOr(
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_TEMPLATE_EDIT),
	)]
	public function getPersonalSettingsAction(PlaceholderRepository $placeholderRepository): array
	{
		if (!Storage::instance()->isB2eAvailable())
		{
			$this->addError(new Main\Error('B2E is not available'));

			return [];
		}

		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId < 1)
		{
			$this->addError(new Main\Error('Current user not found'));

			return [];
		}

		return $placeholderRepository->getPersonalSettings($currentUserId);
	}

	/**
	 * @param string $code code of a visible field and, after a tilde, the modifier parameters of the
	 *                     copy; the tail is bounded by the repository
	 * @param int|null $hcmLinkCompanyId sent from the tab of the external human resources system only
	 */
	#[Attribute\Access\LogicOr(
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_TEMPLATE_EDIT),
	)]
	public function saveFieldUsageAction(
		string $code,
		PlaceholderCollectorService $placeholderService,
		PlaceholderRepository $placeholderRepository,
		?int $hcmLinkCompanyId = null,
	): array
	{
		if (!Storage::instance()->isB2eAvailable())
		{
			$this->addError(new Main\Error('B2E is not available'));

			return [];
		}

		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId < 1)
		{
			$this->addError(new Main\Error('Current user not found'));

			return [];
		}

		$visibleFieldCodes = $this->loadVisibleFieldCodes($placeholderService, $currentUserId, $hcmLinkCompanyId);
		if ($visibleFieldCodes === null)
		{
			return [];
		}

		if (!in_array($this->fieldCodeOf($code), $visibleFieldCodes, true))
		{
			$this->addError(new Main\Error(
				(string)Main\Localization\Loc::getMessage(
					'SIGN_CONTROLLERS_V1_B2E_DOCUMENT_PLACEHOLDER_ERROR_UNKNOWN_FIELD_CODE',
				),
				self::ERROR_UNKNOWN_FIELD_CODE,
			));

			return [];
		}

		$saveResult = $placeholderRepository->pushRecentFieldCode($currentUserId, $code);
		if (!$saveResult->isSuccess())
		{
			$this->addRepositoryErrors($saveResult->getErrors());

			return [];
		}

		return $placeholderRepository->getPersonalSettings($currentUserId);
	}

	/**
	 * The deletable mark of the fields list is a hint for the client only, so every gate is checked
	 * here again, the entity the identifier belongs to and the default legal fields included. The list
	 * cache is invalidated by the `main:OnAfterUserTypeDelete` handler, so the action resets nothing.
	 *
	 * @param string $category a value of FrontFieldCategory: the categories of CRM have no delete
	 *                         permission of the module and are refused here
	 *
	 * @return array{deleted: true}|array{}
	 */
	#[Attribute\Access\LogicOr(
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
		new ActionAccess(permission: ActionDictionary::ACTION_B2E_TEMPLATE_EDIT),
	)]
	public function deleteFieldAction(
		int $fieldId,
		string $category,
		FieldAccessService $fieldAccessService,
	): array
	{
		if (!Storage::instance()->isB2eAvailable())
		{
			return $this->addFieldDeleteError(
				self::ERROR_FIELD_DELETE_ACCESS_DENIED,
				'SIGN_CONTROLLERS_V1_B2E_DOCUMENT_PLACEHOLDER_ERROR_FIELD_DELETE_ACCESS_DENIED',
			);
		}

		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId < 1)
		{
			$this->addError(new Main\Error('Current user not found'));

			return [];
		}

		$fieldCategory = FrontFieldCategory::tryFrom($category);
		if (!$this->isDeleteOfCategoryAllowed($fieldCategory, $currentUserId, $fieldAccessService))
		{
			return $this->addFieldDeleteError(
				self::ERROR_FIELD_DELETE_ACCESS_DENIED,
				'SIGN_CONTROLLERS_V1_B2E_DOCUMENT_PLACEHOLDER_ERROR_FIELD_DELETE_ACCESS_DENIED',
			);
		}

		$fieldName = $this->findUserFieldName($fieldId, $fieldCategory);
		if ($fieldName === null)
		{
			return $this->addFieldDeleteError(
				self::ERROR_FIELD_DELETE_UNKNOWN_FIELD,
				'SIGN_CONTROLLERS_V1_B2E_DOCUMENT_PLACEHOLDER_ERROR_FIELD_DELETE_UNKNOWN_FIELD',
			);
		}

		if (
			$fieldCategory === FrontFieldCategory::PROFILE
			&& in_array($fieldName, LegalInfoProvider::LEGAL_USER_FIELD_DEFAULT, true)
		)
		{
			return $this->addFieldDeleteError(
				self::ERROR_FIELD_DELETE_DEFAULT_LEGAL_FIELD,
				'SIGN_CONTROLLERS_V1_B2E_DOCUMENT_PLACEHOLDER_ERROR_FIELD_DELETE_DEFAULT_LEGAL_FIELD',
			);
		}

		if (!(new \CUserTypeEntity())->Delete($fieldId))
		{
			return $this->addFieldDeleteError(
				self::ERROR_FIELD_DELETE_FAILED,
				'SIGN_CONTROLLERS_V1_B2E_DOCUMENT_PLACEHOLDER_ERROR_FIELD_DELETE_FAILED',
			);
		}

		return ['deleted' => true];
	}

	/**
	 * Additional member fields exist together with the document templates, so their deletion follows
	 * the availability of the templates, exactly as their settings screen does.
	 */
	private function isDeleteOfCategoryAllowed(
		?FrontFieldCategory $category,
		int $userId,
		FieldAccessService $fieldAccessService,
	): bool
	{
		if ($category === null)
		{
			return false;
		}

		if (
			$category === FrontFieldCategory::DYNAMIC_MEMBER
			&& !Feature::instance()->isDocumentTemplatesAvailable()
		)
		{
			return false;
		}

		return ($fieldAccessService->getDeleteByCategoryPermissions($userId)[$category->value] ?? false) === true;
	}

	/**
	 * Null when the identifier belongs to another entity: an alien identifier is refused, never
	 * silently skipped.
	 */
	private function findUserFieldName(int $fieldId, FrontFieldCategory $category): ?string
	{
		$provider = match ($category)
		{
			FrontFieldCategory::PROFILE => $this->container->getLegalInfoProvider(),
			FrontFieldCategory::DYNAMIC_MEMBER => $this->container->getMemberDynamicFieldProvider(),
		};

		$fieldName = array_search($fieldId, $provider->getFieldIdsByName(), true);

		return $fieldName === false ? null : $fieldName;
	}

	/**
	 * @return array{} a refusal answers with the errors only
	 */
	private function addFieldDeleteError(string $errorCode, string $phraseCode): array
	{
		$this->addError(new Main\Error(
			(string)Main\Localization\Loc::getMessage($phraseCode),
			$errorCode,
		));

		return [];
	}

	/**
	 * A refusal of the repository is answered with the phrase of its code, a refusal carrying no
	 * phrase travels as it is: the answer holds the localized text ready, and showing it is the
	 * choice of the client - the panel keeps a write of the history silent, unlike a field deletion.
	 *
	 * @param Main\Error[] $errors
	 */
	private function addRepositoryErrors(array $errors): void
	{
		foreach ($errors as $error)
		{
			$phraseCode = self::REPOSITORY_ERROR_PHRASES[(string)$error->getCode()] ?? null;
			if ($phraseCode === null)
			{
				$this->addError($error);

				continue;
			}

			$this->addError(new Main\Error(
				(string)Main\Localization\Loc::getMessage($phraseCode),
				$error->getCode(),
			));
		}
	}

	private function fieldCodeOf(string $code): string
	{
		$tildePosition = strpos($code, '~');

		return $tildePosition === false ? $code : substr($code, 0, $tildePosition);
	}

	/**
	 * Codes are taken exactly as the collector spelled them, so an alias and a legacy raw field name
	 * are both accepted and a code of somebody else's or of a deleted field never reaches the history.
	 * Only the codes are read here, so the list comes without the marks of the deletable fields: they
	 * are built for the answer of the list action and cost the field maps of the permitted categories.
	 *
	 * @return string[]|null null when a collection cannot be loaded; the errors are already added
	 */
	private function loadVisibleFieldCodes(
		PlaceholderCollectorService $placeholderService,
		int $userId,
		?int $hcmLinkCompanyId,
	): ?array
	{
		$listResult = $placeholderService->loadPlaceholdersByUserId($userId, withDeletableMarks: false);
		if (!$listResult->isSuccess())
		{
			$this->addErrors($listResult->getErrors());

			return null;
		}

		$fieldCodes = $this->collectFieldCodes($listResult->getData());
		if ($hcmLinkCompanyId === null || $hcmLinkCompanyId < 1)
		{
			return $fieldCodes;
		}

		$hcmLinkListResult = $placeholderService->loadPlaceholdersByHcmLinkCompanyId($hcmLinkCompanyId);
		if (!$hcmLinkListResult->isSuccess())
		{
			$this->addErrors($hcmLinkListResult->getErrors());

			return null;
		}

		return array_merge($fieldCodes, $this->collectFieldCodes($hcmLinkListResult->getData()));
	}

	/**
	 * @param array<int, array> $sections sections of the fields list, possibly with subsections
	 *
	 * @return string[]
	 */
	private function collectFieldCodes(array $sections): array
	{
		$fieldCodes = [];
		foreach ($sections as $section)
		{
			foreach (($section['items'] ?? []) as $item)
			{
				$fieldCode = $item['value'] ?? null;
				if (is_string($fieldCode) && $fieldCode !== '')
				{
					$fieldCodes[] = $fieldCode;
				}
			}

			$fieldCodes = array_merge($fieldCodes, $this->collectFieldCodes($section['subsections'] ?? []));
		}

		return $fieldCodes;
	}

	/**
	 * The scenario of the document only narrows the list, so an unknown or inaccessible uid keeps
	 * the full list instead of failing the action.
	 */
	private function resolveScenarioContext(?string $uid): ?ScenarioContext
	{
		if (
			!Storage::instance()->isPlaceholderRestrictionEnabled()
			|| $uid === null
			|| $uid === ''
		)
		{
			return null;
		}

		$document = $this->container->getDocumentRepository()->getByUid($uid);
		if ($document === null || !$this->canEditDocumentOrItsTemplate($document))
		{
			return null;
		}

		return $this->container->getScenarioContextFactory()->fromDocument($document);
	}

	/**
	 * Item-aware counterpart of the permission-level LogicOr of the action: the uid comes from the
	 * client, so the permission must be checked against the loaded document.
	 */
	private function canEditDocumentOrItsTemplate(Item\Document $document): bool
	{
		$accessController = $this->getAccessController();

		return $accessController->checkByItem(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT, $document)
			|| $accessController->checkByItem(ActionDictionary::ACTION_B2E_TEMPLATE_EDIT, $document)
		;
	}
}
