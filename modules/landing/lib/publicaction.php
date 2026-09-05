<?php
namespace Bitrix\Landing;

use Bitrix\Main\Application;
use Bitrix\Rest\AppTable;
use Bitrix\Landing\Site\Type;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Diag\ExceptionHandlerFormatter;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

class PublicAction
{
	/**
	 * Scope for REST (default commands).
	 */
	const REST_SCOPE_DEFAULT = 'landing';

	/**
	 * Scope for REST (cloud repo commands).
	 */
	const REST_SCOPE_CLOUD = 'landing_cloud';

	/**
	 * Code indicating used blocks in REST statistics.
	 */
	public const REST_USAGE_TYPE_BLOCK = 'blocks';

	/**
	 * Code indicating used pages in REST statistics.
	 */
	public const REST_USAGE_TYPE_PAGE = 'pages';

	/**
	 * Max count of commands in one batch request.
	 */
	public const MAX_BATCH_ITEMS = 500;

	/**
	 * Event log type for exceptions thrown by actions.
	 */
	private const LOG_TYPE_ACTION_EXCEPTION = 'LANDING_ACTION_EXCEPTION';

	/**
	 * How long the same exception is logged only once, in seconds.
	 */
	private const LOG_THROTTLE_TTL = 600;

	/**
	 * Cache dir keeping the marks of already logged exceptions.
	 */
	private const LOG_THROTTLE_CACHE_DIR = '/landing/action_exception_log';

	/**
	 * Exceptions already handled by the logger within the current hit.
	 * @var array<string, true>
	 */
	private static array $loggedExceptionKeys = [];

	/**
	 * REST application.
	 * @var array
	 */
	protected static $restApp = null;

	/**
	 * Raw data from waf.
	 * @var mixed
	 */
	protected static $rawData = null;

	/**
	 * Get full namespace for public classes.
	 * @return string
	 */
	protected static function getNamespacePublicClasses()
	{
		return __NAMESPACE__ . '\\PublicAction';
	}

	/**
	 * Get info about method - class/method/params.
	 * @param string $action Full name of action (\Namespace\Class::method).
	 * @param array $data Array of data.
	 * @return array
	 * @throws \ReflectionException
	 */
	protected static function getMethodInfo($action, $data = array())
	{
		$info = array();

		// if action exist and is callable
		if ($action && mb_strpos($action, '::'))
		{
			$actionOriginal = $action;
			$action = self::getNamespacePublicClasses() . '\\' . $action;
			if (is_callable(explode('::', $action)))
			{
				[$class, $method] = explode('::', $action);
				$info = array(
					'action' => $actionOriginal,
					'class' => $class,
					'method' => $method,
					'params_init' => array(),
					'params_missing' => array()
				);
				// parse func params
				$reflection = new \ReflectionMethod($class, $method);
				$static = $reflection->getStaticVariables();
				$mixedParams = isset($static['mixedParams'])
								? $static['mixedParams']
								: [];
				foreach ($reflection->getParameters() as $param)
				{
					$name = $param->getName();
					if (isset($data[$name]))
					{
						if (!in_array($name, $mixedParams))
						{
							if (
								$param->isArray() &&
								!is_array($data[$name])
								||
								!$param->isArray() &&
								is_array($data[$name])

							)
							{
								throw new \Bitrix\Main\ArgumentTypeException(
									$name
								);
							}
						}
						$info['params_init'][$name] = $data[$name];
					}
					elseif ($param->isDefaultValueAvailable())
					{
						$info['params_init'][$name] = $param->getDefaultValue();
					}
					else
					{
						$info['params_missing'][] = $name;
					}
				}
			}
		}

		return $info;
	}

	/**
	 * Get info about method, converting invalid argument type into a regular error.
	 * @param string $action Full name of action (\Namespace\Class::method).
	 * @param array $data Array of data.
	 * @param Error $error Error collector.
	 * @return array
	 * @throws \ReflectionException
	 */
	private static function getMethodInfoSafe($action, array $data, Error $error): array
	{
		try
		{
			return self::getMethodInfo($action, $data);
		}
		catch (\Bitrix\Main\ArgumentTypeException $e)
		{
			$error->addError(
				'TYPE_ERROR',
				Loc::getMessage('LANDING_ARGUMENT_TYPE_ERROR', [
					'#PARAM#' => $e->getParameter()
				])
			);

			return [];
		}
	}

