<?php

namespace Bitrix\Sale\Cashbox;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\NotImplementedException;
use Bitrix\Sale\Cashbox\Internals\CashboxTable;
use Bitrix\Sale\PriceMaths;
use Bitrix\Sale\Result;

Loc::loadMessages(__FILE__);

/**
 * Class Cashbox
 * @package Bitrix\Sale\Cashbox
 */
abstract class Cashbox
{
	const UUID_TYPE_CHECK = 'check';
	const UUID_TYPE_REPORT = 'report';
	const UUID_DELIMITER = '|';

	const EVENT_ON_GET_CUSTOM_CASHBOX_HANDLERS = 'OnGetCustomCashboxHandlers';

	protected const MAX_UUID_LENGTH = 100;

	/** @var array $fields */
	private $fields = array();

	private $ofd;

	/**
	 * @throws Main\LoaderException
	 * @return void
	 */
	public static function init()
	{
		$handlers = static::getHandlerList();
		Main\Loader::registerAutoLoadClasses(null, $handlers);
	}

	public static function getCode()
	{
		$className = (new \ReflectionClass(static::class))->getShortName();
		return mb_strtolower($className);
	}

	/**
	 * @return array
	 */
	public static function getHandlerList()
	{
		static $handlerList = array();

		if (!$handlerList)
		{
			$zone = '';
			$isCloud = Main\Loader::includeModule("bitrix24");
			if ($isCloud)
			{
				$zone = \CBitrix24::getLicensePrefix();
			}
			elseif (Main\Loader::includeModule('intranet'))
			{
				$zone = \CIntranetUtils::getPortalZone();
			}
			if ($zone === 'ru' && $isCloud)
			{
				$handlerList = [
					'\Bitrix\Sale\Cashbox\CashboxAtolFarm' => '/bitrix/modules/sale/lib/cashbox/cashboxatolfarm.php',
					'\Bitrix\Sale\Cashbox\CashboxAtolFarmV4' => '/bitrix/modules/sale/lib/cashbox/cashboxatolfarmv4.php',
					'\Bitrix\Sale\Cashbox\CashboxAtolFarmV5' => '/bitrix/modules/sale/lib/cashbox/cashboxatolfarmv5.php',
					'\Bitrix\Sale\Cashbox\CashboxOrangeData' => '/bitrix/modules/sale/lib/cashbox/cashboxorangedata.php',
					'\Bitrix\Sale\Cashbox\CashboxOrangeDataFfd12' => '/bitrix/modules/sale/lib/cashbox/cashboxorangedataffd12.php',
					'\Bitrix\Sale\Cashbox\CashboxBusinessRu' => '/bitrix/modules/sale/lib/cashbox/cashboxbusinessru.php',
					'\Bitrix\Sale\Cashbox\CashboxBusinessRuV5' => '/bitrix/modules/sale/lib/cashbox/cashboxbusinessruv5.php',
				];
			}
			elseif ($zone === 'ua')
			{
				$handlerList = [
					'\Bitrix\Sale\Cashbox\CashboxCheckbox' => '/bitrix/modules/sale/lib/cashbox/cashboxcheckbox.php',
				];
			}
			else
			{
				$handlerList = [
					'\Bitrix\Sale\Cashbox\CashboxAtolFarm' => '/bitrix/modules/sale/lib/cashbox/cashboxatolfarm.php',
					'\Bitrix\Sale\Cashbox\CashboxAtolFarmV4' => '/bitrix/modules/sale/lib/cashbox/cashboxatolfarmv4.php',
					'\Bitrix\Sale\Cashbox\CashboxAtolFarmV5' => '/bitrix/modules/sale/lib/cashbox/cashboxatolfarmv5.php',
					'\Bitrix\Sale\Cashbox\CashboxOrangeData' => '/bitrix/modules/sale/lib/cashbox/cashboxorangedata.php',
					'\Bitrix\Sale\Cashbox\CashboxOrangeDataFfd12' => '/bitrix/modules/sale/lib/cashbox/cashboxorangedataffd12.php',
					'\Bitrix\Sale\Cashbox\CashboxBitrixV2' => '/bitrix/modules/sale/lib/cashbox/cashboxbitrixv2.php',
					'\Bitrix\Sale\Cashbox\CashboxBitrixV3' => '/bitrix/modules/sale/lib/cashbox/cashboxbitrixv3.php',
					'\Bitrix\Sale\Cashbox\CashboxBitrix' => '/bitrix/modules/sale/lib/cashbox/cashboxbitrix.php',
					'\Bitrix\Sale\Cashbox\Cashbox1C' => '/bitrix/modules/sale/lib/cashbox/cashbox1c.php',
					'\Bitrix\Sale\Cashbox\CashboxCheckbox' => '/bitrix/modules/sale/lib/cashbox/cashboxcheckbox.php',
					'\Bitrix\Sale\Cashbox\CashboxBusinessRu' => '/bitrix/modules/sale/lib/cashbox/cashboxbusinessru.php',
					'\Bitrix\Sale\Cashbox\CashboxBusinessRuV5' => '/bitrix/modules/sale/lib/cashbox/cashboxbusinessruv5.php',
				];
			}

			$handlerList['\Bitrix\Sale\Cashbox\CashboxRest'] = '/bitrix/modules/sale/lib/cashbox/cashboxrest.php';

			$handlerList['\Bitrix\Sale\Cashbox\CashboxRobokassa'] = '/bitrix/modules/sale/lib/cashbox/cashboxrobokassa.php';
			$handlerList['\Bitrix\Sale\Cashbox\CashboxYooKassa'] = '/bitrix/modules/sale/lib/cashbox/cashboxyookassa.php';

			$event = new Main\Event('sale', static::EVENT_ON_GET_CUSTOM_CASHBOX_HANDLERS);
			$event->send();
			$resultList = $event->getResults();

			if (is_array($resultList) && !empty($resultList))
			{
				foreach ($resultList as $eventResult)
				{
					/** @var  Main\EventResult $eventResult */
					if ($eventResult->getType() === Main\EventResult::SUCCESS)
					{
						$params = $eventResult->getParameters();
						if (!empty($params) && is_array($params))
							$handlerList = array_merge($handlerList, $params);
					}
				}
			}
		}

		return $handlerList;
	}

