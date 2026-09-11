<?php

namespace Bitrix\Sign\Factory;

use Bitrix\Fileman\UserField\Types\AddressType;
use Bitrix\Location\Entity\Address;
use Bitrix\Location\Service\FormatService;
use Bitrix\Main;
use Bitrix\Sign\Blanks\Block\Factory;
use Bitrix\Sign\Connector\MemberConnectorFactory;
use Bitrix\Sign\Helper\Field\NameHelper;
use Bitrix\Sign\Helper\Field\UserFieldCodeHelper;
use Bitrix\Sign\Integration\CRM;
use Bitrix\Sign\Item;
use Bitrix\Sign\Operation\GetRequiredFieldsWithCache;
use Bitrix\Sign\Repository\BlockRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Document;
use Bitrix\Sign\Service\Integration\HumanResources\HcmLinkFieldService;
use Bitrix\Sign\Service\Providers\LegalInfoProvider;
use Bitrix\Sign\Service\Providers\MemberDynamicFieldInfoProvider;
use Bitrix\Sign\Service\Result\Sign\Block\B2eRequiredFieldsResult;
use Bitrix\Sign\Type;
use Bitrix\Sign\Type\BlockCode;
use Bitrix\Sign\Type\Field\ConnectorType;
use Bitrix\Sign\Type\Field\EntityType;
use Bitrix\Sign\Type\FieldType;

final class Field
{
	public const USER_FIELD_CODE_PREFIX = 'USER_';

	private const BLOCK_CODE_TO_FIELD_TYPE_MAP = [
		BlockCode::DATE => FieldType::STRING,
		BlockCode::TEXT => FieldType::STRING,
		BlockCode::NUMBER => FieldType::STRING,
		BlockCode::MY_STAMP => FieldType::STAMP,
		BlockCode::STAMP => FieldType::STAMP,
		BlockCode::MY_SIGN => FieldType::SIGNATURE,
		BlockCode::SIGN => FieldType::SIGNATURE,
	];

	private const NOT_REQUIRED_FIELD_TYPES = [
		FieldType::PATRONYMIC,
		FieldType::POSITION,
	];

	private const EMPLOYEE_INITIATED_UNSUPPORTED_USER_FIELD_TYPES = [
		FieldType::ADDRESS,
		FieldType::URL,
	];

	private const ADDRESS_SUBFIELD_CODES = [
		'ADDRESS_1',
		'ADDRESS_2',
		'CITY',
		'POSTAL_CODE',
		'REGION',
		'PROVINCE',
		'COUNTRY',
	];
	private const REQUIRED_ADDRESS_SUBFIELDS = [
		'ADDRESS_1',
		'CITY',
		'POSTAL_CODE',
	];

	private const SNILS_FIELD_CODE = 'UF_LEGAL_SNILS';

	// Legal name parts a full name field is assembled from downstream. Their values feed the
	// name resolver on the service side; the full name field itself carries only a flat string.
	public const FULL_NAME_PART_TYPES = [
		FieldType::FIRST_NAME,
		FieldType::LAST_NAME,
		FieldType::PATRONYMIC,
	];

	private MemberConnectorFactory $memberConnectorFactory;
	private readonly BlockRepository $blockRepository;
	private readonly FieldValue $fieldValueFactory;
	private readonly HcmLinkFieldService $hcmLinkFieldService;
	private readonly \Bitrix\Sign\Blanks\Block\Factory $blockFactory;
	private readonly Document\FieldService $fieldService;

	public function __construct(
		?BlockRepository $blockRepository = null,
		?HcmLinkFieldService $hcmLinkFieldService = null,
		?\Bitrix\Sign\Blanks\Block\Factory $blockFactory = null,
		?Document\FieldService $fieldService = null,
	)
	{
		$this->memberConnectorFactory = new MemberConnectorFactory();
		$this->blockRepository = $blockRepository ?? Container::instance()->getBlockRepository();
		$this->fieldValueFactory = new FieldValue();
		$this->hcmLinkFieldService = $hcmLinkFieldService ?? Container::instance()->getHcmLinkFieldService();
		$this->blockFactory = $blockFactory ?? new \Bitrix\Sign\Blanks\Block\Factory();
		$this->fieldService = $fieldService ?? Container::instance()->getDocumentFieldService();
	}