	/**
	 * Returns true if current user out of the extranet.
	 * In extranet case allowed only GROUP scope.
	 *
	 * @return bool
	 */
	protected static function checkForExtranet(): bool
	{
		if (\Bitrix\Landing\Manager::isAdmin())
		{
			return true;
		}

		if (Type::getCurrentScopeId() === Type::SCOPE_CODE_GROUP)
		{
			return true;
		}

		if (\Bitrix\Main\Loader::includeModule('extranet'))
		{
			return \CExtranet::isIntranetUser(
				\CExtranet::getExtranetSiteID(),
				\Bitrix\Landing\Manager::getUserId()
			);
		}

		return true;
	}

	/**
	 * Processing the AJAX/REST action.
	 * @param string $action Action name.
	 * @param mixed $data Data.
	 * @param boolean $isRest Is rest call.
	 * @return array|null
	 * @throws \ReflectionException
	 */
	protected static function actionProcessing($action, $data, $isRest = false)
	{
		if (!is_array($data))
		{
			$data = array();
		}

		// the scope is process-static and a batch runs all its commands in a single PHP process,
		// so a command that passes no scope must not inherit the one left by its neighbour.
		// Both gateways (ajaxProcessing and restGateway) come through here, so this is the only
		// place where the scope of a command is decided; ajaxProcessing puts the request-level
		// baseline into the data of every command it dispatches.
		if (isset($data['scope']) && is_string($data['scope']) && $data['scope'] !== '')
		{
			Type::setScope($data['scope']);
		}
		else
		{
			Type::clearScope();
		}

		$error = new Error;

		// not for guest
		if (!Manager::getUserId() || !self::checkForExtranet())
		{
			$error->addError(
				'ACCESS_DENIED',
				Loc::getMessage('LANDING_ACCESS_DENIED2')
			);
		}
		// tmp flag for compatibility
		else if (
			ModuleManager::isModuleInstalled('bitrix24') &&
			Manager::getOption('temp_permission_admin_only') &&
			!\CBitrix24::isPortalAdmin(Manager::getUserId())
		)
		{
			$error->addError(
				'ACCESS_DENIED',
				Loc::getMessage('LANDING_ACCESS_DENIED2')
			);
		}
		// check common permission; menu24 of VIBE only controls the navigation, its actions keep their own checks
		else if (
			Type::getCurrentScopeId() !== Type::SCOPE_CODE_VIBE &&
			!Rights::hasAdditionalRight(
				Rights::ADDITIONAL_RIGHTS['menu24'],
				null,
				true
			)
		)
		{
			$error->addError(
				'ACCESS_DENIED',
				Loc::getMessage('LANDING_ACCESS_DENIED2')
			);
		}
		else if (!Manager::isB24() && Manager::getApplication()->getGroupRight('landing') < 'W')
		{
			$error->addError(
				'ACCESS_DENIED',
				Loc::getMessage('LANDING_ACCESS_DENIED2')
			);
		}
		// if method::action exist in PublicAction, call it
		elseif (($action = self::getMethodInfoSafe($action, $data, $error)))
		{
			if (!$isRest && !check_bitrix_sessid())
			{
				$error->addError(
					'SESSION_EXPIRED',
					Loc::getMessage('LANDING_SESSION_EXPIRED')
				);
			}
			if (!empty($action['params_missing']))
			{
				$error->addError(
					'MISSING_PARAMS',
					Loc::getMessage('LANDING_MISSING_PARAMS', array(
						'#MISSING#' => implode(', ', $action['params_missing'])
					))
				);
			}
			if (method_exists($action['class'], 'init'))
			{
				$result = call_user_func_array(
					array($action['class'], 'init'),
					[]
				);
				if (!$result->isSuccess())
				{
					$error->copyError($result->getError());
				}
			}
			// all right - execute
			if ($error->isEmpty())
			{
				try
				{
					$result = call_user_func_array(
						array($action['class'], $action['method']),
						$action['params_init']
					);
					// answer
					if ($result === null)// void is accepted as success
					{
						return array(
							'type' => 'success',
							'result' => true
						);
					}
					else if ($result->isSuccess())
					{
						$restResult = $result->getResult();
						$event = new \Bitrix\Main\Event('landing', 'onSuccessRest', [
							'result' => $restResult,
							'action' => $action
						]);
						$event->send();
						foreach ($event->getResults() as $eventResult)
						{
							if (($modified = $eventResult->getModified()))
							{
								if (isset($modified['result']))
								{
									$restResult = $modified['result'];
								}
							}
						}
						return array(
							'type' => 'success',
							'result' => $restResult
						);
					}
					else
					{
						$error->copyError($result->getError());
					}
				}
				catch (\TypeError $e)
				{
					$error->addError(
						'TYPE_ERROR',
						self::processActionException($e, $action, Loc::getMessage('LANDING_TYPE_ERROR'))
					);
				}
				catch (\Exception $e)
				{
					$error->addError(
						'SYSTEM_ERROR',
						self::processActionException($e, $action, Loc::getMessage('LANDING_SYSTEM_ERROR'))
					);
				}
			}
		}
		// error
		$errors = array();
		foreach ($error->getErrors() as $error)
		{
			$errors[] = array(
				'error' => $error->getCode(),
				'error_description' => $error->getMessage()
			);
		}
		if (!$isRest)
		{
			return [
				'sessid' => bitrix_sessid(),
				'type' => 'error',
				'result' => $errors
			];
		}
		else
		{
			return [
				'type' => 'error',
				'result' => $errors
			];
		}
	}

