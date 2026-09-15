<?php

namespace Sale\Handlers\PaySystem;

use Bitrix\Currency\CurrencyTable;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Bitrix\Main\Web;
use Bitrix\Sale\Payment;
use Bitrix\Sale\PaySystem;
use Bitrix\Sale\PriceMaths;

Loc::loadMessages(__FILE__);

class SberbankOnlineHandler extends PaySystem\ServiceHandler implements PaySystem\IRefund
{
	public function initiatePay(Payment $payment, ?Request $request = null): PaySystem\ServiceResult
	{
		if (!$this->isPaymentValid($payment))
		{
			return $this->error('PAYMENT');
		}

		$result = new PaySystem\ServiceResult();
		$invoiceId = (string)$payment->getField('PS_INVOICE_ID');
		$orderNumber = $this->getOrderNumber($payment);
		$registeredInvoiceId = null;
		if ($invoiceId !== '')
		{
			$status = $this->getOrderStatus($payment);
			if (!$status->isSuccess())
			{
				return $status;
			}

			$data = $status->getData();
			if (!$this->matchesPaymentIdentity($payment, $data)
				|| (!self::isCode($data['orderStatus'] ?? null, 0) && !self::isCode($data['orderStatus'] ?? null, 6))
				|| (!is_int($data['amount'] ?? null) && !is_string($data['amount'] ?? null))
				|| filter_var($data['amount'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
				|| !self::isCode($data['amount'], (int)$data['amount'])
			)
			{
				return $this->error('PAYMENT');
			}

			if (self::isCode($data['orderStatus'], 0)
				&& self::isCode($data['amount'], $this->getMinorAmount($payment->getSum(), $payment->getCurrency()))
			)
			{
				$url = $this->getUrl($payment, 'form') . rawurlencode($invoiceId);
			}
			else
			{
				if ($payment->isPaid())
				{
					return $this->error('PAYMENT');
				}

				$attempt = explode('_', $data['orderNumber'])[2] ?? '0';
				$attempt = filter_var($attempt, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX - 1]]);
				if ($attempt === false)
				{
					return $this->error('PAYMENT');
				}

				$orderNumber .= '_' . ($attempt + 1);
				if (strlen($orderNumber) > 36)
				{
					return $this->error('PAYMENT');
				}
			}
		}
		if (!isset($url))
		{
			if ($payment->isPaid())
			{
				return $this->error('PAYMENT');
			}

			$currencyCode = $this->getCurrencyCode($payment->getCurrency());
			if ($currencyCode === null)
			{
				return $this->error('PAYMENT');
			}

			$result = $this->send($payment, 'register.do', [
				'orderNumber' => $orderNumber,
				'amount' => $this->getMinorAmount($payment->getSum(), $payment->getCurrency()),
				'currency' => $currencyCode,
				'returnUrl' => $this->getReturnUrl($payment, 'RETURN_SUCCESS_URL'),
				'failUrl' => $this->getReturnUrl($payment, 'RETURN_FAIL_URL'),
				'language' => LANGUAGE_ID,
				'description' => $this->getOrderDescription($payment),
				'jsonParams' => [
					'bx_paysystem_code' => $payment->getPaymentSystemId(),
					'bx_label' => '1c_bitrix_sberbankonline',
				],
			]);
			$data = $result->getData();
			if (!$result->isSuccess() || !self::isInvoiceId($data['orderId'] ?? null))
			{
				$recovered = $this->recoverOrder($payment, $orderNumber);
				if (!$recovered->isSuccess())
				{
					return $result->isSuccess() ? $this->error('RESPONSE') : $result;
				}

				$result = $recovered;
				$data = $result->getData();
			}

			$registeredInvoiceId = $data['orderId'];
			if ($invoiceId === '')
			{
				$result->setPsData(['PS_INVOICE_ID' => $registeredInvoiceId]);
			}
			$url = $data['formUrl'] ?? '';
		}

		if (!is_string($url) || !self::isPaymentUrlValid($url, $this->isTestMode($payment)))
		{
			$result->addErrors($this->error('RESPONSE')->getErrors());

			return $result;
		}

		$uri = new Web\Uri($url);
		parse_str($uri->getQuery(), $formParams);
		foreach ($formParams as $value)
		{
			if (!is_string($value))
			{
				$result->addErrors($this->error('RESPONSE')->getErrors());

				return $result;
			}
		}

		$this->setExtraParams([
			'URL' => $url,
			'FORM_ACTION' => (string)$uri->withQuery(''),
			'FORM_PARAMS' => $formParams,
			'TEST_MODE' => $this->isTestMode($payment),
			'SUM' => PriceMaths::roundByFormatCurrency($payment->getSum(), $payment->getCurrency()),
			'CURRENCY' => $payment->getCurrency(),
		]);
		$templateResult = $this->showTemplate($payment, 'template_bank_card');
		if (!$templateResult->isSuccess())
		{
			$result->addErrors($templateResult->getErrors());

			return $result;
		}

		$result->setTemplate($templateResult->getTemplate());
		$result->setPaymentUrl($url);
		if ($registeredInvoiceId !== null)
		{
			$result->setPsData(['PS_INVOICE_ID' => $registeredInvoiceId]);
		}

		return $result;
	}

