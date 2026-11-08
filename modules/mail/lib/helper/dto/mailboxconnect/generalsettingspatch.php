<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Dto\MailboxConnect;

use Bitrix\Mail\Helper\Enum\CrmEntityType;
use Bitrix\Mail\Helper\Enum\CrmFlag;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class GeneralSettingsPatch implements \JsonSerializable
{
	public const ERROR_INVALID_PATCH = 'MAILBOX_GENERAL_SETTINGS_INVALID_PATCH';
	public const ERROR_UNSUPPORTED_FIELD = 'MAILBOX_GENERAL_SETTINGS_UNSUPPORTED_FIELD';

	private const TYPES = [
		'mailboxName' => 'string',
		'link' => 'string',
		'senderName' => 'string',
		'useSenderName' => 'boolean',
		'iCalAccess' => 'boolean',
		'shareAccess' => 'string[]',
		'crmOptions' => [
			'enabled' => 'boolean',
			'config' => [
				'crm_new_entity_in' => 'entity',
				'crm_new_entity_out' => 'entity',
				'crm_lead_source' => 'string',
				'crm_lead_resp' => 'integer[]',
				'crm_new_lead_for' => 'string[]',
				'crm_public' => 'boolean',
				'crm_vcf' => 'boolean',
				'crm_sync_days' => 'integer|null',
			],
		],
	];

	public function __construct(private readonly array $values)
	{
	}

	public static function fromArray(array $values): self
	{
		return new self($values);
	}

	public function has(string $path): bool
	{
		$value = $this->values;
		foreach (explode('.', $path) as $key)
		{
			if (!is_array($value) || !array_key_exists($key, $value))
			{
				return false;
			}
			$value = $value[$key];
		}

		return true;
	}

	public function get(string $path): mixed
	{
		$value = $this->values;
		foreach (explode('.', $path) as $key)
		{
			if (!is_array($value) || !array_key_exists($key, $value))
			{
				return null;
			}
			$value = $value[$key];
		}

		return $value;
	}

	public function isEmpty(): bool
	{
		$values = $this->values;
		if (is_array($values['crmOptions'] ?? null) && ($values['crmOptions']['config'] ?? null) === [])
		{
			unset($values['crmOptions']['config']);
		}
		if (($values['crmOptions'] ?? null) === [])
		{
			unset($values['crmOptions']);
		}

		return $values === [];
	}

	public function hasCrmChanges(): bool
	{
		return $this->has('crmOptions.enabled') || ($this->get('crmOptions.config') ?? []) !== [];
	}

	public function toArray(): array
	{
		return $this->values;
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	public function validate(): Result
	{
		$result = new Result();
		$this->validateFields($this->values, self::TYPES, '', $result);

		return $result;
	}

	/** Merge only after the mailbox has been locked and its options read again. */
	public function mergeCrmOptions(array $options): CrmOptions
	{
		$flags = (array)($options['flags'] ?? []);
		$denyBoth = in_array(CrmFlag::DenyNewLead->value, $flags, true);
		$config = [
			'crm_new_entity_in' => $denyBoth || in_array(CrmFlag::DenyEntityIn->value, $flags, true)
				? '' : ($options['crm_new_entity_in'] ?? ''),
			'crm_new_entity_out' => $denyBoth || in_array(CrmFlag::DenyEntityOut->value, $flags, true)
				? '' : ($options['crm_new_entity_out'] ?? ''),
			'crm_lead_source' => $options['crm_lead_source'] ?? '',
			'crm_lead_resp' => $options['crm_lead_resp'] ?? [],
			'crm_new_lead_for' => $options['crm_new_lead_for'] ?? [],
			'crm_public' => $options['crm_public'] ?? in_array(CrmFlag::PublicBind->value, $flags, true),
			'crm_vcf' => $options['crm_vcf'] ?? !in_array(CrmFlag::DenyNewContact->value, $flags, true),
			'crm_sync_days' => $options['crm_sync_days'] ?? null,
		];

		return CrmOptions::fromArray([
			'enabled' => $this->has('crmOptions.enabled')
				? $this->get('crmOptions.enabled')
				: in_array(CrmFlag::Connect->value, $flags, true),
			'config' => array_replace($config, $this->get('crmOptions.config') ?? []),
		]);
	}

	private function validateFields(array $values, array $types, string $prefix, Result $result): void
	{
		foreach ($values as $key => $value)
		{
			$path = $prefix . $key;
			if (!array_key_exists($key, $types))
			{
				$result->addError(new Error(
					'Unsupported mailbox setting: ' . $path,
					self::ERROR_UNSUPPORTED_FIELD,
					['field' => $path],
				));

				continue;
			}

			$type = $types[$key];
			if (is_array($type) && is_array($value))
			{
				$this->validateFields($value, $type, $path . '.', $result);

				continue;
			}

			$valid = is_string($type) && match ($type)
			{
				'string' => is_string($value),
				'boolean' => is_bool($value),
				'integer|null' => $value === null || is_int($value),
				'entity' => is_string($value) && ($value === '' || CrmEntityType::tryFrom($value) !== null),
				'string[]', 'integer[]' => $this->isListOf($value, $type),
				default => false,
			};
			if (!$valid)
			{
				$result->addError(new Error(
					'Invalid mailbox setting: ' . $path,
					self::ERROR_INVALID_PATCH,
					['field' => $path],
				));
			}
		}
	}

	private function isListOf(mixed $value, string $type): bool
	{
		if (!is_array($value) || !array_is_list($value))
		{
			return false;
		}
		foreach ($value as $item)
		{
			if ($type === 'string[]' ? !is_string($item) : (!is_int($item) || $item <= 0))
			{
				return false;
			}
		}

		return true;
	}
}
