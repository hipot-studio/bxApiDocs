<?php

namespace Bitrix\ImOpenLines\V2\QuickReply;

use Bitrix\Imopenlines\Limit;
use Bitrix\ImOpenlines\QuickAnswers\ListsDataManager;
use Bitrix\ImOpenlines\QuickAnswers\QuickAnswer;
use Bitrix\ImOpenLines\Operator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Text\Converter;

class QuickReplyService
{
	private ?ListsDataManager $dataManager = null;

	private const MAX_LIMIT = 50;

	public function getList(int $lineId, string $search = '', int $sectionId = 0, int $offset = 0, int $limit = 50): Result
	{
		$result = new Result();

		if (!$this->initDataManager($lineId, $result, checkRights: false))
		{
			return $result;
		}

		$canView = Limit::canUseQuickAnswers() && $this->dataManager->isHasRights();
		$replies = [];
		$sections = [];
		$totalCount = 0;
		$manageUrl = '';
		$canCreate = false;

		if ($canView)
		{
			$limit = max(0, min($limit, self::MAX_LIMIT));
			$offset = max(0, $offset);
			$filter = $this->buildSearchFilter($search, $sectionId);

			$allFiltered = QuickAnswer::getListByUserPermissions($filter, null, null);
			$totalCount = count($allFiltered);
			$replies = $this->formatReplies(array_slice($allFiltered, $offset, $limit));
			$sections = $this->formatSections(QuickAnswer::getSectionList());
			$manageUrl = (string)$this->dataManager->getUrlToList();

			$iblockId = $this->dataManager->getIblockId();
			$canCreate = \CIBlockRights::UserHasRightTo($iblockId, $iblockId, 'section_element_bind');
		}

		$result->setData([
			'replies' => $replies,
			'sections' => $sections,
			'totalCount' => $totalCount,
			'manageUrl' => $manageUrl,
			'permissions' => [
				'canView' => $canView,
				'canCreate' => $canCreate,
			],
		]);

		return $result;
	}

	public function save(int $lineId, string $text, int $id = 0, int $sectionId = 0): Result
	{
		$result = new Result();

		if (!Limit::canUseQuickAnswers())
		{
			return $result->addError(new Error('Quick answers feature is not available on your plan', 'QUICK_ANSWERS_LIMIT'));
		}

		if (!$this->initDataManager($lineId, $result))
		{
			return $result;
		}

		$converter = Converter::getHtmlConverter();
		$text = $converter->decode($text);

		if (empty(trim($text)))
		{
			return $result->addError(new Error('Text cannot be empty', 'EMPTY_TEXT'));
		}

		if ($id > 0)
		{
			$answer = $this->updateAnswer($id, $text, $sectionId, $result);
		}
		else
		{
			$answer = $this->createAnswer($text, $sectionId, $result);
		}

		if (!$result->isSuccess())
		{
			return $result;
		}

		if (!$answer || $answer->getId() <= 0)
		{
			return $result->addError(new Error('Failed to save quick answer', 'SAVE_ERROR'));
		}

		$result->setData([
			'reply' => $this->formatReply($answer),
		]);

		return $result;
	}

	public function saveFromMessage(int $chatId, int $messageId, int $userId): Result
	{
		$result = new Result();

		if (!Limit::canUseQuickAnswers())
		{
			return $result->addError(new Error('Quick answers feature is not available on your plan', 'QUICK_ANSWERS_LIMIT'));
		}

		$operator = new Operator($chatId, $userId);
		$operatorResult = $operator->saveToQuickAnswers($messageId);

		if (!$operatorResult)
		{
			$basicError = $operator->getError();
			if ($basicError !== null && $basicError->code !== '')
			{
				$result->addError($basicError->getError());
			}
			else
			{
				$result->addError(new Error('Failed to save message as quick answer', 'SAVE_ERROR'));
			}

			return $result;
		}

		$result->setData(['result' => true]);

		return $result;
	}

	public function incrementRating(int $lineId, int $id): Result
	{
		$result = new Result();

		if (!Limit::canUseQuickAnswers())
		{
			return $result->addError(new Error('Quick answers feature is not available on your plan', 'QUICK_ANSWERS_LIMIT'));
		}

		if (!$this->initDataManager($lineId, $result))
		{
			return $result;
		}

		$answer = QuickAnswer::getById($id);
		if (!$answer)
		{
			return $result->addError(new Error('Quick answer not found', 'NOT_FOUND'));
		}

		if (!\CIBlockElementRights::UserHasRightTo($answer->getIblock(), $id, 'element_read'))
		{
			return $result->addError(new Error('Access denied', 'ACCESS_DENIED'));
		}

		$answer->incrementRating();
		$result->setData(['result' => true]);

		return $result;
	}

