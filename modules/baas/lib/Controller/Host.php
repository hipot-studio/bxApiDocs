<?php

declare(strict_types=1);

namespace Bitrix\Baas\Controller;

use Bitrix\Baas;
use Bitrix\Main;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Request;

class Host extends Main\Engine\Controller
{
	protected Baas\Service\BillingService $billingService;
	protected Baas\Service\ServiceService $serviceService;

	public function __construct(Request $request = null)
	{
		parent::__construct($request);

		$this->billingService = Baas\Service\BillingService::getInstance();
		$this->serviceService = Baas\Baas::getInstance()->getServiceManager();
	}

	public function configureActions(): array
	{
		return [
			'getSignedPurchaseReport' => [
				'-prefilters' => [Authentication::class, Csrf::class],
			],
		];
	}

	public function registerAction(): Main\Engine\Response\Json
	{
		return $this->fullFill(fn() => $this->billingService->register(true));
	}

	public function refreshAction(): Main\Engine\Response\Json
	{
		return $this->fullFill(function() {
			$result = $this->billingService->synchronizeWithBilling();

			if ($result->isSuccess())
			{
				$services = [];
				foreach ($this->serviceService->getAll() as $service)
				{
					$services[$service->getCode()] = $service->jsonSerialize();
				}
				$result->setData(['services' => $services]);
			}

			return $result;
		});
	}

	public function getBaasStatusAction(): Main\Engine\Response\Json
	{
		return $this->fullFill(function() {
			/** @var Baas\UseCase\External\Response\GetBaasSalesStatusResult $result */
			$result = $this->billingService->getBaasSalesStatus();

			return (new Main\Result())->setData([
				'code' => $result->statusCode,
				'description' => $result->statusDescription,
			]);
		});
	}

	public function getPurchaseReportAction(
		$purchasedPackageCode,
		string $serviceCode,
	): Main\Engine\Response\Json
	{
		return $this->fullFill(function() use ($purchasedPackageCode, $serviceCode) {
			$result = $this->billingService->getPurchasedPackageReport(
				(string) $purchasedPackageCode,
				$serviceCode,
			);

			return (new Main\Result())->setData($result->getData());
		});
	}

	public function getSignedPurchaseReportAction(
		string $purchasedPackageCode,
		string $serviceCode,
	): HttpResponse
	{
		$signer = new Main\Security\Sign\TimeSigner();
		$unsignedPurchasedPackageCode = $signer->unsign($purchasedPackageCode);
		$unsignedServiceCode = $signer->unsign($serviceCode);

		$result = $this->billingService->getPurchasedPackageReport($unsignedPurchasedPackageCode, $unsignedServiceCode);

		if(!$result->isSuccess())
		{
			return (new HttpResponse())->setContent($result->getError()?->getMessage());
		}

		$lines = [];
		foreach ($result->getData() as $row)
		{
			if (isset($row[0]))
			{
				$row[0] = '"' . $row[0] . '"';
			}
			$lines[] = implode("\t", $row);
		}
		$content = implode("\r\n", $lines);

		$response = new HttpResponse();
		$response->setContent($content);
		$serverName = Context::getCurrent()->getServer()->getServerName();
		$filename = sprintf('report_%s_%s.xls', $serverName, date('Y-m-d'));
		$response->addHeader(
			'Content-Disposition',
			"attachment; filename=\"$filename\"; filename*=utf-8'",
		);
		$response->addHeader(
			'Content-Type',
			'text/tab-separated-values'
		);

		return $response;
	}

	private function fullFill(callable $callback): Main\Engine\Response\Json
	{
		try
		{
			$httpClient = null;
			Main\EventManager::getInstance()->addEventHandler(
				'baas',
				'onServerInfoReceived',
				function(Main\Event $event) use (&$httpClient)
				{
					/** @var Main\Web\HttpClient $httpClient */
					$httpClient = $event->getParameter('httpClient');
				},
			);
			$result = $callback();
		}
		catch (Baas\UseCase\External\Exception\BaasControllerException\ExceptionFromError $e)
		{
			$result = (new Main\Result())->addError(
				new Main\Error(
					$e->getMessage(),
					$e->getCode(),
					$e->getCustomData(),
				),
			);
		}
		catch (Baas\UseCase\BaasException $e)
		{
			$result = (new Main\Result())->addError(
				new Main\Error(
					$e->getMessage(),
					$e->getCode(),
				),
			);
		}

		if ($result->isSuccess())
		{
			return Main\Engine\Response\AjaxJson::createSuccess($result->getData());
		}

		return Main\Engine\Response\AjaxJson::createError(
			$result->getErrorCollection(),
			['httpResponse' => [
				'status' => $httpClient?->getStatus(),
				// 'headers' => $httpClient?->getHeaders()->toArray(),
				'body' => $httpClient?->getResult(),
			]],
		);
	}
}
