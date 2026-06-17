<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Dto\MailboxConnect;

use Bitrix\Mail\Helper\Enum\CrmEntityType;
use Bitrix\Mail\Helper\Enum\CrmOption;

final class CrmOptions implements \JsonSerializable
{
	public function __construct(
		public bool $enabled = false,
		public ?CrmEntityType $newEntityIn = null,
		public ?CrmEntityType $newEntityOut = null,
		public ?string $leadSource = null,
		/** @var int[] */
		public array $leadResp = [],
		/** @var string[] */
		public array $newLeadFor = [],
		public bool $public = false,
		public bool $vcf = false,
		public ?int $syncDays = null,
	)
	{
	}

	/**
	 * @param array{
	 *     enabled?: bool|string,
	 *     config?: array{
	 *         crm_new_entity_in?: string,
	 *         crm_new_entity_out?: string,
	 *         crm_lead_source?: string,
	 *         crm_lead_resp?: int[],
	 *         crm_new_lead_for?: string[]|string,
	 *         crm_public?: bool|string,
	 *         crm_vcf?: bool|string,
	 *         crm_sync_days?: int,
	 *     },
	 * } $data
	 */
	public static function fromArray(array $data): self
	{
		$config = $data['config'] ?? [];

		$newLeadFor = $config[CrmOption::NewLeadFor->value] ?? [];
		if (is_string($newLeadFor))
		{
			$newLeadFor = $newLeadFor !== '' ? preg_split('/[\r\n,;]+/', $newLeadFor) : [];
		}

		return new self(
			enabled: self::toBool($data['enabled'] ?? false),
			newEntityIn: CrmEntityType::tryFrom((string)($config[CrmOption::NewEntityIn->value] ?? '')),
			newEntityOut: CrmEntityType::tryFrom((string)($config[CrmOption::NewEntityOut->value] ?? '')),
			leadSource: isset($config[CrmOption::LeadSource->value]) ? (string)$config[CrmOption::LeadSource->value] : null,
			leadResp: (array)($config[CrmOption::LeadResp->value] ?? []),
			newLeadFor: array_values(array_filter((array)$newLeadFor)),
			public: self::toBool($config[CrmOption::Public->value] ?? false),
			vcf: self::toBool($config[CrmOption::Vcf->value] ?? false),
			syncDays: isset($config[CrmOption::SyncDays->value]) ? (int)$config[CrmOption::SyncDays->value] : null,
		);
	}

	/**
	 * @return array{
	 *     enabled: bool,
	 *     config: array{
	 *          crm_new_entity_in?: string,
	 *          crm_new_entity_out?: string,
	 *          crm_lead_source?: string,
	 *          crm_lead_resp?: int[],
	 *          crm_new_lead_for?: string[]|string,
	 *          crm_public?: bool|string,
	 *          crm_vcf?: bool|string,
	 *          crm_sync_days?: int,
	 *      }
	 *	 }
	 */
	public function toArray(): array
	{
		$config = [];

		if ($this->enabled)
		{
			$config = [
				CrmOption::NewEntityIn->value => $this->newEntityIn?->value ?? '',
				CrmOption::NewEntityOut->value => $this->newEntityOut?->value ?? '',
				CrmOption::LeadSource->value => $this->leadSource ?? '',
				CrmOption::LeadResp->value => $this->leadResp,
				CrmOption::NewLeadFor->value => $this->newLeadFor,
				CrmOption::Public->value => $this->public,
				CrmOption::Vcf->value => $this->vcf,
				CrmOption::SyncDays->value => $this->syncDays,
			];
		}

		return [
			'enabled' => $this->enabled,
			'config' => $config,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	public static function disabled(): self
	{
		return new self(enabled: false);
	}

	private static function toBool(mixed $value): bool
	{
		if ($value === 'Y' || $value === 'N')
		{
			return $value === 'Y';
		}

		return (bool)$value;
	}
}
