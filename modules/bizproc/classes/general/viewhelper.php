<?php

use Bitrix\Bizproc\Public\Service\Task\UnArchiveTaskService;
use Bitrix\Bizproc\Internal\Model\TaskArchive\TaskArchiveTable;
use Bitrix\Bizproc\Integration\ScopeTokenService;
use Bitrix\Disk\AttachedObject;
use Bitrix\Main\ArgumentException;
use Bitrix\Bizproc\Result\Entity\ResultTable;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Viewer\ItemAttributes;

class CBPViewHelper
{
	private static $cachedTasks = array();
	private const COMPLETED_STATUS = 'COMPLETED';
	private const RUNNING_STATUS = 'RUNNING';
	private static ScopeTokenService $scopeTokenService;

	public const DESKTOP_CONTEXT = 'desktop';
	public const MOBILE_CONTEXT = 'mobile';
	public const TASK_LIMIT = 50;

	public static function renderUserSearch($ID, $searchInputID, $dataInputID, $componentName, $siteID = '', $nameFormat = '', $delay = 0)
	{
		$ID = strval($ID);
		$searchInputID = strval($searchInputID);
		$dataInputID = strval($dataInputID);
		$componentName = strval($componentName);

		$siteID = strval($siteID);
		if($siteID === '')
		{
			$siteID = SITE_ID;
		}

		$nameFormat = strval($nameFormat);
		if($nameFormat === '')
		{
			$nameFormat = CSite::GetNameFormat(false);
		}

		$delay = intval($delay);
		if($delay < 0)
		{
			$delay = 0;
		}

		echo '<input type="text" id="', htmlspecialcharsbx($searchInputID) ,'" style="width:200px;"   >',
		'<input type="hidden" id="', htmlspecialcharsbx($dataInputID),'" name="', htmlspecialcharsbx($dataInputID),'" value="">';

		echo '<script>',
		'BX.ready(function(){',
		'BX.CrmUserSearchPopup.deletePopup("', $ID, '");',
		'BX.CrmUserSearchPopup.create("', $ID, '", { searchInput: BX("', CUtil::JSEscape($searchInputID), '"), dataInput: BX("', CUtil::JSEscape($dataInputID),'"), componentName: "', CUtil::JSEscape($componentName),'", user: {} }, ', $delay,');',
		'});</script>';

		$GLOBALS['APPLICATION']->IncludeComponent(
			'bitrix:intranet.user.selector.new',
			'',
			array(
				'MULTIPLE' => 'N',
				'NAME' => $componentName,
				'INPUT_NAME' => $searchInputID,
				'SHOW_EXTRANET_USERS' => 'NONE',
				'POPUP' => 'Y',
				'SITE_ID' => $siteID,
				'NAME_TEMPLATE' => $nameFormat,
			),
			null,
			array('HIDE_ICONS' => 'Y')
		);
	}

	public static function getWorkflowTasks($workflowId, $withUsers = false, $extendUserInfo = false)
	{
		$withUsers = $withUsers ? 1 : 0;
		$extendUserInfo = $extendUserInfo ? 1 : 0;

		if (!$workflowId)
		{
			return [self::COMPLETED_STATUS => [], self::RUNNING_STATUS => []];
		}

		if (!isset(self::$cachedTasks[$workflowId][$withUsers][$extendUserInfo]))
		{
			$tasks = [self::COMPLETED_STATUS => [], self::RUNNING_STATUS => []];
			$taskData = self::getTasksData($workflowId);
			foreach ($taskData as $task)
			{
				$key = (int)$task['STATUS'] === CBPTaskStatus::Running ? self::RUNNING_STATUS : self::COMPLETED_STATUS;
				$tasks[$key][] = $task;
			}

			if ($withUsers && $taskData)
			{
				$taskUsers = static::getTaskUsers($taskData);
				self::joinUsersToTasks($tasks[self::COMPLETED_STATUS], $taskUsers, $extendUserInfo);
				$tasks['RUNNING_ALL_USERS'] = self::joinUsersToTasks($tasks[self::RUNNING_STATUS], $taskUsers, $extendUserInfo);
			}

			$tasks['COMPLETED_CNT'] = count($tasks[self::COMPLETED_STATUS]);
			$tasks['RUNNING_CNT'] = count($tasks[self::RUNNING_STATUS]);

			self::$cachedTasks[$workflowId][$withUsers][$extendUserInfo] = $tasks;
		}

		return self::$cachedTasks[$workflowId][$withUsers][$extendUserInfo];
	}