	/**
	 * @param int $userId
	 * @param array{entityId: string, sourceName: string, type: string, userFieldId: string} $field
	 * @param string $subFieldName
	 * @param bool $originalValue
	 *
	 * @return mixed
	 */
	public static function getUserFieldValue(int $userId, array $field, string $subFieldName = '', bool $originalValue = false): mixed
	{
		global $USER_FIELD_MANAGER;

		$result = $USER_FIELD_MANAGER->GetUserFieldValue(
			entity_id: $field['entityId'],
			field_id: $field['sourceName'],
			value_id: $userId,
			LANG: LANGUAGE_ID,
		);

		if (empty($result) || $originalValue)
		{
			return $result;
		}

		if ($field['type'] === 'enumeration')
		{
			$enumResultDb = \CUserFieldEnum::GetList([], [
				'USER_FIELD_ID' => $field['userFieldId'],
				'ID' => $result,
			]);

			$userFieldCollection = [];
			while ($enumResult = $enumResultDb->Fetch())
			{
				$userFieldCollection[] = $enumResult['VALUE'];
			}

			return implode(', ', $userFieldCollection);
		}
		elseif (
			$field['type'] === 'address'
			&& Main\Loader::includeModule('fileman')
			&& Main\Loader::includeModule('location')
		)
		{
			[,, $addressId] = AddressType::parseValue($result);

			if (empty($addressId))
			{
				return '';
			}
			else
			{
				/** @var Address $address */
				$address = Address::load($addressId);
				if (!$address)
				{
					return '';
				}

				if (!empty($subFieldName))
				{
					return $address->getFieldValue(FieldType::ADDRESS_SUBFIELD_MAP[$subFieldName]);
				}
				else
				{
					return $address->toString(
						FormatService::getInstance()->findDefault(LANGUAGE_ID),
						\Bitrix\Location\Entity\Address\Converter\StringConverter::STRATEGY_TYPE_TEMPLATE_COMMA,
					);
				}
			}
		}

		return $result;
	}

	public function createByBlocks(
		Item\BlockCollection $blocks,
		?Item\Member $member,
		?Item\Document $document = null,
	): Item\FieldCollection
	{
		if (!Main\Loader::includeModule('crm'))
		{
			return new Item\FieldCollection();
		}

		$documentRepository = Container::instance()->getDocumentRepository();
		if ($member !== null && $document === null)
		{
			$document = $documentRepository->getById($member->documentId);
		}

		$registeredFields = new Item\FieldCollection();
		foreach ($blocks as $block)
		{
			$fields = $this->createFieldsByBlock($block, $registeredFields, $member, $document);
			$registeredFields->mergeFieldsWithNoneIncludedName($fields);
		}

		return $registeredFields;
	}