	/**
	 * @param array $settings
	 * @return Cashbox|null
	 * @throws Main\LoaderException
	 */
	public static function create(array $settings)
	{
		static::init();

		$handler = $settings['HANDLER'];
		if (class_exists($handler))
			return new $handler($settings);

		return null;
	}

	/**
	 * Base constructor.
	 * @param $settings
	 */
	private function __construct($settings)
	{
		$this->fields = $settings;
	}

	/**
	 * @param $name
	 * @return mixed
	 */
	public function getField($name)
	{
		return $this->fields[$name] ?? null;
	}

	/**
	 * @return Ofd|null
	 */
	public function getOfd()
	{
		if ($this->ofd === null)
		{
			$this->ofd = Ofd::create($this);
		}

		return $this->ofd;
	}

	/**
	 * @param Check $check
	 * @return array
	 */
	abstract public function buildCheckQuery(Check $check);

	/**
	 * @param $id
	 * @return array
	 */
	abstract public function buildZReportQuery($id);

	/**
	 * Rounds a monetary value according to the currency's display precision.
	 *
	 * @param float $value
	 * @param string $currency
	 * @return float
	 */
	protected function roundMoney(float $value, string $currency): float
	{
		if ($currency === '')
		{
			return round($value, 2);
		}

		return PriceMaths::roundByFormatCurrency($value, $currency);
	}

