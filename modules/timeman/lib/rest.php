<?php
namespace Bitrix\Timeman;

use Bitrix\Main\Loader;
use Bitrix\Rest\RestException;
use Bitrix\Timeman\Integration\Intranet\Settings;
use Bitrix\Timeman\V2\Infrastructure\Controller\ActionFilter\ToolEnabledFilter;

Loader::includeModule('rest');

class Rest extends \IRestService
{
	const SCOPE = 'timeman';

	public static function onRestServiceBuildDescription()
	{
		return array(
			static::SCOPE => array(
				'timeman.settings' => array('callback' => array(__CLASS__, 'getSettings')),
				'timeman.status' => array('callback' => array(__CLASS__, 'getStatus')),
				'timeman.open' => array('callback' => array(__CLASS__, 'openDay')),
				'timeman.close' => array('callback' => array(__CLASS__, 'closeDay')),
				'timeman.pause' => array('callback' => array(__CLASS__, 'pauseDay')),

				'timeman.networkrange.get' => array('callback' => array(__CLASS__, 'networkRangeGet')),
				'timeman.networkrange.set' => array('callback' => array(__CLASS__, 'networkRangeSet')),
				'timeman.networkrange.check' => array('callback' => array(__CLASS__, 'networkRangeCheck')),

				'timeman.timecontrol.settings.get'=> array('callback' => array(__CLASS__, 'timeControlSettingsGet')),
				'timeman.timecontrol.settings.set'=> array('callback' => array(__CLASS__, 'timeControlSettingsSet')),
				'timeman.timecontrol.report.add'=> array('callback' => array(__CLASS__, 'timeControlReportAdd')),
				'timeman.timecontrol.reports.settings.get'=> array('callback' => array(__CLASS__, 'timeControlReportsSettingsGet')),
				'timeman.timecontrol.reports.users.get'=> array('callback' => array(__CLASS__, 'timeControlReportsUsersGet')),
				'timeman.timecontrol.reports.get'=> array('callback' => array(__CLASS__, 'timeControlReportsGet')),
				'timeman.timecontrol.report' =>  array('callback' => array(__CLASS__, 'timeControlReportAdd'), 'options' => array('private' => true)),
			)
		);
	}

	public static function getSettings($query, $n, \CRestServer $server)
	{
		self::checkToolEnabled();

		global $USER;

		$query = static::prepareQuery($query);
		$tmUser = static::getUserInstance($query);

		$currentSettings = $tmUser->getSettings();

		// temporary fix timeman bug
		if(mb_strpos($currentSettings['UF_TM_ALLOWED_DELTA'], ':') !== false)
		{
			$currentSettings['UF_TM_ALLOWED_DELTA'] = \CTimeMan::MakeShortTS($currentSettings['UF_TM_ALLOWED_DELTA']);
		}

		$result = array(
			'UF_TIMEMAN' => $currentSettings['UF_TIMEMAN'],
			'UF_TM_FREE' => $currentSettings['UF_TM_FREE'],
			'UF_TM_MAX_START' => static::formatTime($currentSettings['UF_TM_MAX_START']),
			'UF_TM_MIN_FINISH' => static::formatTime($currentSettings['UF_TM_MIN_FINISH']),
			'UF_TM_MIN_DURATION' => static::formatTime($currentSettings['UF_TM_MIN_DURATION']),
			'UF_TM_ALLOWED_DELTA' => static::formatTime($currentSettings['UF_TM_ALLOWED_DELTA']),
		);

		if($USER->GetID() == $tmUser->GetID())
		{
			$result['ADMIN'] = \CTimeMan::IsAdmin();
		}

		return $result;
	}

