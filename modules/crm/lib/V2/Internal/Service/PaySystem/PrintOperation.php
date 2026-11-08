<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\PaySystem;

use Bitrix\Crm\Service\Container;
use Psr\Log\LoggerInterface;

final class PrintOperation
{
	private const ENTRIES = [
		'quote_payment' => ['quote', ['print', 'pdf_download']],
		'quote_save_pdf' => ['quote', ['email_attachment']],
		'quote_details_attachment' => ['quote', ['email_attachment']],
		'quote_show_attachment' => ['quote', ['email_attachment']],
		'activity_email_quote' => ['quote', ['email_attachment']],
		'invoice_payment' => ['invoice', ['print', 'pdf_download']],
		'invoice_save_pdf' => ['invoice', ['email_attachment']],
		'activity_email_invoice' => ['invoice', ['email_attachment']],
		'invoice_payment_client' => ['invoice', ['print', 'pdf_download']],
		'ps_edit' => [null, ['preview']],
		'ps_edit_get_fields' => [null, ['preview']],
		'ps_edit_refresh_template' => [null, ['preview']],
	];

	private static ?self $current = null;
	private ?string $handlerCode = null;
	private bool $completed = false;

	public function __construct(
		private readonly string $entity,
		private readonly string $operation,
		private readonly string $entry,
		private readonly ?LoggerInterface $logger = null,
	)
	{
		$definition = self::ENTRIES[$entry] ?? null;
		if (
			!in_array($entity, ['quote', 'invoice'], true)
			|| $definition === null
			|| ($definition[0] !== null && $definition[0] !== $entity)
			|| !in_array($operation, $definition[1], true)
		)
		{
			throw new \InvalidArgumentException('Invalid print operation.');
		}
	}

	public static function getCurrent(): ?self
	{
		return self::$current;
	}

	public function run(callable $callback): mixed
	{
		$previous = self::$current;
		self::$current = $this;
		try
		{
			return $callback($this);
		}
		catch (\Throwable $exception)
		{
			$this->handlerCode = null;
			throw $exception;
		}
		finally
		{
			self::$current = $previous;
		}
	}

	public function capture(callable $callback, array $requestValues = []): array
	{
		$request = $_REQUEST;
		$globals = [];
		foreach (['SALE_INPUT_PARAMS', 'SALE_CORRESPONDENCE'] as $name)
		{
			if (array_key_exists($name, $GLOBALS))
			{
				$globals[$name] = $GLOBALS[$name];
			}
		}
		$bufferLevel = ob_get_level();
		ob_start();
		try
		{
			foreach ($requestValues as $name => $value)
			{
				$_REQUEST[$name] = $value;
			}
			$value = $callback();
			if (ob_get_level() <= $bufferLevel)
			{
				throw new \RuntimeException('Print output buffer was closed.');
			}
			while (ob_get_level() > $bufferLevel + 1)
			{
				ob_end_flush();
			}

			return ['value' => $value, 'output' => (string)ob_get_contents()];
		}
		finally
		{
			while (ob_get_level() > $bufferLevel)
			{
				ob_end_clean();
			}
			$_REQUEST = $request;
			foreach (['SALE_INPUT_PARAMS', 'SALE_CORRESPONDENCE'] as $name)
			{
				if (array_key_exists($name, $globals))
				{
					$GLOBALS[$name] = $globals[$name];
				}
				else
				{
					unset($GLOBALS[$name]);
				}
			}
		}
	}

	public function markGenerated(string $handlerCode): void
	{
		$entry = (new HandlerCatalog())->find($handlerCode, HandlerCatalog::PURPOSE_LEGACY_PRINT);
		if (
			$entry === null
			|| $handlerCode !== $entry['code']
			|| ($entry['className'] === null ? 'quote' : 'invoice') !== $this->entity
			|| ($this->handlerCode !== null && $this->handlerCode !== $handlerCode)
		)
		{
			throw new \InvalidArgumentException('Invalid print handler.');
		}

		$this->handlerCode = $handlerCode;
	}

	public function complete(): void
	{
		if ($this->completed || $this->handlerCode === null)
		{
			return;
		}
		$this->completed = true;

		try
		{
			$logger = $this->logger ?? Container::getInstance()->getLogger('Default');
			$logger->info(sprintf(
				'crm.legacy_print.used version=1 entity=%s operation=%s entry=%s handler=%s',
				$this->entity,
				$this->operation,
				$this->entry,
				$this->handlerCode,
			));
		}
		catch (\Throwable)
		{
			// Logging must not change the generated document.
		}
	}
}
