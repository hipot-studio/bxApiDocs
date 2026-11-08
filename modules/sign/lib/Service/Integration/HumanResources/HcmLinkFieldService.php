<?php

namespace Bitrix\Sign\Service\Integration\HumanResources;

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\HumanResources;
use Bitrix\HumanResources\Item;
use Bitrix\Main\Result;
use Bitrix\Sign\Item\B2e\RequiredFieldsCollection;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Field\HcmLink\HcmLinkFieldParsedName;
use Bitrix\Sign\Operation\GetRequiredFieldsWithCache;
use Bitrix\Sign\Result\Service\Integration\HumanResources\HcmLinkFieldRequestResult;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Providers\LegalInfoProvider;
use Bitrix\Sign\Service\Result\Sign\Block\B2eRequiredFieldsResult;
use Bitrix\Sign\Type\BlockCode;
use Bitrix\Sign\Type\BlockParty;
use Bitrix\Sign\Type\DocumentScenario;
use Bitrix\Sign\Type\FieldType;
use Bitrix\Sign\Type\Member\EntityType;
use Bitrix\Sign\Type\Integration\HumanResources\FieldSerializedIndex;

class HcmLinkFieldService
{
	private const CATEGORY_REPRESENTATIVE = 'REPRESENTATIVE';
	private const CATEGORY_EMPLOYEE = 'EMPLOYEE';
	private const CATEGORY_COMPANY = 'COMPANY';

	private const NAME_SEPARATOR = '_';
	public const FIELD_PREFIX = 'HCMFIELD';

	/** @var array<int, Item\HcmLink\Field> */
	private array $fieldCacheById = [];
	/** @var array<string, ?Item\HcmLink\Field> */
	private array $fieldCacheByCode = [];
	/** @var array<int, true> */
	private array $preloadedCompanyIds = [];

	public function __construct(
		private ?LegalInfoProvider $legalInfoProvider = null,
	)
	{
	}

	public function getFieldsForSelector(int $hcmLinkCompanyId, bool $withEmployee = true): array
	{
		if (!$this->isAvailable())
		{
			return [];
		}

		$employeeFieldCollection = HumanResources\Service\Container::getHcmLinkFieldRepository()
			->getByCompanyIdAndEntityType(
				$hcmLinkCompanyId,
				HumanResources\Type\HcmLink\FieldEntityType::EMPLOYEE,
			)
		;

		return $this->buildSelectorResponse($employeeFieldCollection, $withEmployee);
	}

	private function buildSelectorResponse(
		Item\Collection\HcmLink\FieldCollection $employeeFieldCollection,
		bool $withEmployee,
	): array
	{
		$response = [];
		if ($withEmployee)
		{
			$response[self::CATEGORY_EMPLOYEE] = [
				'CAPTION' => '',
				'FIELDS' => $this->mapFieldCollectionForSelector(
					fieldCollection: $employeeFieldCollection,
					entityName: self::CATEGORY_EMPLOYEE,
					party: BlockParty::LAST_PARTY,
				),
			];
		}

		$response[self::CATEGORY_REPRESENTATIVE] = [
			'CAPTION' => '',
			'FIELDS' => $this->mapFieldCollectionForSelector(
				fieldCollection: $employeeFieldCollection,
				entityName: self::CATEGORY_REPRESENTATIVE,
				party: BlockParty::NOT_LAST_PARTY,
			),
		];

		return $response;
	}

	public function extractCompanyIdFromFieldName(string $fieldName): ?int
	{
		return $this->parseName($fieldName)?->integrationId;
	}

	private function mapFieldName(Item\HcmLink\Field $field, int $party): string
	{
		return implode(self::NAME_SEPARATOR, [
			FieldSerializedIndex::FIELD_PREFIX_INDEX->value => self::FIELD_PREFIX,
			FieldSerializedIndex::FIELD_COMPANY_INDEX->value => $field->companyId,
			FieldSerializedIndex::FIELD_TYPE_INDEX->value => $field->type->value,
			FieldSerializedIndex::FIELD_PARTY_INDEX->value => $party,
			FieldSerializedIndex::FIELD_ID->value => $field->id,
		]);
	}

