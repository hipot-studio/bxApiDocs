<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider;

use Bitrix\Bizproc\Internal\Access\AccessController;
use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Internal\Config\DesignerV2Feature;
use Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\AccessRightsProvider;
use Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\Models\RightModel;
use Bitrix\Bizproc\Internal\Service\Feature\BpDesignerFeature;
use Bitrix\UI\AccessRights\V2\AccessRightsBuilder;
use Bitrix\UI\AccessRights\V2\Dto\Controller\UserGroupDto;
use Bitrix\UI\AccessRights\V2\Options;
use Bitrix\UI\AccessRights\V2\Options\RightSection;
use Bitrix\UI\AccessRights\V2\Options\UserGroup;

/**
 * Provider for the permissions page: builds the ui.accessrights.v2 page options and the load payload, and
 * decodes the UI right ids back to permission ids for the save command.
 *
 * Reads (getData/getOptions) go through the ui.accessrights.v2 builder over the AccessRightsProvider, so the
 * matrix, the role models and the scope pick list share one source of truth. decodeUserGroups maps the UI
 * right ids (entity~~~action) plus access codes into the shape SaveRolePermissionsCommand expects; the caller
 * runs the command so the configure-rights gate always applies.
 *
 * canConfigure answers whether the user may configure rights at all: the CONFIGURE_RIGHTS toggler or a
 * portal administrator, which grants full access to the matrix (any right, every role, every template).
 */
final class PermissionMatrixProvider
{
	private const MODULE_ID = 'bizproc';

	/**
	 * Key of the render-time answer the page ships with its save payload: could this very page express the "own
	 * templates" sentinel. ui.accessrights.v2 carries `additionalSaveParams` into the save request as
	 * `parameters`, so the save controller reads the answer of the render instead of asking the switches again.
	 */
	public const SAVE_PARAM_OWN_SENTINEL_EXPRESSIBLE = 'ownSentinelExpressible';

	/**
	 * The answer of the render that produced a save payload, read back out of its `parameters`.
	 *
	 * A missing key means "could not express": an old page against a new server sends no parameter at all, and
	 * of the two ways to be wrong only one is silent. A removal lost this way the administrator repeats, having
	 * seen the value on the page; a granted right deleted for nobody's reason leaves no trace at all.
	 *
	 * @param array<string, mixed> $parameters `additionalSaveParams` as they arrive at the save action
	 */
	public static function pageCouldExpressOwnSentinel(array $parameters): bool
	{
		$value = $parameters[self::SAVE_PARAM_OWN_SENTINEL_EXPRESSIBLE] ?? null;

		return $value !== null && filter_var($value, FILTER_VALIDATE_BOOLEAN);
	}

	private ?AccessRightsBuilder $displayBuilder = null;
	private ?AccessRightsProvider $displayProvider = null;

	public function __construct(
		private readonly ?int $userId = null,
	)
	{
	}

	public function canConfigure(): bool
	{
		return $this->controller()->check((string)PermissionDictionary::BIZPROC_TEMPLATE_CONFIGURE_RIGHTS);
	}

	/**
	 * Portal-level product/tariff gate for the permissions configuration surface: the new designer must be on
	 * and the tariff feature available. Shared by every entry point (the page ajax controller and the scope
	 * EntitySelector popup) so no path reaches the matrix past a weaker check. The per-user CONFIGURE_RIGHTS
	 * check stays with each caller.
	 */
	public static function isConfigurationFeatureEnabled(): bool
	{
		return DesignerV2Feature::isEnabled()
			&& (new BpDesignerFeature())->isAvailable();
	}

	public function getOptions(string $component, string $containerId): Options
	{
		$builder = $this->displayBuilder();
		$options = new Options($component, $containerId);
		$options
			->setModuleId(self::MODULE_ID)
			->setActionSave('savePermissions')
			->setIsSaveOnlyChangedRights(false)
			->setSearchContainerSelector('#uiToolbarContainer')
			->setAccessRights($builder->buildAccessRights())
			->setUserGroups($builder->buildUserGroups())
			// One render, one answer: the same provider that withheld the value from the cells says whether this
			// page could express it, and the save obeys that instead of re-reading the switches.
			->setAdditionalSaveParams([
				self::SAVE_PARAM_OWN_SENTINEL_EXPRESSIBLE => $this->displayProvider()->isOwnSentinelExpressible(),
			])
		;

		return $options;
	}