	/**
	 * Writes the exception to the event log and returns the message allowed for the client.
	 * Exception details are exposed only in debug mode, because they may contain
	 * SQL, class names and file paths.
	 * @param \Throwable $e Exception thrown by the action.
	 * @param array $action Action info from getMethodInfo().
	 * @param string $publicMessage Generic message for the client.
	 * @return string
	 */
	private static function processActionException(\Throwable $e, array $action, string $publicMessage): string
	{
		self::logActionException($e, $action);

		$exceptionHandling = Configuration::getValue('exception_handling');

		return empty($exceptionHandling['debug']) ? $publicMessage : $e->getMessage();
	}

	/**
	 * Writes exception details to the event log, skipping repeats of the same failure.
	 * Never throws: actions fail exactly when the storage is broken, and a failed log
	 * must not replace the generic error with an unhandled exception.
	 * @param \Throwable $e Exception thrown by the action.
	 * @param array $action Action info from getMethodInfo().
	 * @return void
	 */
	private static function logActionException(\Throwable $e, array $action): void
	{
		try
		{
			$key = md5(implode('|', [
				(string)($action['action'] ?? ''),
				get_class($e),
				$e->getFile(),
				(string)$e->getLine(),
			]));

			// one attempt per failure kind per hit, whatever happens below
			if (isset(self::$loggedExceptionKeys[$key]))
			{
				return;
			}
			self::$loggedExceptionKeys[$key] = true;

			$cache = Cache::createInstance();
			$cache->noOutput();
			if (!$cache->startDataCache(self::LOG_THROTTLE_TTL, $key, self::LOG_THROTTLE_CACHE_DIR))
			{
				return;
			}

			static::writeExceptionToLog(
				(string)($action['action'] ?? ''),
				ExceptionHandlerFormatter::format($e),
			);

			// the mark is left only after the record is really written
			$cache->endDataCache(['loggedAt' => time()]);
		}
		catch (\Throwable $logFailure)
		{
			// nothing can be done here: the message for the client matters more
		}
	}

	/**
	 * Writes the record about the exception to the event log.
	 * @param string $itemId Name of the action.
	 * @param string $description Formatted exception.
	 * @return void
	 */
	protected static function writeExceptionToLog(string $itemId, string $description): void
	{
		Debug::log($itemId, $description, self::LOG_TYPE_ACTION_EXCEPTION);
	}

	/**
	 * Get raw data of curring processing.
	 * @return mixed
	 */
	public static function getRawData()
	{
		return self::$rawData;
	}

	/**
	 * Returns the scope which the commands of the current request fall back to.
	 * @param mixed $requestType Root type of the request.
	 * @return string|null
	 */
	protected static function getBaselineScope($requestType): ?string
	{
		if (is_string($requestType) && $requestType !== '')
		{
			return $requestType;
		}

		// the caller (landing.base) may have set the scope before the dispatcher was reached
		return Type::getCurrentScopeId();
	}

	/**
	 * Builds ajax answer with the single error, in the same format as actionProcessing().
	 * @param string $code Error code.
	 * @param string $message Error description.
	 * @return array
	 */
	private static function getAjaxErrorResponse(string $code, string $message): array
	{
		return [
			'sessid' => bitrix_sessid(),
			'type' => 'error',
			'result' => [
				[
					'error' => $code,
					'error_description' => $message
				]
			]
		];
	}

