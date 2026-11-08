<?php

namespace Bitrix\Sign\Factory;

use Bitrix\Fileman\UserField\Types\AddressType;
use Bitrix\HumanResources\Type\HcmLink\FieldEntityType;
use Bitrix\Location\Entity\Address;
use Bitrix\Location\Service\FormatService;
use Bitrix\Main\Application;
use Bitrix\Main\Type\Date;
use Bitrix\Sign\Helper\Field\NameHelper;
use Bitrix\Sign\Integration\CRM;
use Bitrix\Sign\Item\B2e\Provider\ProfileFieldData;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\HumanResources\HcmLinkFieldService;
use Bitrix\Sign\Service\Integration\HumanResources\HcmLinkService;
use Bitrix\Sign\Service\Providers\LegalInfoProvider;
use Bitrix\Sign\Service\Providers\MemberDynamicFieldInfoProvider;
use Bitrix\Sign\Service\Providers\ProfileProvider;
use Bitrix\Sign\Type\BlockCode;
use Bitrix\Sign\Item;
use Bitrix\Sign\Type\Document\ExternalDateCreateSourceType;
use Bitrix\Sign\Type\Document\ExternalIdSourceType;
use Bitrix\Sign\Type\FieldType;
use Bitrix\Main;
use Bitrix\Sign\Type\Member\EntityType;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Connector\Field\CrmEntity;
use CCrmOwnerType;

class FieldValue
{
	// Full name order for Russian portals: last name, first name, patronymic. Non-RU portals use
	// the portal culture name format instead (same source as nameFormat sent in ConfigureRequest).
	private const FULL_NAME_RU_FORMAT = '#LAST_NAME# #NAME# #SECOND_NAME#';

	private readonly ProfileProvider $profileProvider;
	/**
	 * @var array<int, <string, array>>
	 */
	private array $fieldSetMemoryCacheByMemberId = [];
	/**
	 * @var array<string, array{allowedFieldCodes: list<string>, fieldSetCodes: list<string>, values: array<string, string>}>
	 */
	private array $companyRequisiteFieldDataMemoryCache = [];
	private readonly MemberDynamicFieldInfoProvider $memberDynamicFieldProvider;
	private readonly HcmLinkFieldService $hcmLinkFieldService;
	private readonly LegalInfoProvider $legalInfoProvider;
	private readonly HcmLinkService $hcmLinkService;
	private readonly MemberRepository $memberRepository;
	/**
	 * Caches of the branch of a member excluded from the matching with 1C. They live as long as this
	 * factory does, and it is built anew for every pass of the field building, so an exclusion dropped
	 * in parallel is seen by the next pass.
	 *
	 * @var array<int, array<int, bool>> companyId => userId => is excluded in that company
	 */
	private array $hcmLinkExcludedUserCacheByCompanyId = [];
	/** @var array<int, true> documents whose set of the excluded has already been read */
	private array $hcmLinkPreloadedDocumentIds = [];
	/** the members of the current pass, when the caller knows them */
	private ?Item\MemberCollection $hcmLinkExclusionMembers = null;
	/**
	 * @var array<string, ?string> serialized name of an HCM Link field => legal profile field or null
	 */
	private array $legalFieldNameByHcmLinkFieldCache = [];
	/**
	 * @var array<string, ProfileFieldData> "userId:fieldName" => legal data of the profile
	 */
	private array $legalFieldDataCache = [];

	public function __construct(
		?ProfileProvider $profileProvider = null,
		?MemberDynamicFieldInfoProvider $memberDynamicFieldProvider = null,
		?HcmLinkFieldService $hcmLinkFieldService = null,
		?LegalInfoProvider $legalInfoProvider = null,
		?HcmLinkService $hcmLinkService = null,
		?MemberRepository $memberRepository = null,
	)
	{
		$container = Container::instance();
		$this->profileProvider = $profileProvider ?? $container->getServiceProfileProvider();
		$this->memberDynamicFieldProvider = $memberDynamicFieldProvider ?? $container->getMemberDynamicFieldProvider();
		$this->hcmLinkFieldService = $hcmLinkFieldService ?? $container->getHcmLinkFieldService();
		$this->legalInfoProvider = $legalInfoProvider ?? $container->getLegalInfoProvider();
		$this->hcmLinkService = $hcmLinkService ?? $container->getHcmLinkService();
		$this->memberRepository = $memberRepository ?? $container->getMemberRepository();
	}

