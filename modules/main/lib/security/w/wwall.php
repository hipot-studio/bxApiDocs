<?php

namespace Bitrix\Main\Security\W;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Security\PublicKeyCipher;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Security\W\Rules\Rule;
use Bitrix\Main\Security\W\Rules\Results\RuleAction;
use Bitrix\Main\Security\W\Rules\Results\RuleResult;
use Bitrix\Main\Security\W\Rules\Results\CheckResult;
use Bitrix\Main\Security\W\Rules\Results\ModifyResult;
use Bitrix\Main\Type\ArrayHelper;
use Bitrix\Main\Security\W\Rules\RuleRecordTable;


class WWall
{
	const CACHE_RULES_TTL = 10800;

	private static $ACTUAL_RULES_URI = 'https://wwall.bitrix.info/rules.php';

	protected $logEvents = true;

	public function handle()
	{
		try
		{
			// check for lock
			$cache = Cache::createInstance();
			$cacheStarted = false;

			if ($cache->initCache(static::CACHE_RULES_TTL, 'WWALL_LOCK', 'security'))
			{
				$time = $cache->getVars();

				if (time() - $time > 20)
				{
					// emergency reset
					$connection = Application::getConnection();
					$tableName = RuleRecordTable::getTableName();

					$connection->truncateTable($tableName);
					RuleRecordTable::cleanCache();

					$cache->clean('WWALL_LOCK', 'security');
				}
			}
			elseif ($cache->startDataCache())
			{
				// set lock
				$cache->endDataCache(time());
				$cacheStarted = true;
			}

			// apply rules
			$ruleRecords = RuleRecordTable::getList([
				'cache' => ['ttl' => 3600 * 24 * 7]
			])->fetchAll();

			foreach ($ruleRecords as $ruleRecord)
			{
				$cipher = new PublicKeyCipher;
				$cleanData = $cipher->decrypt($ruleRecord['DATA'], static::getPublicKey());

				if (!str_starts_with($cleanData, '{"'))
				{
					continue;
				}

				$data = json_decode($cleanData, true);

				if (!empty($data))
				{
					$rule = Rule::make($data);

					$results = $this->handleRule($rule);
					$this->applyHandlingResults($results);
				}
			}

			// release lock
			if ($cacheStarted)
			{
				$cache->clean('WWALL_LOCK', 'security');
			}
		}
		catch (\Throwable $e)
		{
			$this->logEvent(
				'SECURITY_WWALL_EXCEPTION',
				'FAIL_CHECKING',
				'Can not execute wwall rules: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString()
			);
		}
	}

	/**
	 * @param Rule $rule
	 * @return HandlingResult[]
	 */
	public function handleRule(Rule $rule): array
	{
		$results = [];

		if ($rule->matchPath($_SERVER['REQUEST_URI']))
		{
			// get context arrays
			$contextElements = $this->getContextElements($rule->getContext());

			foreach ($contextElements as $contextName => &$contextElement)
			{
				$results = array_merge($results,
					$this->recursiveContextKeyHandle($contextName, $contextElement, [], $rule)
				);
			}
		}

		return $results;
	}

	/**
	 * @param HandlingResult[] $results
	 * @return void
	 */
	public function applyHandlingResults(array $results)
	{
		$contextElements = $this->getContextElements([
			'get', 'post', 'cookie', 'request', 'global'
		]);

		foreach ($results as $result)
		{
			$contextElement =& $contextElements[$result->getContextName()];
			$ruleResult = $result->getRuleResult();
			$rule = $result->getRule();

			if ($ruleResult instanceof ModifyResult)
			{
				if ($rule->getProcess() === 'keys')
				{
					// rewrite key
					static::rewriteContextKey(
						$result->getContextName(),
						$contextElement,
						$result->getContextKey(),
						$ruleResult->getCleanValue()
					);
				}
				elseif ($rule->getProcess() === 'values')
				{
					static::rewriteContextValue(
						$result->getContextName(),
						$contextElement,
						$result->getContextKey(),
						$ruleResult->getCleanValue()
					);
				}

				$this->logEvent(
					'SECURITY_WWALL_MODIFY',
					$result->getContextName(),
					join('.', $result->getContextKey())
				);
			}
			elseif ($ruleResult instanceof CheckResult && !$ruleResult->isSuccess())
			{
				if ($ruleResult->getAction() === RuleAction::UNSET)
				{
					static::unsetContextValue(
						$result->getContextName(),
						$contextElement,
						$result->getContextKey(),
					);

					$this->logEvent(
						'SECURITY_WWALL_UNSET',
						$result->getContextName(),
						join('.', $result->getContextKey())
					);
				}
				elseif ($ruleResult->getAction() === RuleAction::EXIT)
				{
					$this->logEvent(
						'SECURITY_WWALL_EXIT',
						$result->getContextName(),
						join('.', $result->getContextKey())
					);

					exit;
				}
			}
		}
	}