	public static function getStatus($query, $n, \CRestServer $server)
	{
		self::checkToolEnabled();

		$query = static::prepareQuery($query);
		$tmUser = static::getUserInstance($query);

		$currentInfo = $tmUser->getCurrentInfo();

		$result = array(
			'STATUS' => $tmUser->State(),
		);

		if (!is_array($currentInfo))
		{
			return $result;
		}

		if (isset($currentInfo['ID']))
		{
			$result['ID'] = (int)$currentInfo['ID'];
		}

		// Date-aware (API-01, Q-2): offset is taken from the record's snapshot fields
		// (START_OFFSET/STOP_OFFSET), no longer from the call-moment getDayStartOffset() + date('Z').
		// START_OFFSET/STOP_OFFSET are absolute UTC offsets (seconds, signed); the same value goes into
		// the ISO suffix and TZ_OFFSET, while date and time-of-day are reconstructed from the absolute
		// RECORDED_*_TIMESTAMP in that snapshot offset so the whole ISO string stays internally coherent.
		$startOffset = (int)$currentInfo['START_OFFSET'];
		$stopOffset = (int)$currentInfo['STOP_OFFSET'];

		if($currentInfo['DATE_START'])
		{
			$result['TIME_START'] = static::convertTimestampToISO(
				(int)$currentInfo['RECORDED_START_TIMESTAMP'],
				$startOffset
			);
			// TIME_FINISH is a real ISO only when the day is actually stopped (closed/EXPIRED), i.e. the
			// stop snapshot exists. The guard is RECORDED_STOP_TIMESTAMP > 0, NOT TIME_FINISH > 0: on pause
			// the model sets TIME_FINISH (start+leaks+duration) and DATE_FINISH but leaves
			// RECORDED_STOP_TIMESTAMP/STOP_OFFSET at 0, so a TIME_FINISH-based guard would format the zero
			// stop snapshot into 1970-01-01T00:00:00+00:00. Open/paused -> null (LBS #6); stopped -> the
			// date-aware ISO from the stop snapshot.
			$result['TIME_FINISH'] = (int)$currentInfo['RECORDED_STOP_TIMESTAMP'] > 0
				? static::convertTimestampToISO((int)$currentInfo['RECORDED_STOP_TIMESTAMP'], $stopOffset)
				: null;
			$result['DURATION'] = static::formatTime(intval($currentInfo['DURATION']));
			$result['TIME_LEAKS'] = static::formatTime(intval($currentInfo['TIME_LEAKS']));
			$result['ACTIVE'] = $currentInfo['ACTIVE'] == 'Y';
			$result['IP_OPEN'] = $currentInfo['IP_OPEN'];
			$result['IP_CLOSE'] = $currentInfo['IP_CLOSE'];
			$result['LAT_OPEN'] = doubleval($currentInfo['LAT_OPEN']);
			$result['LON_OPEN'] = doubleval($currentInfo['LON_OPEN']);
			$result['LAT_CLOSE'] = doubleval($currentInfo['LAT_CLOSE']);
			$result['LON_CLOSE'] = doubleval($currentInfo['LON_CLOSE']);
			$result['TZ_OFFSET'] = $startOffset;
		}

		if($result['STATUS'] == 'EXPIRED')
		{
			// TIME_FINISH_DEFAULT carries day-seconds (in the employee zone) computed date-aware in
			// CTimeManUser::GetExpiredRecommendedDate() (P4.T3); REST only formats it onto the start
			// date with the same date-aware start snapshot offset.
			$result['TIME_FINISH_DEFAULT'] = static::convertDaySecondsToISO(
				$tmUser->getExpiredRecommendedDate(),
				(int)$currentInfo['RECORDED_START_TIMESTAMP'],
				$startOffset
			);
		}

		return $result;
	}

	public static function pauseDay($query, $n, \CRestServer $server)
	{
		self::checkToolEnabled();

		$query = static::prepareQuery($query);
		$tmUser = static::getUserInstance($query);

		$tmUser->PauseDay();

		return static::getStatus($query, $n, $server);
	}

	public static function openDay($query, $n, \CRestServer $server)
	{
		self::checkToolEnabled();

		$query = static::prepareQuery($query);
		$tmUser = static::getUserInstance($query);

		$openAction = $tmUser->OpenAction();

		$result = false;
		if($openAction)
		{
			if($openAction === 'OPEN')
			{
				if(isset($query['TIME']))
				{
					$timeInfo = static::convertTimeFromISO($query['TIME'], $tmUser->GetID());

					// Date-aware (symmetric with close): the open date is validated against "today" in the
					// employee's zone, and the event date/time pair is threaded so the date-aware write-path
					// rebuilds the exact absolute instant sent by the client (no call-moment correction).
					if(!static::checkDate($timeInfo, static::getCurrentDateInUserZone($tmUser->GetID())))
					{
						throw new RestException(
							'Day open date should correspond to the current date',
							DateTimeException::ERROR_WRONG_DATETIME
						);
					}

					$result = $tmUser->openDay(
						$timeInfo['TIME'],
						$query['REPORT'],
						['CUSTOM_DATE' => $timeInfo['DATE']]
					);
				}
				else
				{
					$result = $tmUser->openDay();
				}

				if($result !== false)
				{
					static::setDayGeoPosition($result['ID'], $query, 'open');
				}
			}
			elseif($openAction === 'REOPEN')
			{
				if(isset($query['TIME']))
				{
					throw new RestException('Unable to set time, work day is paused', 'TIME');
				}

				$result = $tmUser->ReopenDay();
			}
		}

		if(!$result)
		{
			global $APPLICATION;
			$ex = $APPLICATION->GetException();
			if($ex)
			{
				throw new RestException($ex->GetString(), $ex->GetID());
			}
		}

		return static::getStatus($query, $n, $server);
	}

