<?php

namespace Bitrix\Landing\Site;

use Bitrix\Landing\Metrika;
use Bitrix\Landing\Role;
use Bitrix\Landing\Site;
use Bitrix\Landing\Transfer\Requisite\FinishRedirectLinkDto;
use Bitrix\Main\Event;
use Bitrix\SignSafe\Processing\Preview;

class Type
{
	/**
	 * Default site type if not set another
	 */
	public const SCOPE_CODE_DEFAULT = 'PAGE';

	/**
	 * Scope group.
	 */
	public const SCOPE_CODE_GROUP = 'GROUP';

	/**
	 * Scope knowledge.
	 */
	public const SCOPE_CODE_KNOWLEDGE = 'KNOWLEDGE';

	/**
	 * Scope for vibe (welcome page)
	 */
	public const SCOPE_CODE_VIBE = 'VIBE';

	/**
	 * Pseudo scope for crm forms.
	 */
	public const PSEUDO_SCOPE_CODE_FORMS = 'crm_forms';

	protected const SCOPES_NOT_PUBLIC = [
		self::SCOPE_CODE_GROUP,
		self::SCOPE_CODE_KNOWLEDGE,
		self::SCOPE_CODE_VIBE,
	];

	/**
	 * Support import old scopes.
	 * Format 'old' => 'new'
	 */
	private const SCOPE_COMPATIBILITY = [
		'MAINPAGE' => self::SCOPE_CODE_VIBE,
	];

	/**
	 * Current scope class name.
	 * @var Scope
	 */
	protected static $currentScopeClass = null;

	/**
	 * Scope already init.
	 * @var bool
	 */
	protected static $scopeInit = false;

	/**
	 * Returns scope class, if exist.
	 * @param string $scope Scope code.
	 * @return string|null
	 */
	protected static function getScopeClass(string $scope): ?string
	{
		$scope = trim($scope);
		$class = self::getFullScopeClass($scope);
		if (class_exists($class))
		{
			return $class;
		}

		$scopeAlias = self::getFullScopeClass(
			self::getCompatibilityScopeClass($scope)
		);

		return class_exists($scopeAlias) ? $scopeAlias : null;
	}

	/**
	 * @param class-string<Scope> $scope
	 * @return string
	 */
	private static function getFullScopeClass(string $scope): string
	{
		return __NAMESPACE__ . '\\Scope\\' . $scope;
	}

	/**
	 * Try to find compatibility class name
	 * @param string $scope
	 * @return string
	 */
	public static function getCompatibilityScopeClass(string $scope): string
	{
		return self::SCOPE_COMPATIBILITY[$scope] ?? $scope;
	}

	/**
	 * Detect site special type (forms or mainpage)
	 *
	 * @param string $siteCode
	 * @return string|null
	 */
	public static function getSiteSpecialType(string $siteCode): ?string
	{
		if (preg_match('#^/' . self::PSEUDO_SCOPE_CODE_FORMS . '\d*/$#', $siteCode))
		{
			return self::PSEUDO_SCOPE_CODE_FORMS;
		}

		return null;
	}

	/**
	 * Set global scope.
	 * @param string $scope Scope code.
	 * @param array $params Additional params.
	 * @return void
	 */
	public static function setScope($scope, array $params = []): void
	{
		if (!is_string($scope) || !$scope)
		{
			return;
		}
		// always clear previous scope
		Role::setExpectedType(null);
		self::$scopeInit = false;
		self::$currentScopeClass = self::getScopeClass($scope);
		if (self::$currentScopeClass)
		{
			self::$scopeInit = true;
			self::$currentScopeClass::init($params);
		}
	}

	/**
	 * Clear selected scope.
	 * @return void
	 */
	public static function clearScope(): void
	{
		self::$scopeInit = false;
		self::$currentScopeClass = null;
	}

	/**
	 * Returns true if scope is public.
	 * @param string|null $scope Scope code.
	 * @return bool
	 */
	public static function isPublicScope(?string $scope = null): bool
	{
		$scope = $scope ? mb_strtoupper($scope) : self::getCurrentScopeId();

		return !in_array($scope, self::SCOPES_NOT_PUBLIC);
	}

	/**
	 * Returns publication path string.
	 * @return string|null
	 */
	public static function getPublicationPath()
	{
		$path = null;
		$scope = null;

		if (self::$currentScopeClass !== null)
		{
			$path = self::$currentScopeClass::getPublicationPath();
			$scope = self::$currentScopeClass::getCurrentScopeId();
		}

		// custom for Preview
		$event = new Event('landing', 'onGetScopePublicationPath', [
			'scope' => $scope,
			'path' => $path,
		]);
		$event->send();
		foreach ($event->getResults() as $result)
		{
			$path = $result->getModified()['path'] ?? $path;
		}

		return $path;
	}