	/**
	 * Adjusts item sums so their total matches the expected total after rounding.
	 *
	 * When $rawSumKey is provided, distributes the difference one smallest
	 * currency unit at a time across items that have the largest rounding
	 * errors, until the total matches exactly.
	 *
	 * @param array $items
	 * @param string $sumKey Key holding the rounded sum.
	 * @param float $expectedTotal
	 * @param string $currency Currency code for rounding; empty defaults to 2 decimal places.
	 * @param string $rawSumKey Key holding the pre-rounded (raw) sum, empty to skip.
	 * @return array
	 */
	protected static function adjustItemsSumToTotal(
		array $items,
		string $sumKey,
		float $expectedTotal,
		string $currency,
		string $rawSumKey
	): array
	{
		if (empty($items))
		{
			return $items;
		}

		$actualTotal = 0.0;
		foreach ($items as $item)
		{
			$actualTotal += (float)$item[$sumKey];
		}

		$diff = PriceMaths::roundByFormatCurrency($expectedTotal - $actualTotal, $currency);
		if ($diff === 0.0)
		{
			return $items;
		}

		// Collect per-item rounding deviations: raw_sum - rounded_sum.
		// Positive deviation means the item was rounded down, negative — rounded up.
		$deviations = [];
		foreach ($items as $index => $item)
		{
			if (!isset($item[$rawSumKey]))
			{
				continue;
			}

			$deviation = (float)$item[$rawSumKey] - (float)$item[$sumKey];
			if (PriceMaths::roundPrecision($deviation) !== 0.0)
			{
				$deviations[] = [
					'index' => $index,
					'valueDifference' => $deviation,
				];
			}
		}
		// Sort by absolute deviation descending — correct the most distorted items first.
		usort($deviations, static function ($a, $b) {
			return abs($b['valueDifference']) <=> abs($a['valueDifference']);
		});

		$isPositiveCorrection = $diff > 0;

		// Distribute correction across items whose rounding deviation
		// direction matches the diff direction (i.e. they were over-rounded
		// in the opposite direction). Each item receives up to its full
		// rounding deviation, capped by the remaining diff.
		foreach ($deviations as $entry)
		{
			if ($diff === 0.0)
			{
				return $items;
			}

			// Only correct items whose rounding deviation has the same sign as diff:
			// diff > 0 means total is short → need items that were rounded DOWN (deviation > 0).
			// diff < 0 means total is over → need items that were rounded UP (deviation < 0).
			if ($isPositiveCorrection && $entry['valueDifference'] > 0 || !$isPositiveCorrection && $entry['valueDifference'] < 0)
			{
				$itemCorrection = $isPositiveCorrection
					? min($entry['valueDifference'], $diff)
					: max($entry['valueDifference'], $diff)
				;

				$itemCorrection = PriceMaths::roundByFormatCurrency($itemCorrection, $currency);
				if ($itemCorrection === 0.0)
				{
					continue;
				}

				$newValue = PriceMaths::roundByFormatCurrency(
					(float)$items[$entry['index']][$sumKey] + $itemCorrection,
					$currency
				);

				if ($newValue >= 0)
				{
					$items[$entry['index']][$sumKey] = $newValue;
					$diff = PriceMaths::roundByFormatCurrency($diff - $itemCorrection, $currency);
				}
			}
		}

		// Fallback: apply whatever remains to the last item.
		if ($diff !== 0.0)
		{
			$lastIndex = array_key_last($items);
			$items[$lastIndex][$sumKey] = PriceMaths::roundByFormatCurrency(
				(float)$items[$lastIndex][$sumKey] + $diff,
				$currency
			);
		}

		return $items;
	}