	public static function closeDay($query, $n, \CRestServer $server)
	{
		self::checkToolEnabled();

		$query = static::prepareQuery($query);
		$tmUser = static::getUserInstance($query);

		if(isset($query['TIME']))
		{
			$currentInfo = $tmUser->getCurrentInfo();

			// Date-aware (symmetric with open): build the absolute instant straight from the client ISO,
			// expressed in the employee's zone — no correctTimeOffset() to a call-moment offset. The close
			// date is validated against the record's start date in the employee's zone (date of the event).
			$timeInfo = static::convertTimeFromISO($query['TIME'], $tmUser->GetID());

			if(!static::checkDate($timeInfo, static::getRecordedStartDateInUserZone($currentInfo, $tmUser->GetID())))
			{
				throw new RestException(
					'Day close date should correspond to the day open date',
					DateTimeException::ERROR_WRONG_DATETIME
				);
			}

			// CUSTOM_DATE is intentionally NOT passed: close validates the client date against the record's
			// START date (LBS #13, quirk #5 — closing is allowed only on the open date), so the only
			// calendar date the model could use is the start date. Pinning the stop to the start date breaks
			// overnight closes (e.g. start 23:00, close 01:00): with a fixed stop date the stop would land
			// before the start. With no CUSTOM_DATE the model's date-aware overnight branch fires
			// (buildStopTimestampBySecondsAndDate: stop <= start -> +1 day, built wall-time in the
			// employee's real IANA zone), restoring a positive duration without a cross-DST hour drift.
			// $timeInfo['TIME'] is already the clock-face seconds in the employee's zone.
			$result = $tmUser->CloseDay(
				$timeInfo['TIME'],
				trim($query['REPORT'] ?? '')
			);
		}
		else
		{
			$result = $tmUser->CloseDay();
		}

		if(!$result)
		{
			global $APPLICATION;
			$ex = $APPLICATION->GetException();
			if($ex)
			{
				throw new RestException($ex->GetString(), $ex->GetID());
			}
		}
		else
		{
			static::setDayGeoPosition($result['ID'], $query, 'close');

			$currentInfo = $tmUser->GetCurrentInfo();

			$reportData = $tmUser->SetReport('', 0, $currentInfo['ID']);

			$dailyReportFields = [
				'USER_ID' => $tmUser->GetID(),
				'ENTRY_ID' => $currentInfo['ID'],
				'REPORT_DATE' => $currentInfo['DATE_START'],
				'ACTIVE' => $currentInfo['ACTIVE'],
				'REPORT' => $reportData['REPORT'],
			];

			\CTimeManReportDaily::Add($dailyReportFields);
		}

		return static::getStatus($query, $n, $server);
	}



	public static function networkRangeGet($query, $n, \CRestServer $server)
	{
		self::checkToolEnabled();

		if (!self::isAdmin())
		{
			throw new RestException(
				"You don't have access to user this method",
				"ACCESS_ERROR",
				\CRestServer::STATUS_WRONG_REQUEST
			);
		}

		return \Bitrix\Timeman\Common::getOptionNetworkRange();
	}

	public static function networkRangeSet($query, $n, \CRestServer $server)
	{
		self::checkToolEnabled();

		if (!self::isAdmin())
		{
			throw new RestException(
				"You don't have access to user this method",
				"ACCESS_ERROR",
				\CRestServer::STATUS_WRONG_REQUEST
			);
		}

		$query = static::prepareQuery($query);

		if (is_string($query['RANGES']))
		{
			$query['RANGES'] = \CUtil::JsObjectToPhp($query['RANGES']);
		}
		$result = \Bitrix\Timeman\Common::checkOptionNetworkRange($query['RANGES']);
		if (!$result)
		{
			throw new RestException(
				"A wrong format for the RANGES field is passed",
				"INVALID_FORMAT",
				\CRestServer::STATUS_WRONG_REQUEST
			);
		}
		if (count($result['ERROR']) > 0)
		{
			$result = Array(
				'result' => false,
				'error_ranges' =>  $result['ERROR'],
			);
		}
		else
		{
			$result = Array(
				'result' => \Bitrix\Timeman\Common::setOptionNetworkRange($result['CORRECT'])
			);
		}


		return $result;
	}

