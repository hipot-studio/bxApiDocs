<?php

namespace Bitrix\Tasks\Ui\Preview;

use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Uri;
use Bitrix\Tasks\Helper\Analytics;
use Bitrix\Tasks\Integration\SocialNetwork\Group;
use Bitrix\Tasks\Util\User;

Loc::loadLanguageFile(__FILE__);

class Task
{
	public static function buildPreview(array $params)
	{
		global $APPLICATION;
		$taskId = (int)$params['taskId'];
		if(!$taskId)
		{
			return '';
		}

		ob_start();
		$APPLICATION->IncludeComponent(
			'bitrix:tasks.task.preview',
			'',
			$params
		);
		return ob_get_clean();
	}

	public static function checkUserReadAccess(array $params, int $userId = 0)
	{
		$taskId = (int)$params['taskId'];
		if(!$taskId)
		{
			return false;
		}

		try
		{
			$task = new \CTaskItem($taskId, static::getUser()?->getId() ?: $userId);
		}
		catch (\CTaskAssertException $e)
		{
			return false;
		}

		$access = $task->checkCanRead();

		return !!$access;
	}

	public static function getImAttach(array $params)
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		$taskId = (int)$params['taskId'];
		if (!$taskId)
		{
			return false;
		}

		$userId = static::getUser()?->getId() ?: Locator::getContext()->getUserId();

		$task = new \CTaskItem($taskId, $userId);
		if (!$task)
		{
			return false;
		}

		try
		{
			$select = [
				'ID',
				'TITLE',
				'DESCRIPTION',
				'CREATED_BY',
				'RESPONSIBLE_ID',
				'REAL_STATUS',
				'DEADLINE',
				'GROUP_ID',
			];
			$taskData = $task->getData(false, ['select' => $select], false);
		}
		catch (\TasksException $exception)
		{
			return false;
		}

		$link = new Uri(
			\CTaskNotifications::getNotificationPath(
				['ID' => $taskData['RESPONSIBLE_ID']],
				$taskData['ID']
			)
		);
		$link->addParams([
			'ta_sec' => Analytics::SECTION['chat'],
			'ta_el' => Analytics::ELEMENT['title_click'],
		]);
		$taskData['LINK'] = $link->getUri();

		$attach = new \CIMMessageParamAttach(1, '#E30000');
		$attach->AddUser([
			'NAME' => \CTextParser::clearAllTags($taskData['TITLE']),
			'LINK' => $taskData['LINK'],
		]);
		$attach->AddDelimiter();
		$attach->AddGrid(static::getImAttachGrid($taskData));

