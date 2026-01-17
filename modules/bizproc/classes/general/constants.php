<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;

class CBPActivityExecutionStatus
{
	public const Initialized = 0;
	public const Closed = 3;
	public const Cancelled = 5;

	public const Executing = 1;
	public const Canceling = 2;
	public const Faulting = 4;

	public static function out($v): string
	{
		return match ($v)
		{
			self::Initialized => 'Initialized',
			self::Executing => 'Executing',
			self::Canceling => 'Canceling',
			self::Closed => 'Closed',
			self::Faulting => 'Faulting',
			self::Cancelled => 'Cancelled',
			default => throw new SystemException('UnknownActivityExecutionStatus'),
		};
	}

	public static function isInProgress(int $status): bool
	{
		return $status === self::Executing || $status === self::Canceling || $status === self::Faulting;
	}
}

class CBPActivityExecutionResult
{
	public const None = 0;
	public const Succeeded = 1;
	public const Canceled = 2;
	public const Faulted = 3;
	public const Uninitialized = 4;

	public static function out($v): string
	{
		return match ($v)
		{
			self::None => 'None',
			self::Succeeded => 'Succeeded',
			self::Canceled => 'Canceled',
			self::Faulted => 'Faulted',
			self::Uninitialized => 'Uninitialized',
			default => throw new SystemException('UnknownActivityExecutionResult'),
		};
	}
}

class CBPWorkflowStatus
{
	public const Created = 0;
	public const Running = 1;
	public const Completed = 2;
	public const Suspended = 3;
	public const Terminated = 4;

	public static function out($v): string
	{
		return match ($v)
		{
			self::Created => 'Created',
			self::Running => 'Running',
			self::Completed => 'Completed',
			self::Suspended => 'Suspended',
			self::Terminated => 'Terminated',
			default => throw new SystemException('UnknownWorkflowStatus'),
		};
	}

	public static function isFinished(int $status): bool
	{
		return $status === static::Completed || $status === static::Terminated;
	}
}

class CBPActivityExecutorOperationType
{
	public const Execute = 0;
	public const Cancel = 1;
	public const HandleFault = 2;

	public static function out($v): string
	{
		return match ($v)
		{
			self::Execute => 'Execute',
			self::Cancel => 'Running',
			self::HandleFault => 'HandleFault',
			default => throw new SystemException('UnknownActivityExecutorOperationType'),
		};
	}
}

class CBPDocumentEventType
{
	public const None = 0;
	public const Create = 1;
	public const Edit = 2;
	public const Delete = 4;
	public const Automation = 8;
	public const Manual = 16;
	public const Script = 32;
	public const Debug = 64;
	public const Trigger = 128;

	public static function out($v): string
	{
		$constants = (new ReflectionClass(__CLASS__))->getConstants();
		$result = [];

		foreach ($constants as $name => $value)
		{
			if (($v & $value) !== 0)
			{
				$result[] = $name;
			}
		}

		return implode(', ', $result);
	}
}

class CBPCanUserOperateOperation
{
	public const ViewWorkflow = 0;
	public const StartWorkflow = 1;
	public const CreateWorkflow = 4;
	public const CreateAutomation = 5;
	public const WriteDocument = 2;
	public const ReadDocument = 3;
	public const DebugAutomation = 6;
}

class CBPSetPermissionsMode
{
	public const Hold = 1;
	public const Rewrite = 2;
	public const Clear = 3;

	public const ScopeWorkflow = 1;
	public const ScopeDocument = 2;

	public static function outMode($v): string
	{
		return match ((int)$v)
		{
			self::Rewrite => 'Rewrite',
			self::Clear => 'Clear',
			default => 'Hold',
		};
	}

	public static function outScope($v): string
	{
		if ((int)$v === self::ScopeDocument)
		{
			return "ScopeDocument";
		}

		return "ScopeWorkflow";
	}
}

class CBPTaskStatus
{
	public const Running = 0;
	public const CompleteYes = 1;
	public const CompleteNo = 2;
	public const CompleteOk = 3;
	public const Timeout = 4;
	public const CompleteCancel = 5;

	public static function isSuccess(int $status): bool
	{
		return $status === self::CompleteYes || $status === self::CompleteOk;
	}
}

class CBPTaskUserStatus
{
	public const Waiting = 0;
	public const Yes = 1;
	public const No = 2;
	public const Ok = 3;
	public const Cancel = 4;

	public static function resolveStatus($name): ?int
	{
		return match (mb_strtolower((string)$name))
		{
			'0', 'waiting' => self::Waiting,
			'1', 'yes' => self::Yes,
			'2', 'no' => self::No,
			'3', 'ok' => self::Ok,
			'4', 'cancel' => self::Cancel,
			default => null,
		};
	}

	public static function isPositive(int $status): bool
	{
		return $status === self::Yes || $status === self::Ok;
	}

	public static function isNegative(int $status): bool
	{
		return $status === self::No || $status === self::Cancel;
	}
}

class CBPTaskChangedStatus
{
	public const Add = 1;
	public const Update = 2;
	public const Delegate = 3;
	public const Delete = 4;
}

class CBPTaskDelegationType
{
	public const Subordinate = 0; // default value
	public const AllEmployees = 1;
	public const None = 2;
	public const ExactlyNone = 3; // not public type

	public static function getSelectList(): array
	{
		return [
			self::Subordinate => Loc::getMessage('BPCG_CONSTANTS_DELEGATION_TYPE_SUBORDINATE'),
			self::AllEmployees => Loc::getMessage('BPCG_CONSTANTS_DELEGATION_TYPE_ALL_EMPLOYEES'),
			self::None => Loc::getMessage('BPCG_CONSTANTS_DELEGATION_TYPE_NONE'),
		];
	}
}