	public static function networkRangeCheck($query, $n, \CRestServer $server)
	{
		self::checkToolEnabled();

		if (!self::isAdmin())
		{
			throw new RestException(
				"You don't have access to user this method",
				"ACCESS_ERROR",
				\CRestServer::STATUS_WRONG_REQUEST
			);
		}

		$query = static::prepareQuery($query);

		$result = \Bitrix\Timeman\Common::isNetworkRange($query['IP']);
		if ($result)
		{
			$result = array_change_key_case($result, CASE_LOWER);
		}

		return $result;
	}

	public static function timeControlReportAdd($query, $n, \CRestServer $server)
	{
		self::checkToolEnabled();

		$query = static::prepareQuery($query);

		$absenceId = isset($query['REPORT_ID'])? $query['REPORT_ID']: $query['ID'];
		$userId = null;

		if (self::isAdmin() && intval($query['USER_ID']) > 0)
		{
			$userId = intval($query['USER_ID']);
			$result = \Bitrix\Timeman\Model\AbsenceTable::getById($absenceId)->fetch();
			if ($result['USER_ID'] != $userId)
			{
				throw new RestException(
					"You don't have access for this report",
					"ACCESS_ERROR",
					\CRestServer::STATUS_WRONG_REQUEST
				);
			}
		}

		$text = $query['TEXT'];
		$type = mb_strtoupper($query['TYPE']) == \Bitrix\Timeman\Absence::REPORT_TYPE_WORK? \Bitrix\Timeman\Absence::REPORT_TYPE_WORK: \Bitrix\Timeman\Absence::REPORT_TYPE_PRIVATE;
		$addToCalendar = $query['CALENDAR'] === 'N'? false: (bool)$query['CALENDAR'];

		$text = trim($text);
		if ($text == '')
		{
			throw new RestException(
				"Text can't be empty",
				"TEXT_EMPTY",
				\CRestServer::STATUS_WRONG_REQUEST
			);
		}



		\Bitrix\Timeman\Absence::addReport($absenceId, $text, $type, $addToCalendar, $userId);

		return true;
	}

	public static function timeControlSettingsGet($query, $n, \CRestServer $server)
	{
		self::checkToolEnabled();

		if (!self::isAdmin())
		{
			throw new RestException(
				"You don't have access to user this method",
				"ACCESS_ERROR",
				\CRestServer::STATUS_WRONG_REQUEST
			);
		}

		return Array(
			'active' => \Bitrix\Timeman\Absence::isActive(),
			'minimum_idle_for_report' => \Bitrix\Timeman\Absence::getMinimumIdleForReport(),

			'register_offline' => \Bitrix\Timeman\Absence::isRegisterOffline(),
			'register_idle' => \Bitrix\Timeman\Absence::isRegisterIdle(),
			'register_desktop' => \Bitrix\Timeman\Absence::isRegisterDesktop(),

			'report_request_type' => mb_strtolower(\Bitrix\Timeman\Absence::getOptionReportEnableType()),
			'report_request_users' => \Bitrix\Timeman\Absence::getOptionReportEnableUsers(),

			'report_simple_type' => mb_strtolower(\Bitrix\Timeman\Absence::getOptionReportListSimpleType()),
			'report_simple_users' => \Bitrix\Timeman\Absence::getOptionReportListSimpleUsers(),

			'report_full_type' => mb_strtolower(\Bitrix\Timeman\Absence::getOptionReportListFullType()),
			'report_full_users' => \Bitrix\Timeman\Absence::getOptionReportListFullUsers(),
		);
	}

