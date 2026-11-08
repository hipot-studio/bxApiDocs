<?php

namespace Bitrix\BIConnector\Access\Rule;

/**
 * Rule of the toggler permission "manage table rights".
 *
 * The class carries no behaviour of its own and exists for its name: BIConstructorRuleFactory builds
 * the class name out of the words of the action and, finding none, falls back to the rule of the axis
 * whose word it sees. The word "table" would send this toggler to TableRule, which answers per object
 * and therefore always says no here - silently. ExternalDashboardConfigRule exists for the same reason.
 */
final class SettingsEditTableRightsRule extends BaseRule
{
}