	private function createFieldsByBlock(
		Item\Block $block,
		Item\FieldCollection $registeredFields,
		?Item\Member $member,
		?Item\Document $document,
	): Item\FieldCollection
	{
		$fieldType = self::BLOCK_CODE_TO_FIELD_TYPE_MAP[$block->code] ?? null;

		if (BlockCode::isCommon($block->code))
		{
			$party = $member?->party ?? 0;
			$fieldName = NameHelper::create($block->code, $fieldType, $party);

			return new Item\FieldCollection(
				new Item\Field(
					0,
					$party,
					$fieldType,
					$fieldName,
					label: null, // simple blocks doesnt contains label because it not include in form
				),
			);
		}
		if ($member === null || $document === null)
		{
			return new Item\FieldCollection();
		}

		if ($fieldType === null)
		{
			return match ($block->code)
			{
				BlockCode::REFERENCE, BlockCode::MY_REFERENCE => $this->createCrmReferenceFields(
					$block,
					$registeredFields,
					$member,
					$document,
				),
				BlockCode::REQUISITES, BlockCode::MY_REQUISITES => $this->createRequisiteFields(
					$block,
					$member,
				),
				BlockCode::B2E_REFERENCE, BlockCode::B2E_MY_REFERENCE => $this->createB2eReferenceFields(
					$block,
					$member,
					$document,
					$registeredFields,
				),
				BlockCode::EMPLOYEE_DYNAMIC => $this->createDynamicMemberFields(
					$block,
					$member,
					$document,
					$registeredFields,
				),
				BlockCode::B2E_HCMLINK_REFERENCE => $this->createHcmLinkFields(
					$block,
					$member,
					$document,
					$registeredFields,
				),
				BlockCode::B2E_EXTERNAL_DATE_CREATE, BlockCode::B2E_EXTERNAL_ID => $this->createB2eRegionalFields(
					$block,
					$member,
					$document,
					$registeredFields,
				),
			};
		}

		if (BlockCode::isSignature($block->code))
		{
			$signField = $registeredFields->findFirst(
				fn(Item\Field $field) => $field->type === FieldType::SIGNATURE && $field->party === $member->party,
			);
			if ($signField !== null)
			{
				return new Item\FieldCollection($signField);
			}
		}
		elseif (BlockCode::isStamp($block->code))
		{
			$stampField = $registeredFields->findFirst(
				fn(Item\Field $field) => $field->type === FieldType::STAMP && $field->party === $member->party,
			);
			if ($stampField !== null)
			{
				return new Item\FieldCollection($stampField);
			}
		}
		elseif ($block->code === BlockCode::NUMBER)
		{
			$numField = $registeredFields->findFirst(
				fn(Item\Field $field) => NameHelper::parse($field->name)['blockCode'] === BlockCode::NUMBER,
			);
			if ($numField !== null)
			{
				return new Item\FieldCollection($numField);
			}
		}

		$fieldName = NameHelper::create($block->code, $fieldType, $member->party);

		return new Item\FieldCollection(
			new Item\Field(
				0,
				$member->party,
				$fieldType,
				$fieldName,
				label: null, // simple blocks doesnt contains label because it not include in form
			),
		);
	}

	private function createCrmReferenceFields(
		Item\Block $block,
		Item\FieldCollection $registeredFields,
		Item\Member $member,
		?Item\Document $document = null,
	): Item\FieldCollection
	{
		$fieldCode = $block->data['field'] ?? '';
		if (!is_string($fieldCode))
		{
			return new Item\FieldCollection();
		}

		$fieldCode = new CRM\FieldCode($fieldCode);
		$fieldDescription = $fieldCode->getDescription($member->presetId);
		if (empty($fieldDescription))
		{
			return new Item\FieldCollection();
		}

		$sourceFieldType = (string)($fieldDescription['TYPE'] ?? '');
		if (
			$document !== null
			&& $this->shouldSkipUnsupportedEmployeeInitiatedFieldType($document, $member, $sourceFieldType)
		)
		{
			return new Item\FieldCollection();
		}

		$fieldType = match ($sourceFieldType)
		{
			FieldType::DATE, FieldType::DATETIME => FieldType::DATE,
			FieldType::LIST => FieldType::LIST,
			FieldType::DOUBLE => FieldType::DOUBLE,
			FieldType::INTEGER => FieldType::INTEGER,
			FieldType::ADDRESS => FieldType::ADDRESS,
			default => FieldType::STRING,
		};
		$itemsDescription = $fieldDescription['ITEMS'] ?? null;
		$items = null;
		if ($itemsDescription !== null)
		{
			$items = new Item\Field\ItemCollection();
			foreach ($itemsDescription as $itemDescription)
			{
				$items->add(
					new Item\Field\Item(
						id: $itemDescription['ID'], value: $itemDescription['VALUE'],
					),
				);
			}
		}

		$fieldName = NameHelper::create($block->code, $fieldType, $member->party, $fieldCode->getCode());

		$field = $registeredFields->getFirstFieldByName($fieldName);
		if ($field !== null)
		{
			return new Item\FieldCollection($field);
		}

		$field = new Item\Field(
			0,
			$member->party,
			$fieldType,
			$fieldName,
			$fieldDescription['CAPTION'] ?? null,
			connectorType: ConnectorType::CRM_ENTITY,
			// todo: dont use \CCrmOwnerType
			entityType: (
				$fieldCode->getEntityTypeName() === \CCrmOwnerType::SmartDocumentName
				|| $fieldCode->getEntityTypeName() === \CCrmOwnerType::SmartB2eDocumentName
			)
				? EntityType::DOCUMENT
				: EntityType::MEMBER
			,
			entityCode: $fieldCode->getEntityFieldCode(),
			items: $items,
		);

		if ($field->type === FieldType::ADDRESS)
		{
			$field->subfields = $this->createAddressSubfieldsByField($field);
		}

		return new Item\FieldCollection($field);
	}