	public function delete(int $lineId, int $id): Result
	{
		$result = new Result();

		if (!Limit::canUseQuickAnswers())
		{
			return $result->addError(new Error('Quick answers feature is not available on your plan', 'QUICK_ANSWERS_LIMIT'));
		}

		if (!$this->initDataManager($lineId, $result))
		{
			return $result;
		}

		$answer = QuickAnswer::getById($id);
		if (!$answer)
		{
			return $result->addError(new Error('Quick answer not found', 'NOT_FOUND'));
		}

		if (!\CIBlockElementRights::UserHasRightTo($answer->getIblock(), $id, 'element_edit'))
		{
			return $result->addError(new Error('Access denied', 'ACCESS_DENIED'));
		}

		if (!$answer->delete())
		{
			return $result->addError(new Error('Failed to delete quick answer', 'DELETE_ERROR'));
		}
		$result->setData(['result' => true]);

		return $result;
	}

	private function updateAnswer(int $id, string $text, int $sectionId, Result $result): ?QuickAnswer
	{
		$answer = QuickAnswer::getById($id);
		if (!$answer)
		{
			$result->addError(new Error('Quick answer not found', 'NOT_FOUND'));

			return null;
		}

		if (!\CIBlockElementRights::UserHasRightTo($answer->getIblock(), $id, 'element_edit'))
		{
			$result->addError(new Error('Access denied', 'ACCESS_DENIED'));

			return null;
		}

		$updateData = ['TEXT' => $text, 'MESSAGEID' => '', 'CATEGORY' => $sectionId];
		$answer->update($updateData);

		return $answer;
	}

	private function createAnswer(string $text, int $sectionId, Result $result): ?QuickAnswer
	{
		$iblockId = $this->dataManager->getIblockId();
		if (!\CIBlockRights::UserHasRightTo($iblockId, $iblockId, 'section_element_bind'))
		{
			$result->addError(new Error('Access denied', 'ACCESS_DENIED'));

			return null;
		}

		$addData = ['TEXT' => $text];
		if ($sectionId > 0)
		{
			$addData['CATEGORY'] = $sectionId;
		}

		$answer = QuickAnswer::add($addData);

		return $answer ?: null;
	}

	private function initDataManager(int $lineId, Result $result, bool $checkRights = true): bool
	{
		if ($lineId <= 0)
		{
			$result->addError(new Error('Invalid lineId', 'INVALID_LINE_ID'));

			return false;
		}

		try
		{
			$this->dataManager = new ListsDataManager($lineId);
		}
		catch (\Bitrix\Main\ArgumentNullException)
		{
			$result->addError(new Error('Invalid lineId', 'INVALID_LINE_ID'));

			return false;
		}

		if (!$this->dataManager->getIblockId())
		{
			$result->addError(new Error('Invalid lineId', 'INVALID_LINE_ID'));

			return false;
		}

		if ($checkRights && !$this->dataManager->isHasRights())
		{
			$result->addError(new Error('Access denied to quick answers of this line', 'ACCESS_DENIED'));

			return false;
		}

		QuickAnswer::setDataManager($this->dataManager);

		return true;
	}

	private function buildSearchFilter(string $search, int $sectionId): array
	{
		$filter = [];

		if ($search !== '')
		{
			$converter = Converter::getHtmlConverter();
			$search = $converter->decode($search);
			$filter[] = [
				'LOGIC' => 'OR',
				['DETAIL_TEXT' => '%' . $search . '%'],
				['NAME' => '%' . $search . '%'],
			];
		}

		if ($sectionId > 0)
		{
			$filter['SECTION_ID'] = $sectionId;
		}

		return $filter;
	}

	private function formatReplies(array $answers): array
	{
		$result = [];
		$converter = Converter::getHtmlConverter();

		foreach ($answers as $answer)
		{
			$result[] = [
				'id' => (int)$answer->getId(),
				'name' => $converter->decode($answer->getName()),
				'text' => $converter->decode($answer->getText()),
				'sectionId' => (int)$answer->getCategory(),
				'canEdit' => \CIBlockElementRights::UserHasRightTo(
					$answer->getIblock(),
					$answer->getId(),
					'element_edit'
				),
				'rating' => $answer->getRating(),
			];
		}

		return $result;
	}

	private function formatReply(QuickAnswer $answer): array
	{
		$converter = Converter::getHtmlConverter();

		return [
			'id' => (int)$answer->getId(),
			'name' => $converter->decode($answer->getName()),
			'text' => $converter->decode($answer->getText()),
			'sectionId' => (int)$answer->getCategory(),
			'canEdit' => \CIBlockElementRights::UserHasRightTo(
				$answer->getIblock(),
				$answer->getId(),
				'element_edit'
			),
			'rating' => $answer->getRating(),
		];
	}

	private function formatSections(array $rawSections): array
	{
		$sections = [];
		foreach ($rawSections as $sectionId => $section)
		{
			$sections[] = [
				'id' => (int)$sectionId,
				'name' => $section['NAME'] ?? '',
				'code' => $section['CODE'] ?? '',
			];
		}

		return $sections;
	}
}