	/**
	 * The members the current pass builds the values for. Told them, the factory asks about the set of
	 * the excluded for that set alone; told nothing, it reads the whole composition of the document,
	 * because a caller that declares no pass may ask about anybody.
	 *
	 * The filling of the fields goes in portions and builds a factory anew for every one of them, so
	 * without the portion the composition of a document of three hundred members would be walked -
	 * and the exclusions of it asked about - as many times as there are portions.
	 */
	public function setHcmLinkExclusionMembers(Item\MemberCollection $members): void
	{
		$this->hcmLinkExclusionMembers = $members;
	}

	public function createByBlock(
		Item\Block $block,
		Item\Field $field,
		Item\Member $member,
		Item\Document $document,
	): ?Item\Field\Value
	{
		if (BlockCode::isSignature($block->code) || BlockCode::isStamp($block->code))
		{
			$valueFileId = $block->data['fileId'] ?? null;

			return is_int($valueFileId) ? new Item\Field\Value(0, fileId: $valueFileId) : null;
		}
		if (BlockCode::isCommon($block->code))
		{
			$valueString = $block->data['text'] ?? null;

			return is_string($valueString) ? new Item\Field\Value(0, text: $valueString) : null;
		}

		$this->correctBlockCodeByFieldCode($block, $document);

		return match (true)
		{
			BlockCode::isReference($block->code) => $this->getCrmReferenceFieldValue($field, $member, $document),
			BlockCode::isRequisites($block->code) => $this->getRequisitesFieldValue($field, $member, $document),
			BlockCode::isB2eReference($block->code) => $this->getB2eReferenceFieldValue($block, $field, $member, $document),
			BlockCode::isMemberDynamic($block->code) => $this->getMemberDynamicFieldValue($field, $member),
			BlockCode::isHcmLinkReference($block->code) => $this->getHcmLinkFieldValue($field, $member, $document),
			BlockCode::isB2eRegional($block->code) => $this->getB2eRegionalFieldValue($field, $member, $document),
			default => null,
		};
	}

	private function correctBlockCodeByFieldCode(Item\Block $block, Item\Document $document): void
	{
		if (isset($block->data['field']))
		{
			[,$fieldName] = NameHelper::parseFieldCode(
				$block->data['field'],
				\CCrmOwnerType::ResolveName($document->entityTypeId),
			);

			if (str_starts_with($fieldName, 'RQ_'))
			{
				$block->code = match($block->code) {
					\Bitrix\Sign\Type\BlockCode::B2E_MY_REFERENCE => \Bitrix\Sign\Type\BlockCode::MY_REQUISITES,
					default => $block->code,
				};
			}
		}
	}

