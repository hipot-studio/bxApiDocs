<?php
namespace Bitrix\Rest;

use Bitrix\Main;
use Bitrix\Main\ORM\Data\Internal\MergeTrait;
use Bitrix\Rest\Internal\Entity\Application\AppInstallCompletionMode;
use Bitrix\Rest\Preset\EventController;
use Bitrix\Main\ORM\Fields\ArrayField;

/**
 * Class EventTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> APP_ID int mandatory
 * <li> EVENT_NAME string(255) mandatory
 * <li> EVENT_HANDLER string(255) mandatory
 * <li> USER_ID int optional
 * <li> OPTIONS array optional
 * </ul>
 *
 * @package Bitrix\Rest
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Event_Query query()
 * @method static EO_Event_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Event_Result getById($id)
 * @method static EO_Event_Result getList(array $parameters = [])
 * @method static EO_Event_Entity getEntity()
 * @method static \Bitrix\Rest\EO_Event createObject($setDefaultValues = true)
 * @method static \Bitrix\Rest\EO_Event_Collection createCollection()
 * @method static \Bitrix\Rest\EO_Event wakeUpObject($row)
 * @method static \Bitrix\Rest\EO_Event_Collection wakeUpCollection($rows)
 */
class EventTable extends Main\Entity\DataManager
{
	use MergeTrait;

	const ERROR_EVENT_NOT_FOUND = 'ERROR_EVENT_NOT_FOUND';

	const TYPE_ONLINE = 'online';
	const TYPE_OFFLINE = 'offline';

	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_rest_event';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap()
	{
		return array(
			'ID' => array(
				'data_type' => 'integer',
				'primary' => true,
				'autocomplete' => true,
			),
			'APP_ID' => array(
				'data_type' => 'integer',
			),
			'EVENT_NAME' => array(
				'data_type' => 'string',
				'required' => true,
			),
			'EVENT_HANDLER' => array(
				'data_type' => 'string',
			),
			'USER_ID' => array(
				'data_type' => 'integer',
			),
			'TITLE' => array(
				'data_type' => 'string'
			),
			'COMMENT' => array(
				'data_type' => 'string'
			),
			'DATE_CREATE' => array(
				'data_type' => 'datetime'
			),
			'APPLICATION_TOKEN' => array(
				'data_type' => 'string'
			),
			'CONNECTOR_ID' => array(
				'data_type' => 'string'
			),
			'INTEGRATION_ID' => array(
				'data_type' => 'integer',
			),
			'OPTIONS' => new ArrayField('OPTIONS'),
			'REST_APP' => array(
				'data_type' => 'Bitrix\Rest\AppTable',
				'reference' => array('=this.APP_ID' => 'ref.ID'),
			),

			/**
			 * @deprecated
			 * Use REST_APP
			 */
			'APP' => array(
				'data_type' => 'Bitrix\Bitrix24\AppsTable',
				'reference' => array('=this.APP_ID' => 'ref.ID'),
			),
			(new Main\ORM\Fields\ArrayField('JSON_OPTIONS'))
				->configureNullable()
				->configureSerializationJson(),
			(new Main\ORM\Fields\IntegerField('VERSION'))
				->configureRequired()
				->addValidator(new Main\ORM\Fields\Validators\RangeValidator(0, 255))
				->configureDefaultValue(1),
		);
	}

	/**
	 * Removes all application event handlers.
	 *
	 * @param int $appId Application ID.
	 *
	 * @return Main\DB\Result
	 */
	public static function deleteByApp($appId)
	{
		$connection = Main\Application::getConnection();
		return $connection->query("DELETE FROM ".static::getTableName()." WHERE APP_ID='".intval($appId)."'");
	}

	/**
	 * Removes application install event handler.
	 *
	 * @param int $appId Application ID.
	 *
	 * @return Main\DB\Result
	 */
	public static function deleteAppInstaller($appId)
	{
		return static::deleteByAppAndName($appId, 'ONAPPINSTALL');
	}

	/**
	 * Removes application event handlers of the given name.
	 *
	 * @param int $appId Application ID.
	 * @param string $eventName Event name.
	 *
	 * @return Main\DB\Result
	 */
	public static function deleteByAppAndName($appId, string $eventName)
	{
		$connection = Main\Application::getConnection();
		$helper = $connection->getSqlHelper();

		return $connection->query(
			"DELETE FROM " . static::getTableName()
			. " WHERE APP_ID='" . intval($appId) . "'"
			. " AND EVENT_NAME='" . $helper->forSql($eventName) . "'"
		);
	}

