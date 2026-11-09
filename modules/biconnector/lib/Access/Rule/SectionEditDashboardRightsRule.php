<?php

namespace Bitrix\BIConnector\Access\Rule;

/**
 * Rule of the right to edit the rights of the dashboard group section.
 *
 * The class carries no check of its own and exists for its name alone: without it
 * BIConstructorRuleFactory falls back to a rule guessed from the words of the action name, and a
 * guessed rule of an axis answers "no" to every check without a word. Sample: ExternalDashboardConfigRule.
 */
final class SectionEditDashboardRightsRule extends BaseRule
{
}