	private function getCrmReferenceFieldValue(Item\Field $field, Item\Member $member, Item\Document $document): ?Item\Field\Value
	{
		$entityType = \CCrmOwnerType::ResolveName($document->entityTypeId);

		[
			'fieldCode' => $fieldCode,
			'fieldType' => $fieldType,
			'subfieldCode' => $subfieldCode,
		] = NameHelper::parse($field->name);

		[$entityFieldType, $fieldName] = NameHelper::parseFieldCode($fieldCode, $entityType);

		if (
			$fieldType !== FieldType::ADDRESS
			&& $fieldType !== FieldType::LIST
			&& $fieldType !== FieldType::ENUMERATION
		)
		{
			$crmEntityId = $this->getCrmEntityId($member, $document, $entityFieldType);
			if ($crmEntityId !== null)
			{
				$value = CRM::getEntityFieldValue(
					$crmEntityId,
					$fieldCode,
					$document->id,
					$member->presetId,
				);
				if ($value !== null && isset($value['text']))
				{
					return new Item\Field\Value(0, text: $value['text']);
				}
			}
		}

		if (str_starts_with($fieldName, 'UF_CRM_' . $entityType))
		{
			return $this->getDocumentFieldValue($document, $fieldName, $fieldType, $subfieldCode);
		}
		if (str_starts_with($fieldName, 'RQ_'))
		{
			return $this->getRequisitesFieldValue($field, $member, $document);
		}
		if (
			!empty($subfieldCode)
			&& !empty($fieldType)
			&& $fieldType === FieldType::ADDRESS
		)
		{
			$value = Field::getUserFieldValue($member->entityId, [
				'entityId' => 'CRM_' . $entityFieldType,
				'sourceName' => $fieldName,
				'type' => $fieldType,
				'userFieldId' => $fieldType,
			], $subfieldCode);

			if ($value !== null)
			{
				return new Item\Field\Value(0, text: (string)$value);
			}
		}
		else
		{
			$connector = new CrmEntity($field, $member);
			$fetchedFieldValue = $connector->fetchFields()->getFirst();

			if ($fetchedFieldValue === null)
			{
				return null;
			}

			if (
				(($fetchedFieldValue->data instanceof Main\Type\DateTime) === true)
				|| is_string($fetchedFieldValue->data)
				|| is_int($fetchedFieldValue->data)
				|| is_float($fetchedFieldValue->data)
			)
			{
				return new Item\Field\Value(0, text: (string)$fetchedFieldValue->data);
			}
		}

		return null;
	}

	private function getRequisitesFieldValue(
		Item\Field $field,
		Item\Member $member,
		Item\Document $document,
	): ?Item\Field\Value
	{
		[
			'fieldCode' => $fieldCode,
			'fieldType' => $fieldType,
			'subfieldCode' => $subFieldCode,
		] = NameHelper::parse($field->name);

		if ($subFieldCode !== '')
		{
			$value = $this->getFieldSetValues($member)[$fieldCode][$subFieldCode] ?? null;
			if (is_string($value))
			{
				return new Item\Field\Value(fieldId: 0, text: $value);
			}

			return null;
		}

		// An empty field set value only opens the fallback where the fallback can actually run;
		// everywhere else it stays a value, as it was before the fallback existed.
		$isCompanyFallbackAllowed
			= $fieldType === FieldType::STRING
			&& $member->entityType === \Bitrix\Sign\Type\Member\EntityType::COMPANY;

		if (
			$isCompanyFallbackAllowed
			&& $member->entityId !== null
			&& $member->presetId !== null
			&& $document->companyEntityId === $member->entityId
			&& Main\Loader::includeModule('crm')
		)
		{
			[$entityType, $requisiteFieldCode] = NameHelper::parseFieldCode(
				$fieldCode,
				CCrmOwnerType::CompanyName,
			);
			if (
				$entityType === CCrmOwnerType::CompanyName
				&& str_starts_with($requisiteFieldCode, 'RQ_')
			)
			{
				$fieldData = $this->getCompanyRequisiteFieldData(
					$document,
					$member->entityId,
					$member->presetId,
				);
				if (!in_array($fieldCode, $fieldData['fieldSetCodes'], true))
				{
					if (!in_array($requisiteFieldCode, $fieldData['allowedFieldCodes'], true))
					{
						return null;
					}

					$value = $fieldData['values'][$requisiteFieldCode] ?? null;

					return is_string($value)
						? new Item\Field\Value(fieldId: 0, text: $value)
						: null;
				}
			}
		}

		$fieldsSetValue = $this->getFieldSetValues($member)[$fieldCode] ?? null;
		if (is_string($fieldsSetValue) && ($fieldsSetValue !== '' || !$isCompanyFallbackAllowed))
		{
			return new Item\Field\Value(fieldId: 0, text: $fieldsSetValue);
		}

		if (!$isCompanyFallbackAllowed || !Main\Loader::includeModule('crm'))
		{
			return null;
		}

		[$entityType, $requisiteFieldCode] = NameHelper::parseFieldCode(
			$fieldCode,
			CCrmOwnerType::CompanyName,
		);
		if (
			$entityType !== CCrmOwnerType::CompanyName
			|| !str_starts_with($requisiteFieldCode, 'RQ_')
		)
		{
			return null;
		}

		$value = $this->getCompanyRequisiteFieldValue(
			$document,
			$member->entityId,
			$member->presetId,
			$requisiteFieldCode,
		);
		if (is_string($value))
		{
			return new Item\Field\Value(fieldId: 0, text: $value);
		}

		return null;
	}

