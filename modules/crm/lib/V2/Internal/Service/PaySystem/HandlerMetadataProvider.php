<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\PaySystem;

use Bitrix\Crm\V2\Internal\Integration\Sale\CheckedPaySystemServiceFactory;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Sale\Internals\PaySystemRestHandlersTable;
use Bitrix\Sale\PaySystem\Manager;
use Bitrix\Sale\PaySystem\RestHandler;

final class HandlerMetadataProvider
{
	public function get(array $fields): Result
	{
		if (!Loader::includeModule('sale'))
		{
			return $this->deny();
		}
		$result = (new HandlerGuard())->resolve($fields, HandlerCatalog::PURPOSE_METADATA);
		if (!$result->isSuccess())
		{
			return $result;
		}
		$handler = $result->getData()['handler'];
		try
		{
			if ($handler->getClassName() === null)
			{
				$data = $this->readQuoteDescription($handler->getFiles()['description']);
				\Bitrix\Main\Localization\Loc::loadMessages(Loader::getLocal('modules/crm/classes/general/crm_pay_system.php'));
				$data['legacyFields']['USER_COLUMNS'] = [
					'NAME' => GetMessage('CRM_PS_USER_FIELDS'),
					'SORT' => 6000,
					'GROUP' => 'COLUMN_SETTINGS',
					'TYPE' => 'USER_COLUMN_LIST',
					'VALUE' => '',
				];
				$data['handler'] = $handler;
				$data['modes'] = [];

				return (new Result())->setData($data);
			}

			$result = (new CheckedPaySystemServiceFactory())->createFromFields($fields, HandlerCatalog::PURPOSE_METADATA);
			if (!$result->isSuccess())
			{
				return $result;
			}
			$data = $result->getData();
			$data['description'] = $data['service']->getHandlerDescription();
			$data['description']['CODES'] = array_merge(
				$data['description']['CODES'] ?? [],
				\CCrmPaySystem::getHandlerDescriptionEx(new \Bitrix\Main\Event('sale', '', [
					'handler' => $data['handler']->getCode(),
				])),
			);
			$className = $data['handler']->getClassName();
			$data['modes'] = $className::getHandlerModeList();

			return (new Result())->setData($data);
		}
		catch (\Throwable)
		{
			return $this->deny();
		}
	}

	public function getActions(array $systemCodes): array
	{
		if (!Loader::includeModule('sale'))
		{
			return [];
		}
		$printCodes = (new HandlerCatalog())->getPrintCodes();
		$codes = array_fill_keys(array_merge($printCodes, $systemCodes), true);
		$actions = [];
		$rest = PaySystemRestHandlersTable::query()->setSelect(['CODE', 'NAME'])->exec();
		while ($row = $rest->fetch())
		{
			$code = $row['CODE'];
			if (preg_match('/^[a-z][a-z0-9_]*$/D', $code) !== 1)
			{
				unset($codes[$code]);
				continue;
			}
			if (
				in_array($code, $printCodes, true)
				|| is_file(Application::getDocumentRoot() . '/bitrix/modules/sale/handlers/paysystem/' . $code . '/handler.php')
			)
			{
				$codes[$code] = true;
				continue;
			}
			unset($codes[$code]);
			if ($this->isRestListEntryAllowed($code))
			{
				$actions[$code] = [
					'ID' => $code,
					'PATH' => $code,
					'HANDLER' => $code,
					'TITLE' => $row['NAME'] . ' (' . $code . ')',
					'NEW_FORMAT' => 'Y',
				];
			}
		}

		foreach (array_keys($codes) as $code)
		{
			$result = $this->get(['ACTION_FILE' => $code]);
			if (!$result->isSuccess())
			{
				continue;
			}
			$data = $result->getData();
			$handler = $data['handler'];
			$description = $data['description'];
			if (($description['IS_AVAILABLE'] ?? null) === \Bitrix\Sale\PaySystem\Manager::HANDLER_AVAILABLE_FALSE)
			{
				continue;
			}
			$actions[$code] = [
				'ID' => $code,
				'PATH' => $handler->getServiceFields()['ACTION_FILE'],
				'HANDLER' => $handler->getServiceFields()['ACTION_FILE'],
				'TITLE' => ($description['NAME'] ?? $code) . ' (' . $code . ')',
				'NEW_FORMAT' => 'Y',
			];
			if ($handler->getClassName() !== null && isset($handler->getFiles()['description']))
			{
				$actions[$code]['PATH'] = '/bitrix/modules/sale/handlers/paysystem/' . $code;
			}
		}
		ksort($actions);

		return $actions;
	}