	private function createRequisiteFields(
		Item\Block $block,
		Item\Member $member,
	): Item\FieldCollection
	{
		$memberConnector = $this->memberConnectorFactory->createRequisiteConnector($member);
		if ($memberConnector === null)
		{
			return new Item\FieldCollection();
		}

		$result = new Item\FieldCollection();

		$requisiteFields = $memberConnector->fetchRequisite(
			new Item\Connector\FetchRequisiteModifier($member->presetId),
		);
		foreach ($requisiteFields as $requisiteField)
		{
			$fieldCode = new CRM\FieldCode($requisiteField->name);
			$fieldDescription = $fieldCode->getDescription() ?? [];

			$fieldType = match ($fieldDescription['TYPE'] ?? null)
			{
				FieldType::DATE, FieldType::DATETIME => FieldType::DATE,
				FieldType::LIST => FieldType::LIST,
				FieldType::DOUBLE => FieldType::DOUBLE,
				FieldType::INTEGER => FieldType::INTEGER,
				FieldType::ADDRESS => FieldType::ADDRESS,
				default => FieldType::STRING,
			};
			$itemsDescription = $fieldDescription['ITEMS'] ?? null;
			$items = null;
			if ($itemsDescription !== null)
			{
				$items = new Item\Field\ItemCollection();
				foreach ($itemsDescription as $itemDescription)
				{
					$items->add(
						new Item\Field\Item(
							id: $itemDescription['ID'], value: $itemDescription['VALUE'],
						),
					);
				}
			}

			$fieldName = NameHelper::create($block->code, $fieldType, $member->party, $requisiteField->name);
			$fieldEntityType = $fieldCode->getEntityTypeName() === \CCrmOwnerType::SmartDocumentName
				? EntityType::DOCUMENT
				: EntityType::MEMBER
			;
			$field = new Item\Field(
				0,
				$member->party,
				$fieldType,
				$fieldName,
				$requisiteField->label,
				ConnectorType::REQUISITE,
				// todo: dont use \CCrmOwnerType
				$fieldEntityType,
				items: $items,
			);

			if ($field->type === FieldType::ADDRESS)
			{
				$field->subfields = $this->createAddressSubfieldsByField($field);
			}

			$result->add($field);
		}

		return $result;
	}

	private function createAddressSubfieldsByField(Item\Field $field): Item\FieldCollection
	{
		$result = new Item\FieldCollection();
		$parsedParentFieldName = NameHelper::parse($field->name);

		foreach (self::ADDRESS_SUBFIELD_CODES as $addressSubfieldCode)
		{
			$field = new Item\Field(
				0, $field->party, FieldType::STRING, // all address subfields has string type
				NameHelper::create(
					$parsedParentFieldName['blockCode'],
					$parsedParentFieldName['fieldType'],
					$parsedParentFieldName['party'],
					$parsedParentFieldName['fieldCode'],
					$addressSubfieldCode,
				), $this->getAddressFieldLabels()[$addressSubfieldCode] ?? null,
			);

			$field->required = in_array($addressSubfieldCode, self::REQUIRED_ADDRESS_SUBFIELDS, true);

			$result->add($field);
		}

		return $result;
	}