	public static function timeControlSettingsSet($query, $n, \CRestServer $server)
	{
		self::checkToolEnabled();

		if (!self::isAdmin())
		{
			throw new RestException(
				"You don't have access to user this method",
				"ACCESS_ERROR",
				\CRestServer::STATUS_WRONG_REQUEST
			);
		}

		$query = static::prepareQuery($query);

		if (array_key_exists('ACTIVE', $query))
		{
			\Bitrix\Timeman\Absence::setOptionActive((bool)$query['ACTIVE']);
		}
		if (array_key_exists('MINIMUM_IDLE_FOR_REPORT', $query))
		{
			\Bitrix\Timeman\Absence::setOptionMinimumIdleForReport((int)$query['MINIMUM_IDLE_FOR_REPORT']);
		}

		if (array_key_exists('REGISTER_OFFLINE', $query))
		{
			\Bitrix\Timeman\Absence::setOptionRegisterOffline((bool)$query['REGISTER_OFFLINE']);
		}
		if (array_key_exists('REGISTER_IDLE', $query))
		{
			\Bitrix\Timeman\Absence::setOptionRegisterIdle((bool)$query['REGISTER_IDLE']);
		}
		if (array_key_exists('REGISTER_DESKTOP', $query))
		{
			\Bitrix\Timeman\Absence::setOptionRegisterDesktop((bool)$query['REGISTER_DESKTOP']);
		}

		if (array_key_exists('REPORT_REQUEST_TYPE', $query))
		{
			if (mb_strtoupper($query['REPORT_REQUEST_TYPE']) == \Bitrix\Timeman\Absence::TYPE_ALL)
			{
				\Bitrix\Timeman\Absence::setOptionRequestReport(true);
			}
			else if (mb_strtoupper($query['REPORT_REQUEST_TYPE']) == \Bitrix\Timeman\Absence::TYPE_FOR_USER)
			{
				if (array_key_exists('REPORT_REQUEST_USERS', $query))
				{
					if (is_string($query['REPORT_REQUEST_USERS']))
					{
						$query['REPORT_REQUEST_USERS'] = \CUtil::JsObjectToPhp($query['REPORT_REQUEST_USERS']);
					}
					\Bitrix\Timeman\Absence::setOptionRequestReport($query['REPORT_REQUEST_USERS']);
				}
				else
				{
					\Bitrix\Timeman\Absence::setOptionRequestReport([]);
				}
			}
			else
			{
				\Bitrix\Timeman\Absence::setOptionRequestReport(false);
			}
		}

		if (array_key_exists('REPORT_SIMPLE_TYPE', $query))
		{
			if (mb_strtoupper($query['REPORT_SIMPLE_TYPE']) == \Bitrix\Timeman\Absence::TYPE_ALL)
			{
				\Bitrix\Timeman\Absence::setOptionReportListSimple(true);
			}
			else if (mb_strtoupper($query['REPORT_SIMPLE_TYPE']) == \Bitrix\Timeman\Absence::TYPE_FOR_USER)
			{
				if (array_key_exists('REPORT_SIMPLE_USERS', $query))
				{
					if (is_string($query['REPORT_SIMPLE_USERS']))
					{
						$query['REPORT_SIMPLE_USERS'] = \CUtil::JsObjectToPhp($query['REPORT_SIMPLE_USERS']);
					}
					\Bitrix\Timeman\Absence::setOptionReportListSimple($query['REPORT_SIMPLE_USERS']);
				}
				else
				{
					\Bitrix\Timeman\Absence::setOptionReportListSimple([]);
				}
			}
			else
			{
				\Bitrix\Timeman\Absence::setOptionReportListSimple([]);
			}
		}

		if (array_key_exists('REPORT_FULL_TYPE', $query))
		{
			if (mb_strtoupper($query['REPORT_FULL_TYPE']) == \Bitrix\Timeman\Absence::TYPE_ALL)
			{
				\Bitrix\Timeman\Absence::setOptionReportListFull(true);
			}
			else if (mb_strtoupper($query['REPORT_FULL_TYPE']) == \Bitrix\Timeman\Absence::TYPE_FOR_USER)
			{
				if (array_key_exists('REPORT_FULL_USERS', $query))
				{
					if (is_string($query['REPORT_FULL_USERS']))
					{
						$query['REPORT_FULL_USERS'] = \CUtil::JsObjectToPhp($query['REPORT_FULL_USERS']);
					}
					\Bitrix\Timeman\Absence::setOptionReportListFull($query['REPORT_FULL_USERS']);
				}
				else
				{
					\Bitrix\Timeman\Absence::setOptionReportListFull([]);
				}
			}
			else
			{
				\Bitrix\Timeman\Absence::setOptionReportListFull([]);
			}
		}



		return true;
	}

	public static function timeControlReportsSettingsGet($query, $n, \CRestServer $server)
	{
		self::checkToolEnabled();

		$userId = $GLOBALS['USER']->GetId();
		$subordinateDepartments = \Bitrix\Timeman\Absence::getSubordinateDepartments($userId);
		foreach ($subordinateDepartments as $id => $value)
		{
			$subordinateDepartments[$id] = array_change_key_case($value, CASE_LOWER);
		}

		$isAdmin = self::isAdmin();
		$isHead = $subordinateDepartments || $isAdmin;

		$reportViewType = 'none';
		if ($isHead)
		{
			$reportViewType = 'head';
		}
		else if (\Bitrix\Timeman\Absence::isReportListFullEnableForUser($userId))
		{
			$reportViewType = 'full';
		}
		else if (\Bitrix\Timeman\Absence::isReportListSimpleEnableForUser($userId))
		{
			$reportViewType = 'simple';
		}

		return Array(
			'active' => \Bitrix\Timeman\Absence::isActive(),
			'user_id' => (int)$userId,
			'user_admin' => $isAdmin,
			'user_head' => $isHead,
			'departments' => $subordinateDepartments,
			'minimum_idle_for_report' => \Bitrix\Timeman\Absence::getMinimumIdleForReport(),
			'report_view_type' => $reportViewType,
		);
	}

