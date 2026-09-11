<?php

namespace Bitrix\Sign\Blanks\Block;

use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Compatibility\Role;
use Bitrix\Sign\Exception\SignException;
use Bitrix\Sign\Helper\Field\NameHelper;
use Bitrix\Sign\Repository\BlockRepository;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\HumanResources\HcmLinkFieldService;
use Bitrix\Sign\Type;
use Bitrix\Sign\Item;
use Bitrix\Sign\Service;
use Bitrix\Sign\Service\Providers\LegalInfoProvider;

class Factory
{
	private MemberRepository $memberRepository;
	private Service\Sign\BlockService $blockService;
	private readonly HcmLinkFieldService $hcmLinkFieldService;
	private readonly LegalInfoProvider $legalInfoProvider;
	private readonly BlockRepository $blockRepository;
	/** @var array<string, Item\MemberCollection> */
	private array $membersByDocumentParty = [];
	/** @var array<int, list<string>> */
	private array $regionalBlockCodesByBlankId = [];

	public function __construct(
		?MemberRepository $memberRepository = null,
		?Service\Sign\BlockService $blockService = null,
		?HcmLinkFieldService $hcmLinkFieldService = null,
		?LegalInfoProvider $legalInfoProvider = null,
		?BlockRepository $blockRepository = null,
	)
	{
		$this->memberRepository = $memberRepository ?? Container::instance()->getMemberRepository();
		$this->blockService = $blockService ?? Container::instance()->getSignBlockService();
		$this->hcmLinkFieldService = $hcmLinkFieldService ?? Container::instance()->getHcmLinkFieldService();
		$this->legalInfoProvider = $legalInfoProvider ?? Container::instance()->getLegalInfoProvider();
		$this->blockRepository = $blockRepository ?? Container::instance()->getBlockRepository();
	}

	/**
	 * @throws SignException
	 */
	public function getConfigurationByCode(string $code, bool $skipSecurity = false): Configuration
	{
		if (!in_array($code, Type\BlockCode::getAll(), true))
		{
			throw new SignException("No block configuration for code $code");
		}

		return match ($code)
		{
			Type\BlockCode::TEXT => new Configuration\Text(),
			Type\BlockCode::NUMBER => new Configuration\Number(),
			Type\BlockCode::DATE => new Configuration\Date(),

			Type\BlockCode::MY_SIGN => new Configuration\MySign(),
			Type\BlockCode::MY_STAMP => new Configuration\MyStamp(),
			Type\BlockCode::MY_REFERENCE => new Configuration\MyReference(),
			Type\BlockCode::MY_REQUISITES => new Configuration\MyRequisites(),

			Type\BlockCode::SIGN => new Configuration\Sign(),
			Type\BlockCode::STAMP => new Configuration\Stamp(),
			Type\BlockCode::REFERENCE => new Configuration\Reference(),
			Type\BlockCode::REQUISITES => new Configuration\Requisites(),

			Type\BlockCode::B2E_MY_REFERENCE => new Configuration\B2e\MyB2eReference($skipSecurity),
			Type\BlockCode::B2E_REFERENCE => new Configuration\B2e\B2eReference($skipSecurity),
			Type\BlockCode::EMPLOYEE_DYNAMIC => new Configuration\B2e\EmployeeDynamic(),
			Type\BlockCode::B2E_HCMLINK_REFERENCE => new Configuration\B2e\HcmLinkReference(),
			Type\BlockCode::B2E_EXTERNAL_ID => new Configuration\B2e\ExternalId(),
			Type\BlockCode::B2E_EXTERNAL_DATE_CREATE => new Configuration\B2e\ExternalDateCreate(),
		};
	}

	/**
	 * @param Item\Document $document
	 * @param string $code
	 * @param int $party
	 * @param array|null $data
	 * @param bool $skipSecurity
	 * @param Type\Member\Role::*|null $role
	 *
	 * @return Item\Block
	 */
	public function makeItem(
		Item\Document $document,
		string $code,
		int $party,
		?array $data = null,
		bool $skipSecurity = false,
		?string $role = null,
	): Item\Block
	{
		$configuration = $this->getConfigurationByCode($code, $skipSecurity);

		$item =  new Item\Block(
			party: $party,
			type: $this->getTypeByCode($code),
			code: $code,
			data: $data ?? [],
			role: $role ?? Role::createForBlock($party, $document->parties),
		);
		// we need only first member, and other to check that count is more that 1
		$membersByParty = $this->getMembersByDocumentParty($document, $party, 2);

		$result = $this->blockService->loadData($item, $document, $membersByParty->getFirst(), $skipSecurity);
		if (!$result->isSuccess())
		{
			return $item;
		}
		$item->data = $result->getData();

		if (
			Type\DocumentScenario::isB2EScenario($document->scenario)
			&& $party === $document->parties
			&& $membersByParty->count() > 1
			&& ($item->data['show'] ?? '') !== true
		)
		{
			$item->data['text'] = '';
		}

		$viewData = $configuration->getViewSpecificData($item);
		if ($viewData !== null)
		{
			$item->data[Configuration::VIEW_SPECIFIC_DATA_KEY] = $viewData;
		}

		return $item;
	}

	private function getMembersByDocumentParty(Item\Document $document, int $party, int $limit): Item\MemberCollection
	{
		if ($document->id === null)
		{
			return $this->memberRepository->listByDocumentIdWithParty($document->id, $party, $limit);
		}

		$key = $document->id . ':' . $party;
		if (!isset($this->membersByDocumentParty[$key]))
		{
			$this->membersByDocumentParty[$key] = $this->memberRepository->listByDocumentIdWithParty($document->id, $party, $limit);
		}

		return $this->membersByDocumentParty[$key];
	}

