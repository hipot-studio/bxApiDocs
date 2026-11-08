<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Sale;

use Bitrix\Crm\V2\Internal\Service\PaySystem\CheckedBusinessValueContext;
use Bitrix\Main\NotSupportedException;
use Bitrix\Main\Request;
use Bitrix\Sale\Payment;
use Bitrix\Sale\PaySystem\BaseServiceHandler;
use Bitrix\Sale\PaySystem\Service;
use Bitrix\Sale\PaySystem\ServiceResult;
use Bitrix\Sale\Services\Base\RestrictionInfoCollection;

final class CheckedPaySystemService extends Service
{
	private ?array $operationDescription = null;

	private const PASSIVE_METHODS = [
		'getConsumerName', 'getField', 'getFieldsValues', 'isCash', 'canPrintCheck',
		'isBlockable', 'isAffordPdf', 'isAffordDocumentGeneratePdf', 'isPrePayable',
		'isAllowEditPayment', 'isClone', 'getContext', 'isSupportPrintCheck',
		'isFiscalizationAware', 'getServiceId',
	];

	public function __construct($fields)
	{
		$this->assertSupportedInterface();
		parent::__construct($fields);
	}

	public function initiatePay(Payment $payment, ?Request $request = null, $mode = BaseServiceHandler::STREAM)
	{
		return $this->withConsumer(fn() => parent::initiatePay($payment, $request, $mode));
	}

	public function isRefundable()
	{
		return $this->withConsumer(fn() => parent::isRefundable());
	}

	public function refund(Payment $payment, $refundableSum = 0)
	{
		return $this->withConsumer(fn() => parent::refund($payment, $refundableSum));
	}

	public function processRequest(Request $request)
	{
		return $this->withConsumer(fn() => parent::processRequest($request));
	}

	public function getHandlerDescription()
	{
		return $this->withConsumer(fn() => $this->operationDescription);
	}

	public function cancel(Payment $payment)
	{
		return $this->withConsumer(fn() => parent::cancel($payment));
	}

	public function confirm(Payment $payment, $sum = 0)
	{
		return $this->withConsumer(fn() => parent::confirm($payment, $sum));
	}

	public function getCurrency()
	{
		return $this->withConsumer(fn() => parent::getCurrency());
	}

	public function getClientTypeFromHandler()
	{
		return $this->withConsumer(fn() => parent::getClientTypeFromHandler());
	}

	public function getClientType()
	{
		return $this->withConsumer(fn() => parent::getClientType());
	}

	public function canPrintCheckSelf(Payment $payment): bool
	{
		return $this->withConsumer(fn() => parent::canPrintCheckSelf($payment));
	}

	public function creditNoDemand(Payment $payment)
	{
		return $this->withConsumer(fn() => parent::creditNoDemand($payment));
	}

	public function debitNoDemand(Payment $payment)
	{
		return $this->withConsumer(fn() => parent::debitNoDemand($payment));
	}

	public function isPayable()
	{
		return $this->withConsumer(fn() => parent::isPayable());
	}

	public function getPdfContent(Payment $payment)
	{
		return $this->withConsumer(fn() => parent::getPdfContent($payment));
	}

	public function getPdf(Payment $payment)
	{
		return $this->withConsumer(fn() => parent::getPdf($payment));
	}

	public function registerCallbackOnGenerate(Payment $payment, $params)
	{
		return $this->withConsumer(fn() => parent::registerCallbackOnGenerate($payment, $params));
	}

	public function isPdfGenerated(Payment $payment)
	{
		return $this->withConsumer(fn() => parent::isPdfGenerated($payment));
	}

	public function getPaymentPrice(Payment $payment)
	{
		return $this->withConsumer(fn() => parent::getPaymentPrice($payment));
	}

	public function setTemplateParams(array $params)
	{
		return $this->withConsumer(fn() => parent::setTemplateParams($params));
	}

