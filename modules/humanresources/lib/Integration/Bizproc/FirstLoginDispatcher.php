<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

use Bitrix\Bizproc\Starter\Result\StartResult;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Extranet\Service\ServiceContainer as ExtranetServiceContainer;
use Bitrix\Extranet\User as ExtranetUser;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Main\Authentication\Context as AuthenticationContext;
use Bitrix\Main\Authentication\Method;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\UserTable;
use Bitrix\Socialnetwork\Collab\CollabFeature;

/**
 * Recognizes the first login of an employee on the `main::OnAfterUserAuthorize` event and hands that
 * moment to the automation scenarios of the new employee trigger node.
 *
 * The event arrives on every login of every user, so recognition runs on the event snapshot alone: no
 * query and no neighbour module until the login is known to be a first one. A login that fails
 * recognition leaves silently: that is the vast majority of the calls, and a journal record per login
 * would be noise.
 *
 * Not final on purpose: the state a test cannot set within one process (a virtual security session, an
 * absent engine) is read through overridable methods.
 */
class FirstLoginDispatcher
{
	private const MODULE_ID = 'humanresources';

	/**
	 * Marker of the single launch per employee. Category and key are fixed: renaming them resets
	 * idempotency for every employee already dispatched, and their scenarios would run a second time.
	 */
	private const MARKER_CATEGORY = 'humanresources';
	private const MARKER_NAME = 'new_employee_trigger_launched';
	private const MARKER_VALUE = 'Y';

	/**
	 * Authorization methods that never carry the employee's own first login: an administrator logging in
	 * as the employee, an application password and the sites controller.
	 */
	private const REJECTED_METHODS = [Method::LoginAs, Method::AppPassword, Method::Controller];

	/**
	 * Prefix of the error codes the filter of the trigger node answers with when it does not take the
	 * employee ({@see \CBPNewEmployeeTrigger::checkApplyRules}). The node lives in an activity file the
	 * engine loads on demand, so the prefix is taken from the contract of the event and not off a constant
	 * of a class that may not be loaded at all.
	 */
	private const FILTER_REFUSAL_CODE_PREFIX = NewEmployeeTrigger::FILTER_REFUSAL_CODE_PREFIX;

	/** Refusal reasons of the journal, one per step of the gate. */
	public const REFUSAL_NO_BIZPROC = 'bizprocUnavailable';
	public const REFUSAL_EXTRANET = 'extranetMember';
	public const REFUSAL_COLLABER = 'collabMember';
	public const REFUSAL_ALREADY_RUN = 'alreadyRun';
	public const REFUSAL_FILTERED_OUT = 'filteredOut';
	public const REFUSAL_LAUNCH_FAILED = 'launchFailed';
	public const REFUSAL_UNEXPECTED_FAILURE = 'unexpectedFailure';

	/**
	 * Handler of `main::OnAfterUserAuthorize`. Stands in the login path of every user of the portal, so
	 * nothing it does may reach the caller: anything thrown, of any kind, goes to the journal instead.
	 *
	 * The parameter is left untyped, as in every other event handler of the module, so an unexpected call
	 * shape is answered by the journal and not by a broken login.
	 */
	public static function onAfterUserAuthorize($params): void
	{
		$params = is_array($params) ? $params : [];

		try
		{
			(new static())->dispatch($params);
		}
		catch (\Throwable $exception)
		{
			static::logRefusal(
				self::extractUserId($params),
				self::REFUSAL_UNEXPECTED_FAILURE,
				$exception->getMessage(),
			);
		}
	}

	private function dispatch(array $params): void
	{
		if (!$this->isFirstLogin($params))
		{
			return;
		}

		$userId = self::extractUserId($params);
		if ($userId <= 0)
		{
			return;
		}

		$this->launchForNewEmployee($userId);
	}

	/**
	 * ALG-01: recognition of the first login by the event snapshot only. The order is the order of cost:
	 * the cheapest check first, no query and no neighbour module anywhere.
	 */
	private function isFirstLogin(array $params): bool
	{
		if ($this->isVirtualSecuritySession())
		{
			return false;
		}

		// A REST authorization comes in numbers and writes no login history, so it can never be a first
		// login: the core marks exactly those calls by not updating the user data.
		if (($params['update'] ?? null) !== true)
		{
			return false;
		}

		$fields = $params['user_fields'] ?? null;
		if (!is_array($fields))
		{
			return false;
		}

		if (!empty($fields['LAST_LOGIN']))
		{
			return false;
		}

		// Only the service types are rejected. A non-empty type as such must not be: LDAP users carry
		// `LDAP#<id>`, social service and SSO users carry the service id, and those are real employees.
		if (in_array((string)($fields['EXTERNAL_AUTH_ID'] ?? ''), UserTable::getExternalUserTypes(), true))
		{
			return false;
		}

		return !in_array($this->authorizationMethod($params), self::REJECTED_METHODS, true);
	}