	private function getCompanyRequisiteFieldValue(
		Item\Document $document,
		int $companyId,
		?int $presetId,
		string $fieldCode,
	): ?string
	{
		$fieldData = $this->getCompanyRequisiteFieldData($document, $companyId, $presetId);
		if (!in_array($fieldCode, $fieldData['allowedFieldCodes'], true))
		{
			return null;
		}

		return $fieldData['values'][$fieldCode] ?? null;
	}

	/**
	 * @return array{allowedFieldCodes: list<string>, fieldSetCodes: list<string>, values: array<string, string>}
	 */
	private function getCompanyRequisiteFieldData(
		Item\Document $document,
		int $companyId,
		?int $presetId,
	): array
	{
		$cacheKey = implode(
			':',
			[
				$document->entityTypeId,
				$document->entityId,
				$document->companyEntityId,
				CCrmOwnerType::Company,
				$companyId,
				$presetId,
			],
		);
		if (!array_key_exists($cacheKey, $this->companyRequisiteFieldDataMemoryCache))
		{
			$this->companyRequisiteFieldDataMemoryCache[$cacheKey] = $this->loadCompanyRequisiteFieldData(
				$document->companyEntityId,
				$companyId,
				$presetId,
			);
		}

		return $this->companyRequisiteFieldDataMemoryCache[$cacheKey];
	}

	/**
	 * Requisites are read without an ACL check, so the fallback is limited to the company the
	 * document itself belongs to. companyEntityId is that company: it is written on company
	 * selection (DocumentService::modifyCompany) and a copy made from a template inherits it.
	 *
	 * @return array{allowedFieldCodes: list<string>, fieldSetCodes: list<string>, values: array<string, string>}
	 */
	protected function loadCompanyRequisiteFieldData(
		?int $documentCompanyEntityId,
		int $companyId,
		?int $presetId,
	): array
	{
		if ($documentCompanyEntityId !== $companyId)
		{
			return [
				'allowedFieldCodes' => [],
				'fieldSetCodes' => [],
				'values' => [],
			];
		}

		return CRM::getCompanyRequisiteStringFieldData($companyId, $presetId);
	}

	/**
	 * @param Item\Member $member
	 *
	 * @return array<string, array>
	 */
	protected function getFieldSetValues(Item\Member $member): array
	{
		if (!Main\Loader::includeModule('crm'))
		{
			return [];
		}
		if (!isset($this->fieldSetMemoryCacheByMemberId[$member->id]))
		{
			$this->fieldSetMemoryCacheByMemberId[$member->id] = \Bitrix\Crm\Integration\Sign\Form::getFieldSetValues(
				match ($member->entityType)
				{
					\Bitrix\Sign\Type\Member\EntityType::CONTACT => \CCrmOwnerType::Contact,
					\Bitrix\Sign\Type\Member\EntityType::COMPANY => \CCrmOwnerType::Company,
					default => 0,
				},
				$member->entityId,
				requisitePresetId: $member->presetId,
			);
		}

		return $this->fieldSetMemoryCacheByMemberId[$member->id];
	}

