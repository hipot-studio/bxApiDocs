<?php
namespace Bitrix\Landing\Site;

use Bitrix\Landing\Domain;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Rights;
use Bitrix\Landing\Site as SiteEntity;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Random;

class Creator
{
	private const EMPTY_SITE_TEMPLATE_CODE = 'empty';
	private const EMPTY_MULTIPAGE_SITE_TEMPLATE_CODE = 'empty-multipage';

	/**
	 * Creates a new empty site with one empty page and returns editor redirect URL.
	 *
	 * @param string $siteType Site type.
	 * @param string $siteTitle Created site title.
	 * @param string $pageTitle Created page title.
	 * @return Result
	 */
	public static function createEmptySiteWithEmptyPage(string $siteType, string $siteTitle, string $pageTitle): Result
	{
		$siteType = mb_strtoupper(trim($siteType));
		if ($siteType === '')
		{
			$siteType = SiteEntity::getDefaultType();
		}

		// every check of the creation, and everything the creation reaches, reads the section the
		// process entered, while the ajax entrance is dispatched past executeComponent(), the place
		// a component of a section enters it: the section of the operation is the one of the site.
		// SCOPE_CODE_DEFAULT restores the state of no scope set, it has no scope class of its own
		$previousScope = Type::getCurrentScopeId() ?? Type::SCOPE_CODE_DEFAULT;
		Type::setScope($siteType);

		try
		{
			// a section closed by the plan is not entered at all, and the checks after it answer for
			// the base one. hasCreateRight() names the section itself, but the tariff feature of the
			// rights is still read by the entered section (Restriction\Rights::isAllowed()), so on a
			// plan without that feature the right of the section reads as allowed to everyone. What
			// stops the creation today is the list of types the base section allows, and that list is
			// a compatibility one, not a check of access
			if (!Type::isScopeEntered($siteType))
			{
				$result = new Result();
				$result->addError(new Error('Access denied.', 'ACCESS_DENIED'));

				return $result;
			}

			return self::createSite($siteType, $siteTitle, $pageTitle);
		}
		finally
		{
			Type::setScope($previousScope);
		}
	}

	private static function createSite(string $siteType, string $siteTitle, string $pageTitle): Result
	{
		$templateResult = null;
		if (self::shouldCreateByTemplate($siteType))
		{
			$templateResult = self::createEmptySiteWithEmptyPageByTemplate($siteType, $siteTitle, $pageTitle);
			if ($templateResult->isSuccess())
			{
				return $templateResult;
			}
		}

		$siteResult = null;
		$landingResult = null;
		$siteId = 0;

		if (Rights::isOn() && !self::canCreateSite($siteType))
		{
			$result = new Result();
			$result->addError(new Error('Access denied.', 'ACCESS_DENIED'));

			return $result;
		}

		$rightsState = Rights::isOn();
		Rights::setOff();
		try
		{
			$siteFields = [
				'TITLE' => $siteTitle,
				'TYPE' => $siteType,
				'ACTIVE' => 'N',
				'CODE' => mb_strtolower(Random::getString(8)),
			];

			$domainId = Type::getDomainId();
			if ($domainId === '' && !Manager::isB24())
			{
				$domainId = Domain::getCurrentId();
			}
			if ($domainId !== '')
			{
				$siteFields['DOMAIN_ID'] = $domainId;
			}

			$siteResult = SiteEntity::add($siteFields);
			if ($siteResult->isSuccess())
			{
				$siteId = (int)$siteResult->getId();
				$landingResult = Landing::add([
					'SITE_ID' => $siteId,
					'TITLE' => $pageTitle,
					'ACTIVE' => 'N',
					'PUBLIC' => 'N',
				]);
			}
		}
		finally
		{
			if ($rightsState)
			{
				Rights::setOn();
			}
			else
			{
				Rights::setOff();
			}
		}

		if (
			$siteResult instanceof Result
			&& $landingResult instanceof Result
			&& $siteResult->isSuccess()
			&& $landingResult->isSuccess()
		)
		{
			$landingId = (int)$landingResult->getId();

			$result = new Result();
			$result->setData([
				'siteId' => $siteId,
				'landingId' => $landingId,
				'redirectUrl' => self::buildLandingEditorUrl($siteId, $landingId),
			]);

			return $result;
		}

		if ($siteId > 0 && !($landingResult instanceof Result && $landingResult->isSuccess()))
		{
			SiteEntity::delete($siteId, true);
		}

		$directResult = new Result();
		if ($siteResult instanceof Result)
		{
			foreach ($siteResult->getErrors() as $error)
			{
				$directResult->addError($error);
			}
		}
		if ($landingResult instanceof Result)
		{
			foreach ($landingResult->getErrors() as $error)
			{
				$directResult->addError($error);
			}
		}

		$result = new Result();
		$directMessage = implode('; ', $directResult->getErrorMessages());
		$templateMessage = $templateResult instanceof Result ? implode('; ', $templateResult->getErrorMessages()) : '';
		$message = trim(
			($templateMessage !== '' ? 'template: ' . $templateMessage : '')
			. ($directMessage !== '' ? '; direct: ' . $directMessage : ''),
			'; '
		);
		$result->addError(new Error($message !== '' ? $message : 'Failed to create an empty site.'));

		return $result;
	}