	public function processRequest(Payment $payment, Request $request): PaySystem\ServiceResult
	{
		$data = self::getNotification($request);
		if (!$this->isPaymentValid($payment)
			|| !self::isInvoiceId($data['mdOrder'] ?? null)
			|| $data['mdOrder'] !== (string)$payment->getField('PS_INVOICE_ID')
			|| !$this->matchesOrderNumber($payment, $data['orderNumber'] ?? null)
			|| ($data['operation'] ?? null) !== 'deposited'
			|| !self::isCode($data['status'] ?? null, 1)
		)
		{
			return $this->error('NOTIFICATION');
		}

		$orderNumber = $data['orderNumber'];
		$result = $this->getOrderStatus($payment);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$data = $result->getData();
		if (!$this->matchesPayment($payment, $data)
			|| ($data['orderNumber'] ?? null) !== $orderNumber
			|| !self::isCode($data['orderStatus'] ?? null, 2)
		)
		{
			return $this->error('PAYMENT');
		}

		$result->setPsData([
			'PS_INVOICE_ID' => $payment->getField('PS_INVOICE_ID'),
			'PS_STATUS' => 'Y',
			'PS_STATUS_CODE' => 'deposited',
			'PS_STATUS_DESCRIPTION' => Loc::getMessage('SALE_HPS_SBERBANK_ORDER_ID', [
				'#ORDER_ID#' => $payment->getField('PS_INVOICE_ID'),
			]),
			'PS_SUM' => $this->getMinorAmount($payment->getSum(), $payment->getCurrency()) / 100,
			'PS_CURRENCY' => $payment->getCurrency(),
			'PS_RESPONSE_DATE' => new Main\Type\DateTime(),
		]);
		if ($this->getBusinessValue($payment, 'PS_CHANGE_STATUS_PAY') === 'Y')
		{
			$result->setOperationType(PaySystem\ServiceResult::MONEY_COMING);
		}

		return $result;
	}

	public function refund(Payment $payment, $refundableSum): PaySystem\ServiceResult
	{
		$amount = $this->getMinorAmount($refundableSum, $payment->getCurrency());
		if (!$this->isPaymentValid($payment)
			|| !self::isInvoiceId($payment->getField('PS_INVOICE_ID'))
			|| $amount === null
		)
		{
			return $this->error('REFUND');
		}

		$result = $this->send($payment, 'refund.do', [
			'orderId' => $payment->getField('PS_INVOICE_ID'),
			'amount' => $amount,
		]);
		if ($result->isSuccess())
		{
			$result->setOperationType(PaySystem\ServiceResult::MONEY_LEAVING);
		}

		return $result;
	}

	public function getCurrencyList(): array
	{
		return ['RUB'];
	}

	public static function isMyResponse(Request $request, $paySystemId): bool
	{
		$data = self::getNotification($request);
		$number = self::parseOrderNumber($data['orderNumber'] ?? null);

		return $number !== []
			&& $number[1] === (string)$paySystemId
			&& self::isInvoiceId($data['mdOrder'] ?? null)
			&& is_string($data['operation'] ?? null)
			&& (self::isCode($data['status'] ?? null, 0) || self::isCode($data['status'] ?? null, 1));
	}

	public function getPaymentIdFromRequest(Request $request): string
	{
		$data = self::getNotification($request);
		$number = self::parseOrderNumber($data['orderNumber'] ?? null);

		return $number[0] ?? '';
	}

	private static function getNotification(Request $request): array
	{
		if (!$request instanceof Main\HttpRequest || !$request->isPost() || !$request->isJson())
		{
			return [];
		}

		try
		{
			$request->decodeJsonStrict();

			return $request->getJsonList()->toArray();
		}
		catch (Main\SystemException)
		{
			return [];
		}
	}

	private static function parseOrderNumber(mixed $number): array
	{
		if (!is_string($number) || preg_match('/^([1-9][0-9]*)_([1-9][0-9]*)(?:_[1-9][0-9]*)?$/D', $number, $parts) !== 1)
		{
			return [];
		}

		return [$parts[1], $parts[2]];
	}

	private function getOrderNumber(Payment $payment): string
	{
		return $payment->getId() . '_' . $payment->getPaymentSystemId();
	}

	private function matchesOrderNumber(Payment $payment, mixed $number): bool
	{
		return self::parseOrderNumber($number) === [
			(string)$payment->getId(),
			(string)$payment->getPaymentSystemId(),
		];
	}