	/**
	 * @return array{USER_GROUPS: array, ACCESS_RIGHTS: array} payload for the ajax load of the page.
	 */
	public function getData(): array
	{
		return $this->snapshot($this->displayBuilder());
	}

	/**
	 * The snapshot a save hands back, built in the mode of the render that sent the payload instead of the portal
	 * state of this very moment.
	 *
	 * The client replaces its matrix with this answer but keeps the `additionalSaveParams` of its own render, so
	 * a snapshot that asked the switches afresh would leave the two halves of the pair disagreeing for the rest
	 * of the page session: with the pair switched off in between, the cells would stop carrying the value while
	 * the next save of the same page still claims the page could express it - and the grant would be deleted by
	 * a save aimed at something else. One render, one answer, for every payload that render produces.
	 *
	 * The answer carries the mode with it, so the elements of the rights follow the same render as the cells: a
	 * page that expressed the value keeps both, whatever the switches say by now. The other side of the answer
	 * names no mode of its own - "could not express" is the state of either switch being off - so a snapshot for
	 * such a page is built in the mode of the portal, exactly as the load of a page is.
	 *
	 * @param array<string, mixed> $parameters `additionalSaveParams` as they arrive at the save action
	 * @return array{USER_GROUPS: array, ACCESS_RIGHTS: array} payload for the save reload.
	 */
	public function getDataForSaveResponse(array $parameters): array
	{
		return $this->snapshot(new AccessRightsBuilder(new AccessRightsProvider(
			$this->userId,
			true,
			ownSentinelExpressible: self::pageCouldExpressOwnSentinel($parameters),
		)));
	}

	/**
	 * @return array{USER_GROUPS: array, ACCESS_RIGHTS: array}
	 */
	private function snapshot(AccessRightsBuilder $builder): array
	{
		return [
			'USER_GROUPS' => array_map(static fn (UserGroup $group): array => $group->toArray(), $builder->buildUserGroups()),
			'ACCESS_RIGHTS' => array_map(static fn (RightSection $section): array => $section->toArray(), $builder->buildAccessRights()),
		];
	}

	/**
	 * Decodes the UI payload into the SaveRolePermissionsCommand input. The UI sends right ids as
	 * `entity~~~action`; they are decoded back to numeric permission ids, access codes become members.
	 *
	 * @param array<int, array> $userGroups
	 * @return array<int, array{id: int, title: string, accessRights: array, members: array}>
	 */
	public function decodeUserGroups(array $userGroups): array
	{
		$decodeBuilder = $this->builder(false);

		$mappedUserGroups = [];
		foreach (UserGroupDto::fromArrayList($userGroups) as $index => $userGroup)
		{
			$accessRights = [];
			foreach ($userGroup->accessRights as $accessRight)
			{
				$model = $decodeBuilder->decodeAccessCode($accessRight);
				if (!$model instanceof RightModel)
				{
					continue;
				}

				$accessRights[] = ['id' => (int)$model->actionId, 'value' => (int)$model->getValue()];
			}

			$mappedUserGroup = [
				'id' => (int)$userGroup->id,
				'title' => (string)$userGroup->title,
				'accessRights' => $accessRights,
			];
			if (array_key_exists('accessCodes', $userGroups[$index] ?? []))
			{
				$members = [];
				foreach ($userGroup->accessCodes as $accessCode)
				{
					$members[(string)$accessCode->accessCode] = (string)$accessCode->type;
				}
				$mappedUserGroup['members'] = $members;
			}

			$mappedUserGroups[] = $mappedUserGroup;
		}

		return $mappedUserGroups;
	}

	private function builder(bool $withVariables): AccessRightsBuilder
	{
		return new AccessRightsBuilder(new AccessRightsProvider($this->userId, $withVariables));
	}

	private function displayBuilder(): AccessRightsBuilder
	{
		return $this->displayBuilder ??= new AccessRightsBuilder($this->displayProvider());
	}

	/**
	 * The display provider itself and not just the builder over it: the page options carry its render-time answer
	 * about the sentinel, and it has to be the same instance the cells were built from.
	 */
	private function displayProvider(): AccessRightsProvider
	{
		return $this->displayProvider ??= new AccessRightsProvider($this->userId, true);
	}

	private function controller(): AccessController
	{
		return $this->userId === null
			? AccessController::getCurrent()
			: AccessController::getInstance($this->userId);
	}
}
