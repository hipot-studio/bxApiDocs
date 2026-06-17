<?php

namespace Bitrix\Intranet\Internal\Service\Invitation;

use Bitrix\Intranet\Enum\InvitationType;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Intranet\CurrentUser;
use Bitrix\Main\Application;

class Analytics
{
	private const ANALYTIC_TOOL = 'Invitation';
	private const ANALYTIC_CATEGORY_INVITATION = 'invitation';
	private const ANALYTIC_PARAM_IS_ADMIN_Y = 'isAdmin_Y';
	private const ANALYTIC_PARAM_IS_ADMIN_N = 'isAdmin_N';
	private const ANALYTIC_PARAM_EMPLOYEE_COUNT = 'employeeCount_';
	private const ANALYTIC_CATEGORY_REGISTRATION = 'registration';
	private const ANALYTIC_EVENT_INVITATION = 'invitation';
	private const ANALYTIC_EVENT_REGISTRATION = 'registration';
	private const ANALYTIC_INVITATION_TYPE_EMAIL = 'email';
	private const ANALYTIC_INVITATION_TYPE_PHONE = 'phone';
	public const ANALYTIC_EVENT_CHANGE_QUICK_REG = 'change_quick_reg';
	public const ANALYTIC_CATEGORY_SETTINGS = 'settings';
	public const ANALYTIC_INVITATION_TYPE_C_SUB_SECTION_EMAIL = 'tab_by_email';
	public const ANALYTIC_INVITATION_TYPE_C_SUB_SECTION_MASS = 'tab_mass';
	public const ANALYTIC_INVITATION_TYPE_C_SUB_SECTION_DEPARTMENT = 'tab_department';
	public const ANALYTIC_INVITATION_TYPE_C_SUB_SECTION_INTEGRATOR = 'tab_integrator';

	private function send(array $data): void
	{
		foreach ($data as $onaAnalytic)
		{
			if (isset($onaAnalytic['event'], $onaAnalytic['tool'], $onaAnalytic['category']))
			{
				$event = new AnalyticsEvent($onaAnalytic['event'], $onaAnalytic['tool'], $onaAnalytic['category']);

				if (isset($onaAnalytic['section']))
				{
					$event->setSection($onaAnalytic['section']);
				}
				if (isset($onaAnalytic['type']))
				{
					$event->setType($onaAnalytic['type']);
				}
				if (isset($onaAnalytic['subSection']))
				{
					$event->setSubSection($onaAnalytic['subSection']);
				}
				if (isset($onaAnalytic['status']))
				{
					$event->setStatus($onaAnalytic['status']);
				}
				if (isset($onaAnalytic['p1']))
				{
					$event->setP1($onaAnalytic['p1']);
				}
				if (isset($onaAnalytic['p2']))
				{
					$event->setP2($onaAnalytic['p2']);
				}
				if (isset($onaAnalytic['p3']))
				{
					$event->setP3($onaAnalytic['p3']);
				}
				if (isset($onaAnalytic['p5']))
				{
					$event->setP5($onaAnalytic['p5']);
				}
				$event->send();
			}
		}
	}

	public function sendRegistration(
		bool $isSuccess,
		bool $withDepartments,
		bool $withGroups,
		bool $withInvite,
		?\Bitrix\Intranet\Entity\User $invitedUser = null,
	): void
	{
		$event = new AnalyticsEvent(
			event: self::ANALYTIC_EVENT_REGISTRATION,
			tool: self::ANALYTIC_TOOL,
			category: self::ANALYTIC_CATEGORY_REGISTRATION,
		);
		$analyticData = $this->getData();
		$event
			->setStatus($isSuccess ? 'success' : 'fail')
			->setSection($analyticData['section'] ?? '')
			->setSubSection('email')
			->setP1($this->getAdmin())
			->setP2($withInvite ? 'withoutInvite_N' : 'withoutInvite_Y')
			->setP3($withDepartments ? 'department_Y' : 'department_N')
			->setP4($withGroups ? 'group_Y' : 'group_N')
		;

		if ($invitedUser)
		{
			$event->setP5('userId_' . $invitedUser->getId());
		}

		$event->send();
	}

