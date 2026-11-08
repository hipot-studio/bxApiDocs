<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Trigger\ContextFields;

/**
 * Contract of the employee dismissal trigger node, held once for both sides of it: the fields of the event
 * the handler of the dismissal raises and the node reads back, and the codes the filter of the node refuses
 * an employee with, which the handler tells apart from a failure of the launch.
 */
final class EmployeeDismissalTrigger
{
	public const FIELD_USER_ID = 'userId';
	public const FIELD_FIRED_AT = 'firedAt';
	public const FIELD_NAME = 'name';
	public const FIELD_LAST_NAME = 'lastName';

	/**
	 * Prefix of the error codes the filter of the node answers with when it does not take the employee
	 * ({@see \CBPEmployeeDismissalTrigger::checkApplyRules()}). The handler of the event tells a refusal of
	 * the filter from a failure of the launch by this prefix alone, so the prefix and the codes below stay
	 * one literal here: the node itself lives in an activity file the engine loads on demand, which is why
	 * neither side may hold a copy of its own.
	 */
	public const FILTER_REFUSAL_CODE_PREFIX = 'BIZPROC_EMPLOYEE_DISMISSAL_TRIGGER_';

	/**
	 * The event reached the node without an employee at all. The node is a public trigger, so any raiser of
	 * the event reaches it, and a refusal of the node is what this is: the code carries the prefix above.
	 */
	public const REFUSAL_NO_EMPLOYEE = self::FILTER_REFUSAL_CODE_PREFIX . 'NO_EMPLOYEE';

	public const REFUSAL_UNRESOLVED_FILTER = self::FILTER_REFUSAL_CODE_PREFIX . 'UNRESOLVED_FILTER';

	/**
	 * The binding names an employee by hand, which the node selector of the setting cannot express any
	 * more. The whole binding is refused for it: narrowing such a setting down to the nodes standing next
	 * to the employee would change what a published scenario does no less than taking everyone would.
	 */
	public const REFUSAL_STALE_USER_CODES = self::FILTER_REFUSAL_CODE_PREFIX . 'STALE_USER_CODES';

	public const REFUSAL_NO_STRUCTURE_CODES = self::FILTER_REFUSAL_CODE_PREFIX . 'NO_STRUCTURE_CODES';
	public const REFUSAL_NO_MEMBER_NODES = self::FILTER_REFUSAL_CODE_PREFIX . 'NO_MEMBER_NODES';
	public const REFUSAL_OUTSIDE_SELECTION = self::FILTER_REFUSAL_CODE_PREFIX . 'OUTSIDE_SELECTION';

	/**
	 * The org structure is not on the portal at all while a binding is saved. Fails closed: a node that
	 * cannot read the binding it was given must not start a scenario for every dismissal instead.
	 */
	public const REFUSAL_NO_HUMANRESOURCES = self::FILTER_REFUSAL_CODE_PREFIX . 'NO_HUMANRESOURCES';
}
