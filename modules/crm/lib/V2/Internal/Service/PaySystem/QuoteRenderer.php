<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\PaySystem;

use Bitrix\Crm\V2\Internal\Entity\PaySystem\ResolvedHandler;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class QuoteRenderer
{
	public function render(
		ResolvedHandler $handler,
		array $paymentData,
		PrintOperation $operation,
		bool $pdf = false,
		bool $getContent = false,
	): Result
	{
		if (
			$handler->getPurpose() !== HandlerCatalog::PURPOSE_LEGACY_PRINT
			|| $handler->getClassName() !== null
			|| !isset($handler->getFiles()['payment'])
		)
		{
			return $this->error();
		}

		$fields = $handler->getFields();
		try
		{
			$rendered = $operation->capture(
				function () use ($handler, $fields, $paymentData): mixed {
					$params = unserialize($fields['PARAMS'], ['allowed_classes' => false]);
					if (!is_array($params))
					{
						throw new \UnexpectedValueException('Invalid print parameters.');
					}
					(new CheckedBusinessValueContext())->run(
						'PAYSYSTEM_' . (int)($fields['ID'] ?? 0),
						static fn() => ['CODES' => []],
						static function () use ($paymentData, $fields): void {
							\CSalePaySystemAction::InitParamArrays(
								$paymentData['ORDER'],
								0,
								$fields['PARAMS'],
								[
									'PROPERTIES' => $paymentData['PROPERTIES'],
									'BASKET_ITEMS' => $paymentData['CART_ITEMS'],
									'TAX_LIST' => $paymentData['TAX_LIST'],
									'REQUISITE' => $paymentData['REQUISITE'],
									'BANK_DETAIL' => $paymentData['BANK_DETAIL'],
									'CRM_COMPANY' => $paymentData['CRM_COMPANY'],
									'CRM_CONTACT' => $paymentData['CRM_CONTACT'],
									'MC_REQUISITE' => $paymentData['MC_REQUISITE'],
									'MC_BANK_DETAIL' => $paymentData['MC_BANK_DETAIL'],
									'CRM_MYCOMPANY' => $paymentData['CRM_MYCOMPANY'],
								],
								$paymentData['PAYMENT'],
								$paymentData['SHIPMENT'],
							);
						},
					);
					foreach ($params as $key => &$param)
					{
						if ($key === 'USER_COLUMNS')
						{
							$columns = is_string($param['VALUE'])
								? unserialize($param['VALUE'], ['allowed_classes' => false]) : [];
							$param['VALUE'] = [];
							foreach (is_array($columns) ? $columns : [] as $code => $column)
							{
								$param['VALUE']['PROPERTY_' . $code] = $column;
							}
							$param['~VALUE'] = $param['VALUE'];
						}
						else
						{
							$param['~VALUE'] = htmlspecialcharsbx($param['VALUE']);
						}
					}
					unset($param);
					$GLOBALS['SALE_CORRESPONDENCE'] = $params;

					return $this->includePayment($handler->getFiles()['payment'], $fields);
				},
				['pdf' => $pdf, 'GET_CONTENT' => $getContent ? 'Y' : 'N'],
			);
		}
		catch (\Throwable)
		{
			return $this->error();
		}

		$content = $getContent ? $rendered['value'] : $rendered['output'];
		if (
			!is_string($content)
			|| $content === ''
			|| $rendered['value'] === false
			|| ($pdf && !str_starts_with($content, '%PDF-'))
			|| ($pdf && !str_ends_with(rtrim($content), '%%EOF'))
			|| ($getContent && trim($rendered['output']) !== '')
		)
		{
			return $this->error();
		}

		return (new Result())->setData(['content' => $content]);
	}

	private function includePayment(string $file, array $arPaySysAction): mixed
	{
		global $APPLICATION, $USER, $DB;

		return include $file;
	}

	private function error(): Result
	{
		return (new Result())->addError(new Error('Could not generate the document.', 'CRM_QUOTE_PRINT_FAILED'));
	}
}