	private function getAddressFieldLabels(): array
	{
		// todo: encapsulate it to another class
		if (!Main\Loader::includeModule('crm'))
		{
			return [];
		}

		return \Bitrix\Crm\EntityAddress::getLabels();
	}

	private function createB2eReferenceFields(
		Item\Block $block,
		Item\Member $member,
		Item\Document $document,
		Item\FieldCollection $registeredFields,
	): Item\FieldCollection
	{
		if (!Type\DocumentScenario::isB2EScenario($document->scenario))
		{
			return new Item\FieldCollection();
		}

		$fieldCode = $block->data['field'] ?? '';
		if (!is_string($fieldCode))
		{
			return new Item\FieldCollection();
		}

		$fieldCodeForCheck = UserFieldCodeHelper::removePrefix($fieldCode);

		// The full name is a virtual profile field: it has no user field in the database, so
		// isProfileField()/getDescriptionByFieldName() do not know it. Build its descriptor here
		// with an explicit FULL_NAME type; the value is assembled from the legal parts later.
		$isVirtualFullName = $fieldCodeForCheck === LegalInfoProvider::VIRTUAL_FULL_NAME_FIELD;

		$profileProvider = Container::instance()->getServiceProfileProvider();
		if (!$isVirtualFullName && !$profileProvider->isProfileField($fieldCodeForCheck))
		{
			return $this->createCrmReferenceFields($block, $registeredFields, $member, $document);
		}

		if ($block->role === Type\Member\Role::ASSIGNEE && $document->representativeId === null)
		{
			return new Item\FieldCollection();
		}

		$fieldDescription = $profileProvider->getDescriptionByFieldName($fieldCodeForCheck);
		$sourceFieldType = (string)($fieldDescription['type'] ?? '');
		if ($this->shouldSkipUnsupportedEmployeeInitiatedFieldType($document, $member, $sourceFieldType))
		{
			return new Item\FieldCollection();
		}

		$fieldType = $isVirtualFullName
			? FieldType::FULL_NAME
			: $this->fieldService->convertUserFieldType($sourceFieldType);

		if ($fieldCodeForCheck === self::SNILS_FIELD_CODE)
		{
			$fieldType = FieldType::SNILS;
		}

		if ($isVirtualFullName)
		{
			// The caption phrase lives in the ProfileProvider lang file, which is not auto-loaded here.
			Main\Localization\Loc::loadMessages(__DIR__ . '/../Service/Providers/ProfileProvider.php');
			$fieldDescription = [
				'caption' => (string)Main\Localization\Loc::getMessage('SIGN_SERVICE_PROVIDER_PROFILE_FIELD_CAPTION_FULL_NAME'),
			];
		}

		$fieldCodeWithPrefix = UserFieldCodeHelper::addPrefix($fieldCode);

		$fieldName = NameHelper::create($block->code, $fieldType, $member->party, $fieldCodeWithPrefix);

		return $this->makeFieldsByUserFieldDescription(
			$fieldName,
			$fieldType,
			$fieldDescription,
			$member->party,
			$registeredFields,
		);
	}

	/**
	 * For every role that owns a full name field, build the legal name part fields
	 * (first name / last name / patronymic) that are not registered yet, so the service always
	 * receives the parts a full name is assembled from. Deduplicated by field name against the
	 * already collected fields; the appended parts are never required on their own.
	 */
	public function createFullNamePartFields(
		Item\Document $document,
		Item\MemberCollection $members,
		Item\FieldCollection $existingFields,
	): Item\FieldCollection
	{
		$result = new Item\FieldCollection();
		if (!Type\DocumentScenario::isB2EScenario($document->scenario))
		{
			return $result;
		}

		foreach ($this->getRolesWithFullNameField($members, $existingFields) as $role)
		{
			foreach ($members->filterByRole($role) as $member)
			{
				foreach ($this->createFullNamePartFieldsForMember($member, $document) as $partField)
				{
					if (
						!$existingFields->existWithName($partField->name)
						&& !$result->existWithName($partField->name)
					)
					{
						$result->add($partField);
					}
				}
			}
		}

		return $result;
	}