	/**
	 * ALG-02: the guard of the engine, the gate of portal employees and the marker of the single launch.
	 * Every refusal from here on names its reason in the journal.
	 */
	private function launchForNewEmployee(int $userId): void
	{
		if (!$this->includeProcessEngine())
		{
			static::logRefusal($userId, self::REFUSAL_NO_BIZPROC);

			return;
		}

		$outsiderReason = $this->findOutsiderReason($userId);
		if ($outsiderReason !== null)
		{
			static::logRefusal($userId, $outsiderReason);

			return;
		}

		// The marker stands behind the engine guard: on a portal without the engine the moment would be
		// spent for nothing, and there is no second first login.
		if ($this->hasMarker($userId))
		{
			static::logRefusal($userId, self::REFUSAL_ALREADY_RUN);

			return;
		}

		$this->setMarker($userId);

		$this->journalLaunchOutcome($userId, $this->getProcessLauncher()->launchForNewEmployee($userId));
	}

	/**
	 * The outcome of the launch as the journal has to read it. The result of the engine carries the refusals
	 * of the rules of every published scenario, so the result alone is not the answer: a scenario that did
	 * start comes back beside the scenarios the filter of the node did not take.
	 *
	 * A refusal of the filter is journalled apart from a failure of the engine: it is the normal answer for
	 * an employee outside the selected nodes, and it comes on every first login of such an employee. What is
	 * left keeps the name of a launch failure, the tariff refusal among it: the tariff is checked inside the
	 * process starter ({@see \Bitrix\Bizproc\Starter\Constraint\BPDesignerConstraint}) and reaches here as
	 * an error of the result, never as a state the guard above could read.
	 */
	private function journalLaunchOutcome(int $userId, Result $result): void
	{
		if ($result->isSuccess() || $this->isTriggerApplied($result))
		{
			return;
		}

		static::logRefusal(
			$userId,
			$this->isFilterRefusal($result) ? self::REFUSAL_FILTERED_OUT : self::REFUSAL_LAUNCH_FAILED,
			implode('; ', $result->getErrorMessages()),
		);
	}

	/**
	 * Whether the engine started a scenario of the trigger. The launch boundary answers with the result of
	 * the core, so the richer answer of the process starter is read only when it is the one that came.
	 */
	private function isTriggerApplied(Result $result): bool
	{
		return $result instanceof StartResult
			&& ($result->isTriggerApplied() || $result->getWorkflowIds() !== [])
		;
	}

	/**
	 * Whether the filter of the trigger node is what refused, and nothing else: a single error of any other
	 * origin keeps the whole launch a failure, so a portal problem is never journalled as a filtered login.
	 */
	private function isFilterRefusal(Result $result): bool
	{
		$errors = $result->getErrors();
		if ($errors === [])
		{
			return false;
		}

		foreach ($errors as $error)
		{
			if (!str_starts_with((string)$error->getCode(), self::FILTER_REFUSAL_CODE_PREFIX))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * The reason the user is not an employee of the portal, or null for an employee.
	 *
	 * Membership in the extranet group and the collab role are read directly, never the predicates of the
	 * employee domain: those are derived from having a department, and an employee without a department
	 * must still get their scenarios.
	 */
	private function findOutsiderReason(int $userId): ?string
	{
		if (!$this->includeExtranet())
		{
			return null;
		}

		if ((new ExtranetUser($userId))->isExtranet())
		{
			return self::REFUSAL_EXTRANET;
		}

		if (!Loader::includeModule('socialnetwork') || !CollabFeature::isOn())
		{
			return null;
		}

		return ExtranetServiceContainer::getInstance()->getCollaberService()->isCollaberById($userId)
			? self::REFUSAL_COLLABER
			: null
		;
	}

	private function hasMarker(int $userId): bool
	{
		return \CUserOptions::getOption(self::MARKER_CATEGORY, self::MARKER_NAME, false, $userId)
			=== self::MARKER_VALUE
		;
	}

	private function setMarker(int $userId): void
	{
		\CUserOptions::setOption(self::MARKER_CATEGORY, self::MARKER_NAME, self::MARKER_VALUE, false, $userId);
	}

	private function getProcessLauncher(): ProcessLauncherInterface
	{
		return Container::getBizprocProcessLauncher();
	}

	private function authorizationMethod(array $params): ?Method
	{
		$context = $params['context'] ?? null;

		return $context instanceof AuthenticationContext ? $context->getMethod() : null;
	}

	/**
	 * The normative source of the employee id is the event snapshot; reading it must not throw on a
	 * malformed one, because the caller of this class is the login itself.
	 */
	private static function extractUserId(array $params): int
	{
		$fields = $params['user_fields'] ?? null;

		return is_array($fields) ? (int)($fields['ID'] ?? 0) : 0;
	}

	/**
	 * A virtual session of the security module is a background authorization with nobody behind it.
	 * A method and not the constant itself: the constant cannot be switched within one process.
	 */
	protected function isVirtualSecuritySession(): bool
	{
		return defined('BX_SECURITY_SESSION_VIRTUAL') && BX_SECURITY_SESSION_VIRTUAL === true;
	}

	/**
	 * The engine and the start API the launcher goes through: an engine without that API can start nothing,
	 * and the marker must not be spent on a portal where it cannot.
	 */
	protected function includeProcessEngine(): bool
	{
		return Loader::includeModule('bizproc') && class_exists(Starter::class);
	}

	protected function includeExtranet(): bool
	{
		return Loader::includeModule('extranet');
	}

	protected static function logRefusal(int $userId, string $reason, string $details = ''): void
	{
		\AddMessage2Log(
			sprintf(
				'New employee scenarios are not launched for user %d: %s%s',
				$userId,
				$reason,
				$details === '' ? '' : '. ' . $details,
			),
			self::MODULE_ID,
		);
	}
}