	/**
	 * Splits a single item if rounded price × quantity doesn't match the expected sum.
	 *
	 * For APIs that calculate sum = price × quantity on their side,
	 * a position may produce a rounding mismatch. This method splits
	 * it into two: one with (quantity − 1) at the rounded price,
	 * and one with quantity = 1 at the remainder price, so the API's own
	 * multiplication yields the correct total.
	 *
	 * Returns an array with one element (original item) if no split is needed,
	 * or two elements (main + remainder) if split was applied.
	 *
	 * @param array $item Single item with price, sum, quantity, currency keys.
	 * @param string $priceKey Key holding the per-unit price.
	 * @param string $sumKey Key holding the expected total for the position.
	 * @param string $quantityKey Key holding the quantity.
	 * @param string $currencyKey Key holding the currency code.
	 * @return array[] Array of one or two items.
	 */
	protected function splitItemForPriceQuantityApi(
		array $item,
		string $priceKey = 'price',
		string $sumKey = 'sum',
		string $quantityKey = 'quantity',
		string $currencyKey = 'currency'
	): array
	{
		$currency = $item[$currencyKey] ?? '';
		$price = $this->roundMoney((float)$item[$priceKey], $currency);
		$quantity = (float)$item[$quantityKey];
		$expectedSum = $this->roundMoney((float)$item[$sumKey], $currency);

		$calculatedSum = $this->roundMoney($price * $quantity, $currency);

		if ($calculatedSum === $expectedSum)
		{
			return [$item];
		}

		if ($quantity <= 1)
		{
			// Cannot split, adjust price so that price * quantity == expectedSum
			$adjustedPrice = $this->roundMoney($expectedSum / $quantity, $currency);
			if ($this->roundMoney($adjustedPrice * $quantity, $currency) === $expectedSum)
			{
				$item[$priceKey] = $adjustedPrice;
				$item[$sumKey] = $expectedSum;
			}

			return [$item];
		}

		// Split: (quantity - 1) at rounded price + 1 at remainder price
		$mainQuantity = $quantity - 1;
		$mainSum = $this->roundMoney($price * $mainQuantity, $currency);
		$remainderPrice = $this->roundMoney($expectedSum - $mainSum, $currency);

		if ($remainderPrice <= 0)
		{
			return [$item];
		}

		$mainItem = $item;
		$mainItem[$quantityKey] = $mainQuantity;
		$mainItem[$sumKey] = $mainSum;

		$remainderItem = $item;
		$remainderItem[$quantityKey] = 1;
		$remainderItem[$priceKey] = $remainderPrice;
		$remainderItem[$sumKey] = $remainderPrice;

		return [$mainItem, $remainderItem];
	}

	/**
	 * @throws NotImplementedException
	 * @return string
	 */
	public static function getName()
	{
		throw new NotImplementedException();
	}

	/**
	 * @param $name
	 * @param $code
	 * @return mixed
	 */
	public function getValueFromSettings($name, $code)
	{
		$map = $this->fields['SETTINGS'];
		if (isset($map[$name]))
		{
			if (!is_array($map[$name]))
			{
				return $map[$name];
			}

			if (isset($map[$name][$code]))
			{
				return $map[$name][$code];
			}
		}

		$settings = static::getSettings($this->getField('KKM_ID'));

		return $settings[$name]['ITEMS'][$code]['VALUE'] ?? null;
	}

	/**
	 * @param array $linkParams
	 * @return string
	 */
	public function getCheckLink(array $linkParams)
	{
		if (!$linkParams)
		{
			return '';
		}

		if (static::isSupportedDirectCheckLink() && isset($linkParams[Check::PARAM_OFD_RECEIPT_URL]))
		{
			return $linkParams[Check::PARAM_OFD_RECEIPT_URL];
		}
		else
		{
			/** @var Ofd $ofd */
			$ofd = $this->getOfd();
			if ($ofd !== null)
			{
				return $ofd->generateCheckLink($linkParams);
			}
		}

		return '';
	}

	/**
	 * @param $errorCode
	 * @throws NotImplementedException
	 * @return int
	 */
	protected static function getErrorType($errorCode)
	{
		throw new NotImplementedException();
	}

	/**
	 * @param array $data
	 * @throws NotImplementedException
	 * @return array
	 */
	protected static function extractCheckData(array $data)
	{
		throw new NotImplementedException();
	}

	/**
	 * @param array $data
	 * @throws NotImplementedException
	 * @return array
	 */
	protected static function extractZReportData(array $data)
	{
		throw new NotImplementedException();
	}

	/**
	 * @param array $data
	 * @return Result
	 */
	public static function applyCheckResult(array $data)
	{
		$result = static::extractCheckData($data);

		$checkId = $result['ID'] ?? 0;

		return CheckManager::savePrintResult($checkId, $result);
	}

	/**
	 * @param array $data
	 * @return Result
	 */
	public static function applyZReportResult(array $data)
	{
		$result = static::extractZReportData($data);

		return ReportManager::saveZReportPrintResult($result['ID'], $result);
	}

	/**
	 * @param int $modelId
	 * @return array
	 */
	public static function getSettings($modelId = 0)
	{
		return array();
	}

	/**
	 * @return Result
	 */
	public function validate()
	{
		$fields = $this->fields;
		unset($fields['OFD_SETTINGS']);

		$result = $this->validateFields($fields);

		$ofd = $this->getOfd();
		if ($ofd)
		{
			$r = $ofd->validate();
			if (!$r->isSuccess())
			{
				$result->addErrors($r->getErrors());
			}
		}

		return $result;
	}