	protected function getOrderDescription(Payment $payment): string
	{
		$description = (string)$this->getBusinessValue($payment, 'SBERBANK_ORDER_DESCRIPTION');
		if ($description === '')
		{
			return '';
		}

		$order = $payment->getCollection()->getOrder();
		$userEmail = $order->getPropertyCollection()->getUserEmail();

		return str_replace(
			['#PAYMENT_NUMBER#', '#ORDER_NUMBER#', '#PAYMENT_ID#', '#ORDER_ID#', '#USER_EMAIL#'],
			[
				$payment->getField('ACCOUNT_NUMBER'),
				$order->getField('ACCOUNT_NUMBER'),
				$payment->getId(),
				$order->getId(),
				$userEmail ? $userEmail->getValue() : '',
			],
			$description,
		);
	}

	private function isPaymentValid(Payment $payment): bool
	{
		return in_array($payment->getCurrency(), $this->getCurrencyList(), true)
			&& $payment->getId() > 0
			&& $payment->getPaymentSystemId() === (int)$this->service->getField('ID')
			&& $this->getMinorAmount($payment->getSum(), $payment->getCurrency()) !== null;
	}

	private function getMinorAmount(mixed $sum, string $currency): ?int
	{
		if ((!is_int($sum) && !is_float($sum) && !is_string($sum)) || !is_numeric($sum) || !is_finite((float)$sum))
		{
			return null;
		}

		$amount = round(PriceMaths::roundByFormatCurrency((float)$sum, $currency) * 100);
		if (!is_finite($amount) || $amount < 1 || $amount >= PHP_INT_MAX)
		{
			return null;
		}

		return (int)$amount;
	}

	private function getCurrencyCode(string $currency): ?int
	{
		$data = CurrencyTable::getById($currency)->fetch();
		$code = (string)($data['NUMCODE'] ?? '');

		return preg_match('/^[0-9]{3}$/D', $code) === 1 && (int)$code > 0 ? (int)$code : null;
	}

	private static function isCode(mixed $value, int $expected): bool
	{
		return $value === $expected || $value === (string)$expected;
	}

	private static function isInvoiceId(mixed $value): bool
	{
		return is_string($value) && preg_match('/^[a-zA-Z0-9-]{1,100}$/D', $value) === 1;
	}

	private function matchesPayment(Payment $payment, array $data): bool
	{
		return $this->matchesPaymentIdentity($payment, $data)
			&& self::isCode($data['amount'] ?? null, $this->getMinorAmount($payment->getSum(), $payment->getCurrency()));
	}

	private function matchesPaymentIdentity(Payment $payment, array $data, ?string $invoiceId = null): bool
	{
		$invoiceId ??= (string)$payment->getField('PS_INVOICE_ID');
		$currencyCode = $this->getCurrencyCode($payment->getCurrency());
		if ($currencyCode === null
			|| !$this->matchesOrderNumber($payment, $data['orderNumber'] ?? null)
			|| !self::isCode($data['currency'] ?? null, $currencyCode)
			|| !is_array($data['attributes'] ?? null)
		)
		{
			return false;
		}

		$bankIds = [];
		foreach ($data['attributes'] as $attribute)
		{
			if (is_array($attribute) && ($attribute['name'] ?? null) === 'mdOrder')
			{
				$bankIds[] = $attribute['value'] ?? null;
			}
		}

		return $bankIds === [$invoiceId]
			&& (!array_key_exists('orderId', $data) || $data['orderId'] === $invoiceId);
	}

	private function recoverOrder(Payment $payment, string $orderNumber): PaySystem\ServiceResult
	{
		$result = $this->send($payment, 'getOrderStatusExtended.do', ['orderNumber' => $orderNumber]);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$data = $result->getData();
		$invoiceId = null;
		foreach (is_array($data['attributes'] ?? null) ? $data['attributes'] : [] as $attribute)
		{
			if (is_array($attribute) && ($attribute['name'] ?? null) === 'mdOrder')
			{
				$invoiceId = $attribute['value'] ?? null;
			}
		}

		if (!self::isInvoiceId($invoiceId)
			|| !$this->matchesPaymentIdentity($payment, $data, $invoiceId)
			|| ($data['orderNumber'] ?? null) !== $orderNumber
			|| !self::isCode($data['orderStatus'] ?? null, 0)
			|| !self::isCode($data['amount'] ?? null, $this->getMinorAmount($payment->getSum(), $payment->getCurrency()))
		)
		{
			return $this->error('PAYMENT');
		}

		$result->setData([
			'orderId' => $invoiceId,
			'formUrl' => $this->getUrl($payment, 'form') . rawurlencode($invoiceId),
		]);

		return $result;
	}