	/**
	 * Listen commands from ajax.
	 * @return array|null
	 * @throws \ReflectionException
	 */
	public static function ajaxProcessing()
	{
		$context = Application::getInstance()->getContext();
		$request = $context->getRequest();
		$files = $request->getFileList()->toArray();
		$postlist = $context->getRequest()->getPostList();

		Type::setScope($request->get('type'));

		// actionProcessing() decides the scope of every command by its own data, so the
		// request-level baseline has to travel with that data
		$baseScope = self::getBaselineScope($request->get('type'));

		// multiple commands
		if (
			$request->offsetExists('batch')
			&& is_array($request->get('batch'))
		)
		{
			$batch = $request->get('batch');
			if (count($batch) > self::MAX_BATCH_ITEMS)
			{
				return self::getAjaxErrorResponse(
					'BATCH_LIMIT_EXCEEDED',
					Loc::getMessage('LANDING_BATCH_LIMIT_EXCEEDED', [
						'#LIMIT#' => self::MAX_BATCH_ITEMS
					])
				);
			}
			// an uploaded file has no addressee among the batch items
			if ($files)
			{
				return self::getAjaxErrorResponse(
					'BATCH_FILES_NOT_ALLOWED',
					Loc::getMessage('LANDING_BATCH_FILES_NOT_ALLOWED')
				);
			}

			$result = array();
			// additional site id detect
			if ($request->offsetExists('site_id'))
			{
				$siteId = $request->get('site_id');
			}
			foreach ($batch as $key => $batchItem)
			{
				if (
					isset($batchItem['action']) &&
					isset($batchItem['data'])
				)
				{
					$batchItem['data'] = (array)$batchItem['data'];
					if ($baseScope !== null && !isset($batchItem['data']['scope']))
					{
						$batchItem['data']['scope'] = $baseScope;
					}
					if (isset($siteId))
					{
						$batchItem['data']['siteId'] = $siteId;
					}
					$rawData = $postlist->getRaw('batch');
					if (isset($rawData[$key]['data']))
					{
						self::$rawData = $rawData[$key]['data'];
					}
					$result[$key] = static::actionProcessing(
						$batchItem['action'],
						$batchItem['data']
					);
				}
			}

			return $result;
		}

		// or single command
		else if (
			$request->offsetExists('action')
			&& $request->offsetExists('data')
			&& is_array($request->get('data'))
		)
		{
			$data = $request->get('data');
			if ($baseScope !== null && !isset($data['scope']))
			{
				$data['scope'] = $baseScope;
			}
			// additional site id detect
			if ($request->offsetExists('site_id'))
			{
				$data['siteId'] = $request->get('site_id');
			}
			if ($files)
			{
				$conflicts = array_intersect(array_keys($files), array_keys($data));
				if ($conflicts)
				{
					return self::getAjaxErrorResponse(
						'FILE_KEY_CONFLICT',
						Loc::getMessage('LANDING_FILE_KEY_CONFLICT', [
							'#KEYS#' => implode(', ', $conflicts)
						])
					);
				}
				foreach ($files as $code => $file)
				{
					$data[$code] = $file;
				}
			}
			$rawData = $postlist->getRaw('data');
			if (isset($rawData['data']))
			{
				self::$rawData = $rawData['data'];
			}
			return static::actionProcessing(
				$request->get('action'),
				$data
			);
		}

		return null;
	}

	/**
	 * Register methods in REST.
	 * @return array
	 * @throws \ReflectionException
	 */
	public static function restBase()
	{
		static $restMethods = array();

		if (empty($restMethods))
		{
			$restMethods[self::REST_SCOPE_DEFAULT] = array();
			$restMethods[self::REST_SCOPE_CLOUD] = array();

			$classes = array(
				self::REST_SCOPE_DEFAULT => array(
					'block', 'site', 'landing', 'repo', 'template',
					'demos', 'role', 'syspage', 'chat', 'repowidget'
				),
				self::REST_SCOPE_CLOUD => array(
					'cloud'
				)
			);

			// then methods list for each class
			foreach ($classes as $scope => $classList)
			{
				foreach ($classList as $className)
				{
					$fullClassName = self::getNamespacePublicClasses() . '\\' . $className;
					$class = new \ReflectionClass($fullClassName);
					$methods = $class->getMethods(\ReflectionMethod::IS_PUBLIC);
					foreach ($methods as $method)
					{
						$static = $method->getStaticVariables();
						if (!isset($static['internal']) || !$static['internal'])
						{
							$command = $scope.'.'.
								mb_strtolower($className).'.'.
								mb_strtolower($method->getName());
							$restMethods[$scope][$command] = array(
								__CLASS__, 'restGateway'
							);
						}
					}
				}
			}
		}

		return array(
			self::REST_SCOPE_DEFAULT => $restMethods[self::REST_SCOPE_DEFAULT],
			self::REST_SCOPE_CLOUD => $restMethods[self::REST_SCOPE_CLOUD]
		);
	}

