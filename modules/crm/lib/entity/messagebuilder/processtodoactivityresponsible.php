<?php

namespace Bitrix\Crm\Entity\MessageBuilder;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Supports phrase codes:
 *
 * CRM_ACTIVITY_TODO_DEFAULT_BECOME_RESPONSIBLE
 * CRM_ACTIVITY_TODO_LEAD_BECOME_RESPONSIBLE
 * CRM_ACTIVITY_TODO_DEAL_BECOME_RESPONSIBLE
 * CRM_ACTIVITY_TODO_CONTACT_BECOME_RESPONSIBLE
 * CRM_ACTIVITY_TODO_COMPANY_BECOME_RESPONSIBLE
 * CRM_ACTIVITY_TODO_QUOTE_BECOME_RESPONSIBLE
 * CRM_ACTIVITY_TODO_ORDER_BECOME_RESPONSIBLE
 * CRM_ACTIVITY_TODO_SMART_INVOICE_BECOME_RESPONSIBLE
 * CRM_ACTIVITY_TODO_DYNAMIC_BECOME_RESPONSIBLE
 *
 * CRM_ACTIVITY_TODO_DEFAULT_NO_LONGER_RESPONSIBLE
 * CRM_ACTIVITY_TODO_LEAD_NO_LONGER_RESPONSIBLE
 * CRM_ACTIVITY_TODO_DEAL_NO_LONGER_RESPONSIBLE
 * CRM_ACTIVITY_TODO_CONTACT_NO_LONGER_RESPONSIBLE
 * CRM_ACTIVITY_TODO_COMPANY_NO_LONGER_RESPONSIBLE
 * CRM_ACTIVITY_TODO_QUOTE_NO_LONGER_RESPONSIBLE
 * CRM_ACTIVITY_TODO_ORDER_NO_LONGER_RESPONSIBLE
 * CRM_ACTIVITY_TODO_SMART_INVOICE_NO_LONGER_RESPONSIBLE
 * CRM_ACTIVITY_TODO_DYNAMIC_NO_LONGER_RESPONSIBLE
 *
 * CRM_ACTIVITY_TODO_DEFAULT_BECOME_RESPONSIBLE_EX
 * CRM_ACTIVITY_TODO_DEFAULT_NO_LONGER_RESPONSIBLE_EX
 *
 * CRM_ACTIVITY_TODO_DEFAULT_BECOME_RESPONSIBLE_EMPTY_SUBJECT
 * CRM_ACTIVITY_TODO_DEFAULT_NO_LONGER_RESPONSIBLE_EMPTY_SUBJECT
 *
 */
final class ProcessToDoActivityResponsible extends ProcessEntity
{
	public const BECOME = 'BECOME_RESPONSIBLE';
	public const BECOME_EX = 'BECOME_RESPONSIBLE_EX';
	public const BECOME_EMPTY_SUBJECT = 'BECOME_RESPONSIBLE_EMPTY_SUBJECT';
	public const NO_LONGER = 'NO_LONGER_RESPONSIBLE';
	public const NO_LONGER_EX = 'NO_LONGER_RESPONSIBLE_EX';
	public const NO_LONGER_EMPTY_SUBJECT = 'NO_LONGER_RESPONSIBLE_EMPTY_SUBJECT';

	protected const MESSAGE_BASE_PREFIX = 'CRM_ACTIVITY_TODO';
}