	public function getTypeByCode(string $code): string
	{
		return match ($code)
		{
			Type\BlockCode::SIGN,
			Type\BlockCode::STAMP,
			Type\BlockCode::MY_STAMP,
			Type\BlockCode::MY_SIGN => Type\BlockType::IMAGE,
			Type\BlockCode::MY_REQUISITES, Type\BlockCode::REQUISITES => Type\BlockType::MULTILINE_TEXT,
			default => Type\BlockType::TEXT,
		};
	}

	public function makeStubBlockByRequiredField(
		Item\Document $document,
		Item\B2e\RequiredField $requiredField,
		int $party,
	): ?Item\Block
	{
		$name = null;
		$code = null;
		if ($document->hcmLinkCompanyId && $this->hcmLinkFieldService->isAvailable())
		{
			$name = $this->hcmLinkFieldService->getHcmRequiredFieldSelectorNameByType(
				integrationId: $document->hcmLinkCompanyId,
				fieldType: $requiredField->type,
				party: $party,
			);
			$code = Type\BlockCode::B2E_HCMLINK_REFERENCE;
		}

		if (!$name)
		{
			$name = $this->legalInfoProvider->getFirstFieldNameByType($requiredField->type);
			$code = Type\BlockCode::getB2eReferenceCodeByRole($requiredField->role);
		}

		if (Type\FieldType::isRegional($requiredField->type))
		{
			if ($this->shouldCreateRegionalBlock($document, $code, $requiredField->type))
			{
				$code = static::getB2eRegionalBlockCodeByFieldType($requiredField->type);
				$name = NameHelper::create($code, $requiredField->type, $party);
			}
			elseif ($code !== Type\BlockCode::B2E_HCMLINK_REFERENCE)
			{
				// A declined regional field must produce nothing at all. Falling through to the legal
				// branch would only stay harmless while LegalInfoProvider has no entry for the regional
				// types: the moment it gains one, the field silently returns as a legal reference block.
				// HCM keeps its own block, so it is the single exception here.
				return null;
			}
		}

		if (!$name)
		{
			return null;
		}

		return $this->makeItem(
			document: $document,
			code: $code,
			party: $party,
			data: ['field' => $name],
			skipSecurity: true,
			role: $requiredField->role,
		);
	}

	/**
	 * Builds a legal reference stub block for a profile field type, always on the legal branch.
	 * Used for the full name part dosend: parts are legal profile fields, so the HCM branch of
	 * makeStubBlockByRequiredField is intentionally bypassed here (Q-1).
	 */
	public function makeStubLegalReferenceBlock(
		Item\Document $document,
		string $fieldType,
		string $role,
		int $party,
	): ?Item\Block
	{
		$name = $this->legalInfoProvider->getFirstFieldNameByType($fieldType);
		if (!$name)
		{
			return null;
		}

		return $this->makeItem(
			document: $document,
			code: Type\BlockCode::getB2eReferenceCodeByRole($role),
			party: $party,
			data: ['field' => $name],
			skipSecurity: true,
			role: $role,
		);
	}

	/**
	 * Decides whether the regional external-field block (registration number / creation date)
	 * must be produced for the given document.
	 *
	 * Company-initiated documents always get the regional block: the send wizard has a regional
	 * settings step where the company supplies the values.
	 * Employee-initiated documents get it only when the blank really holds the matching placeholder
	 * block, and never when an HCM link reference block was already resolved above (HCM keeps
	 * priority). Each regional field is decided on its own: a blank carrying only the registration
	 * number code must not produce the creation date block. The blank-level "has placeholders" flag
	 * is not a usable signal here: it is one flag per blank, so it neither tells the two codes apart
	 * nor distinguishes a blank authored from the placeholder tile with no codes typed into it.
	 */
	private function shouldCreateRegionalBlock(
		Item\Document $document,
		?string $currentCode,
		string $fieldType,
	): bool
	{
		if (!$document->isInitiatedByEmployee())
		{
			return true;
		}

		if ($currentCode === Type\BlockCode::B2E_HCMLINK_REFERENCE)
		{
			return false;
		}

		$regionalCode = static::getB2eRegionalBlockCodeByFieldType($fieldType);

		return $regionalCode !== null
			&& in_array($regionalCode, $this->getExistingRegionalBlockCodes($document), true);
	}

	/**
	 * @return list<string>
	 */
	private function getExistingRegionalBlockCodes(Item\Document $document): array
	{
		$blankId = $document->blankId;
		if ($blankId === null)
		{
			return [];
		}

		return $this->regionalBlockCodesByBlankId[$blankId] ??= $this->blockRepository
			->getExistingB2eRegionalBlockCodesByBlankId($blankId)
		;
	}

	public static function getStaticLabelByBlockCode(string $blockCode): ?string
	{
		return match($blockCode)
		{
			Type\BlockCode::B2E_EXTERNAL_DATE_CREATE => Loc::getMessage('SIGN_BLANKS_BLOCK_FACTORY_B2E_EXTERNAL_DOCUMENT_DATE'),
			Type\BlockCode::B2E_EXTERNAL_ID => Loc::getMessage('SIGN_BLANKS_BLOCK_FACTORY_B2E_EXTERNAL_ID'),
			default => null,
		};
	}

	private static function getB2eRegionalBlockCodeByFieldType(string $type): ?string
	{
		return match($type)
		{
			Type\FieldType::EXTERNAL_ID => Type\BlockCode::B2E_EXTERNAL_ID,
			Type\FieldType::EXTERNAL_DATE => Type\BlockCode::B2E_EXTERNAL_DATE_CREATE,
			default => null,
		};
	}
}