		return $attach;
	}

	public static function getImRich(array $params)
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		if (!class_exists('\Bitrix\Im\V2\Entity\Url\RichData'))
		{
			return false;
		}

		$taskId = (int)$params['taskId'];
		if (!$taskId)
		{
			return false;
		}

		$userId = static::getUser()?->getId() ?: Locator::getContext()->getUserId();

		$task = new \CTaskItem($taskId, $userId);
		if (!$task)
		{
			return false;
		}

		try
		{
			$select = ['ID', 'TITLE', 'DESCRIPTION', 'RESPONSIBLE_ID', 'CREATED_BY', 'AUDITORS', 'ACCOMPLICES'];
			$taskData = $task->getData(false, ['select' => $select], false);
		}
		catch (\TasksException $exception)
		{
			return false;
		}

		$membersIds = array_merge(
			[(int)$taskData['CREATED_BY']],
			[(int)$taskData['RESPONSIBLE_ID']],
			array_map(static fn ($id) => (int)$id, $taskData['AUDITORS'] ?? []),
			array_map(static fn ($id) => (int)$id, $taskData['ACCOMPLICES'] ?? [])
		);
		$membersIds = array_values(array_unique($membersIds));

		$richData = new \Bitrix\Im\V2\Entity\Url\RichData();
		$link = \CTaskNotifications::getNotificationPath(
			['ID' => $taskData['RESPONSIBLE_ID']],
			$taskData['ID']
		);
		$uri = new Uri($link);
		$uri->addParams([
			'ta_sec' => Analytics::SECTION['chat'],
			'ta_el' => Analytics::ELEMENT['title_click'],
		]);

		$richData
			->setType(\Bitrix\Im\V2\Entity\Url\RichData::TASKS_TYPE)
			->setName(\CTextParser::clearAllTags($taskData['TITLE']))
			->setDescription(\CTextParser::clearAllTags($taskData['DESCRIPTION']))
			->setLink($uri->getUri())
			->setAllowedUsers($membersIds)
		;

		return $richData;
	}

	protected static function getImAttachGrid(array $taskData): array
	{
		$grid = [];
		$display = 'COLUMN';
		$columnWidth = 120;

		if ($taskData['REAL_STATUS'] > 0)
		{
			$grid[] = [
				'NAME' => Loc::getMessage('TASK_PREVIEW_FIELD_STATUS') . ':',
				'VALUE' => Loc::getMessage('TASKS_TASK_STATUS_' . $taskData['REAL_STATUS']),
				'DISPLAY' => $display,
				'WIDTH' => $columnWidth,
			];
		}

		$grid[] = [
			'NAME' => Loc::getMessage('TASK_PREVIEW_FIELD_ASSIGNER') . ':',
			'VALUE' => \Bitrix\Im\User::getInstance($taskData['CREATED_BY'])->getFullName(false),
			'USER_ID' => $taskData['CREATED_BY'],
			'DISPLAY' => $display,
			'WIDTH' => $columnWidth,
		];

		$grid[] = [
			'NAME' => Loc::getMessage('TASK_PREVIEW_FIELD_ASSIGNEE') . ':',
			'VALUE' => \Bitrix\Im\User::getInstance($taskData['RESPONSIBLE_ID'])->getFullName(false),
			'USER_ID' => $taskData['RESPONSIBLE_ID'],
			'DISPLAY' => $display,
			'WIDTH' => $columnWidth,
		];

		$deadline = self::formatDeadline((string)($taskData['DEADLINE'] ?? ''));

		if ($deadline !== '')
		{
			$grid[] = [
				'NAME' => Loc::getMessage('TASK_PREVIEW_FIELD_DEADLINE') . ':',
				'VALUE' => $deadline,
				'DISPLAY' => $display,
				'WIDTH' => $columnWidth,
			];
		}

		$description = self::prepareDescription((string)($taskData['DESCRIPTION'] ?? ''));

		if ($description !== '')
		{
			$grid[] = [
				'NAME' => Loc::getMessage('TASK_PREVIEW_FIELD_DESCRIPTION') . ':',
				'VALUE' => $description,
				'DISPLAY' => $display,
				'WIDTH' => $columnWidth,
			];
		}

		if ($taskData['GROUP_ID'] > 0)
		{
			$groupId = $taskData['GROUP_ID'];
			$groupData = Group::getData([$groupId]);

			if (is_array($groupData[$groupId]))
			{
				$grid[] = [
					'NAME' => Loc::getMessage('TASK_PREVIEW_FIELD_GROUP') . ':',
					'VALUE' => $groupData[$groupId]['NAME'],
					'DISPLAY' => $display,
					'WIDTH' => $columnWidth,
				];
			}
		}

		return $grid;
	}

	protected static function formatDeadline(string $deadline): string
	{
		if ($deadline === '')
		{
			return '';
		}

		$culture = Context::getCurrent()?->getCulture();

		if (!$culture)
		{
			return $deadline;
		}

		try
		{
			$deadlineDate = new DateTime($deadline);

			$format = "{$culture->getShortDateFormat()} {$culture->getShortTimeFormat()}";

			return $deadlineDate->format($format);
		}
		catch (ObjectException)
		{
			return $deadline;
		}
	}

	protected static function prepareDescription(string $description): string
	{
		if ($description === '')
		{
			return '';
		}

		$description = htmlspecialchars_decode(htmlspecialcharsback($description), ENT_QUOTES);
		$description = \CTextParser::clearAllTags($description);
		$description = trim($description);
		$description = preg_replace('/\n{3,}/', "\n\n", $description);
		$description = str_replace(
			["&#91;", "&#93;"],
			["[", "]"],
			$description,
		);

		if ($description === '')
		{
			return '';
		}

		if (mb_strlen($description) > 100)
		{
			$description = mb_substr($description, 0, 100) . '...';
		}

		return $description;
	}

	protected static function getUser()
	{
		return User::get();
	}
}