	public function sendChangeQuickRegistration(bool $isEnabled): void
	{
		$event = new AnalyticsEvent(
			event: self::ANALYTIC_EVENT_CHANGE_QUICK_REG,
			tool: self::ANALYTIC_TOOL,
			category: self::ANALYTIC_CATEGORY_INVITATION,
		);
		$analyticData = $this->getData();
		$event
			->setStatus($isEnabled ? 'on' : 'off')
			->setSection($analyticData['section'] ?? '')
			->setP1($this->getAdmin())
		;
		$event->send();
	}

	public function sendInvitationByContacts(
		bool $isSuccess,
		int $totalCount,
		bool $withDepartments,
		bool $withGroups,
		?\Bitrix\Intranet\Entity\User $invitedUser = null,
	): void
	{
		$event = new AnalyticsEvent(
			event: self::ANALYTIC_EVENT_INVITATION,
			tool: self::ANALYTIC_TOOL,
			category: self::ANALYTIC_CATEGORY_INVITATION,
		);
		$analyticData = $this->getData();
		$event
			->setType($analyticData['type'] ?? '')
			->setStatus($isSuccess ? 'success' : 'fail')
			->setSection($analyticData['section'] ?? '')
			->setP1($this->getAdmin())
			->setP2('employeeCount_' . $totalCount)
			->setP3($withDepartments ? 'department_Y' : 'department_N')
			->setP4($withGroups ? 'group_Y' : 'group_N')
		;

		if ($invitedUser)
		{
			$event->setP5('userId_' . $invitedUser->getId());
			$event->setSubSection($invitedUser->getInvitedVia()?->value ?? '');
		}

		$event->send();
	}

	private function getData(): array
	{
		$analyticsData = Application::getInstance()->getContext()->getRequest()->getPost('analyticsData');
		$result = [];

		if (is_array($analyticsData))
		{
			$result = $analyticsData;
		}

		return $result;
	}

	public function sendInvitation(
		int $userId,
		string $subSection,
		bool $status,
		int $analyticEmails = 0,
		int $analyticPhones = 0,
		bool $isSelectedDepartments = false,
	): void
	{
		$analyticData = $this->getData();

		$analyticBase = [
			'tool' => self::ANALYTIC_TOOL,
			'category' => self::ANALYTIC_CATEGORY_INVITATION,
			'event' => self::ANALYTIC_EVENT_INVITATION,
			'section' => $analyticData['source'] ?? '',
			'subSection' => $subSection,
			'status' => $status ? 'success' : 'fail',
			'p1' => $this->getAdmin(),
			'p3' => $isSelectedDepartments ? 'department_Y' : 'department_N',
			'p5' => 'userId_' . $userId,
		];

		if ($analyticEmails > 0)
		{
			$analytic[] = array_merge($analyticBase, [
				'type' => self::ANALYTIC_INVITATION_TYPE_EMAIL,
				'p2' => self::ANALYTIC_PARAM_EMPLOYEE_COUNT . $analyticEmails,
			]);
		}

		if ($analyticPhones > 0)
		{
			$analytic[] = array_merge($analyticBase, [
				'type' => self::ANALYTIC_INVITATION_TYPE_PHONE,
				'p2' => self::ANALYTIC_PARAM_EMPLOYEE_COUNT . $analyticPhones,
			]);
		}

		if (!empty($analytic))
		{
			$this->send($analytic);
		}
	}

	private function getAdmin(): string
	{
		return CurrentUser::get()->isAdmin()
			? self::ANALYTIC_PARAM_IS_ADMIN_Y
			: self::ANALYTIC_PARAM_IS_ADMIN_N;
	}
}