	private function getB2eReferenceFieldValue(Item\Block $block, Item\Field $field, Item\Member $member, Item\Document $document): ?Item\Field\Value
	{
		['fieldCode' => $fieldCode, 'subfieldCode' => $subfieldCode] = NameHelper::parse($field->name);
		if (str_starts_with($fieldCode, Field::USER_FIELD_CODE_PREFIX))
		{
			$fieldCode = mb_substr($fieldCode, mb_strlen(Field::USER_FIELD_CODE_PREFIX));
		}

		if ($fieldCode === LegalInfoProvider::VIRTUAL_FULL_NAME_FIELD)
		{
			return $this->getFullNameFieldValue($block, $member, $document);
		}

		if (!$this->profileProvider->isProfileField($fieldCode))
		{
			return $this->getCrmReferenceFieldValue($field, $member, $document);
		}

		$entityId = $block->role === Role::ASSIGNEE
			? $document->representativeId
			: $member->entityId;

		$originalValue = $field->type === FieldType::LIST;

		$profileFieldData = $this->profileProvider->loadFieldData(
			userId: $entityId,
			fieldName: $fieldCode,
			subFieldName: $subfieldCode,
			originalValue: $originalValue,
		);
		if ($profileFieldData->value === '')
		{
			return null;
		}

		return new Item\Field\Value(0, text: $profileFieldData->value, trusted: $profileFieldData->isLegal);
	}

	/**
	 * Builds the flat full name string from the three legal profile parts. This is degradation
	 * material only: a new service assembles the name from the separate parts and ignores it.
	 */
	private function getFullNameFieldValue(
		Item\Block $block,
		Item\Member $member,
		Item\Document $document,
	): ?Item\Field\Value
	{
		$entityId = $block->role === Role::ASSIGNEE
			? $document->representativeId
			: $member->entityId;
		if ($entityId === null)
		{
			return null;
		}

		$firstName = $this->loadLegalPartValue($entityId, FieldType::FIRST_NAME);
		$lastName = $this->loadLegalPartValue($entityId, FieldType::LAST_NAME);
		$secondName = $this->loadLegalPartValue($entityId, FieldType::PATRONYMIC);

		$format = $document->langId === 'ru'
			? self::FULL_NAME_RU_FORMAT
			: $this->profileProvider->getNameFormat();

		$value = self::formatFullName($format, $firstName, $lastName, $secondName);
		if ($value === '')
		{
			return null;
		}

		// Derived field assembled from the trusted legal name parts and always filtered out of the
		// fill forms, so it never carries untrusted signer input: mark it trusted like the parts.
		return new Item\Field\Value(0, text: $value, trusted: true);
	}

	private function loadLegalPartValue(int $entityId, string $fieldType): string
	{
		$fieldName = $this->legalInfoProvider->getFirstFieldNameByType($fieldType);
		if ($fieldName === null)
		{
			return '';
		}

		return (string)$this->profileProvider->loadFieldData($entityId, $fieldName)->value;
	}

	/**
	 * Fills a name format template with the profile parts, dropping empty parts without leaving
	 * double spaces. Mirrors the token set of ProfileProvider::getFormattedName. Public and static
	 * so the pure formatting rule (ALG-01) can be unit tested without the final ProfileProvider.
	 */
	public static function formatFullName(
		string $format,
		string $firstName,
		string $lastName,
		string $secondName,
	): string
	{
		$shorten = static fn(string $part): string => $part === '' ? '' : mb_substr($part, 0, 1) . '.';

		// Single-pass replacement: strtr never re-scans already substituted text, so a name part that
		// itself contains a literal token (e.g. "#NAME#") is emitted verbatim instead of being expanded
		// again by a later token. Longer tokens take precedence over their prefixes.
		$value = strtr($format, [
			'#LAST_NAME#' => $lastName,
			'#NAME#' => $firstName,
			'#SECOND_NAME#' => $secondName,
			'#LAST_NAME_SHORT#' => $shorten($lastName),
			'#NAME_SHORT#' => $shorten($firstName),
			'#SECOND_NAME_SHORT#' => $shorten($secondName),
		]);

		return trim((string)preg_replace('/\s+/u', ' ', $value));
	}