	public function disableEventLogging()
	{
		$this->logEvents = false;
	}

	protected function rewriteContextKey($contextName, &$contextElement, $oldFullKey, $newKey)
	{
		$newFullKey = $oldFullKey;

		// replace last element
		array_pop($newFullKey);
		$newFullKey[] = $newKey;


		if ($contextName === 'global')
		{
			$globalName = array_shift($oldFullKey);
			array_shift($newFullKey);

			if (empty($oldFullKey))
			{
				$GLOBALS[$newKey] = $GLOBALS[$globalName];
				unset($GLOBALS[$globalName]);
			}
			else
			{
				$contextElement =& $GLOBALS[$globalName];

				$value = ArrayHelper::getByNestedKey($contextElement, $oldFullKey);

				// set value with new key
				ArrayHelper::setByNestedKey($contextElement, $newFullKey, $value);

				// unset old key
				ArrayHelper::unsetByNestedKey($contextElement, $oldFullKey);
			}
		}
		else
		{
			$value = ArrayHelper::getByNestedKey($contextElement, $oldFullKey);

			// set value with new key
			ArrayHelper::setByNestedKey($contextElement, $newFullKey, $value);

			// unset old key
			ArrayHelper::unsetByNestedKey($contextElement, $oldFullKey);
		}
	}

	protected function rewriteContextValue($contextName, &$contextElement, $fullKey, $value)
	{
		if ($contextName === 'global')
		{
			$globalName = array_shift($fullKey);

			if (empty($fullKey))
			{
				$GLOBALS[$globalName] = $value;
			}
			else
			{
				$contextElement =& $GLOBALS[$globalName];
				ArrayHelper::setByNestedKey($contextElement, $fullKey, $value);
			}
		}
		else
		{
			// set new value with new key
			ArrayHelper::setByNestedKey($contextElement, $fullKey, $value);
		}
	}

	protected function unsetContextValue($contextName, &$contextElement, $fullKey)
	{
		if ($contextName === 'global')
		{
			$globalName = array_shift($fullKey);

			if (empty($fullKey))
			{
				unset($GLOBALS[$globalName]);
			}
			else
			{
				$contextElement =& $GLOBALS[$globalName];
				ArrayHelper::unsetByNestedKey($contextElement, $fullKey);
			}
		}
		else
		{
			ArrayHelper::unsetByNestedKey($contextElement, $fullKey);
		}
	}

	/**
	 * @param string $contextName
	 * @param array $contextElement
	 * @param array $baseKey
	 * @param Rule $rule
	 * @return HandlingResult[]
	 */
	protected function recursiveContextKeyHandle(string $contextName, array &$contextElement, array $baseKey, Rule $rule): array
	{
		/** @var HandlingResult[] $results */
		$results = [];

		foreach ($contextElement as $key => $value)
		{
			$fullKey = array_merge($baseKey, [$key]);

			if ($rule->matchKey($fullKey))
			{
				// evaluation
				if ($rule->getProcess() === 'keys')
				{
					$ruleResult = $rule->evaluate($key);
				}
				elseif ($rule->getProcess() === 'values')
				{
					$ruleResult = $rule->evaluateValue($value);
				}

				// collect results
				if (!empty($ruleResult) && $ruleResult instanceof RuleResult)
				{
					$results[] = new HandlingResult($contextName, $fullKey, $ruleResult, $rule);
				}
			}

			// recursive call for sub arrays
			if (is_array($value))
			{
				$results = array_merge($results, $this->recursiveContextKeyHandle(
					$contextName,
					$contextElement[$key],
					$fullKey,
					$rule
				));
			}
		}

		return $results;
	}

