<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Reason;

use Bitrix\Main\Localization\Loc;

/**
 * The single source of truth for digest refusal reasons: both activities and the tests read it, never a copy.
 * Case values are compared by workflow template expressions, so renaming one breaks the template silently.
 */
enum DigestReason: string
{
	case NoDashboardRight = 'no_dashboard_right';
	case DashboardNotFound = 'dashboard_not_found';
	case ScopeValueNotAccessible = 'scope_value_not_accessible';
	case NoAccessibleScopeValue = 'no_accessible_scope_value';
	case ScopeValueForeignToDashboard = 'scope_value_foreign_to_dashboard';
	case ScopeParametersMismatch = 'scope_parameters_mismatch';
	case BiPreparing = 'bi_preparing';
	case DashboardPreparing = 'dashboard_preparing';
	case DashboardNotSynced = 'dashboard_not_synced';
	case WarmupFailed = 'warmup_failed';
	case DashboardTimeout = 'dashboard_timeout';
	case BiDisabled = 'bi_disabled';
	case DomainNotLinked = 'domain_not_linked';
	case BiLimitExceeded = 'bi_limit_exceeded';
	case BiDeleted = 'bi_deleted';

	public function code(): string
	{
		return $this->value;
	}

	public function reasonClass(): DigestReasonClass
	{
		return match ($this)
		{
			self::NoDashboardRight,
			self::DashboardNotFound,
			self::ScopeValueNotAccessible,
			self::NoAccessibleScopeValue,
			self::ScopeValueForeignToDashboard,
			self::ScopeParametersMismatch => DigestReasonClass::Settings,

			self::BiPreparing,
			self::DashboardPreparing,
			self::DashboardNotSynced,
			self::WarmupFailed,
			self::DashboardTimeout => DigestReasonClass::Temporary,

			self::BiDisabled,
			self::DomainNotLinked,
			self::BiLimitExceeded,
			self::BiDeleted => DigestReasonClass::Configuration,
		};
	}

	public function text(): string
	{
		return Loc::getMessage($this->phraseKey()) ?? $this->value;
	}

	/**
	 * Wording for the setup form, where the reason stands next to the name of the
	 * person it is about. Reasons that never reach the setup - everything outside
	 * {@see DigestReasonClass::Settings} - keep the after-the-run wording.
	 */
	public function setupText(): string
	{
		$key = $this->setupPhraseKey();

		return $key === null ? $this->text() : (Loc::getMessage($key) ?? $this->value);
	}

	/**
	 * Two reasons hold for the setup as a whole rather than for the recipient they
	 * came back for: the scope the setup names does not fit the report, so nobody
	 * would be sent anything. They are shown once and without a name.
	 */
	public function isAboutSetupAsWhole(): bool
	{
		return match ($this)
		{
			self::ScopeValueForeignToDashboard,
			self::ScopeParametersMismatch => true,

			default => false,
		};
	}

	private function phraseKey(): string
	{
		return match ($this)
		{
			self::NoDashboardRight => 'BI_DIGEST_REASON_NO_DASHBOARD_RIGHT',
			self::DashboardNotFound => 'BI_DIGEST_REASON_DASHBOARD_NOT_FOUND',
			self::ScopeValueNotAccessible => 'BI_DIGEST_REASON_SCOPE_VALUE_NOT_ACCESSIBLE',
			self::NoAccessibleScopeValue => 'BI_DIGEST_REASON_NO_ACCESSIBLE_SCOPE_VALUE',
			self::ScopeValueForeignToDashboard => 'BI_DIGEST_REASON_SCOPE_VALUE_FOREIGN',
			self::ScopeParametersMismatch => 'BI_DIGEST_REASON_SCOPE_PARAMETERS_MISMATCH',
			self::BiPreparing => 'BI_DIGEST_REASON_BI_PREPARING',
			self::DashboardPreparing => 'BI_DIGEST_REASON_DASHBOARD_PREPARING',
			self::DashboardNotSynced => 'BI_DIGEST_REASON_DASHBOARD_NOT_SYNCED',
			self::WarmupFailed => 'BI_DIGEST_REASON_WARMUP_FAILED',
			self::DashboardTimeout => 'BI_DIGEST_REASON_DASHBOARD_TIMEOUT',
			self::BiDisabled => 'BI_DIGEST_REASON_BI_DISABLED',
			self::DomainNotLinked => 'BI_DIGEST_REASON_DOMAIN_NOT_LINKED',
			self::BiLimitExceeded => 'BI_DIGEST_REASON_BI_LIMIT_EXCEEDED',
			self::BiDeleted => 'BI_DIGEST_REASON_BI_DELETED',
		};
	}

	private function setupPhraseKey(): ?string
	{
		return match ($this)
		{
			self::NoDashboardRight => 'BI_DIGEST_SETUP_REASON_NO_DASHBOARD_RIGHT',
			self::DashboardNotFound => 'BI_DIGEST_SETUP_REASON_DASHBOARD_NOT_FOUND',
			self::ScopeValueNotAccessible => 'BI_DIGEST_SETUP_REASON_SCOPE_VALUE_NOT_ACCESSIBLE',
			self::NoAccessibleScopeValue => 'BI_DIGEST_SETUP_REASON_NO_ACCESSIBLE_SCOPE_VALUE',
			self::ScopeValueForeignToDashboard => 'BI_DIGEST_SETUP_REASON_SCOPE_VALUE_FOREIGN',
			self::ScopeParametersMismatch => 'BI_DIGEST_SETUP_REASON_SCOPE_PARAMETERS_MISMATCH',

			default => null,
		};
	}
}