	/**
	 * @return list<string> unique member roles that own a full name field
	 */
	private function getRolesWithFullNameField(
		Item\MemberCollection $members,
		Item\FieldCollection $fields,
	): array
	{
		$roles = [];
		foreach ($fields as $field)
		{
			if ($field->type !== FieldType::FULL_NAME)
			{
				continue;
			}

			$member = $members->findFirstByParty($field->party);
			if ($member?->role !== null && !in_array($member->role, $roles, true))
			{
				$roles[] = $member->role;
			}
		}

		return $roles;
	}

	private function createFullNamePartFieldsForMember(
		Item\Member $member,
		Item\Document $document,
	): Item\FieldCollection
	{
		$result = new Item\FieldCollection();
		if ($member->party === null || $member->role === null)
		{
			return $result;
		}

		foreach (self::FULL_NAME_PART_TYPES as $partType)
		{
			$block = $this->blockFactory->makeStubLegalReferenceBlock(
				$document,
				$partType,
				$member->role,
				$member->party,
			);
			if ($block === null)
			{
				continue;
			}

			foreach ($this->createByBlocks(new Item\BlockCollection($block), $member, $document) as $field)
			{
				$field->required = false;
				$result->add($field);
			}
		}

		return $result;
	}

	public function createByRequired(
		Item\Document $document,
		Item\MemberCollection $members,
		Item\B2e\RequiredField $requiredField,
	): Item\FieldCollection
	{
		$result = new Item\FieldCollection();
		foreach ($members->filterByRole($requiredField->role) as $memberItem)
		{
			if ($memberItem === null)
			{
				continue;
			}

			if ($memberItem->party === null)
			{
				continue;
			}

			$block = $this->blockFactory->makeStubBlockByRequiredField($document, $requiredField, $memberItem->party);
			if ($block === null)
			{
				continue;
			}

			$field = $this->createByBlocks(new Item\BlockCollection($block), $memberItem, $document)->getFirst();
			if ($field === null)
			{
				continue;
			}

			$result->add($field);
		}

		return $result;
	}

	public function createDocumentMemberFields(
		Item\Document $document,
		Item\Member $member,
		bool $withValues = false,
	): Item\FieldCollection
	{
		$blocks = $this->blockRepository
			->getCollectionByBlankId($document->blankId)
			->filterByRole($member->role)
		;

		$fieldNameKeyMap = [];
		foreach ($blocks as $block)
		{
			$fields = $this->createByBlocks(new Item\BlockCollection($block), $member, $document);
			foreach ($fields as $field)
			{
				$fieldNameKeyMap[$field->name] = $field;
				if ($withValues)
				{
					$this->createAndAppendValueToFieldWithSubfields($block, $field, $member, $document);
				}
			}
		}

		foreach ($this->getB2eRequiredFields($document) as $requiredField)
		{
			if ($member->role !== $requiredField->role)
			{
				continue;
			}

			$block = $this->blockFactory->makeStubBlockByRequiredField($document, $requiredField, $member->party);
			if ($block === null)
			{
				continue;
			}

			$fields = $this->createByBlocks(new Item\BlockCollection($block), $member, $document);
			foreach ($fields as $field)
			{
				if (isset($fieldNameKeyMap[$field->name]))
				{
					continue;
				}
				$fieldNameKeyMap[$field->name] = $field;
				if ($withValues)
				{
					$this->createAndAppendValueToFieldWithSubfields($block, $field, $member, $document);
				}
			}
		}

		return new Item\FieldCollection(...array_values($fieldNameKeyMap));
	}