	/**
	 * Gateway between REST and publicaction.
	 * @param array $fields Rest fields.
	 * @param mixed $t Var.
	 * @param \CRestServer $server Server instance.
	 * @return mixed
	 * @throws \ReflectionException
	 */
	public static function restGateway($fields, $t, $server)
	{
		// get context app
		self::$restApp = AppTable::getByClientId($server->getClientId());
		// prepare method and call action
		$method = $server->getMethod();
		$method = mb_substr($method, mb_strpos($method, '.') + 1);// delete module-prefix
		$method = preg_replace('/\./', '\\', $method, substr_count($method, '.') - 1);
		$method = str_replace('.', '::', $method);
		$result = self::actionProcessing(
			$method,
			$fields,
			true
		);
		// prepare answer
		if ($result['type'] == 'error')
		{
			foreach ($result['result'] as $error)
			{
				throw new \Bitrix\Rest\RestException(
					$error['error_description'],
					$error['error']
				);
			}
		}
		else
		{
			return $result['result'];
		}
	}

	/**
	 * Get current REST application.
	 * @return array
	 */
	public static function restApplication()
	{
		return self::$restApp;
	}

	/**
	 * On REST app delete.
	 * @param array $app App info.
	 * @return void
	 */
	public static function restApplicationDelete($app)
	{
		if (isset($app['APP_ID']) && $app['APP_ID'])
		{
			if (($app = AppTable::getByClientId($app['APP_ID'])))
			{
				Rights::setOff();
				Repo::deleteByAppCode($app['CODE']);
				Placement::deleteByAppId($app['ID']);
				Demos::deleteByAppCode($app['CODE']);
				Rights::setOn();
			}
		}
	}

	/**
	 * Before REST app delete.
	 * @param \Bitrix\Main\Event $event Event data.
	 * @return \Bitrix\Main\EventResult
	 */
	public static function beforeRestApplicationDelete(\Bitrix\Main\Event $event)
	{
		$parameters = $event->getParameters();

		if ($app = AppTable::getByClientId($parameters['ID']))
		{
			$stat = self::getRestStat(true);
			if (isset($stat[self::REST_USAGE_TYPE_BLOCK][$app['CODE']]))
			{
				$eventResult = new \Bitrix\Main\EventResult(
					\Bitrix\Main\EventResult::ERROR,
					new \Bitrix\Main\Error(
						Loc::getMessage('LANDING_REST_DELETE_EXIST_BLOCKS'),
						'LANDING_EXISTS_BLOCKS'
					)
				);

				return $eventResult;
			}
			else if (isset($stat[self::REST_USAGE_TYPE_PAGE][$app['CODE']]))
			{
				$eventResult = new \Bitrix\Main\EventResult(
					\Bitrix\Main\EventResult::ERROR,
					new \Bitrix\Main\Error(
						Loc::getMessage('LANDING_REST_DELETE_EXIST_PAGES'),
						'LANDING_EXISTS_PAGES'
					)
				);

				return $eventResult;
			}
		}
	}

