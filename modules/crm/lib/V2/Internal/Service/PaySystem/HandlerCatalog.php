<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\PaySystem;

final class HandlerCatalog
{
	public const PURPOSE_LEGACY_PRINT = 'legacy_print';
	public const PURPOSE_METADATA = 'metadata';
	public const PURPOSE_PAYMENT = 'payment';
	public const PURPOSE_DOCUMENT_GENERATOR = 'document_generator';

	private const QUOTE_ROOT = '/bitrix/php_interface/include/sale_payment/';
	private const BILL_ROOT = '/bitrix/modules/sale/handlers/paysystem/';
	private const QUOTE_CODES = [
		'quote_br', 'quote_de', 'quote_en', 'quote_fr', 'quote_la', 'quote_ru', 'quote_ua',
	];
	private const BILL_CLASSES = [
		'bill' => 'BillHandler',
		'billbr' => 'BillBrHandler',
		'billby' => 'BillByHandler',
		'billde' => 'BillDeHandler',
		'billen' => 'BillEnHandler',
		'billfr' => 'BillFrHandler',
		'billkz' => 'BillKzHandler',
		'billla' => 'BillLaHandler',
		'billua' => 'BillUaHandler',
	];

	public function getPrintCodes(): array
	{
		return array_merge(self::QUOTE_CODES, array_keys(self::BILL_CLASSES));
	}

	public function find(string $actionFile, string $purpose, ?string $documentRoot = null): ?array
	{
		if (!in_array($purpose, [
			self::PURPOSE_LEGACY_PRINT,
			self::PURPOSE_METADATA,
			self::PURPOSE_PAYMENT,
			self::PURPOSE_DOCUMENT_GENERATOR,
		], true))
		{
			return null;
		}

		foreach ($this->getPrintCodes() as $code)
		{
			$isQuote = in_array($code, self::QUOTE_CODES, true);
			$root = $isQuote ? self::QUOTE_ROOT : self::BILL_ROOT;
			$directory = $root . $code;
			if (!in_array($actionFile, [$code, $directory, $directory . '/'], true))
			{
				continue;
			}
			if (!in_array($purpose, [self::PURPOSE_LEGACY_PRINT, self::PURPOSE_METADATA], true))
			{
				return null;
			}

			return [
				'code' => $code,
				'root' => $root,
				'directory' => $directory,
				'serviceAction' => $isQuote ? $directory : $code,
				'className' => $isQuote ? null : 'Sale\\Handlers\\PaySystem\\' . self::BILL_CLASSES[$code],
				'handlerType' => $isQuote ? null : 'SYSTEM',
				'files' => $isQuote
					? [
						'payment' => 'payment.php',
						'description' => '.description.php',
						'html' => 'html.php',
						'pdf' => 'pdf.php',
					]
					: [
						'handler' => 'handler.php',
						'description' => '.description.php',
						'template' => 'template/template.php',
						'template_pdf' => 'template/template_pdf.php',
					],
			];
		}

		return $this->findPaymentHandler($actionFile, $purpose, $documentRoot);
	}

	private function findPaymentHandler(string $actionFile, string $purpose, ?string $documentRoot): ?array
	{
		if ($purpose === self::PURPOSE_LEGACY_PRINT || $documentRoot === null)
		{
			return null;
		}

		$code = $actionFile;
		if (str_starts_with($actionFile, self::BILL_ROOT))
		{
			$code = substr($actionFile, strlen(self::BILL_ROOT));
			if (str_ends_with($code, '/'))
			{
				$code = substr($code, 0, -1);
			}
		}
		if (preg_match('/^[a-z][a-z0-9_]*$/D', $code) !== 1)
		{
			return null;
		}

		$isDocument = in_array($code, ['invoicedocument', 'orderdocument'], true);
		if (
			($purpose === self::PURPOSE_DOCUMENT_GENERATOR && !$isDocument)
			|| ($purpose === self::PURPOSE_PAYMENT && $isDocument)
		)
		{
			return null;
		}

		$directory = self::BILL_ROOT . $code;
		if (is_file($documentRoot . $directory . '/handler.php'))
		{
			return [
				'code' => $code,
				'root' => self::BILL_ROOT,
				'directory' => $directory,
				'serviceAction' => $code,
				'className' => 'Sale\\Handlers\\PaySystem\\' . $code . 'Handler',
				'handlerType' => 'SYSTEM',
				'files' => ['handler' => 'handler.php', 'description' => '.description.php'],
			];
		}

		if (
			$actionFile !== $code
			|| $purpose === self::PURPOSE_DOCUMENT_GENERATOR
			|| !\Bitrix\Main\Loader::includeModule('sale')
			|| !\Bitrix\Sale\PaySystem\Manager::isRestHandler($code)
		)
		{
			return null;
		}

		return [
			'code' => $code,
			'root' => '/bitrix/modules/sale/',
			'directory' => '/bitrix/modules/sale/lib/paysystem',
			'serviceAction' => $code,
			'className' => 'Bitrix\\Sale\\PaySystem\\RestHandler',
			'handlerType' => '',
			'files' => ['handler' => 'resthandler.php'],
		];
	}
}