	private function createAndAppendValueToFieldWithSubfields(
		Item\Block $block,
		Item\Field $field,
		Item\Member $member,
		Item\Document $document,
	): void
	{
		if (!$field->subfields)
		{
			$field->replaceValueIfPresent($this->fieldValueFactory->createByBlock($block, $field, $member, $document));

			return;
		}

		foreach ($field->subfields as $subfield)
		{
			$subfield->replaceValueIfPresent($this->fieldValueFactory->createByBlock($block, $subfield, $member, $document));
		}
	}

	private function getB2eRequiredFields(Item\Document $document): Item\B2e\RequiredFieldsCollection
	{
		if (!Type\DocumentScenario::isB2EScenario($document->scenario) || !$document->id || !$document->companyUid)
		{
			return new Item\B2e\RequiredFieldsCollection();
		}

		$operation = new GetRequiredFieldsWithCache(
			documentId: $document->id,
			companyUid: $document->companyUid,
		);
		$result = $operation->launch();

		return $result instanceof B2eRequiredFieldsResult ? $result->collection : new Item\B2e\RequiredFieldsCollection();
	}

	public function createDocumentFutureSignerFields(
		Item\Document $document,
		int $userId,
		bool $withValues = true,
	): Item\FieldCollection
	{
		$member = new Item\Member(
			documentId: $document->id,
			party: 1,
			entityType: \Bitrix\Sign\Type\Member\EntityType::USER,
			entityId: $userId,
			role: Type\Member\Role::SIGNER,
		);

		$fields = $this->createDocumentMemberFields($document, $member, $withValues);

		// Don't show trusted fields, and never offer the full name: it is a derived field whose
		// value is built only by sign from the legal parts, so it is not filled in the send wizards.
		return $fields->filter(
			static fn(Item\Field $field) =>
				$field->type !== FieldType::FULL_NAME
				&& !$field->values?->getFirst()?->trusted
		);
	}

	private function createDynamicMemberFields(
		Item\Block $block,
		Item\Member $member,
		Item\Document $document,
		Item\FieldCollection $registeredFields,
	): Item\FieldCollection
	{
		if (!Type\DocumentScenario::isB2EScenario($document->scenario))
		{
			return new Item\FieldCollection();
		}

		$fieldCode = $block->data['field'] ?? '';
		if (!is_string($fieldCode))
		{
			return new Item\FieldCollection();
		}

		$fieldDescription = (new MemberDynamicFieldInfoProvider())->getFieldDescription($fieldCode);
		if (empty($fieldDescription))
		{
			return new Item\FieldCollection();
		}

		$sourceFieldType = (string)($fieldDescription['type'] ?? '');
		if ($this->shouldSkipUnsupportedEmployeeInitiatedFieldType($document, $member, $sourceFieldType))
		{
			return new Item\FieldCollection();
		}

		$fieldType = $this->fieldService->convertUserFieldType($sourceFieldType);
		$fieldName = NameHelper::create($block->code, $fieldType, $member->party, $fieldCode);

		return $this->makeFieldsByUserFieldDescription(
			$fieldName,
			$fieldType,
			$fieldDescription,
			$member->party,
			$registeredFields,
		);
	}

	/**
	 * @param array{type: string, items: array} $fieldDescription
	 *
	 * @return Item\Field\ItemCollection|null
	 */
	private function convertUserFieldItems(array $fieldDescription): ?Item\Field\ItemCollection
	{
		if (
			empty($fieldDescription['items'])
			|| !is_array($fieldDescription['items'])
			&& FieldType::LIST !== $this->fieldService->convertUserFieldType($fieldDescription['type'] ?? '')
		)
		{
			return null;
		}

		$itemCollection = new Item\Field\ItemCollection();
		foreach ($fieldDescription['items'] as $item)
		{
			$itemCollection->add(
				new Item\Field\Item(
					id: $item['id'], value: $item['value'],
				),
			);
		}

		return $itemCollection;
	}