	protected function validateFields($fields)
	{
		$result = new Result();

		foreach ($fields as $code => $value)
		{
			if (is_array($value))
			{
				$r = $this->validateFields($value);
				if (!$r->isSuccess())
				{
					$result->addErrors($r->getErrors());
				}

				continue;
			}

			if (
				$this->isRequiredField($code)
				&& $value === ''
			)
			{
				$requiredFields = $this->getRequiredFields();

				$result->addError(
					new Main\Error(
						Loc::getMessage(
							'SALE_CASHBOX_VALIDATE_ERROR',
							['#FIELD_ID#' => $requiredFields[$code]]
						)
					)
				);
			}
		}

		return $result;
	}

	protected function isRequiredField($field) : bool
	{
		$requiredFields = $this->getRequiredFields();

		return isset($requiredFields[$field]);
	}

	/**
	 * @param Main\HttpRequest $request
	 * @return array
	 */
	public static function extractSettingsFromRequest(Main\HttpRequest $request)
	{
		/** @var array $settings */
		$settings = $request->get('SETTINGS');

		return $settings;
	}

	/**
	 * @param $modelId
	 * @return array
	 */
	private function getRequiredFields()
	{
		$result = static::getGeneralRequiredFields();

		$settings = static::getSettings($this->getField('KKM_ID'));
		foreach ($settings as $groupId => $group)
		{
			foreach ($group['ITEMS'] as $code => $item)
			{
				$isRequired =
					isset($group['REQUIRED']) && $group['REQUIRED'] === 'Y'
					|| isset($item['REQUIRED']) && $item['REQUIRED'] === 'Y'
				;
				if ($isRequired)
				{
					$result[$code] = $item['LABEL'];
				}
			}
		}

		return $result;
	}

	/**
	 * @return array
	 */
	public static function getGeneralRequiredFields()
	{
		$map = CashboxTable::getMap();

		return array(
			'NAME' => $map['NAME']['title'],
			'EMAIL' => $map['EMAIL']['title'],
			'HANDLER' => $map['HANDLER']['title']
		);
	}

	/**
	 * @param $type
	 * @param $id
	 * @return string
	 */
	protected static function buildUuid($type, $id)
	{
		$context = Main\Application::getInstance()->getContext();
		$server = $context->getServer();
		$domain = $server->getServerName();
		$timestamp = time();

		$uuid =
			$type.static::UUID_DELIMITER.
			$domain.static::UUID_DELIMITER.
			$id.static::UUID_DELIMITER.
			$timestamp
		;

		return mb_substr($uuid, 0, static::MAX_UUID_LENGTH);
	}

	/**
	 * @param $uuid
	 * @return array
	 */
	protected static function parseUuid($uuid)
	{
		$info = explode(static::UUID_DELIMITER, $uuid);

		return array('type' => $info[0], 'id' => $info[2]);
	}

	/**
	 * @return array
	 */
	public static function getSupportedKkmModels()
	{
		return array();
	}

	/**
	 * @return bool
	 */
	public function isCheckable()
	{
		return $this instanceof ICheckable;
	}

	/**
	 * @return bool
	 */
	public function isCorrection()
	{
		return (
			$this instanceof ICorrection
			&& $this::isCorrectionOn()
		);
	}

	/**
	 * @return bool
	 */
	public static function isCorrectionOn(): bool
	{
		return true;
	}

	/**
	 * @return float|null
	 */
	public static function getFfdVersion(): ?float
	{
		return null;
	}

	/**
	 * @deprecated Use \Bitrix\Sale\Cashbox\Cashbox::getFfdVersion instead
	 *
	 * @return bool
	 */
	public static function isSupportedFFD105()
	{
		return static::getFfdVersion() >= 1.05;
	}

	protected static function isSupportedDirectCheckLink(): bool
	{
		return false;
	}

	public static function isOfdSettingsNeeded(): bool
	{
		if (static::isSupportedDirectCheckLink())
		{
			return false;
		}

		return true;
	}
}