	public function showTemplate(?Payment $payment = null, $templateName)
	{
		return $this->withConsumer(fn() => parent::showTemplate($payment, $templateName));
	}

	public function initPrePayment(?Payment $payment = null, Request $request)
	{
		return $this->withConsumer(fn() => parent::initPrePayment($payment, $request));
	}

	public function getPrePaymentProps()
	{
		return $this->withConsumer(fn() => parent::getPrePaymentProps());
	}

	public function basketButtonAction(array $orderData = [])
	{
		return $this->withConsumer(fn() => parent::basketButtonAction($orderData));
	}

	public function setOrderDataForPrePayment($orderData = [])
	{
		return $this->withConsumer(fn() => parent::setOrderDataForPrePayment($orderData));
	}

	public function payOrderByPrePayment($orderData)
	{
		return $this->withConsumer(fn() => parent::payOrderByPrePayment($orderData));
	}

	public function isCheckable()
	{
		return $this->withConsumer(fn() => parent::isCheckable());
	}

	public function check(Payment $payment)
	{
		return $this->withConsumer(fn() => parent::check($payment));
	}

	public function isCustom()
	{
		return $this->withConsumer(fn() => parent::isCustom());
	}

	public function getParamsBusValue(Payment $payment)
	{
		return $this->withConsumer(fn() => parent::getParamsBusValue($payment));
	}

	public function isTuned()
	{
		return $this->withConsumer(fn() => parent::isTuned());
	}

	public function getDemoParams()
	{
		return $this->withConsumer(fn() => parent::getDemoParams());
	}

	public function setTemplateMode($mode)
	{
		return $this->withConsumer(fn() => parent::setTemplateMode($mode));
	}

	public function isRecurring(Payment $payment): bool
	{
		return $this->withConsumer(fn() => parent::isRecurring($payment));
	}

	public function repeatRecurrent(Payment $payment, ?Request $request = null): ServiceResult
	{
		return $this->withConsumer(fn() => parent::repeatRecurrent($payment, $request));
	}

	public function cancelRecurrent(Payment $payment, ?Request $request = null): ServiceResult
	{
		return $this->withConsumer(fn() => parent::cancelRecurrent($payment, $request));
	}

	public function getCashboxClass(): string
	{
		return $this->withConsumer(fn() => parent::getCashboxClass());
	}

	public function isFiscalizationEnabled(Payment $payment): bool
	{
		return $this->withConsumer(fn() => parent::isFiscalizationEnabled($payment));
	}

	public function getStartupRestrictions(): RestrictionInfoCollection
	{
		return $this->withConsumer(fn() => parent::getStartupRestrictions());
	}

	public function createClone(\SplObjectStorage $cloneEntity)
	{
		// Service::createClone exposes the raw handler through the caller's storage.
		throw new NotSupportedException('Cloning a checked payment service is not supported.');
	}

	public function __call(string $name, array $arguments): never
	{
		throw new NotSupportedException('Unsupported checked payment service operation.');
	}

	private function withConsumer(callable $action): mixed
	{
		$previous = $this->operationDescription;
		try
		{
			return (new CheckedBusinessValueContext())->run(
				parent::getConsumerName(),
				fn() => $this->operationDescription ??= parent::getHandlerDescription(),
				$action,
			);
		}
		finally
		{
			$this->operationDescription = $previous;
		}
	}

	private function assertSupportedInterface(): void
	{
		$parent = new \ReflectionClass(Service::class);
		$current = new \ReflectionClass(self::class);
		foreach ($parent->getMethods(\ReflectionMethod::IS_PUBLIC) as $method)
		{
			if ($method->isStatic() || $method->isConstructor())
			{
				continue;
			}
			$name = $method->getName();
			if (
				!in_array($name, self::PASSIVE_METHODS, true)
				&& $current->getMethod($name)->getDeclaringClass()->getName() !== self::class
			)
			{
				throw new NotSupportedException('Unsupported payment service interface.');
			}
		}
	}
}