	/**
	 * Gets stat data of using rest app.
	 * @param bool $humanFormat Gets data in human format.
	 * @param bool $onlyActive Gets data only in active states.
	 * @param array $additionalFilter Additional filter array.
	 * @return array
	 */
	public static function getRestStat(bool $humanFormat = false, bool $onlyActive = true, array $additionalFilter = []): array
	{
		$blockCnt = [];
		$fullStat = [
			self::REST_USAGE_TYPE_BLOCK => [],
			self::REST_USAGE_TYPE_PAGE => []
		];
		$activeValues = $onlyActive ? 'Y' : ['Y', 'N'];
		$filter = [
			'=%CODE' => 'repo_%',
			'=DELETED' => 'N',
			'=PUBLIC' => $activeValues,
			'=LANDING.ACTIVE' => $activeValues,
			'=LANDING.SITE.ACTIVE' => $activeValues
		];

		if (isset($additionalFilter['SITE_ID']))
		{
			$filter['LANDING.SITE_ID'] = $additionalFilter['SITE_ID'];
		}

		Rights::setOff();

		// gets all partners active block, placed on pages
		$res = Internals\BlockTable::getList([
			'select' => [
				'CODE', 'CNT'
			],
			'filter' => $filter,
			'group' => [
				'CODE'
			],
			'runtime' => [
				new \Bitrix\Main\Entity\ExpressionField('CNT', 'COUNT(*)')
			]
		]);
		while ($row = $res->fetch())
		{
			$blockCnt[mb_substr($row['CODE'], 5)] = $row['CNT'];
		}

		// gets apps for this blocks
		$res = Repo::getList([
			'select' => [
				'ID', 'APP_CODE'
			],
			'filter' => [
				'ID' => array_keys($blockCnt)
			]
 		]);
		while ($row = $res->fetch())
		{
			if (!$row['APP_CODE'])
			{
				continue;
			}
			if (!isset($fullStat[self::REST_USAGE_TYPE_BLOCK][$row['APP_CODE']]))
			{
				$fullStat[self::REST_USAGE_TYPE_BLOCK][$row['APP_CODE']] = 0;
			}
			$fullStat[self::REST_USAGE_TYPE_BLOCK][$row['APP_CODE']] += $blockCnt[$row['ID']];
		}
		unset($blockCnt);

		// gets additional partners active block with not empty INITIATOR_APP_CODE, placed on pages
		$filter['!CODE'] = $filter['CODE'];
		unset($filter['CODE']);
		$filter['!=INITIATOR_APP_CODE'] = null;
		$res = Internals\BlockTable::getList([
			'select' => [
				'INITIATOR_APP_CODE', 'CNT'
			],
			'filter' => $filter,
			'group' => [
				'INITIATOR_APP_CODE'
			],
			'runtime' => [
				new \Bitrix\Main\Entity\ExpressionField('CNT', 'COUNT(*)')
			]
		]);
		while ($row = $res->fetch())
		{
			$appCode = $row['INITIATOR_APP_CODE'];
			if (!isset($fullStat[self::REST_USAGE_TYPE_BLOCK][$appCode]))
			{
				$fullStat[self::REST_USAGE_TYPE_BLOCK][$appCode] = 0;
			}
			$fullStat[self::REST_USAGE_TYPE_BLOCK][$appCode] += $row['CNT'];
		}

		// gets all partners active pages
		$filter = [
			'=DELETED' => 'N',
			'=ACTIVE' => $activeValues,
			'=SITE.ACTIVE' => $activeValues,
			'!=INITIATOR_APP_CODE' => null
		];
		if (isset($additionalFilter['SITE_ID']))
		{
			$filter['SITE_ID'] = $additionalFilter['SITE_ID'];
		}
		$res = Landing::getList([
			'select' => [
				'INITIATOR_APP_CODE', 'CNT'
			],
			'filter' => $filter,
			'group' => [
				'INITIATOR_APP_CODE'
			],
			'runtime' => [
				new \Bitrix\Main\Entity\ExpressionField('CNT', 'COUNT(*)')
			]
		]);
		while ($row = $res->fetch())
		{
			$appCode = $row['INITIATOR_APP_CODE'];
			if (!isset($fullStat[self::REST_USAGE_TYPE_PAGE][$appCode]))
			{
				$fullStat[self::REST_USAGE_TYPE_PAGE][$appCode] = 0;
			}
			$fullStat[self::REST_USAGE_TYPE_PAGE][$appCode] += $row['CNT'];
		}

		// get client id for apps
		if (!$humanFormat && \Bitrix\Main\Loader::includeModule('rest'))
		{
			$appsCode = array_merge(
				array_keys($fullStat[self::REST_USAGE_TYPE_BLOCK]),
				array_keys($fullStat[self::REST_USAGE_TYPE_PAGE])
			);
			$fullStatNew = [
				self::REST_USAGE_TYPE_BLOCK => [],
				self::REST_USAGE_TYPE_PAGE => []
			];
			if ($appsCode)
			{
				$appsCode = array_unique($appsCode);
				$res = AppTable::getList([
					'select' => [
						'CLIENT_ID', 'CODE'
					],
					'filter' => [
						'=CODE' => $appsCode
					]
				]);
				while ($row = $res->fetch())
				{
					foreach ($fullStat as $code => $stat)
					{
						if (isset($stat[$row['CODE']]))
						{
							$fullStatNew[$code][$row['CLIENT_ID']] = $stat[$row['CODE']];
						}
					}
				}
			}

			return $fullStatNew;
		}

		Rights::setOn();

		return $fullStat;
	}
}