	private static function createEmptySiteWithEmptyPageByTemplate(
		string $siteType,
		string $siteTitle,
		string $pageTitle
	): Result
	{
		// the branch is able to create a site and still report a refusal, so the right goes first.
		// only the soft create right of the section is asked here, while canCreateSite() of the
		// direct branch also demands the edit operation: on a portal whose section was never
		// configured the holder of the right can have no operation at all, and demanding one would
		// close creating a site of the section for him again (regression 0242125)
		if (Rights::isOn() && !self::hasCreateRight($siteType))
		{
			$result = new Result();
			$result->addError(new Error('Access denied.', 'ACCESS_DENIED'));

			return $result;
		}

		$maxSiteIdBeforeCreate = 0;
		$beforeRow = SiteEntity::getList([
			'select' => ['ID'],
			'filter' => ['=TYPE' => $siteType],
			'order' => ['ID' => 'desc'],
			'limit' => 1,
		])->fetch();
		if ($beforeRow)
		{
			$maxSiteIdBeforeCreate = (int)$beforeRow['ID'];
		}

		$siteResult = SiteEntity::addByTemplate(self::getTemplateCode($siteType), $siteType);
		if (!$siteResult->isSuccess())
		{
			$result = new Result();
			foreach ($siteResult->getErrors() as $error)
			{
				$result->addError($error);
			}

			return $result;
		}

		$siteId = (int)$siteResult->getId();
		if ($siteId <= 0)
		{
			$createdSiteRow = SiteEntity::getList([
				'select' => ['ID'],
				'filter' => [
					'=TYPE' => $siteType,
					'>ID' => $maxSiteIdBeforeCreate,
				],
				'order' => ['ID' => 'asc'],
				'limit' => 1,
			])->fetch();
			$siteId = (int)($createdSiteRow['ID'] ?? 0);
		}

		if ($siteId <= 0)
		{
			$result = new Result();
			$result->addError(new Error('Unable to detect created site ID.'));

			return $result;
		}

		SiteEntity::update($siteId, [
			'TITLE' => $siteTitle,
		]);

		$siteRow = SiteEntity::getList([
			'select' => ['LANDING_ID_INDEX'],
			'filter' => ['ID' => $siteId],
		])->fetch();

		$landingId = (int)($siteRow['LANDING_ID_INDEX'] ?? 0);
		if ($landingId <= 0)
		{
			$landingRow = Landing::getList([
				'select' => ['ID'],
				'filter' => ['SITE_ID' => $siteId],
				'order' => ['ID' => 'asc'],
				'limit' => 1,
			])->fetch();
			$landingId = (int)($landingRow['ID'] ?? 0);
		}

		if ($landingId <= 0)
		{
			$result = new Result();
			$result->addError(new Error('Unable to detect created landing ID.'));

			return $result;
		}

		Landing::update($landingId, [
			'TITLE' => $pageTitle,
		]);

		$result = new Result();
		$result->setData([
			'siteId' => $siteId,
			'landingId' => $landingId,
			'redirectUrl' => self::buildLandingEditorUrl($siteId, $landingId),
		]);

		return $result;
	}

	private static function buildLandingEditorUrl(int $siteId, int $landingId): string
	{
		return '/sites/site/' . $siteId . '/view/' . $landingId . '/';
	}

	private static function getTemplateCode(string $siteType): string
	{
		return in_array($siteType, [Type::SCOPE_CODE_KNOWLEDGE, Type::SCOPE_CODE_GROUP], true)
			? self::EMPTY_MULTIPAGE_SITE_TEMPLATE_CODE
			: self::EMPTY_SITE_TEMPLATE_CODE
		;
	}

	private static function shouldCreateByTemplate(string $siteType): bool
	{
		return in_array($siteType, [Type::SCOPE_CODE_KNOWLEDGE, Type::SCOPE_CODE_GROUP], true);
	}

	private static function canCreateSite(string $siteType): bool
	{
		$rights = Rights::getOperationsForSite(0);

		return self::hasCreateRight($siteType)
			&& in_array(Rights::ACCESS_TYPES['edit'], $rights, true)
		;
	}

	/**
	 * Create right of the section the site belongs to. The entered scope alone would not do: a
	 * section whose scope cannot be entered (a tariff closes it) would fall back to the right of
	 * the base section, and that is the escalation this asks the section explicitly for.
	 */
	private static function hasCreateRight(string $siteType): bool
	{
		return Rights::hasAdditionalRight(
			Rights::ADDITIONAL_RIGHTS['create'],
			self::sectionOfSiteType($siteType)
		);
	}

	/**
	 * Section of the site type as the registry of the additional rights names it, or null when the
	 * type carries no create right of its own and the base section answers for it: an unknown code
	 * would be denied to everyone, and there is no page_create in the registry.
	 */
	private static function sectionOfSiteType(string $siteType): ?string
	{
		$section = Type::getCompatibilityScopeClass(mb_strtoupper($siteType));

		return Rights::hasCodeInScope(Rights::ADDITIONAL_RIGHTS['create'], $section) ? $section : null;
	}
}
