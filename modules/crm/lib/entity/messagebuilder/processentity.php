<?php

namespace Bitrix\Crm\Entity\MessageBuilder;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Supported phrase codes:
 * 	CRM_PROCESS_ENTITY_LEAD_ADD
 * 	CRM_PROCESS_ENTITY_DEAL_ADD
 * 	CRM_PROCESS_ENTITY_RECURRING_DEAL_ADD
 * 	CRM_PROCESS_ENTITY_ORDER_ADD
 * 	CRM_PROCESS_ENTITY_ORDER_PAYMENT_ADD
 * 	CRM_PROCESS_ENTITY_ORDER_SHIPMENT_ADD
 * 	CRM_PROCESS_ENTITY_CONTACT_ADD
 * 	CRM_PROCESS_ENTITY_COMPANY_ADD
 * 	CRM_PROCESS_ENTITY_SMART_INVOICE_ADD
 * 	CRM_PROCESS_ENTITY_QUOTE_ADD_MSGVER_1
 * 	CRM_PROCESS_ENTITY_DYNAMIC_ADD
 * 	CRM_PROCESS_ENTITY_DEFAULT_ADD
 */
class ProcessEntity extends BaseBuilder
{
	public const PROCESS_ADD = 'ADD';

	protected string $type;
	private ?string $postfix;
	public const POSTFIX_SUBJECT = 'SUBJECT';
	public const POSTFIX_PLAIN_TEXT = 'PLAIN_TEXT';


	public function setType(string $type): self
	{
		$this->type = $type;

		return $this;
	}

	public function setPostfix(string $postfix): self
	{
		$this->postfix = $postfix;

		return $this;
	}

	public function buildCode(): string
	{
		$parts = [
			static::MESSAGE_BASE_PREFIX,
			$this->fetchEntityTypeName(),
			$this->type,
		];

		if (isset($this->postfix))
		{
			$parts[] = $this->postfix;
		}

		return implode('_', $parts);
	}

	final protected function buildDefaultCode(): string
	{
		$parts = [
			static::MESSAGE_BASE_PREFIX,
			'DEFAULT',
			$this->type,
		];

		if (isset($this->postfix))
		{
			$parts[] = $this->postfix;
		}

		return implode('_', $parts);
	}

	public static function getFilePath(): string
	{
		return __FILE__;
	}
}