	protected function getContextElements(array $contextNames)
	{
		$elements = [];

		if (in_array('get', $contextNames, true))
		{
			$elements['get'] = &$_GET;
		}

		if (in_array('post', $contextNames, true))
		{
			$elements['post'] = &$_POST;
		}

		if (in_array('cookie', $contextNames, true))
		{
			$elements['cookie'] = &$_COOKIE;
		}

		if (in_array('request', $contextNames, true))
		{
			$elements['request'] = &$_REQUEST;
		}

		if (in_array('global', $contextNames, true))
		{
			$elements['global'] = $GLOBALS;
		}

		return $elements;
	}

	public static function refreshRules()
	{
		try
		{
			$lastTime = Option::get('main_sec', 'WWALL_ACTUALIZE_RULES', 0);

			if ((time() - $lastTime) < static::CACHE_RULES_TTL)
			{
				return;
			}

			Option::set('main_sec', 'WWALL_ACTUALIZE_RULES', time());

			$newRules = null;

			// get modules versions
			$modules = array_map(function ($module) {
				return ['v' => $module['version'], 'i' => (int) $module['isInstalled']];
			}, ModuleManager::getModulesFromDisk());

			// get actual rules
			$http = new HttpClient([
				'socketTimeout' => 5,
				'streamTimeout' => 5
			]);

			$response = $http->post(
				static::$ACTUAL_RULES_URI,
				[
					'modules' => json_encode($modules),
					'license' => Application::getInstance()->getLicense()->getHashLicenseKey()
				]
			);

			if ($http->getStatus() == 200 && !empty($response))
			{
				$newRules = Json::decode($response);
			}

			//update db
			if ($newRules !== null)
			{
				$connection = Application::getConnection();
				$tableName = RuleRecordTable::getTableName();

				if (!empty($newRules))
				{
					foreach ($newRules as $newRule)
					{
						if (!static::checkRuleSign($newRule))
						{
							throw new SystemException('Invalid sign for rule '.json_encode($newRule));
						}
					}
				}

				// remove current data
				$connection->truncateTable($tableName);

				// prepare new data
				if (!empty($newRules))
				{
					$records = [];
					foreach ($newRules as $newRule)
					{
						$records[] = "('" .
							$connection->getSqlHelper()->forSql($newRule['data'])
							. "', '" . $connection->getSqlHelper()->forSql($newRule['module'])
							. "', '" . $connection->getSqlHelper()->forSql($newRule['module_version'])
							. "')";
					}

					$recordsSql = join(", ", $records);

					// save new data
					$connection->query("INSERT INTO {$tableName} (DATA, MODULE, MODULE_VERSION) VALUES {$recordsSql}");

					// clean entity cache
					RuleRecordTable::cleanCache();
				}
			}
		}
		catch (\Throwable $e)
		{
			\CEventLog::log(
				\CEventLog::SEVERITY_SECURITY,
				'SECURITY_WWALL_EXCEPTION',
				'main',
				'FAIL_REFRESHING',
				'Can not refresh wwall rules: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString()
			);
		}
	}

	protected static function checkRuleSign($rule)
	{
		$cipher = new PublicKeyCipher;
		$data = $cipher->decrypt($rule['data'], static::getPublicKey());

		return str_starts_with($data, '{"');
	}

	private static function getPublicKey()
	{
		$s = '';
		$s .= '-----BEGIN PUBLIC KEY-----';

		$s .= '
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAq8QE0HjmHJUStWV6n0za
RVoLx02KzbfrbS/P6sWaxTzw8SeGTtbTCOrpHi5QF6ORyjZ/Xxz/KLU1Gbof9CZ3
4z7SkqUt66ibXvOFBx4fw/APPRGDqtm0nD3fgGsu3RePgw29i8+vm7mtBKJUYl4r
Vpb6sfZET9KEb6T1HDYmEvc1hq/iiuyxLrZZi5Q6Uff4UEvTI+68ssFRkQ+owTRy
eOIMbFhM/UTmfVYbTRFy2oUQ8WMza2nJ5Sahzi1UKO1jAjXTPRrzc7Aju639j1O0
ppqfm5xgWlFAJkHQTgbdd5AWqDFQkt9HKkY+TnfBLGVMvVyPwTHNWQYAw4xpg/wA
ZwIDAQAB
-----END PUBLIC KEY-----';

		return $s;
	}

	protected function logEvent($auditTypeId, $itemId, $description)
	{
		if ($this->logEvents)
		{
			\CEventLog::log(
				\CEventLog::SEVERITY_SECURITY,
				$auditTypeId,
				'main',
				$itemId,
				$description
			);
		}
	}
}