	/**
	 * Return general key for site path (ID or CODE).
	 * @return string
	 */
	public static function getKeyCode()
	{
		if (self::$currentScopeClass !== null)
		{
			return self::$currentScopeClass::getKeyCode();
		}

		return 'ID';
	}

	/**
	 * Returns domain id for new site.
	 * @return int|string
	 */
	public static function getDomainId()
	{
		if (self::$currentScopeClass !== null)
		{
			return self::$currentScopeClass::getDomainId();
		}

		return '';
	}

	/**
	 * Returns current scope id.
	 * @return string|null
	 */
	public static function getCurrentScopeId()
	{
		if (self::$currentScopeClass !== null)
		{
			return self::$currentScopeClass::getCurrentScopeId();
		}

		return null;
	}

	/**
	 * Returns filter value for 'TYPE' key.
	 * @param bool $strict If strict, returns without default.
	 * @return string|string[]|null
	 */
	public static function getFilterType($strict = false)
	{
		if (self::$currentScopeClass !== null)
		{
			return self::$currentScopeClass::getFilterType();
		}

		// compatibility, huh
		return $strict ? null : ['PAGE', 'STORE', 'SMN'];
	}

	/**
	 * Returns array of hook's codes, which excluded by scope.
	 * @return array
	 */
	public static function getExcludedHooks(): array
	{
		if (self::$currentScopeClass !== null)
		{
			return self::$currentScopeClass::getExcludedHooks();
		}

		return [];
	}

	/**
	 * Returns true, if type is enabled in system.
	 * @param string $code Type code.
	 * @return bool
	 */
	public static function isEnabled($code)
	{
		if (is_string($code))
		{
			$code = mb_strtoupper(trim($code));
			$types = Site::getTypes();
			if (array_key_exists($code, $types))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Scoped method for returning available operations of site.
	 * @param int $siteId Site id.
	 * @return array|null
	 * @see \Bitrix\Landing\Rights::getOperationsForSite
	 */
	public static function getOperationsForSite(int $siteId): ?array
	{
		if (
			self::$currentScopeClass !== null
			&& is_callable([self::$currentScopeClass, 'getOperationsForSite'])
		)
		{
			return self::$currentScopeClass::getOperationsForSite($siteId);
		}

		return null;
	}

	/**
	 * Change manifest field by special conditions of site type
	 * @param array $manifest
	 * @return array prepared manifest
	 */
	public static function prepareBlockManifest(array $manifest): array
	{
		if (
			self::$currentScopeClass !== null
			&& is_callable([self::$currentScopeClass, 'prepareBlockManifest'])
		)
		{
			return self::$currentScopeClass::prepareBlockManifest($manifest);
		}

		return $manifest;
	}

	/**
	 * Check is current scope can use extension
	 * @param string $code - name of extension
	 * @return bool
	 */
	public static function isExtensionAllow(string $code): bool
	{
		if (self::$currentScopeClass !== null)
		{
			return self::$currentScopeClass::isExtensionAllow($code);
		}

		return true;
	}

	/**
	 * To be compatible with previously exported archives, the transfer may use old scope codes
	 * @return string
	 */
	public static function getScopeIdForTransfer(): string
	{
		if (self::$currentScopeClass !== null)
		{
			return self::$currentScopeClass::getScopeIdForTransfer();
		}

		return mb_strtolower(self::getCurrentScopeId() ?? self::SCOPE_CODE_DEFAULT);
	}

	/**
	 * Scoped hook for transfer import finish URL.
	 * @param int $siteId
	 * @return FinishRedirectLinkDto|null
	 */
	public static function onTransferFinishRedirectUrlGet(int $siteId): ?FinishRedirectLinkDto
	{
		if (self::$currentScopeClass !== null)
		{
			return self::$currentScopeClass::onTransferFinishRedirectUrlGet($siteId);
		}

		return null;
	}

	/**
	 * Scoped hook for transfer finish analytics enrichment.
	 */
	public static function onTransferFinishAnalyticSend(int $siteId, Metrika\Metrika $metrika): void
	{
		if (self::$currentScopeClass !== null)
		{
			self::$currentScopeClass::onTransferFinishAnalyticSend($siteId, $metrika);
		}
	}
}
