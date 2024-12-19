<?php

namespace Bitrix\Sign\Operation\SigningService;

use Bitrix\Main;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Factory;
use Bitrix\Sign\Item;
use Bitrix\Sign\Item\B2e\Provider\ProfileFieldData;
use Bitrix\Sign\Operation\GetRequiredFieldsWithCache;
use Bitrix\Sign\Operation\Result\FillFieldsResult;
use Bitrix\Sign\Repository\BlockRepository;
use Bitrix\Sign\Repository\FieldValueRepository;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service\Api\Document\FieldService;
use Bitrix\Sign\Service\Cache\Memory\Sign\UserCache;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Providers\ProfileProvider;
use Bitrix\Sign\Service\Result\Sign\Block\B2eRequiredFieldsResult;
use Bitrix\Sign\Type;

final class FillFields implements Contract\Operation
{
	private Factory\Field $fieldFactory;
	private Factory\Api\Property\Request\Field\Fill\Value $fieldFillRequestValueFactory;
	private Factory\FieldValue $fieldValueFactory;
	private MemberRepository $memberRepository;
	private BlockRepository $blockRepository;
	private FieldService $apiDocumentFieldService;
	private ProfileProvider $profileProvider;
	private UserCache $userCache;
	private readonly int $limit;
	private readonly FieldValueRepository $fieldValueRepository;
	private ?Item\Field\FieldValueCollection $fieldValues = null;

	public function __construct(
		private readonly Item\Document $document,
	)
	{
		$this->fieldFactory = new Factory\Field();
		$this->fieldFillRequestValueFactory = new Factory\Api\Property\Request\Field\Fill\Value();
		$this->memberRepository = Container::instance()->getMemberRepository();
		$this->blockRepository = Container::instance()->getBlockRepository();
		$this->apiDocumentFieldService = Container::instance()->getApiDocumentFieldService();
		$this->profileProvider = Container::instance()->getServiceProfileProvider();
		$this->userCache = new UserCache();
		$this->memberRepository->setUserCache($this->userCache);
		$this->profileProvider->setCache($this->userCache);
		$this->limit = Storage::instance()->getFieldsFillMembersLimit();
		$this->fieldValueFactory = new Factory\FieldValue($this->profileProvider);
		$this->fieldValueRepository = Container::instance()->getFieldValueRepository();
	}

	public function launch(): FillFieldsResult|Main\Result
	{
		if ($this->document->blankId === null)
		{
			return (new Main\Result())->addError(new Main\Error("Document has no linked blank"));
		}

		if (!$this->getLock())
		{
			return new FillFieldsResult(false);
		}

		$result = $this->processMembers();

		$this->releaseLock();

		return $result;
	}

	private function processMembers(): FillFieldsResult|Main\Result
	{
		$members = $this->memberRepository->listNotConfiguredByDocumentId($this->document->id, $this->limit);
		if ($members->isEmpty())
		{
			return new FillFieldsResult(true);
		}
		$this->loadSignFields($members);

		$blocks = $this->blockRepository->getCollectionByBlankId($this->document->blankId);
		if (
			Type\DocumentScenario::isB2EScenario($this->document->scenario)
			&& $this->document->scheme === Type\Document\SchemeType::ORDER
		)
		{
			$blocks = $blocks->filterExcludeRole(Type\Member\Role::SIGNER);
		}

		$blocks = $blocks->filterExcludeParty(0);

		$memberFields = new Item\Api\Property\Request\Field\Fill\MemberFieldsCollection();

		foreach ($members as $member)
		{
			$roleBlocks = $blocks->filterByRole($member->role);

			$requestFields = new Item\Api\Property\Request\Field\Fill\FieldCollection();
			foreach ($roleBlocks as $block)
			{
				$fields = $this->fieldFactory->createByBlocks(new Item\BlockCollection($block), $member, $this->document);
				foreach ($fields as $field)
				{
					$this->addFieldValueToRequest($block, $field, $member, $requestFields);
				}
			}

			if (!$requestFields->isEmpty())
			{
				$memberFields->addItem(
					new Item\Api\Property\Request\Field\Fill\MemberFields(
						$member->uid,
						$requestFields,
					),
				);
			}
		}

		$this->appendRequiredFieldsWithoutBlocks($memberFields, $members);

		foreach ($memberFields->toArray() as $memberField)
		{
			$this->checkAndSetTrusted($memberField);
		}

		if (empty($memberFields->toArray()))
		{
			$this->memberRepository->markAsConfigured($members);

			return $this->makeResult($members->count());
		}

		$response = $this->apiDocumentFieldService->fill(
			new Item\Api\Document\Field\FillRequest(
				$this->document->uid,
				$memberFields,
			),
		);

		if (!$response->isSuccess())
		{
			return (new Main\Result())->addErrors($response->getErrors());
		}

		$this->memberRepository->markAsConfigured($members);

		return $this->makeResult($members->count());
	}

