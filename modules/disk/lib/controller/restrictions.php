<?php
declare(strict_types=1);

namespace Bitrix\Disk\Controller;

use Bitrix\Disk\Controller\Restrictions\SectionAccessTrait;
use Bitrix\Disk\Internal\Service\Restrictions\ExtensionListNormalizer;
use Bitrix\Disk\Internal\Service\Restrictions\FormatPolicyRuleService;
use Bitrix\Disk\Internal\Service\Restrictions\FormatPolicyStorage;
use Bitrix\Disk\Internal\Service\Restrictions\FormatPresetMap;
use Bitrix\Disk\Public\Enum\RestrictionPolicy;
use Bitrix\Disk\Public\Provider\RestrictionsAvailabilityProvider;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\HttpMethod as HttpMethodRule;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Bitrix\Main\Result;

/**
 * Admin API of the Disk restrictions section: reading and saving the file format policy.
 *
 * Only the resulting list of extensions is applied, presets travel as the state of the
 * settings interface. Restrictions of other kinds get their own actions beside these ones.
 */
class Restrictions extends Controller
{
	use SectionAccessTrait;

	public const ERROR_INVALID_PRESETS = FormatPolicyRuleService::ERROR_INVALID_PRESETS;

	protected FormatPolicyStorage $policyStorage;
	protected ExtensionListNormalizer $extensionListNormalizer;
	protected FormatPresetMap $presetMap;
	protected RestrictionsAvailabilityProvider $availabilityProvider;
	protected FormatPolicyRuleService $ruleService;

	public function __construct(?Request $request = null)
	{
		parent::__construct($request);

		$serviceLocator = ServiceLocator::getInstance();

		$this->policyStorage = $serviceLocator->get(FormatPolicyStorage::class);
		$this->extensionListNormalizer = $serviceLocator->get(ExtensionListNormalizer::class);
		$this->presetMap = $serviceLocator->get(FormatPresetMap::class);
		$this->availabilityProvider = $serviceLocator->get('disk.restrictionsAvailabilityProvider');
		$this->ruleService = $serviceLocator->get(FormatPolicyRuleService::class);
	}

	/**
	 * Returns the saved policy, the reference book of presets, the availability of restrictions on
	 * this portal, whether the private rules may address departments and teams and how many of them it may have.
	 *
	 * @return array
	 */
	#[HttpMethodRule([HttpMethod::METHOD_GET])]
	public function getFormatPolicyAction(): array
	{
		return [
			'policy' => $this->policyStorage->read(),
			'presetMap' => $this->buildPresetMap(),
			'availability' => $this->availabilityProvider->getState(RestrictionPolicy::Formats)->value,
			'departmentsAvailable' => $this->ruleService->areDepartmentsAvailable(),
			'teamsAvailable' => $this->ruleService->areTeamsAvailable(),
			'rulesLimit' => $this->ruleService->getRulesLimit(),
		];
	}

	/**
	 * Saves the list of extensions sent by the administrator and returns the stored snapshot.
	 * An empty policy is saved as well: it means the portal restricts nothing globally.
	 *
	 * A portal where the format policy is not applied saves nothing: the section answers with the
	 * refusal of its own instead.
	 *
	 * @param array $extensions Extensions as they were typed in the interface.
	 * @param array $presets Ids of the presets chosen in the interface.
	 * @param mixed $blockNoExtension Whether files without an extension are restricted; a form sends
	 *     the flag as a string, and a declared type would refuse it before the action runs.
	 * @return array|null
	 */
	#[HttpMethodRule([HttpMethod::METHOD_POST])]
	public function saveFormatPolicyAction(array $extensions = [], array $presets = [], $blockNoExtension = false): ?array
	{
		if (!$this->checkPolicyIsApplied(RestrictionPolicy::Formats, $this->availabilityProvider))
		{
			return null;
		}

		$extensionsResult = $this->extensionListNormalizer->normalize($extensions);
		$presetsResult = $this->ruleService->normalizePresets($presets);

		if (!$extensionsResult->isSuccess() || !$presetsResult->isSuccess())
		{
			$this->addLocalizedErrors($extensionsResult);
			$this->addLocalizedErrors($presetsResult);

			return null;
		}

		return [
			'policy' => $this->policyStorage->save(
				$extensionsResult->getData()['extensions'],
				$presetsResult->getData()['presets'],
				FormatPolicyRuleService::readFlag($blockNoExtension),
			),
		];
	}

	protected function getAccessDeniedMessage(): string
	{
		return (string)Loc::getMessage('DISK_RESTRICTIONS_ERROR_ACCESS_DENIED');
	}

	protected function getRestrictionsUnavailableMessage(): string
	{
		return (string)Loc::getMessage('DISK_RESTRICTIONS_ERROR_UNAVAILABLE');
	}

	protected function buildPresetMap(): array
	{
		$presetMap = [];
		foreach ($this->presetMap->getAll() as $preset)
		{
			$presetMap[] = [
				'id' => $preset['id'],
				'title' => (string)Loc::getMessage($preset['labelKey']),
				'extensions' => $preset['extensions'],
			];
		}

		return $presetMap;
	}

	/**
	 * The checks speak their own technical language; the client gets the same codes and
	 * custom data under a text it can show.
	 */
	protected function addLocalizedErrors(Result $result): void
	{
		foreach ($result->getErrors() as $error)
		{
			$this->addError(new Error(
				$this->getLocalizedErrorMessage($error),
				$error->getCode(),
				$error->getCustomData(),
			));
		}
	}

	protected function getLocalizedErrorMessage(Error $error): string
	{
		$message = match ($error->getCode())
		{
			ExtensionListNormalizer::ERROR_INVALID_EXTENSIONS
				=> Loc::getMessage('DISK_RESTRICTIONS_ERROR_INVALID_EXTENSIONS'),
			ExtensionListNormalizer::ERROR_POLICY_TOO_LARGE
				=> Loc::getMessage('DISK_RESTRICTIONS_ERROR_POLICY_TOO_LARGE', [
					'#LIMIT#' => ExtensionListNormalizer::MAX_COUNT,
				]),
			self::ERROR_INVALID_PRESETS => Loc::getMessage('DISK_RESTRICTIONS_ERROR_INVALID_PRESETS'),
			default => $error->getMessage(),
		};

		return (string)$message;
	}
}