	private function isRestListEntryAllowed(string $code): bool
	{
		if (preg_match('/^[a-z][a-z0-9_]*$/D', $code) !== 1)
		{
			return false;
		}
		$root = Application::getDocumentRoot();
		$folder = Manager::getFolderFromClassName($code);
		foreach (Manager::getHandlerDirectories() as $directory)
		{
			if (is_file($root . $directory . $folder . '/handler.php'))
			{
				return false;
			}
		}
		$saleRoot = realpath($root . '/bitrix/modules/sale');
		$file = realpath($root . '/bitrix/modules/sale/lib/paysystem/resthandler.php');
		if ($saleRoot === false || $file === false || !str_starts_with($file, $saleRoot . '/') || !is_readable($file))
		{
			return false;
		}
		try
		{
			if (!class_exists(RestHandler::class, false))
			{
				require_once $file;
			}
			foreach ([RestHandler::class, $code] as $class)
			{
				if (class_exists($class, false))
				{
					$source = (new \ReflectionClass($class))->getFileName();
					if (!is_string($source) || realpath($source) !== $file)
					{
						return false;
					}
				}
			}
			return class_exists(RestHandler::class, false);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function getParameters(array $fields, int $personTypeId): Result
	{
		$result = $this->get($fields);
		if (!$result->isSuccess())
		{
			return $result;
		}
		$data = $result->getData();
		$codes = $data['legacyFields'] ?? $data['description']['CODES'] ?? [];
		$consumer = 'PAYSYSTEM_' . (int)($fields['ID'] ?? 0);
		$params = [];
		foreach ($codes as $code => $definition)
		{
			$mapping = \Bitrix\Sale\BusinessValue::getMapping($code, $consumer, $personTypeId, [
				'MATCH' => \Bitrix\Sale\BusinessValue::MATCH_EXACT | \Bitrix\Sale\BusinessValue::MATCH_COMMON,
			]);
			if (!$mapping)
			{
				$mapping = $definition['DEFAULT'] ?? [
					'PROVIDER_KEY' => $definition['TYPE'] ?? '',
					'PROVIDER_VALUE' => is_array($definition['VALUE'] ?? null) ? '' : ($definition['VALUE'] ?? ''),
				];
			}
			$type = $mapping['PROVIDER_KEY'];
			if ($type === 'INPUT')
			{
				$type = $definition['INPUT']['TYPE'] ?? '';
				$type = ['ENUM' => 'SELECT', 'Y/N' => 'CHECKBOX'][$type] ?? $type;
			}
			$params[$code] = [
				'TYPE' => $type === 'VALUE' ? '' : $type,
				'VALUE' => $mapping['PROVIDER_VALUE'],
			];
		}
		$params['BX_PAY_SYSTEM_ID'] = ['TYPE' => '', 'VALUE' => (int)($fields['ID'] ?? 0)];

		return (new Result())->setData(['params' => $params]);
	}

	public function getValueById(int $id, string $code, int $personTypeId): mixed
	{
		$fields = \Bitrix\Sale\PaySystem\Manager::getById($id);
		if (!$fields)
		{
			return null;
		}
		$result = $this->getParameters($fields, $personTypeId);

		return $result->isSuccess() ? ($result->getData()['params'][$code]['VALUE'] ?? null) : null;
	}

	public function getByDescriptionFile(mixed $fileName): Result
	{
		if (!is_string($fileName))
		{
			return $this->deny();
		}
		$documentRoot = Application::getDocumentRoot();
		$roots = ['/bitrix/php_interface/include/sale_payment/', '/bitrix/modules/sale/handlers/paysystem/'];
		foreach ($roots as $root)
		{
			$prefix = $documentRoot . $root;
			if (!str_starts_with($fileName, $prefix) || !str_ends_with($fileName, '/.description.php'))
			{
				continue;
			}
			$code = substr($fileName, strlen($prefix), -strlen('/.description.php'));
			if (preg_match('/^[a-z][a-z0-9_]*$/D', $code) !== 1)
			{
				return $this->deny();
			}

			return $this->get(['ACTION_FILE' => $root . $code]);
		}

		return $this->deny();
	}

	public function preview(array $fields, array $parameters, string $entry): Result
	{
		$result = (new HandlerGuard())->resolve($fields, HandlerCatalog::PURPOSE_LEGACY_PRINT);
		if (!$result->isSuccess())
		{
			return $result;
		}
		$handler = $result->getData()['handler'];
		$isQuote = $handler->getClassName() === null;
		$operation = new PrintOperation($isQuote ? 'quote' : 'invoice', 'preview', $entry);

		return $operation->run(function () use ($fields, $parameters, $handler, $isQuote, $operation): Result {
			$factory = new CheckedPaySystemServiceFactory();
			$serviceResult = $factory->createFromFields(
				$isQuote ? ['ACTION_FILE' => 'bill'] : $fields,
				HandlerCatalog::PURPOSE_LEGACY_PRINT,
			);
			if (!$serviceResult->isSuccess())
			{
				return $serviceResult;
			}
			$service = $serviceResult->getData()['service'];
			try
			{
				$captured = $operation->capture(function () use ($service, $parameters, $handler, $isQuote, $operation): Result {
					if ($isQuote)
					{
						// Only the stock demo-data method is used; this adapter never loads a quote handler.
						$demo = (new \Bitrix\Sale\PaySystem\CompatibilityHandler('', $service))->getDemoParams();
						$parameters = array_merge($demo, $parameters);
						$params = [];
						foreach ($parameters as $code => $value)
						{
							$params[$code] = ['TYPE' => '', 'VALUE' => $value];
						}
						$renderFields = $handler->getFields();
						$renderFields['PARAMS'] = serialize($params);
						$resolved = (new HandlerGuard())->resolve($renderFields, HandlerCatalog::PURPOSE_LEGACY_PRINT);
						if (!$resolved->isSuccess())
						{
							return $resolved;
						}
						$paymentData = array_fill_keys([
							'PROPERTIES', 'REQUISITE', 'BANK_DETAIL', 'CRM_COMPANY', 'CRM_CONTACT',
							'MC_REQUISITE', 'MC_BANK_DETAIL', 'CRM_MYCOMPANY', 'PAYMENT', 'SHIPMENT',
						], []);
						$paymentData['ORDER'] = array_merge(['ID' => 0], $parameters['ORDER']);
						$paymentData['CART_ITEMS'] = $parameters['BASKET_ITEMS'];
						$paymentData['TAX_LIST'] = $parameters['TAX_LIST'];

						return (new QuoteRenderer())->render($resolved->getData()['handler'], $paymentData, $operation);
					}

					$service->setTemplateMode(\Bitrix\Sale\PaySystem\BaseServiceHandler::STRING);
					$service->setTemplateParams(array_merge($service->getDemoParams(), $parameters));
					$payment = \Bitrix\Sale\PaySystem\Manager::getPaymentObjectByData($handler->getServiceFields());
					$result = $service->showTemplate($payment, 'template');
					if (!$result->isSuccess() || !is_string($result->getTemplate()) || $result->getTemplate() === '')
					{
						return $this->deny();
					}

					return (new Result())->setData(['content' => $result->getTemplate()]);
				}, ['pdf' => false, 'GET_CONTENT' => 'N']);
			}
			catch (\Throwable)
			{
				return $this->deny();
			}
			$result = $captured['value'];
			if ($result->isSuccess())
			{
				$operation->markGenerated($handler->getCode());
				$operation->complete();
			}

			return $result;
		});
	}

	private function readQuoteDescription(string $file): array
	{
		$psTitle = '';
		$psDescription = '';
		$arPSCorrespondence = [];
		require $file;

		return [
			'description' => ['NAME' => $psTitle, 'DESCRIPTION' => $psDescription],
			'legacyFields' => $arPSCorrespondence,
		];
	}

	private function deny(): Result
	{
		return (new Result())->addError(new Error('The payment handler is unavailable.', HandlerGuard::ERROR_NOT_ALLOWED));
	}
}