	private function addFieldValueToRequest(
		Item\Block $block,
		Item\Field $field,
		Item\Member $member,
		Item\Api\Property\Request\Field\Fill\FieldCollection $requestFields,
	): void
	{
		if ($field->subfields !== null)
		{
			foreach ($field->subfields as $subfield)
			{
				$this->addFieldValueToRequest($block, $subfield, $member, $requestFields);
			}

			return;
		}

		$value = $this->loadFieldValue($block, $field, $member);
		if ($value === null)
		{
			return;
		}
		$fieldValue = $this->fieldFillRequestValueFactory->createByValueItem($value);
		if ($fieldValue === null)
		{
			return;
		}

		$requestFields->addItem(
			new Item\Api\Property\Request\Field\Fill\Field(
				$field->name,
				new Item\Api\Property\Request\Field\Fill\FieldValuesCollection(
					$fieldValue,
				),
				trusted: $value->trusted ?? false,
			),
		);
	}

	private function loadFieldValue(Item\Block $block, Item\Field $field, Item\Member $member): ?Item\Field\Value
	{
		return $this->getFieldValueFromLocalFields($member, $field)
			?? $this->fieldValueFactory->createByBlock($block, $field, $member, $this->document)
		;
	}

	private function checkAndSetTrusted(Item\Api\Property\Request\Field\Fill\MemberFields $memberFields): void
	{
		foreach ($memberFields->fields->toArray() as $field)
		{
			if (!$field->trusted)
			{
				return;
			}
		}

		$memberFields->trusted = true;
	}

	private function appendRequiredFieldsWithoutBlocks(
		Item\Api\Property\Request\Field\Fill\MemberFieldsCollection $memberFieldsCollection,
		Item\MemberCollection $members,
	): void
	{
		if (!Type\DocumentScenario::isB2EScenario($this->document->scenario))
		{
			return;
		}

		$requiredFields = $this->getRequiredFields();
		if ($requiredFields === null)
		{
			return;
		}

		$blockFieldNames = $this->getFieldNames($memberFieldsCollection);
		foreach ($requiredFields as $requiredField)
		{
			$field = $this->fieldFactory->createByRequired($this->document, $members, $requiredField);
			if ($field instanceof Item\Field && !isset($blockFieldNames[$field->name]))
			{
				$this->appendFieldsWithoutBlocksValues($field, $requiredField, $members, $memberFieldsCollection);
			}
		}
	}

	private function getRequiredFields(): ?Item\B2e\RequiredFieldsCollection
	{
		$operation = new GetRequiredFieldsWithCache(
			documentId: $this->document->id,
			companyUid: $this->document->companyUid,
		);
		$result = $operation->launch();

		return $result instanceof B2eRequiredFieldsResult ? $result->collection : null;
	}

	/**
	 * Get all field names in blocks as key map
	 *
	 * @param Item\Api\Property\Request\Field\Fill\MemberFieldsCollection $memberFieldsCollection
	 *
	 * @return array<string, string>
	 */
	private function getFieldNames(Item\Api\Property\Request\Field\Fill\MemberFieldsCollection $memberFieldsCollection): array
	{
		$fieldNames = [];
		foreach ($memberFieldsCollection->toArray() as $item)
		{
			foreach ($item->fields->toArray() as $field)
			{
				$name = $field->name;
				$fieldNames[$name] = $name;
			}
		}

		return $fieldNames;
	}

