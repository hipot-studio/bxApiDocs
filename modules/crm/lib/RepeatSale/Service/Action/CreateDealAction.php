<?php

namespace Bitrix\Crm\RepeatSale\Service\Action;

use Bitrix\Crm\Format\PlaceholderFormatter;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Integration\DocumentGeneratorManager;
use Bitrix\Crm\Item;
use Bitrix\Crm\RepeatSale\Segment\SegmentCode;
use Bitrix\Crm\RepeatSale\Segment\SegmentItem;
use Bitrix\Crm\RepeatSale\Service\Context;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use CCrmOwnerType;

final class CreateDealAction implements ActionInterface
{
	public function process(
		Item $clientItem,
		int $assignmentUserId,
		?Result $prevActionResult = null,
		?Context $context = null,
		?SegmentItem $segmentItem = null,
	): Result
	{
		$this->setAnalyticsContext($segmentItem?->getCode());

		$deal = $this->createDeal($assignmentUserId, $segmentItem);

		$entityTypeId = $clientItem->getEntityTypeId();
		if ($entityTypeId === CCrmOwnerType::Company)
		{
			$deal->setCompanyId($clientItem->getId());
		}
		elseif ($entityTypeId === CCrmOwnerType::Contact)
		{
			$deal->setContactId($clientItem->getId());
		}
		elseif ($entityTypeId === CCrmOwnerType::Deal)
		{
			$baseDeal = Container::getInstance()
				->getFactory(CCrmOwnerType::Deal)
				?->getItem($clientItem->getId())
			;
			if ($baseDeal)
			{
				$deal->setCompanyId($baseDeal->getCompanyId());
				$deal->setContactIds($baseDeal->getContactIds());
			}
			else
			{
				return (new Result())->addError(new Error('Base deal not found: ' . $clientItem->getId()));
			}
		}

		$addResult = $this->getFactory()->getAddOperation($deal)->disableAllChecks()->launch();

		if (!$addResult->isSuccess()) {
			return $addResult;
		}

		$updateResult = $this->replaceDealTitlePlaceholders($deal);

		return $updateResult->isSuccess() ? $updateResult->setData(['item' => $deal]) : $updateResult;
	}

	private function createDeal(int $assignmentUserId, ?SegmentItem $segmentItem): Item
	{
		$title = $segmentItem ? $this->getTitle($segmentItem) : null;

		return $this->getFactory()->createItem([
			'ASSIGNED_BY_ID' => $assignmentUserId,
			'TITLE' => $title ??  Loc::getMessage('CRM_REPEAT_SALE_ACTION_CREATE_DEAL_TITLE'),
			'SOURCE_ID' => 'REPEAT_SALE',
			'CATEGORY_ID' => $segmentItem->getEntityCategoryId(),
			'STAGE_ID' => $segmentItem->getEntityStageId(),
		]);
	}

	// @todo will use the field values in the future
	private function getTitle(SegmentItem $segmentItem): ?string
	{
		return $segmentItem->getEntityTitlePattern();
	}

	private function replaceDealTitlePlaceholders(Item $deal): Result
	{
		$pattern = $deal->getTitle();

		if ($pattern === null || !PlaceholderFormatter::hasPlaceholders($pattern))
		{
			return new Result();
		}

		$title = (new DocumentGeneratorManager())
			->replacePlaceholdersInText(
				\CCrmOwnerType::Deal,
				$deal->getId(),
				$pattern,
			)
		;

		$title = trim($title);
		$title = empty($title) ? $deal->getTitlePlaceholder() : $title;

		$deal->setTitle($title);

		return $this->getFactory()->getUpdateOperation($deal)->disableAllChecks()->launch();
	}

	private function getFactory(): Factory
	{
		return Container::getInstance()->getFactory(CCrmOwnerType::Deal);
	}

	private function setAnalyticsContext(?string $segmentCode): void
	{
		Container::getInstance()
			->getContext()
			->getAnalytics()
			->setEvent(Dictionary::EVENT_ENTITY_CREATE)
			->setSection(Dictionary::SECTION_REPEAT_SALE)
			->setSubSection(Dictionary::SUB_SECTION_REPEAT_SALE_SYSTEM)
			->setP5($this->getP5BySegmentCode($segmentCode))
		;
	}

	private function getP5BySegmentCode(string $segmentCode): ?string
	{
		return match ($segmentCode)
		{
			SegmentCode::SLEEPING_CLIENT->value => 'segment_deal-activity-less-12m',
			SegmentCode::LOST_CLIENT->value => 'segment_deal-lost-more-12m',
			SegmentCode::DEAL_EVERY_YEAR->value => 'segment_deal-annual',
			SegmentCode::DEAL_EVERY_HALF_YEAR->value => 'segment_deal-semiannual',
			SegmentCode::DEAL_EVERY_MONTH->value => 'segment_deal-month-yr',
			SegmentCode::AI_SCREENING->value => 'segment_deal-ai-screening',
			SegmentCode::AI_APPROVE->value => 'segment_deal-ai-approve',
			default => '',
		};
	}
}