	/**
	 * Keeps install-time event handlers (ONAPPINSTALL / ONAPPUSERREADY) in sync with
	 * the current completion mode and handler URL. Used by every install path
	 * (marketplace, preset integrations, legacy CRestUtil) so mode transitions behave the same.
	 *
	 * ONAPPINSTALL is registered only for immediate completion with a handler URL and dropped
	 * otherwise: keeping a stale handler after immediate -> deferred would leak fresh OAuth
	 * tokens to an outdated endpoint once the deferred install finishes.
	 * ONAPPUSERREADY tracks the current handler URL regardless of the mode.
	 *
	 * @param int $appId Application ID.
	 * @param AppInstallCompletionMode $mode Resolved completion mode.
	 * @param string|null $urlInstall Current install handler URL.
	 *
	 * @return Main\Result
	 */
	public static function syncInstallEventHandlers(
		int $appId,
		AppInstallCompletionMode $mode,
		?string $urlInstall
	): Main\Result
	{
		$result = new Main\Result();
		$url = trim((string)$urlInstall);

		static::deleteAppInstaller($appId);
		if ($mode === AppInstallCompletionMode::Immediate && $url !== '')
		{
			// checkCallback is already called inside checkFields
			$installResult = static::add([
				'APP_ID' => $appId,
				'EVENT_NAME' => 'ONAPPINSTALL',
				'EVENT_HANDLER' => $url,
			]);
			if ($installResult->isSuccess())
			{
				\Bitrix\Rest\Event\Sender::bind('rest', 'OnRestAppInstall');
			}
			else
			{
				$result->addErrors($installResult->getErrors());
			}
		}

		static::deleteByAppAndName($appId, 'ONAPPUSERREADY');
		if ($url !== '')
		{
			// checkCallback is already called inside checkFields
			$userReadyResult = static::add([
				'APP_ID' => $appId,
				'EVENT_NAME' => 'ONAPPUSERREADY',
				'EVENT_HANDLER' => $url,
			]);
			if ($userReadyResult->isSuccess())
			{
				\Bitrix\Rest\Event\Sender::bind('rest', 'OnRestAppUserReady');
			}
			else
			{
				$result->addErrors($userReadyResult->getErrors());
			}
		}

		return $result;
	}

	/**
	 * @deprecated
	 *
	 * Use \Bitrix\Rest\HandlerHelper::checkCallback
	 */
	public static function checkCallback($eventCallback, $appInfo, $checkInstallUrl = true)
	{
		return \Bitrix\Rest\HandlerHelper::checkCallback($eventCallback, $appInfo, $checkInstallUrl);
	}

	public static function onBeforeUpdate(Main\Entity\Event $event)
	{
		return static::checkUniq($event);
	}

	public static function onBeforeAdd(Main\Entity\Event $event)
	{
		return static::checkUniq($event);
	}

	public static function bind($eventName)
	{
		$provider = new \CRestProvider();
		$restDescription = $provider->getDescription();
		foreach($restDescription as $scope => $scopeDescription)
		{
			if (!empty($scopeDescription[\CRestUtil::EVENTS][$eventName]))
			{
				\Bitrix\Rest\Event\Sender::bind(
					$scopeDescription[\CRestUtil::EVENTS][$eventName][0],
					$scopeDescription[\CRestUtil::EVENTS][$eventName][1]
				);

				break;
			}
		}
	}

	public static function onAfterAdd(Main\Entity\Event $event)
	{
		$result = new Main\Entity\EventResult();

		$fields = $event->getParameter('fields');
		static::bind($fields['EVENT_NAME']);

		EventController::onAfterAddEvent($event);

		return $result;
	}

	public static function onAfterUpdate(Main\Entity\Event $event)
	{
		$result = new Main\Entity\EventResult();

		$fields = $event->getParameter('fields');
		static::bind($fields['EVENT_NAME']);

		return $result;
	}

	protected static function checkUniq(Main\Entity\Event $event)
	{
		$result = new Main\Entity\EventResult();
		$data = $event->getParameter("fields");

		$dbRes = static::getList(array(
			'filter' => array(
				'=APP_ID' => $data['APP_ID'],
				'=EVENT_NAME' => $data['EVENT_NAME'],
				'=EVENT_HANDLER' => $data['EVENT_HANDLER'],
				'=USER_ID' => $data['USER_ID'] ?? 0,
				'=CONNECTOR_ID' => $data['CONNECTOR_ID'] ?? '',
				'=VERSION' => $data['VERSION'] ?? 1,
			),
			'select' => array('ID')
		));

		if($dbRes->fetch())
		{
			$result->addError(new Main\Entity\EntityError(
				"Handler already binded"
			));
		}

		return $result;
	}
}