	private function mapFieldCollectionForSelector(
		Item\Collection\HcmLink\FieldCollection $fieldCollection,
		string $entityName,
		int $party,
	): array
	{
		return array_values(
			$fieldCollection->map(
				fn (Item\HcmLink\Field $field) => [
					'type' => $field->type->value,
					'entity_name' => $entityName,
					'name' => $this->mapFieldName($field, $party),
					'caption' => $field->title,
					'multiple' => false,
					'required' => false,
					'hidden' => false,
					'companyId' => $field->companyId,
				],
			),
		);
	}

	public function isAvailable(): bool
	{
		return Container::instance()->getHcmLinkService()->isAvailable()
			&& Loader::includeModule('humanresources')
			&& class_exists('\Bitrix\HumanResources\Type\HcmLink\FieldType')
			;
	}

	public function parseName(string $serializedName): ?HcmLinkFieldParsedName
	{
		if (!str_starts_with($serializedName, self::FIELD_PREFIX))
		{
			return null;
		}

		$maxPartCount = count(FieldSerializedIndex::values());
		$explodedName = explode(self::NAME_SEPARATOR, $serializedName, $maxPartCount);

		if (count($explodedName) !== $maxPartCount)
		{
			return null;
		}

		$companyId = (int)$explodedName[FieldSerializedIndex::FIELD_COMPANY_INDEX->value];
		$type = (int)$explodedName[FieldSerializedIndex::FIELD_TYPE_INDEX->value];
		$id = (int)$explodedName[FieldSerializedIndex::FIELD_ID->value];
		$party = (int)$explodedName[FieldSerializedIndex::FIELD_PARTY_INDEX->value];

		return new HcmLinkFieldParsedName(
			integrationId: $companyId,
			id: $id,
			type: $type,
			party: $party,
		);
	}

	/**
	 * @param string $fieldName
	 *
	 * @return FieldType::*
	 */
	public function getFieldTypeByName(string $fieldName): string
	{
		if (!$this->isAvailable())
		{
			return FieldType::STRING;
		}

		$parsedName = $this->parseName($fieldName);
		if (!$parsedName)
		{
			return FieldType::STRING;
		}

		$hcmLinkType = HumanResources\Type\HcmLink\FieldType::tryFrom($parsedName->type);
		if ($hcmLinkType === null)
		{
			return FieldType::STRING;
		}

		// ADDRESS is deliberately not mapped: an HCM Link field holds a single value per employee, it has no
		// subfields to build a structured address from. Mapping it would enable the address subfield branch in
		// Factory\Field::createHcmLinkFields() and put the whole address string into every subfield.
		return match ($hcmLinkType)
		{
			HumanResources\Type\HcmLink\FieldType::SNILS => FieldType::SNILS,
			HumanResources\Type\HcmLink\FieldType::INN => FieldType::INN,
			HumanResources\Type\HcmLink\FieldType::FIRST_NAME => FieldType::FIRST_NAME,
			HumanResources\Type\HcmLink\FieldType::LAST_NAME => FieldType::LAST_NAME,
			HumanResources\Type\HcmLink\FieldType::PATRONYMIC_NAME => FieldType::PATRONYMIC,
			HumanResources\Type\HcmLink\FieldType::PHONE => FieldType::PHONE,
			HumanResources\Type\HcmLink\FieldType::EMAIL => FieldType::EMAIL,
			HumanResources\Type\HcmLink\FieldType::BIRTHDAY => FieldType::DATE,
			HumanResources\Type\HcmLink\FieldType::POSITION => FieldType::POSITION,
			default => FieldType::STRING,
		};
	}

