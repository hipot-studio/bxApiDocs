<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder;

/**
 * Machine-readable reason of a refused builder operation, shared by every entry point.
 *
 * RateLimit is never returned by an operation: both rate limiters live in the entry points,
 * the code is declared for the method catalog.
 *
 * Unavailable is a transient state of the dashboard (loading, failed, not synced yet), retrying
 * may help; FeatureDisabled is the builder switched off on the portal plan, retrying does not.
 */
enum FailureCode: string
{
	case AccessDenied = 'ACCESS_DENIED';
	case NotFound = 'NOT_FOUND';
	case NotCustom = 'NOT_CUSTOM';
	case Unavailable = 'BI_UNAVAILABLE';
	case FeatureDisabled = 'FEATURE_DISABLED';
	case InvalidArgument = 'INVALID_ARGUMENT';
	case PublishedReport = 'PUBLISHED_REPORT';
	case DestructiveBudget = 'DESTRUCTIVE_BUDGET_EXCEEDED';
	case RateLimit = 'RATE_LIMIT_EXCEEDED';
	case DomainRule = 'DOMAIN_RULE';
	case VersionConflict = 'VERSION_CONFLICT';
}