	private static function getTasksData(string $workflowId)
	{
		$query =
			TaskArchiveTable::query()
				->setSelect(['ID', 'TASKS_DATA'])
				->where('WORKFLOW_ID', $workflowId)
				->setOrder(['TASKS.TASK_ID' => 'DESC'])
				->setLimit(50)
		;
		$archives = $query->fetchAll();

		if ($archives)
		{
			$archives = array_column($archives, 'TASKS_DATA', 'ID');

			return (new UnArchiveTaskService($archives, true))->getTasks(self::TASK_LIMIT, ['ID' => SORT_DESC]);
		}

		$taskIterator = CBPTaskService::GetList(
			['ID' => 'DESC'],
			['WORKFLOW_ID' => $workflowId],
			false,
			['nTopCount' => self::TASK_LIMIT],
			[
				'ID',
				'MODIFIED',
				'NAME',
				'DESCRIPTION',
				'PARAMETERS',
				'STATUS',
				'IS_INLINE',
				'ACTIVITY',
				'ACTIVITY_NAME',
				'CREATED_DATE',
				'DELEGATION_TYPE',
				'OVERDUE_DATE',
			],
		);

		$taskData = [];
		while ($task = $taskIterator->getNext())
		{
			$taskData[$task['ID']] = $task;
		}

		return $taskData;
	}

	private static function getTaskUsers(array $tasks): array
	{
		$taskUsers = [];
		$toFillTasks = [];
		$toGetTasksIds = [];
		foreach ($tasks as $task)
		{
			if (!empty($task['USERS']))
			{
				foreach ($task['USERS'] as $taskUser)
				{
					$taskId = $task['ID'];
					$toFillTasks[$taskId][] = [
						'ID' => null,
						'TASK_ID' => $taskId,
						...$taskUser,
					];
				}

			}
			else
			{
				$toGetTasksIds[] = $task['ID'];
			}
		}

		if ($toFillTasks)
		{
			$taskUsers = static::fillTaskUsers($toFillTasks);
		}

		if ($toGetTasksIds)
		{
			$taskUsers = CBPTaskService::getTaskUsers($toGetTasksIds) + $taskUsers;
		}

		return $taskUsers;
	}

	private static function fillTaskUsers(array $tasks)
	{
		$filledTaskUsers = [];
		$userIds = array_merge(
			...array_map(
				static fn(array $task): array => array_column($task, 'USER_ID'),
				$tasks
			)
		);

		$users = \Bitrix\Main\UserTable::wakeUpCollection($userIds);
		$users->fill([
			'PERSONAL_PHOTO',
			'NAME',
			'LAST_NAME',
			'SECOND_NAME',
			'LOGIN',
			'TITLE',
		]);
		foreach ($tasks as $task)
		{
			foreach ($task as $taskUser)
			{
				$user = $users->getByPrimary($taskUser['USER_ID']);
				$userData = $user->collectValues();
				$filledTaskUsers[$taskUser['TASK_ID']][] = array_merge($userData, $taskUser);
			}

		}

		return $filledTaskUsers;
	}

	protected static function joinUsersToTasks(&$tasks, &$taskUsers, $extendUserInfo = false)
	{
		$allUsers = array();
		foreach ($tasks as &$t)
		{
			$t['USERS'] = array();
			$t['USERS_CNT'] = 0;
			if (isset($taskUsers[$t['ID']]))
			{
				foreach ($taskUsers[$t['ID']] as $u)
				{
					if ($extendUserInfo)
					{
						if (empty($u['FULL_NAME']))
							$u['FULL_NAME'] = self::getUserFullName($u);
						if (empty($u['PHOTO_SRC']))
							$u['PHOTO_SRC'] = self::getUserPhotoSrc($u);
					}
					$t['USERS'][] = $u;
					$t['USERS_CNT'] = sizeof($t['USERS']);
					$allUsers[] = $u;
				}
			}
		}
		return $allUsers;
	}