	public function getFieldById(int $id): ?Item\HcmLink\Field
	{
		if (isset($this->fieldCacheById[$id]))
		{
			return $this->fieldCacheById[$id];
		}

		if (!$this->isAvailable())
		{
			return null;
		}

		$field = HumanResources\Service\Container::getHcmLinkFieldRepository()->getById($id);

		if ($field !== null)
		{
			$this->cacheField($field);
		}

		return $field;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, Item\HcmLink\Field>
	 */
	public function getFieldsByIds(array $ids): array
	{
		if (!$this->isAvailable())
		{
			return [];
		}

		$missingIds = [];
		foreach ($ids as $id)
		{
			if (!isset($this->fieldCacheById[$id]))
			{
				$missingIds[] = $id;
			}
		}

		if (!empty($missingIds))
		{
			$fields = HumanResources\Service\Container::getHcmLinkFieldRepository()
				->getByIds(array_unique($missingIds))
			;
			foreach ($fields as $field)
			{
				$this->cacheField($field);
			}
		}

		$result = [];
		foreach ($ids as $id)
		{
			if (isset($this->fieldCacheById[$id]))
			{
				$result[$id] = $this->fieldCacheById[$id];
			}
		}

		return $result;
	}

	public function getFieldValue(
		array $entityIds,
		array $fieldIds,
	): Result|HumanResources\Result\Service\HcmLink\GetFieldValueResult
	{
		if (!$this->isAvailable())
		{
			return (new Result())->addError(new Error('Integration not available'));
		}

		return HumanResources\Service\Container::getHcmLinkFieldValueService()->getFieldValue($entityIds, $fieldIds);
	}

	public function requestFieldValues(
		int $companyId,
		array $employeeIds,
		array $fieldIds,
		array $signerMemberIdByEmployeeIdMap,
	): Result|HcmLinkFieldRequestResult
	{
		if (!$this->isAvailable())
		{
			return (new Result())->addError(new Error('Integration not available'));
		}

		$result = HumanResources\Service\Container::getHcmLinkFieldValueService()
			->requestFieldValue(
				$companyId,
				$employeeIds,
				$fieldIds,
				$signerMemberIdByEmployeeIdMap,
			)
		;

		if ($result instanceof HumanResources\Result\Service\HcmLink\JobServiceResult)
		{
			return new HcmLinkFieldRequestResult($result->job->id);
		}

		return $result;
	}

	public function getHcmRequiredFieldSelectorNameByType(
		int $integrationId,
		string $fieldType,
		int $party,
	): ?string
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		$hcmLinkFieldType = $this->getSpecialHcmFieldTypeBySignFieldType($fieldType);
		if ($hcmLinkFieldType === null)
		{
			return null;
		}

		$fields = HumanResources\Service\Container::getHcmLinkFieldRepository()->getByCompany($integrationId);
		foreach ($fields as $field)
		{
			if ($field->type === $hcmLinkFieldType)
			{
				return $this->mapFieldName($field, $party);
			}
		}

		return null;
	}

	public function getSpecialHcmFieldTypeBySignFieldType(string $fieldType): ?HumanResources\Type\HcmLink\FieldType
	{
		return match ($fieldType)
		{
			FieldType::SNILS => HumanResources\Type\HcmLink\FieldType::SNILS,
			FieldType::FIRST_NAME => HumanResources\Type\HcmLink\FieldType::FIRST_NAME,
			FieldType::LAST_NAME => HumanResources\Type\HcmLink\FieldType::LAST_NAME,
			FieldType::PATRONYMIC => HumanResources\Type\HcmLink\FieldType::PATRONYMIC_NAME,
			FieldType::POSITION => HumanResources\Type\HcmLink\FieldType::POSITION,
			FieldType::EXTERNAL_ID => HumanResources\Type\HcmLink\FieldType::DOCUMENT_REGISTRATION_NUMBER,
			FieldType::EXTERNAL_DATE => HumanResources\Type\HcmLink\FieldType::DOCUMENT_DATE,
			default => null,
		};
	}