	private function makeFieldsByUserFieldDescription(
		string $fieldName,
		string $fieldType,
		array $fieldDescription,
		int $party,
		Item\FieldCollection $registeredFields,
	): Item\FieldCollection
	{
		$field = $registeredFields->getFirstFieldByName($fieldName);
		if ($field !== null)
		{
			return new Item\FieldCollection($field);
		}

		$field = new Item\Field(
			blankId: 0,
			party: $party,
			type: $fieldType,
			name: $fieldName,
			label: $fieldDescription['caption'] ?? '',
			items: $this->convertUserFieldItems($fieldDescription),
			required: $this->getFieldRequiredByType($fieldType),
		);

		if ($field->type === FieldType::ADDRESS)
		{
			$field->subfields = $this->createAddressSubfieldsByField($field);
		}

		return new Item\FieldCollection($field);
	}

	private function createHcmLinkFields(
		Item\Block $block,
		Item\Member $member,
		Item\Document $document,
		Item\FieldCollection $registeredFields,
	): Item\FieldCollection
	{
		if (!Type\DocumentScenario::isB2EScenario($document->scenario))
		{
			return new Item\FieldCollection();
		}

		$fieldCode = $block->data['field'] ?? '';
		if (!is_string($fieldCode))
		{
			return new Item\FieldCollection();
		}

		if (!$this->hcmLinkFieldService->isAvailable())
		{
			return new Item\FieldCollection();
		}

		$parsedName = $this->hcmLinkFieldService->parseName($fieldCode);
		if (!$parsedName || $parsedName->integrationId !== $document->hcmLinkCompanyId)
		{
			return new Item\FieldCollection();
		}

		$fieldType = $this->hcmLinkFieldService->getFieldTypeByName($fieldCode);
		$fieldName = NameHelper::create($block->code, $fieldType, $member->party, $fieldCode);

		$field = $registeredFields->getFirstFieldByName($fieldName);
		if ($field !== null)
		{
			return new Item\FieldCollection($field);
		}

		$hcmField = $this->hcmLinkFieldService->getFieldById($parsedName->id);

		$field = new Item\Field(
			blankId: 0,
			party: $member->party,
			type: $fieldType,
			name: $fieldName,
			label: $hcmField->title ?? '',
			required: $this->getFieldRequiredByType($fieldType),
		);

		if ($field->type === FieldType::ADDRESS)
		{
			$field->subfields = $this->createAddressSubfieldsByField($field);
		}

		return new Item\FieldCollection($field);
	}

	private function getFieldRequiredByType(string $fieldType): ?bool
	{
		return in_array($fieldType, self::NOT_REQUIRED_FIELD_TYPES, true) ? false : null;
	}

	private function shouldSkipUnsupportedEmployeeInitiatedFieldType(
		Item\Document $document,
		Item\Member $member,
		string $sourceFieldType,
	): bool
	{
		if ($member->role !== Type\Member\Role::SIGNER || !$document->isInitiatedByEmployee())
		{
			return false;
		}

		return in_array(strtolower($sourceFieldType), self::EMPLOYEE_INITIATED_UNSUPPORTED_USER_FIELD_TYPES, true);
	}

	private function createB2eRegionalFields(
		Item\Block $block,
		Item\Member $member,
		Item\Document $document,
		Item\FieldCollection $registeredFields,
	): Item\FieldCollection
	{
		if (!Type\DocumentScenario::isB2EScenario($document->scenario))
		{
			return new Item\FieldCollection();
		}

		$fieldType = $this->fieldService->getB2eRegionalFieldTypeByBlockCode($block->code);
		$fieldName = NameHelper::create($block->code, $fieldType, $member->party);

		$field = $registeredFields->getFirstFieldByName($fieldName);
		if ($field !== null)
		{
			return new Item\FieldCollection($field);
		}

		$field = new Item\Field(
			blankId: 0,
			party: $member->party,
			type: $fieldType,
			name: $fieldName,
			label: Factory::getStaticLabelByBlockCode($block->code),
			required: $this->getFieldRequiredByType($fieldType),
		);

		return new Item\FieldCollection($field);
	}
}