	public static function getUserPhotoSrc(array $user)
	{
		if (empty($user['PERSONAL_PHOTO']))
			return '';
		$arFileTmp = \CFile::ResizeImageGet(
			$user["PERSONAL_PHOTO"],
			array('width' => 58, 'height' => 58),
			\BX_RESIZE_IMAGE_EXACT,
			false
		);
		return $arFileTmp['src'];
	}

	public static function getUserFullNameById(int $userId): ?string
	{
		$user = \Bitrix\Main\UserTable::getRow([
			'filter' => ['ID' => $userId],
			'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN'],
		]);
		if ($user)
		{
			return self::getUserFullName($user);
		}

		return null;
	}

	public static function getUserFullName(array $user)
	{
		return \CUser::FormatName(\CSite::GetNameFormat(false), $user, true, false);
	}

	public static function getHtmlEditor($id, $fieldName, $content = '')
	{
		$id = htmlspecialcharsbx($id);
		$fieldName = htmlspecialcharsbx($fieldName);

		if (is_array($content) && isset($content['TEXT']))
		{
			$content = $content['TEXT'];
		}

		$result = '<textarea rows="5" cols="40" id="'.$id.'" name="'.$fieldName.'">'.htmlspecialcharsbx(\CBPHelper::stringify($content)).'</textarea>';

		if (CModule::includeModule("fileman"))
		{
			$editor = new \CHTMLEditor;
			$res = array(
				'useFileDialogs' => false,
				'height' => 200,
				'minBodyWidth' => 350,
				'normalBodyWidth' => 555,
				'bAllowPhp' => false,
				'limitPhpAccess' => false,
				'showTaskbars' => false,
				'showNodeNavi' => false,
				'askBeforeUnloadPage' => true,
				'bbCode' => false,
				'siteId' => SITE_ID,
				'autoResize' => true,
				'autoResizeOffset' => 40,
				'saveOnBlur' => true,
				'controlsMap' => array(
					array('id' => 'Bold',  'compact' => true, 'sort' => 80),
					array('id' => 'Italic',  'compact' => true, 'sort' => 90),
					array('id' => 'Underline',  'compact' => true, 'sort' => 100),
					array('id' => 'Strikeout',  'compact' => true, 'sort' => 110),
					array('id' => 'RemoveFormat',  'compact' => true, 'sort' => 120),
					array('id' => 'Color',  'compact' => true, 'sort' => 130),
					array('id' => 'FontSelector',  'compact' => false, 'sort' => 135),
					array('id' => 'FontSize',  'compact' => false, 'sort' => 140),
					array('separator' => true, 'compact' => false, 'sort' => 145),
					array('id' => 'OrderedList',  'compact' => true, 'sort' => 150),
					array('id' => 'UnorderedList',  'compact' => true, 'sort' => 160),
					array('id' => 'AlignList', 'compact' => false, 'sort' => 190),
					array('separator' => true, 'compact' => false, 'sort' => 200),
					array('id' => 'InsertLink',  'compact' => true, 'sort' => 210, 'wrap' => 'bx-b-link-'.$id),
					array('id' => 'InsertImage',  'compact' => false, 'sort' => 220),
					array('id' => 'InsertVideo',  'compact' => true, 'sort' => 230, 'wrap' => 'bx-b-video-'.$id),
					array('id' => 'InsertTable',  'compact' => false, 'sort' => 250),
					array('id' => 'Code',  'compact' => true, 'sort' => 260),
					array('id' => 'Quote',  'compact' => true, 'sort' => 270, 'wrap' => 'bx-b-quote-'.$id),
					array('id' => 'Smile',  'compact' => false, 'sort' => 280),
					array('separator' => true, 'compact' => false, 'sort' => 290),
					array('id' => 'Fullscreen',  'compact' => false, 'sort' => 310),
					array('id' => 'BbCode',  'compact' => true, 'sort' => 340),
					array('id' => 'More',  'compact' => true, 'sort' => 400),
				),

				'name' => $fieldName.'[TEXT]',
				'inputName' => $fieldName.'[TEXT]',
				'id' => $id,
				'width' => '100%',
				'content' => htmlspecialcharsback($content),
			);

			ob_start();
			echo '<input type="hidden" name="'.$fieldName.'[TYPE]" value="html">';
			$editor->show($res);
			$result = ob_get_contents();
			ob_end_clean();
		}

		return $result;
	}

	public static function prepareTaskDescription($description)
	{
		$description = self::replaceFileLinks($description ?? '');

		if (\Bitrix\Main\Loader::includeModule('disk'))
		{
			$description = self::replaceDiskLinks($description);
		}

		return nl2br(trim($description));
	}

	public static function prepareMobileTaskDescription($description)
	{
		$description = self::replaceFileLinks($description ?? '', true);

		if (\Bitrix\Main\Loader::includeModule('disk'))
		{
			$description = self::replaceDiskLinks($description, true);
		}

		return nl2br($description);
	}

	private static function replaceFileLinks(string $description, $isMobile = false)
	{
		$callback = $isMobile ? self::getMobileFileLinksReplaceCallback() : self::getFileLinksReplaceCallback();

		return preg_replace_callback(
			'|<a href="(/bitrix/tools/bizproc_show_file.php\?)([^"]+)"[^>]*>|',
			$callback,
			$description
		);
	}

	private static function getFileLinksReplaceCallback()
	{
		return static function($matches)
		{
			$matches[2] = htmlspecialcharsback($matches[2]);
			parse_str($matches[2], $query);
			$fileId = (int)($query['i'] ?? null);
			if ($fileId > 0)
			{
				try
				{
					$url = $matches[1].$matches[2];
					$url = self::getScopeTokenService()->tokenizeUrl($url, $fileId) ?? $url;

					$attributes = ItemAttributes::buildByFileId($fileId, $url);

					return "<a href=\"$url\" $attributes>";
				}
				catch (ArgumentException $e)
				{
					return sprintf(
						'<a class="bizproc-file-not-found" data-hint="%s" data-hint-no-icon data-hint-center>',
						Loc::getMessage('BIZPROC_VIEW_HELPER_FILE_NOT_FOUND')
					);
				}
			}

			return $matches[0];
		};
	}

	private static function getMobileFileLinksReplaceCallback()
	{
		return function ($matches)
		{
			$matches[2] = htmlspecialcharsback($matches[2]);
			parse_str($matches[2], $query);
			$filename = '';
			if (isset($query['f']))
			{
				$query['hash'] = md5($query['f']);
				$filename = $query['f'];
				unset($query['f']);
			}
			$query['mobile_action'] = 'bp_show_file';
			$query['filename'] = $filename;

			return '<a href="#" data-url="' . SITE_DIR . 'mobile/ajax.php?' . http_build_query($query)
				. '" data-name="' . htmlspecialcharsbx($filename)
				. '" onclick="BXMobileApp.UI.Document.open({url: this.getAttribute(\'data-url\'), '
				. 'filename: this.getAttribute(\'data-name\')}); return false;">'
			;
		};
	}

	private static function replaceDiskLinks(string $description, $isMobile = false)
	{
		$callback = $isMobile ? self::getMobileDiskLinksReplaceCallback() : self::getDiskLinksReplaceCallback();

		return preg_replace_callback(
			'|<a href="(/bitrix/tools/disk/uf.php\?)([^"]+)"[^>]*>([^<]+)|',
			$callback,
			$description
		);
	}

	private static function getDiskLinksReplaceCallback()
	{
		return static function($matches)
		{
			$matches[2] = htmlspecialcharsback($matches[2]);
			parse_str($matches[2], $query);
			$attachedId = (int)($query['attachedId'] ?? null);
			if ($attachedId > 0)
			{
				$attach = AttachedObject::loadById($attachedId);
				if ($attach)
				{
					try
					{
						$url = $matches[1].$matches[2];
						$url = self::getScopeTokenService()->tokenizeUrl($url, $attach) ?? $url;

						$attributes = ItemAttributes::buildByFileId($attach->getFileId(), $url);

						return "<a href=\"$url\" $attributes>{$matches[3]}";
					}
					catch (ArgumentException $e)
					{
						return sprintf(
							'<a class="bizproc-file-not-found" data-hint="%s" data-hint-no-icon data-hint-center>',
							Loc::getMessage('BIZPROC_VIEW_HELPER_FILE_NOT_FOUND')
						);
					}
				}
			}

			return $matches[0];
		};
	}

	private static function getMobileDiskLinksReplaceCallback()
	{
		return function($matches)
		{
			$matches[2] = htmlspecialcharsback($matches[2]);
			parse_str($matches[2], $query);
			$filename = htmlspecialcharsback($matches[3]);
			$query['mobile_action'] = 'disk_uf_view';
			$query['filename'] = $filename;

			return '<a href="#" data-url="'.SITE_DIR.'mobile/ajax.php?'.http_build_query($query)
				.'" data-name="'.htmlspecialcharsbx($filename).'" onclick="BXMobileApp.UI.Document.open({url: this.getAttribute(\'data-url\'), filename: this.getAttribute(\'data-name\')}); return false;">'.$matches[3];
		};
	}

	public static function getWorkflowResult(
		string $workflowId,
		int $userId,
		string $context = self::DESKTOP_CONTEXT
	): array
	{
		static $cache = [];

		if (!isset($cache[$workflowId]))
		{
			$cache[$workflowId] = ResultTable::getList([
				'filter' => [
					'=WORKFLOW_ID' => $workflowId,
				],
				'select' => ['ACTIVITY', 'RESULT'],
			])->fetch();
		}

		$result = $cache[$workflowId];
		$renderedResult = null;
		if ($result)
		{
			$renderedResult = \CBPActivity::callStaticMethod(
				$result['ACTIVITY'],
				'renderResult',
				[
					$result['RESULT'],
					$workflowId,
					$userId,
				],
			);
		}

		$processedResult =
			($context === self::MOBILE_CONTEXT)
				? (new \Bitrix\Bizproc\Result\MobileResultHandler($workflowId))->handle($renderedResult)
				: (new \Bitrix\Bizproc\Result\WebResultHandler($workflowId))->handle($renderedResult)
		;

		return $processedResult;
	}

	public static function formatDateTime(?DateTime $date): string
	{
		if (!$date)
		{
			return '';
		}

		$thisYear = $date->format('Y') === date('Y');
		$culture = \Bitrix\Main\Application::getInstance()->getContext()->getCulture();
		$df = $thisYear
			? $culture?->getDayMonthFormat() ?? 'j F'
			: $culture?->getLongDateFormat() ?? 'j F Y'
		;
		$tf = $culture?->getShortTimeFormat() ?? 'H:i';

		return \FormatDate("$df, $tf", $date->toUserTime());
	}

	public static function renderDelayLimitsInfo(): string
	{
		$delayMinLimit = CBPSchedulerService::getDelayMinLimit();
		$delayMaxDays = CBPSchedulerService::getDelayMaxDays();

		if (empty($delayMinLimit) && empty($delayMaxDays))
		{
			return '';
		}
		$message = '';
		if ($delayMinLimit > 0)
		{
			$message .= Loc::getMessage("BIZPROC_VIEW_HELPER_DELAY_MIN_TEXT", ["#VALUE#" => CBPHelper::FormatTimePeriod($delayMinLimit)]);
		}

		if ($delayMaxDays > 0)
		{
			if (!empty($message))
			{
				$message .= "<br/>";
			}
			$message .= Loc::getMessage("BIZPROC_VIEW_HELPER_DELAY_MAX_TEXT", ["#VALUE#" => CBPHelper::FormatTimePeriod($delayMaxDays * 24 * 60 * 60)]);
		}

		return <<<HTML
			<p class="ui-alert ui-alert-primary ui-alert-icon-info ui-alert-xs" style="box-sizing: border-box;">
				<span class="ui-alert-message">{$message}</span>
			</p>
		HTML;
	}

	private static function getScopeTokenService(): ScopeTokenService
	{
		if (!isset(self::$scopeTokenService))
		{
			self::$scopeTokenService = new ScopeTokenService();
		}

		return self::$scopeTokenService;
	}
}