	private function getDocumentFieldValue(
		Item\Document $document,
		string $fieldName,
		string $fieldType,
		string $subfieldCode,
	): Item\Field\Value
	{
		$factory = \Bitrix\Crm\Service\Container::getInstance()?->getFactory($document->entityTypeId);
		$value = $factory->getItem($document->entityId)?->get($fieldName);

		if (
			$fieldType === FieldType::ADDRESS
			&& Main\Loader::includeModule('fileman')
			&& Main\Loader::includeModule('location')
		)
		{
			$value = $this->parseAddressSubfieldValue($value, $subfieldCode);
		}

		return new Item\Field\Value(0, text: (string)$value);
	}

	private function parseAddressSubfieldValue(mixed $value, string $subfieldCode): string
	{
		[,,$addressId] = AddressType::parseValue($value);
		if (!$addressId)
		{
			return '';
		}

		/** @var Address $address */
		$address = Address::load($addressId);
		if (!$address)
		{
			return '';
		}

		if (!empty($subfieldCode))
		{
			$value = $address->getFieldValue(FieldType::ADDRESS_SUBFIELD_MAP[$subfieldCode]);
		}
		else
		{
			$value = $address->toString(
				FormatService::getInstance()->findDefault(LANGUAGE_ID),
				\Bitrix\Location\Entity\Address\Converter\StringConverter::STRATEGY_TYPE_TEMPLATE_COMMA,
			);
		}

		return (string)$value;
	}

	private function getCrmEntityId(Item\Member $member, Item\Document $document, string $entityTypeName): ?int
	{
		if (
			$entityTypeName === CCrmOwnerType::CompanyName
			&& $member->entityType === \Bitrix\Sign\Type\Member\EntityType::COMPANY
		)
		{
			return $member->entityId;
		}
		if (
			$entityTypeName === CCrmOwnerType::ContactName
			&& $member->entityType === \Bitrix\Sign\Type\Member\EntityType::CONTACT
		)
		{
			return $member->entityId;
		}
		if (in_array($entityTypeName, [CCrmOwnerType::SmartB2eDocumentName, CCrmOwnerType::SmartDocumentName], true))
		{
			return $document->id;
		}

		return null;
	}

	private function getMemberDynamicFieldValue(Item\Field $field, Item\Member $member): ?Item\Field\Value
	{
		if ($member->id === null)
		{
			return null;
		}

		['fieldCode' => $fieldCode, 'subfieldCode' => $subfieldCode] = NameHelper::parse($field->name);

		$value = $this->memberDynamicFieldProvider->loadFieldData(
			memberId: $member->id,
			fieldName: $fieldCode,
			subFieldName: $subfieldCode,
			isOriginalValue: $field->type === FieldType::LIST,
		);

		if ($value === '')
		{
			return null;
		}

		return new Item\Field\Value(0, text: $value, trusted: true);
	}

	private function getHcmLinkFieldValue(
		Item\Field $field,
		Item\Member $member,
		Item\Document $document,
	): ?Item\Field\Value
	{
		if (!$this->hcmLinkFieldService->isAvailable())
		{
			return null;
		}

		['fieldCode' => $fieldCode] = NameHelper::parse($field->name);
		$parsedName = $this->hcmLinkFieldService->parseName($fieldCode);
		if (!$parsedName)
		{
			return null;
		}
		if ($parsedName->integrationId !== $document->hcmLinkCompanyId)
		{
			return null;
		}

		// The position of an employee is asked first: a member who has one goes the way of 1C even
		// while an exclusion hangs on him, because the matching wins over the exclusion.
		if ($member->employeeId === null)
		{
			return $this->getHcmLinkExcludedMemberFieldValue($fieldCode, $member, $document);
		}

		$valueId = new Item\Field\HcmLink\HcmLinkFieldValueId(
			entityType: FieldEntityType::EMPLOYEE->value,
			fieldId: $parsedName->id,
			employeeId: $member->employeeId,
		);

		return new Item\Field\Value(
			fieldId: 0,
			text: '',
			trusted: true,
			hcmLinkFieldValueId: $valueId,
		);
	}