	public static function timeControlReportsUsersGet($query, $n, \CRestServer $server)
	{
		self::checkToolEnabled();

		$query = static::prepareQuery($query);

		$userId = $GLOBALS['USER']->GetId();
		$departmentId = intval($query['DEPARTMENT_ID']);

		$result = \Bitrix\Timeman\Absence::getSubordinateUsers($departmentId, $userId);

		return self::formatJsonAnswer($result);
	}


	public static function timeControlReportsGet($query, $n, \CRestServer $server)
	{
		self::checkToolEnabled();

		$query = static::prepareQuery($query);

		$userId = $query['USER_ID'];
		$month = $query['MONTH'];
		$year = $query['YEAR'];
		$idleMinutes = $query['IDLE_MINUTES'];
		$workdayHours = $query['WORKDAY_HOURS'];

		$currentUserId = $GLOBALS['USER']->GetId();

		if (\Bitrix\Timeman\Absence::isHead())
		{
			$reportViewType = 'head';
		}
		else if (\Bitrix\Timeman\Absence::isReportListFullEnableForUser($currentUserId))
		{
			$reportViewType = 'full';
		}
		else if (\Bitrix\Timeman\Absence::isReportListSimpleEnableForUser($currentUserId))
		{
			$reportViewType = 'simple';
		}
		else
		{
			throw new RestException(
				"You don't have access to this method",
				"ACCESS_ERROR",
				\CRestServer::STATUS_WRONG_REQUEST
			);
		}

		if (!\Bitrix\Timeman\Absence::hasAccessToReport($userId))
		{
			throw new RestException(
				"You don't have access to report for this user",
				"USER_ACCESS_ERROR",
				\CRestServer::STATUS_WRONG_REQUEST
			);
		}

		if ($reportViewType != 'head')
		{
			$idleMinutes = null;
		}

		$result = \Bitrix\Timeman\Absence::getMonthReport($userId, $year, $month, $workdayHours, $idleMinutes);

		$fullTypesWhiteList = [
			\Bitrix\Timeman\Absence::SOURCE_ONLINE_EVENT,
			\Bitrix\Timeman\Absence::SOURCE_OFFLINE_AGENT,
			\Bitrix\Timeman\Absence::SOURCE_IDLE_EVENT,
			\Bitrix\Timeman\Absence::SOURCE_TM_EVENT,
		];

		if ($reportViewType != 'head')
		{
			foreach ($result['REPORT']['DAYS'] as $id => $entry)
			{
				if ($reportViewType == 'simple')
				{
					$result['REPORT']['DAYS'][$id]['REPORTS'] = [];
				}
				else
				{
					foreach ($entry['REPORTS'] as $reportId => $reportValue)
					{
						if (!in_array($reportValue['SOURCE_START'], $fullTypesWhiteList))
						{
							unset($result['REPORT']['DAYS'][$id]['REPORTS'][$reportId]);
						}
						else
						{
							unset($result['REPORT']['DAYS'][$id]['REPORTS'][$reportId]['IP_START']);
							unset($result['REPORT']['DAYS'][$id]['REPORTS'][$reportId]['IP_START_NETWORK']);
							unset($result['REPORT']['DAYS'][$id]['REPORTS'][$reportId]['IP_FINISH']);
							unset($result['REPORT']['DAYS'][$id]['REPORTS'][$reportId]['IP_FINISH_NETWORK']);
							unset($result['REPORT']['DAYS'][$id]['REPORTS'][$reportId]['SYSTEM_TEXT']);
						}
					}
				}
			}
		}

		return self::formatJsonAnswer($result);
	}








	private static function checkToolEnabled(): void
	{
		if (!(new Settings())->isToolAvailable(Settings::TOOLS['worktime']))
		{
			throw new RestException(
				ToolEnabledFilter::getErrorMessage(),
				ToolEnabledFilter::ERROR_CODE,
			);
		}
	}

	protected static function prepareQuery(array $query)
	{
		return array_change_key_case($query, CASE_UPPER);
	}

	public static function getPublicDomain()
	{
		return (\Bitrix\Main\Context::getCurrent()->getRequest()->isHttps() ? "https" : "http")."://".((defined("SITE_SERVER_NAME") && SITE_SERVER_NAME <> '') ? SITE_SERVER_NAME : \Bitrix\Main\Config\Option::get("main", "server_name", $_SERVER['SERVER_NAME']));
	}

