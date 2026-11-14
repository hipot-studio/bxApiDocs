<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Tracking\Ad;

enum Refusal: string
{
	case AccessDenied = 'CRM_TRACKING_AD_ACCESS_DENIED';
	case SourceNotFound = 'CRM_TRACKING_AD_SOURCE_NOT_FOUND';
	case SourceArchived = 'CRM_TRACKING_AD_SOURCE_ARCHIVED';
	case ProviderUnsupported = 'CRM_TRACKING_AD_PROVIDER_UNSUPPORTED';
	case CapabilityUnsupported = 'CRM_TRACKING_AD_CAPABILITY_UNSUPPORTED';
	case ProviderUnavailable = 'CRM_TRACKING_AD_PROVIDER_UNAVAILABLE';
	case SeoUnavailable = 'CRM_TRACKING_AD_SEO_UNAVAILABLE';
	case AuthRequired = 'CRM_TRACKING_AD_AUTH_REQUIRED';
	case AccountUnavailable = 'CRM_TRACKING_AD_ACCOUNT_UNAVAILABLE';
	case PeriodInvalid = 'CRM_TRACKING_AD_PERIOD_INVALID';
	case TimezoneUnsupported = 'CRM_TRACKING_AD_TIMEZONE_UNSUPPORTED';
	case OperationConflict = 'CRM_TRACKING_AD_OPERATION_CONFLICT';
	case OperationNotFound = 'CRM_TRACKING_AD_OPERATION_NOT_FOUND';
	case KeyConflict = 'CRM_TRACKING_AD_KEY_CONFLICT';
	case ExpensesConflict = 'CRM_TRACKING_AD_EXPENSES_CONFLICT';
	case RemoteTimeout = 'CRM_TRACKING_AD_REMOTE_TIMEOUT';
	case RemoteError = 'CRM_TRACKING_AD_REMOTE_ERROR';
	case CurrencyInvalid = 'CRM_TRACKING_AD_CURRENCY_INVALID';
	case StorageUnsupported = 'CRM_TRACKING_AD_STORAGE_UNSUPPORTED';

	public function exception(): \DomainException
	{
		return new \DomainException($this->value);
	}
}