	/**
	 * A member excluded from the matching with 1C has no position of an employee and never gets one,
	 * so his field of 1C has no answer of 1C to wait for: the value comes from the legal field of his
	 * profile, where the filling of the signing form settled it, and it is immediate - no job in 1C is
	 * created for him at all.
	 *
	 * A member who is not excluded keeps behaving as he does today and gets nothing: reading the
	 * profile for everybody is a product decision of its own.
	 */
	private function getHcmLinkExcludedMemberFieldValue(
		string $fieldCode,
		Item\Member $member,
		Item\Document $document,
	): ?Item\Field\Value
	{
		$userId = $this->resolveHcmLinkMemberUserId($member, $document);
		if ($userId === null || !$this->isExcludedFromHcmLinkMatching($document, $userId))
		{
			return null;
		}

		$legalFieldName = $this->getLegalInfoFieldNameByHcmLinkFieldCode($fieldCode);
		if ($legalFieldName === null)
		{
			return null;
		}

		$fieldData = $this->getLegalFieldData($userId, $legalFieldName);
		if ($fieldData->value === '')
		{
			return null;
		}

		return new Item\Field\Value(0, text: $fieldData->value, trusted: $fieldData->isLegal);
	}

	/**
	 * A member of the company entity type stands for the representative of the document - the same
	 * rule the facade of the integration keys the exclusions by and the filling of the positions of
	 * the employees follows.
	 */
	private function resolveHcmLinkMemberUserId(Item\Member $member, Item\Document $document): ?int
	{
		$userId = match ($member->entityType)
		{
			EntityType::USER => $member->entityId,
			EntityType::COMPANY => $document->representativeId,
			default => null,
		};

		return $userId !== null && $userId > 0 ? $userId : null;
	}

	/**
	 * The whole set of the members of the pass is asked about at once and kept in memory. The value of
	 * a field is built for every field of every member, so a read per call would give hundreds of them
	 * on a document of three hundred members.
	 */
	private function isExcludedFromHcmLinkMatching(Item\Document $document, int $userId): bool
	{
		$companyId = (int)$document->hcmLinkCompanyId;
		$this->preloadHcmLinkExclusionsByDocument($document, $userId);

		if (!array_key_exists($userId, $this->hcmLinkExcludedUserCacheByCompanyId[$companyId] ?? []))
		{
			// A member built in memory - the preview of a future signer - belongs to no collection of
			// the document, so he is asked about on his own and joins the same cache.
			$this->cacheHcmLinkExclusions($companyId, [$userId => $userId]);
		}

		return $this->hcmLinkExcludedUserCacheByCompanyId[$companyId][$userId];
	}

	private function preloadHcmLinkExclusionsByDocument(Item\Document $document, int $userId): void
	{
		$documentId = (int)$document->id;
		if (isset($this->hcmLinkPreloadedDocumentIds[$documentId]))
		{
			return;
		}
		$this->hcmLinkPreloadedDocumentIds[$documentId] = true;

		$userIds = [$userId => $userId];
		foreach ($this->getHcmLinkExclusionMembers($document) as $member)
		{
			$memberUserId = $this->resolveHcmLinkMemberUserId($member, $document);
			if ($memberUserId !== null)
			{
				$userIds[$memberUserId] = $memberUserId;
			}
		}

		$this->cacheHcmLinkExclusions((int)$document->hcmLinkCompanyId, $userIds);
	}

	private function getHcmLinkExclusionMembers(Item\Document $document): Item\MemberCollection
	{
		if ($this->hcmLinkExclusionMembers !== null)
		{
			return $this->hcmLinkExclusionMembers;
		}

		return $document->id === null
			? new Item\MemberCollection()
			: $this->memberRepository->listByDocumentId($document->id)
		;
	}

	/**
	 * @param array<int, int> $userIds
	 */
	private function cacheHcmLinkExclusions(int $companyId, array $userIds): void
	{
		$excludedUserIds = $this->hcmLinkService->filterExcludedUserIds($companyId, array_values($userIds));

		$this->hcmLinkExcludedUserCacheByCompanyId[$companyId] = array_replace(
			$this->hcmLinkExcludedUserCacheByCompanyId[$companyId] ?? [],
			array_fill_keys($userIds, false),
			array_fill_keys($excludedUserIds, true),
		);
	}