	public static function formatJsonAnswer($array)
	{
		if (!is_array($array))
		{
			return $array;
		}

		foreach ($array as $name => $value)
		{
			if (is_array($value))
			{
				$array[$name] = self::formatJsonAnswer($value);
			}
			else if ($value instanceof \Bitrix\Main\Type\DateTime)
			{
				$array[$name] = date('c', $value->getTimestamp());
			}
			else if ($name == 'AVATAR' && is_string($value) && $value && mb_strpos($value, 'http') !== 0)
			{
				$array[$name] = self::getPublicDomain().$value;
			}
		}

		return array_change_key_case($array, CASE_LOWER);
	}

	/**
	 * @param array $query
	 *
	 * @return \CTimeManUser
	 * @throws RestException
	 */
	protected static function getUserInstance(array $query)
	{
		global $USER;

		if(array_key_exists('USER_ID', $query) && $query['USER_ID'] != $USER->getId())
		{
			if(!\CTimeMan::isAdmin())
			{
				throw new RestException('User does not have access to managing other users work time');
			}

			if(!static::checkUser($query['USER_ID']))
			{
				throw new RestException('User not found');
			}

			return new \CTimeManUser($query['USER_ID']);
		}
		else
		{
			return \CTimeManUser::instance();
		}
	}

	protected static function checkUser($userId)
	{
		$dbRes = \CUser::getById($userId);
		return is_array($dbRes->fetch());
	}

	/**
	 * Current calendar date (site FORMAT_DATE) in the employee's real IANA zone — the date-aware
	 * replacement for the server-local ConvertTimeStamp() anchor used by the open-date guard.
	 */
	protected static function getCurrentDateInUserZone($userId)
	{
		global $DB;

		$timeHelper = \Bitrix\Timeman\Helper\TimeHelper::getInstance();
		$nowInUserZone = (new \DateTime('@' . $timeHelper->getUtcNowTimestamp()))
			->setTimezone($timeHelper->getUserDateTimeZone((int)$userId));

		return $nowInUserZone->format($DB->DateFormatToPHP(FORMAT_DATE));
	}

	/**
	 * The record's start calendar date (site FORMAT_DATE) reconstructed in the employee's zone from the
	 * absolute start instant and the START_OFFSET snapshot — the date-aware replacement for the
	 * server-local MakeTimeStamp(DATE_START) anchor used by the close-date guard.
	 */
	protected static function getRecordedStartDateInUserZone($currentInfo, $userId)
	{
		global $DB;

		$timeHelper = \Bitrix\Timeman\Helper\TimeHelper::getInstance();
		$startDateTime = $timeHelper->reconstructHistoricalDateTime(
			(int)$currentInfo['RECORDED_START_TIMESTAMP'],
			(int)$currentInfo['START_OFFSET']
		);

		return $startDateTime->format($DB->DateFormatToPHP(FORMAT_DATE));
	}

	/**
	 * Builds the wire ISO string (Y-m-dTH:i:s±HH:MM) for an absolute instant in the recorded
	 * snapshot offset. Date, time-of-day and the offset suffix are all derived from the same instant
	 * reconstructed in the historical snapshot offset, so the triple is internally coherent (parsing
	 * it back as RFC 3339 yields the original absolute moment) — this fixes the legacy cross-DST drift
	 * where the time was in server-midnight coordinates but the suffix was the user offset.
	 *
	 * @param int $timestamp absolute UTC Unix-seconds (RECORDED_*_TIMESTAMP)
	 * @param int $snapshotOffset absolute UTC offset stored at recording time (START_OFFSET/STOP_OFFSET)
	 * @return string
	 */
	protected static function convertTimestampToISO($timestamp, $snapshotOffset)
	{
		$dateTime = \Bitrix\Timeman\Helper\TimeHelper::getInstance()
			->reconstructHistoricalDateTime((int)$timestamp, (int)$snapshotOffset);

		return $dateTime->format('Y-m-d')
			. 'T' . static::formatTime(\Bitrix\Timeman\Helper\TimeHelper::getInstance()->getSecondsFromDateTime($dateTime))
			. static::formatOffsetSuffix((int)$snapshotOffset);
	}

