<?php

namespace Bitrix\StaffTrack\Dictionary;

/**
 * The wire codes of the staff of an office. They live apart from {@see OfficeErrorCode} because most of
 * them answer about a person rather than about the office: a reason per employee travels in the data of
 * the answer, not as an error of the call.
 */
final class OfficeEmployeeErrorCode
{
	public const NOT_FOUND = 'STAFFTRACK_OFFICE_EMPLOYEE_NOT_FOUND';
	public const NO_STRUCTURE = 'STAFFTRACK_OFFICE_EMPLOYEE_NO_STRUCTURE';
	public const SAVE_FAILED = 'STAFFTRACK_OFFICE_EMPLOYEE_SAVE_FAILED';
	public const TOO_MANY_CODES = 'STAFFTRACK_OFFICE_EMPLOYEE_TOO_MANY_CODES';

	/**
	 * The ceiling of the removal, which takes people by identifier rather than by code of the selector:
	 * a code of the assignment would report a limit this call does not have.
	 */
	public const TOO_MANY_USER_IDS = 'STAFFTRACK_OFFICE_EMPLOYEE_TOO_MANY_USER_IDS';
}