	private function appendFieldsWithoutBlocksValues(
		Item\Field $field,
		Item\B2e\RequiredField $requiredField,
		Item\MemberCollection $members,
		Item\Api\Property\Request\Field\Fill\MemberFieldsCollection $memberFieldsCollection,
	): void
	{
		foreach ($members->filterByRole($requiredField->role) as $member)
		{
			$profileFieldData = $this->getProfileDataFieldValueFromLocalFields($member, $field)
				?? $this->fieldValueFactory->createProfileFieldDataByRequired($requiredField, $member, $this->document)
			;
			if (!$profileFieldData?->value)
			{
				continue;
			}
			$requestField = $this->makeRequestFieldByValue($field, $profileFieldData);
			$existedMemberFields = $this->getExistedMemberFields($memberFieldsCollection, $member->uid);
			if ($existedMemberFields)
			{
				$existedMemberFields->fields->addItem($requestField);
			}
			else
			{
				$requestFields = new Item\Api\Property\Request\Field\Fill\FieldCollection($requestField);
				$memberFields = new Item\Api\Property\Request\Field\Fill\MemberFields($member->uid, $requestFields);
				$memberFieldsCollection->addItem($memberFields);
			}
		}
	}

	private function makeRequestFieldByValue(
		Item\Field $field,
		ProfileFieldData $profileFieldData,
	): Item\Api\Property\Request\Field\Fill\Field
	{
		$fieldValue = new Item\Api\Property\Request\Field\Fill\Value\StringFieldValue($profileFieldData->value);
		$requestValues = new Item\Api\Property\Request\Field\Fill\FieldValuesCollection($fieldValue);

		return new Item\Api\Property\Request\Field\Fill\Field($field->name, $requestValues, $profileFieldData->isLegal);
	}

	private function getExistedMemberFields(
		Item\Api\Property\Request\Field\Fill\MemberFieldsCollection $memberFieldsCollection,
		string $memberUid,
	): ?Item\Api\Property\Request\Field\Fill\MemberFields
	{
		foreach ($memberFieldsCollection->toArray() as $memberFields)
		{
			if ($memberFields->memberId === $memberUid)
			{
				return $memberFields;
			}
		}

		return null;
	}

	private function getLock(): bool
	{
		return Main\Application::getConnection()
			->lock($this->getLockName())
		;
	}

	private function releaseLock(): bool
	{
		return Main\Application::getConnection()
		  ->unlock($this->getLockName())
		;
	}

	private function getLockName(): string
	{
		return "member_fields_{$this->document->uid}";
	}

	private function makeResult(int $processedMembersCount): FillFieldsResult
	{
		if ($processedMembersCount < $this->limit)
		{
			return new FillFieldsResult(true);
		}

		$notConfigured = $this->memberRepository->countNotConfiguredByDocumentId($this->document->id);

		return new FillFieldsResult(!$notConfigured);
	}

	private function loadSignFields(Item\MemberCollection $members): void
	{
		if ($this->canDocumentContainLocalFieldValues())
		{
			$this->fieldValues = $this->fieldValueRepository->listByMemberIds($members->getIds());
		}
	}

	private function canDocumentContainLocalFieldValues(): bool
	{
		return Type\DocumentScenario::isB2EScenario($this->document->scenario)
			&& $this->document->initiatedByType === Type\Document\InitiatedByType::EMPLOYEE;
	}

	private function getFieldValueFromLocalFields(
		Item\Member $member,
		Item\Field $field,
	): ?Item\Field\Value
	{
		if ($this->fieldValues === null)
		{
			return null;
		}

		foreach ($this->fieldValues as $fieldValue)
		{
			if (
				$fieldValue instanceof Item\Field\FieldValue
				&& $fieldValue->memberId === $member->id
				&& $fieldValue->fieldName === $field->name
			)
			{
				return new Item\Field\Value(0, text: $fieldValue->value);
			}
		}

		return null;
	}

	private function getProfileDataFieldValueFromLocalFields(
		Item\Member $member,
		Item\Field $field,
	): ?ProfileFieldData
	{
		$value = $this->getFieldValueFromLocalFields($member, $field);
		if ($value)
		{
			$profileFieldData = new ProfileFieldData();
			$profileFieldData->value = $value->text;

			return $profileFieldData;
		}

		return null;
	}
}