	public function convertHcmLinkIntTypeToLegalInfoType(int $type): ?string
	{
		return match (HumanResources\Type\HcmLink\FieldType::tryFrom($type))
		{
			HumanResources\Type\HcmLink\FieldType::SNILS => FieldType::SNILS,
			HumanResources\Type\HcmLink\FieldType::FIRST_NAME => FieldType::FIRST_NAME,
			HumanResources\Type\HcmLink\FieldType::LAST_NAME => FieldType::LAST_NAME,
			HumanResources\Type\HcmLink\FieldType::PATRONYMIC_NAME => FieldType::PATRONYMIC,
			HumanResources\Type\HcmLink\FieldType::POSITION => FieldType::POSITION,
			default => null,
		};
	}

	/**
	 * The legal field of the user profile an HCM Link field of this name corresponds to, or null when
	 * its type has no legal equivalent. The single place of the rule: the write back of a value that
	 * came from 1C, the write of a value filled in the signing form and the read of a value filled
	 * earlier all ask it.
	 */
	public function getLegalInfoFieldNameByFieldName(string $fieldName): ?string
	{
		// A field name arrives from the callback of the signing service too, and the types of
		// humanresources below are unreachable without the module.
		if (!$this->isAvailable())
		{
			return null;
		}

		$parsedName = $this->parseName($fieldName);
		if ($parsedName === null)
		{
			return null;
		}

		$legalInfoType = $this->convertHcmLinkIntTypeToLegalInfoType($parsedName->type);
		if ($legalInfoType === null)
		{
			return null;
		}

		return $this->getLegalInfoProvider()->getFirstFieldNameByType($legalInfoType);
	}

	private function getLegalInfoProvider(): LegalInfoProvider
	{
		return $this->legalInfoProvider ??= Container::instance()->getLegalInfoProvider();
	}

	/**
	 * Those of the users the document has nothing left to ask by hand from: for every field of 1C it
	 * carries on their side, their profile already holds a value. The same rule Factory\FieldValue
	 * fills such a field by, read forward - a field of 1C without a legal equivalent has no answer in
	 * the profile at all, so a document carrying one leaves everybody of that role unfilled.
	 *
	 * Only a user excluded from the matching is worth asking about: a matched one takes his values
	 * from 1C. Picking whom to ask belongs to the caller, the answer is read for the whole set at once.
	 *
	 * @param array<mixed> $userIds
	 *
	 * @return list<int>
	 */
	public function listUserIdsWithFilledLegalData(Document $document, array $userIds): array
	{
		$userIds = $this->normalizeUserIds($userIds);
		if ($userIds === [] || !$this->isAvailable())
		{
			return [];
		}

		$legalFieldNamesByRole = $this->listLegalFieldNamesByRole($document);
		if ($legalFieldNamesByRole === [])
		{
			// The document asks 1C for nothing, so there is nothing to fill by hand either.
			return $userIds;
		}

		$rolesByUserId = $this->listMemberRolesByUserId($document, $userIds);

		$requiredFieldNamesByUserId = [];
		$askedFieldNames = [];
		foreach ($userIds as $userId)
		{
			$required = [];
			foreach ($rolesByUserId[$userId] ?? [] as $role)
			{
				if (!array_key_exists($role, $legalFieldNamesByRole))
				{
					continue;
				}

				$roleFieldNames = $legalFieldNamesByRole[$role];
				if ($roleFieldNames === null)
				{
					$required = null;

					break;
				}

				foreach ($roleFieldNames as $fieldName)
				{
					$required[$fieldName] = $fieldName;
					$askedFieldNames[$fieldName] = $fieldName;
				}
			}

			$requiredFieldNamesByUserId[$userId] = $required;
		}

		$filledFieldNamesByUserId = $askedFieldNames === []
			? []
			: $this->listFilledLegalFieldNames($userIds, array_values($askedFieldNames))
		;

		$filledUserIds = [];
		foreach ($userIds as $userId)
		{
			$required = $requiredFieldNamesByUserId[$userId];
			if ($required === null)
			{
				continue;
			}

			$filledFieldNames = $filledFieldNamesByUserId[$userId] ?? [];
			if (array_diff_key($required, $filledFieldNames) === [])
			{
				$filledUserIds[] = $userId;
			}
		}

		return $filledUserIds;
	}

