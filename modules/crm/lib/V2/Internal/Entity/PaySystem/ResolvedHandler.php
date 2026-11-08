<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\PaySystem;

final class ResolvedHandler
{
	private readonly array $fields;
	private readonly array $serviceFields;
	private readonly array $files;
	private readonly array $context;

	public function __construct(
		array $fields,
		private readonly string $code,
		private readonly string $purpose,
		string $serviceAction,
		array $files,
		private readonly ?string $className,
		private readonly ?string $handlerType,
		array $context,
		private readonly ?int $paymentId = null,
		private readonly ?int $paySystemId = null,
	)
	{
		$this->fields = self::copyArray($fields);
		$serviceFields = $this->fields;
		$serviceFields['ACTION_FILE'] = $serviceAction;
		$this->serviceFields = $serviceFields;
		$this->files = self::copyArray($files);
		$this->context = self::copyArray($context);
	}

	public function getFields(): array
	{
		return $this->fields;
	}

	public function getServiceFields(): array
	{
		return $this->serviceFields;
	}

	public function getCode(): string
	{
		return $this->code;
	}

	public function getPurpose(): string
	{
		return $this->purpose;
	}

	public function getFiles(): array
	{
		return $this->files;
	}

	public function getClassName(): ?string
	{
		return $this->className;
	}

	public function getHandlerType(): ?string
	{
		return $this->handlerType;
	}

	public function getContext(): array
	{
		return $this->context;
	}

	public function getPaymentId(): ?int
	{
		return $this->paymentId;
	}

	public function getPaySystemId(): ?int
	{
		return $this->paySystemId;
	}

	private static function copyArray(array $values, int $depth = 0): array
	{
		if ($depth > 32)
		{
			throw new \InvalidArgumentException('Invalid payment system snapshot.');
		}

		$copy = [];
		foreach ($values as $key => $value)
		{
			if (is_array($value))
			{
				$copy[$key] = self::copyArray($value, $depth + 1);
			}
			elseif ($value === null || is_scalar($value))
			{
				$copy[$key] = $value;
			}
			else
			{
				throw new \InvalidArgumentException('Invalid payment system snapshot.');
			}
		}

		return $copy;
	}
}