	protected function getOrderStatus(Payment $payment): PaySystem\ServiceResult
	{
		if (!self::isInvoiceId($payment->getField('PS_INVOICE_ID')))
		{
			return $this->error('PAYMENT');
		}

		return $this->send($payment, 'getOrderStatusExtended.do', [
			'orderId' => $payment->getField('PS_INVOICE_ID'),
		]);
	}

	private function send(Payment $payment, string $action, array $params): PaySystem\ServiceResult
	{
		$params['userName'] = $this->getBusinessValue($payment, 'SBERBANK_LOGIN');
		$params['password'] = $this->getBusinessValue($payment, 'SBERBANK_PASSWORD');
		if (!is_string($params['userName']) || $params['userName'] === ''
			|| !is_string($params['password']) || $params['password'] === ''
		)
		{
			return $this->error('SETTINGS');
		}

		$client = $this->createHttpClient();
		$client->setHeader('Content-Type', 'application/json; charset=utf-8');
		try
		{
			$response = $client->post($this->getUrl($payment, $action), Web\Json::encode($params));
			if ($response === false || $client->getError() !== []
				|| $client->getStatus() < 200 || $client->getStatus() >= 300
			)
			{
				return $this->error('TRANSPORT');
			}

			$data = Web\Json::decode($response);
		}
		catch (Main\SystemException)
		{
			return $this->error('RESPONSE');
		}

		if (!is_array($data))
		{
			return $this->error('RESPONSE');
		}
		if (!self::isCode($data['errorCode'] ?? null, 0))
		{
			$message = $data['errorMessage'] ?? null;
			if (!is_string($message) || trim($message) === ''
				|| str_contains($message, $params['userName'])
				|| str_contains($message, $params['password'])
			)
			{
				$message = '';
			}

			$code = $data['errorCode'] ?? 0;
			$result = new PaySystem\ServiceResult();
			$result->addError(new Main\Error(
				htmlspecialcharsbx($message),
				is_int($code) || is_string($code) ? $code : 0,
			));

			return $result;
		}

		$result = new PaySystem\ServiceResult();
		$result->setData($data);

		return $result;
	}

	protected function createHttpClient(): Web\HttpClient
	{
		$client = new Web\HttpClient([
			'disableSslVerification' => false,
			'redirect' => false,
			'debugLevel' => 0,
			'socketTimeout' => 10,
			'streamTimeout' => 20,
			'bodyLengthMax' => 1048576,
			'privateIp' => false,
		]);
		$client->setLogger(new \Psr\Log\NullLogger());

		return $client;
	}

	protected function isTestMode(?Payment $payment = null): bool
	{
		return $this->getBusinessValue($payment, 'SBERBANK_TEST_MODE') === 'Y';
	}

	protected function getUrlList(): array
	{
		$urls = [];
		foreach (['register.do', 'getOrderStatusExtended.do', 'refund.do'] as $action)
		{
			$urls[$action] = [
				self::TEST_URL => 'https://ecomtest.sberbank.ru/ecomm/gw/partner/api/v1/' . $action,
				self::ACTIVE_URL => 'https://epay.sberbank.ru/ecomm/gw/partner/api/v1/' . $action,
			];
		}
		$urls['form'] = [
			self::TEST_URL => 'https://sbox.payecom.ru/pay_ru?orderId=',
			self::ACTIVE_URL => 'https://payecom.ru/pay_ru?orderId=',
		];

		return $urls;
	}

	private function getReturnUrl(Payment $payment, string $code): string
	{
		return (string)($this->getBusinessValue($payment, 'SBERBANK_' . $code)
			?: $this->service->getContext()->getUrl());
	}

	public static function isPaymentUrlValid(string $url, bool $testMode): bool
	{
		if (preg_match('/[\x00-\x20\\\\]/', $url))
		{
			return false;
		}

		$uri = new Web\Uri($url);
		$hosts = $testMode ? ['sbox.payecom.ru', 'ecomtest.sberbank.ru'] : ['payecom.ru', 'epay.sberbank.ru'];

		return $uri->getScheme() === 'https'
			&& in_array($uri->getHost(), $hosts, true)
			&& $uri->getPort() === 443
			&& $uri->getUserInfo() === ''
			&& $uri->getFragment() === '';
	}

	private function error(string $code): PaySystem\ServiceResult
	{
		$messageCode = match ($code)
		{
			'SETTINGS', 'TRANSPORT' => 'SALE_PS_SERVICE_ERROR_CONNECT_PS',
			'NOTIFICATION' => 'SALE_HPS_SBERBANK_ERROR_PAYMENT',
			default => 'SALE_HPS_SBERBANK_ERROR_' . $code,
		};
		$result = new PaySystem\ServiceResult();
		$result->addError(new Main\Error(
			Loc::getMessage($messageCode),
			'SBERBANK_' . $code,
		));

		return $result;
	}
}