	/**
	 * The rule that translates a field of 1C into a legal field is pure, but it asks the licence of
	 * the portal whether the integration is available on every call, so its answer is remembered.
	 */
	private function getLegalInfoFieldNameByHcmLinkFieldCode(string $fieldCode): ?string
	{
		if (!array_key_exists($fieldCode, $this->legalFieldNameByHcmLinkFieldCache))
		{
			$this->legalFieldNameByHcmLinkFieldCache[$fieldCode] =
				$this->hcmLinkFieldService->getLegalInfoFieldNameByFieldName($fieldCode);
		}

		return $this->legalFieldNameByHcmLinkFieldCache[$fieldCode];
	}

	private function getLegalFieldData(int $userId, string $fieldName): ProfileFieldData
	{
		return $this->legalFieldDataCache["$userId:$fieldName"]
			??= $this->loadLegalFieldData($userId, $fieldName)
		;
	}

	/**
	 * Seam for tests: the profile provider is declared final, so a test can neither answer this read
	 * nor count it any other way.
	 */
	protected function loadLegalFieldData(int $userId, string $fieldName): ProfileFieldData
	{
		return $this->profileProvider->loadFieldData($userId, $fieldName);
	}

	private function getB2eRegionalFieldValue(
		Item\Field $field,
		Item\Member $member,
		Item\Document $document,
	): ?Item\Field\Value
	{
		if ($field->type === FieldType::EXTERNAL_ID)
		{
			return match ($document->externalIdSourceType)
			{
				ExternalIdSourceType::MANUAL => new Item\Field\Value(
					fieldId: 0,
					text: $document->externalId ?? '',
					trusted: true,
				),
				ExternalIdSourceType::HCMLINK => $this->getB2eRegionalFieldValueByHcmLink(
					$field,
					$member,
					$document,
					$document->hcmLinkExternalIdSettingId,
				),
				default => null,
			};
		}

		if ($field->type === FieldType::EXTERNAL_DATE)
		{
			$cultureDateFormat = $document->configuredDateFormat
				?? Application::getInstance()->getContext()->getCulture()?->getDateFormat();

			$dateFormat = $cultureDateFormat ? Date::convertFormatToPhp($cultureDateFormat) : 'Y-m-d';

			return match ($document->externalDateCreateSourceType)
			{
				ExternalDateCreateSourceType::MANUAL => new Item\Field\Value(
					fieldId: 0,
					text: $document->externalDateCreate?->format($dateFormat) ?? '',
					trusted: true,
				),
				ExternalDateCreateSourceType::HCMLINK => $this->getB2eRegionalFieldValueByHcmLink(
					$field,
					$member,
					$document,
					$document->hcmLinkDateSettingId,
				),
				default => null,
			};
		}

		return null;
	}

	private function getB2eRegionalFieldValueByHcmLink(
		Item\Field $field,
		Item\Member $member,
		Item\Document $document,
		?int $hcmLinkFieldId,
	): ?Item\Field\Value
	{
		if (
			$member->employeeId === null
			|| !$hcmLinkFieldId
			|| !$this->hcmLinkFieldService->isAvailable()
		)
		{
			return null;
		}

		$field = $this->hcmLinkFieldService->getFieldById($hcmLinkFieldId);
		if (
			!$field
			|| $field->companyId !== $document->hcmLinkCompanyId
		)
		{
			return null;
		}

		return new Item\Field\Value(
			fieldId: 0,
			text: '',
			trusted: true,
			hcmLinkFieldValueId: new Item\Field\HcmLink\HcmLinkFieldValueId(
				entityType: FieldEntityType::DOCUMENT->value,
				fieldId: $field->id,
				employeeId: $member->employeeId,
				signerId: $member->id,
			),
		);
	}
}