	/**
	 * Seam for tests: the provider of the profile is declared final, so a test can neither answer this
	 * read nor count it any other way.
	 *
	 * @param list<int> $userIds
	 * @param list<string> $fieldNames
	 *
	 * @return array<int, array<string, true>>
	 */
	protected function listFilledLegalFieldNames(array $userIds, array $fieldNames): array
	{
		return Container::instance()
			->getServiceProfileProvider()
			->listFilledFieldNamesByUserIds($userIds, $fieldNames)
		;
	}

	/**
	 * The legal fields of the profile a member of a role is asked for, by that role - the same two
	 * sources FillFields builds the fields of a member out of: the blocks of 1C the blank carries and
	 * the fields the provider of the signing requires. A blank holding no block of 1C for a role does
	 * not mean the role is asked nothing: the required fields of the provider are materialized into
	 * the very same blocks of 1C by Blanks\Block\Factory, and the blank never carries them.
	 *
	 * @return array<string, array<string, string>|null> null when the role is asked a field of 1C the
	 *     profile has no equivalent of
	 */
	private function listLegalFieldNamesByRole(Document $document): array
	{
		$fieldNamesByRole = $this->listBlockLegalFieldNamesByRole($document);

		foreach ($this->loadRequiredFields($document) as $requiredField)
		{
			// A regional field is answered by the document itself - its registration number and its
			// date belong to the document and not to the profile of anybody - so it asks the member
			// for nothing. Blanks\Block\Factory turns it into the regional block or into no block.
			if (FieldType::isRegional($requiredField->type))
			{
				continue;
			}

			$role = $requiredField->role;
			if (array_key_exists($role, $fieldNamesByRole) && $fieldNamesByRole[$role] === null)
			{
				continue;
			}

			$legalFieldName = $this->getLegalInfoProvider()->getFirstFieldNameByType($requiredField->type);
			if ($legalFieldName === null)
			{
				$fieldNamesByRole[$role] = null;

				continue;
			}

			$fieldNamesByRole[$role][$legalFieldName] = $legalFieldName;
		}

		return $fieldNamesByRole;
	}

	/**
	 * Seam for tests: the fields the provider of the signing requires are read through an operation
	 * that goes to the database and, while the document has not been configured yet, to the provider
	 * itself. The same read Factory\Field makes for the very same document of the same wizard.
	 */
	protected function loadRequiredFields(Document $document): RequiredFieldsCollection
	{
		if (!DocumentScenario::isB2EScenario($document->scenario) || !$document->id || !$document->companyUid)
		{
			return new RequiredFieldsCollection();
		}

		$result = (new GetRequiredFieldsWithCache(
			documentId: $document->id,
			companyUid: $document->companyUid,
		))->launch();

		return $result instanceof B2eRequiredFieldsResult
			? $result->collection
			: new RequiredFieldsCollection()
		;
	}

	/**
	 * The legal fields of the profile the blocks of 1C of the blank stand for, by the role they are
	 * placed for.
	 *
	 * @return array<string, array<string, string>|null> null when a block of the role asks a field of
	 *     1C the profile has no equivalent of
	 */
	private function listBlockLegalFieldNamesByRole(Document $document): array
	{
		$blocks = Container::instance()
			->getBlockRepository()
			->getCollectionByBlankId((int)$document->blankId)
		;

		$fieldNamesByRole = [];
		foreach ($blocks as $block)
		{
			if ($block->code !== BlockCode::B2E_HCMLINK_REFERENCE || $block->party === 0)
			{
				continue;
			}

			$fieldCode = $block->data['field'] ?? '';
			$parsedName = is_string($fieldCode) ? $this->parseName($fieldCode) : null;
			if ($parsedName === null || $parsedName->integrationId !== $document->hcmLinkCompanyId)
			{
				continue;
			}

			$role = (string)$block->role;
			if (array_key_exists($role, $fieldNamesByRole) && $fieldNamesByRole[$role] === null)
			{
				continue;
			}

			$legalFieldName = $this->getLegalInfoFieldNameByFieldName($fieldCode);
			if ($legalFieldName === null)
			{
				$fieldNamesByRole[$role] = null;

				continue;
			}

			$fieldNamesByRole[$role][$legalFieldName] = $legalFieldName;
		}

		return $fieldNamesByRole;
	}