	/**
	 * Builds the wire ISO string for a recommended day time expressed as day-seconds in the employee
	 * zone (TIME_FINISH_DEFAULT). The calendar date is taken from the start instant reconstructed in the
	 * snapshot offset, so it stays consistent with TIME_START; the day-seconds become the time-of-day
	 * and the snapshot offset becomes the suffix.
	 *
	 * @param int $daySeconds seconds from midnight in the employee zone (date-aware, from P4.T3)
	 * @param int $startTimestamp absolute UTC Unix-seconds of the start instant
	 * @param int $snapshotOffset absolute UTC offset of the start snapshot (START_OFFSET)
	 * @return string
	 */
	protected static function convertDaySecondsToISO($daySeconds, $startTimestamp, $snapshotOffset)
	{
		$startDateTime = \Bitrix\Timeman\Helper\TimeHelper::getInstance()
			->reconstructHistoricalDateTime((int)$startTimestamp, (int)$snapshotOffset);

		return $startDateTime->format('Y-m-d')
			. 'T' . static::formatTime((int)$daySeconds)
			. static::formatOffsetSuffix((int)$snapshotOffset);
	}

	/**
	 * Returns the ISO offset suffix ±HH:MM (east positive) for an absolute UTC offset in seconds.
	 */
	protected static function formatOffsetSuffix($offset)
	{
		$offsetSign = $offset >= 0 ? '+' : '-';

		return $offsetSign
			.str_pad(abs(intval($offset / 3600)), 2, '0', STR_PAD_LEFT).':'.str_pad(abs(intval($offset % 3600 / 60)), 2, '0', STR_PAD_LEFT);
	}

	protected static function formatTime($ts)
	{
		return str_pad(intval($ts / 3600), 2, '0', STR_PAD_LEFT)
			.':'.str_pad(intval(($ts % 3600) / 60), 2, '0', STR_PAD_LEFT)
			.':'.str_pad(intval($ts % 60), 2, '0', STR_PAD_LEFT);
	}

	/**
	 * Parses the client ISO string into a date-aware event description for the given employee.
	 *
	 * The absolute instant is taken straight from the ISO (which carries its own offset), then it is
	 * re-expressed in the EMPLOYEE's real IANA zone on the event date: DATE (site format) and TIME
	 * (wall-seconds from midnight in that zone). The downstream date-aware write-path rebuilds the same
	 * absolute instant from this pair, so open and close are symmetric and there is no call-moment
	 * offset correction (the legacy correctTimeOffset() step is gone). The raw ISO offset is returned
	 * too for reference but is no longer applied to TIME.
	 *
	 * @param string $isoTime
	 * @param int $userId employee whose IANA zone interprets the wall-time
	 * @return array{DATE:string,TIME:int,OFFSET:int,TIMESTAMP:int}
	 */
	protected static function convertTimeFromISO($isoTime, $userId)
	{
		global $DB;

		$correctedIsoTime = str_replace(' ', '+', trim($isoTime));

		$date = \DateTime::createFromFormat(\DateTime::ATOM, $correctedIsoTime);
		if(!$date)
		{
			throw new RestException(
				'Wrong datetime format',
				DateTimeException::ERROR_WRONG_DATETIME_FORMAT
			);
		}

		$timeHelper = \Bitrix\Timeman\Helper\TimeHelper::getInstance();
		$timestamp = $date->getTimestamp();

		// Re-express the absolute instant in the employee's real IANA zone on the event date.
		$eventDateTime = (new \DateTime('@' . $timestamp))
			->setTimezone($timeHelper->getUserDateTimeZone((int)$userId));

		return array(
			'DATE' => $eventDateTime->format($DB->DateFormatToPHP(FORMAT_DATE)),
			'TIME' => $timeHelper->getSecondsFromDateTime($eventDateTime),
			'OFFSET' => $date->getOffset(),
			'TIMESTAMP' => $timestamp,
		);
	}

	protected static function setDayGeoPosition($entryId, $query, $action = 'open')
	{
		$updateFields = array(
			'LAT_'.mb_strtoupper($action) => isset($query['LAT']) ? doubleval($query['LAT']) : '',
			'LON_'.mb_strtoupper($action) => isset($query['LON']) ? doubleval($query['LON']) : '',
		);

		\CTimeManEntry::Update($entryId, $updateFields);
		static::getUserInstance($query)->GetCurrentInfo(true);
	}

	protected static function checkDate(array $timeInfo, $compareDate)
	{
		return $timeInfo['DATE'] === $compareDate;
	}

	protected static function isAdmin()
	{
		if ($GLOBALS['USER']->IsAdmin())
			return true;

		if (\Bitrix\Main\Loader::includeModule('bitrix24'))
		{
			if (\CBitrix24::IsPortalAdmin($GLOBALS['USER']->GetID()))
			{
				return true;
			}
			else if (\CBitrix24::isIntegrator($GLOBALS['USER']->GetID()))
			{
				return true;
			}
		}

		return false;
	}
}
