<?php

namespace Bitrix\Sign\Blanks\Block;

use Bitrix\Sign\Compatibility\Role;
use Bitrix\Sign\Exception\SignException;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type;
use Bitrix\Sign\Item;
use Bitrix\Sign\Service;

class Factory
{
	private MemberRepository $memberRepository;
	private Service\Sign\BlockService $blockService;

	public function __construct(
		?MemberRepository $memberRepository = null,
		?Service\Sign\BlockService $blockService = null,
	)
	{
		$this->memberRepository = $memberRepository ?? Container::instance()->getMemberRepository();
		$this->blockService = $blockService ?? Container::instance()->getSignBlockService();
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
		};
	}

	public function makeItem(
		Item\Document $document,
		string $code,
		int $party,
		?array $data = null,
		bool $skipSecurity = false,
	): Item\Block
	{
		$configuration = $this->getConfigurationByCode($code, $skipSecurity);

		$item =  new Item\Block(
			party: $party,
			type: $this->getTypeByCode($code),
			code: $code,
			data: $data ?? [],
			role: Role::createForBlock($party, $document->parties),
		);
		// we need only first member, and other to check that count is more that 1
		$membersByParty = $this->memberRepository->listByDocumentIdWithParty($document->id, $party, 2);

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

	public function getTypeByCode(string $code): string
	{
		return match ($code)
		{
			Type\BlockCode::SIGN,
			Type\BlockCode::STAMP,
			Type\BlockCode::MY_STAMP,
			Type\BlockCode::MY_SIGN => Type\BlockType::IMAGE,

			Type\BlockCode::MY_REQUISITES, Type\BlockCode::REQUISITES => Type\BlockType::MULTILINE_TEXT,
			default => Type\BlockType::TEXT
		};
	}
}