	/**
	 * @param list<int> $userIds
	 *
	 * @return array<int, array<string, string>> userId => the roles he takes in the document
	 */
	private function listMemberRolesByUserId(Document $document, array $userIds): array
	{
		$wantedUserIds = array_fill_keys($userIds, true);
		$representativeId = (int)$document->representativeId;

		$rolesByUserId = [];
		$members = Container::instance()->getMemberRepository()->listByDocumentId((int)$document->id);
		foreach ($members as $member)
		{
			// A member of the company entity type stands for the representative of the document - the
			// same rule the facade of the integration keys the exclusions by.
			$userId = match ($member->entityType)
			{
				EntityType::USER => (int)$member->entityId,
				EntityType::COMPANY => $representativeId,
				default => 0,
			};

			if ($userId < 1 || !isset($wantedUserIds[$userId]) || $member->role === null)
			{
				continue;
			}

			$rolesByUserId[$userId][$member->role] = $member->role;
		}

		return $rolesByUserId;
	}

	/**
	 * @param array<mixed> $userIds
	 *
	 * @return list<int> unique positive identifiers
	 */
	private function normalizeUserIds(array $userIds): array
	{
		$normalized = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$normalized[$userId] = $userId;
			}
		}

		return array_values($normalized);
	}

	public function getFieldByCode(int $companyId, string $code): ?Item\HcmLink\Field
	{
		$cacheKey = "{$companyId}:{$code}";
		if (array_key_exists($cacheKey, $this->fieldCacheByCode))
		{
			return $this->fieldCacheByCode[$cacheKey];
		}

		if (!$this->isAvailable())
		{
			return null;
		}

		$field = HumanResources\Service\Container::getHcmLinkFieldRepository()->getByUnique($companyId, $code);
		$this->fieldCacheByCode[$cacheKey] = $field;
		if ($field !== null)
		{
			$this->cacheField($field);
		}

		return $field;
	}

	public function buildFieldSelectorName(Item\HcmLink\Field $field, int $party): string
	{
		return $this->mapFieldName($field, $party);
	}

	public function preloadByCompanyId(int $companyId): void
	{
		if (isset($this->preloadedCompanyIds[$companyId]))
		{
			return;
		}

		if (!$this->isAvailable())
		{
			return;
		}

		$fields = HumanResources\Service\Container::getHcmLinkFieldRepository()->getByCompany($companyId);

		foreach ($fields as $field)
		{
			$this->cacheField($field);
		}

		$this->preloadedCompanyIds[$companyId] = true;
	}

	private function cacheField(Item\HcmLink\Field $field): void
	{
		if ($field->id !== null)
		{
			$this->fieldCacheById[$field->id] = $field;
		}
		$this->fieldCacheByCode["{$field->companyId}:{$field->field}"] = $field;
	}

	public function isDateSettingFieldById(int $id): bool
	{
		return $this->getFieldById($id)?->type === HumanResources\Type\HcmLink\FieldType::DOCUMENT_DATE;
	}

	public function isExternalIdSettingFieldById(int $id): bool
	{
		return $this->getFieldById($id)?->type === HumanResources\Type\HcmLink\FieldType::DOCUMENT_REGISTRATION_NUMBER;
	}

	public function isDocumentTypeSettingFieldById(int $id): bool
	{
		return $this->getFieldById($id)?->type === HumanResources\Type\HcmLink\FieldType::DOCUMENT_UID;
	}
}
