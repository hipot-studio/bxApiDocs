<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller\Restrictions;

use Bitrix\Disk\Internal\Entity\FormatPolicyRule;
use Bitrix\Disk\Internal\Repository\FormatPolicyRuleRepository;
use Bitrix\Disk\Internal\Service\Restrictions\EffectivePolicy;
use Bitrix\Disk\Internal\Service\Restrictions\EffectivePolicyResolver;
use Bitrix\Disk\Internal\Service\Restrictions\ExtensionListNormalizer;
use Bitrix\Disk\Internal\Service\Restrictions\FormatPolicyRuleIndex;
use Bitrix\Disk\Internal\Service\Restrictions\FormatPolicyRuleService;
use Bitrix\Disk\Internal\Service\Restrictions\FormatPolicyStorage;
use Bitrix\Disk\Public\Enum\RestrictionPolicy;
use Bitrix\Disk\Public\Provider\RestrictionsAvailabilityProvider;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\HttpMethod as HttpMethodRule;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

/**
 * Admin API of the private rules of the file format policy and of the policy an employee gets.
 *
 * Reading stays open where the portal does not apply the format policy - the section shows what was
 * saved there - while saving is refused: a rule the write node does not apply is a promise not kept.
 */
class FormatRule extends Controller
{
	use SectionAccessTrait;

	public const ERROR_INVALID_PAGE = 'INVALID_PAGE';
	public const ERROR_USER_NOT_FOUND = 'FORMAT_RULE_USER_NOT_FOUND';

	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT = 100;

	/**
	 * Returns a page of the rules. The rules of deactivated employees are kept but not listed.
	 *
	 * The page carries no type declaration on purpose, as in the list of quotas: a value of another
	 * type is refused by the section itself instead of by the engine.
	 *
	 * @param FormatPolicyRuleRepository $ruleRepository
	 * @param FormatPolicyRuleService $ruleService
	 * @param mixed $offset First row of the page, a whole number of zero or more.
	 * @param mixed $limit Size of the page, a whole number from one to self::MAX_LIMIT.
	 * @return array|null
	 */
	#[HttpMethodRule([HttpMethod::METHOD_GET])]
	public function listAction(
		FormatPolicyRuleRepository $ruleRepository,
		FormatPolicyRuleService $ruleService,
		$offset = 0,
		$limit = self::DEFAULT_LIMIT,
	): ?array
	{
		if (!$this->isWholeNumber($offset) || (int)$offset < 0
			|| !$this->isWholeNumber($limit) || (int)$limit < 1 || (int)$limit > self::MAX_LIMIT
		)
		{
			$this->addError(new Error(
				(string)Loc::getMessage('DISK_RESTRICTIONS_FORMAT_RULE_ERROR_INVALID_PAGE'),
				self::ERROR_INVALID_PAGE,
				['maxLimit' => self::MAX_LIMIT],
			));

			return null;
		}

		$page = $ruleRepository->list((int)$offset, (int)$limit);

		return [
			'items' => $this->buildRuleDtos($ruleService, $page['items']),
			'total' => $page['total'],
		];
	}

	/**
	 * @param FormatPolicyRuleRepository $ruleRepository
	 * @param FormatPolicyRuleService $ruleService
	 * @param int $id Id of the rule.
	 * @return array|null
	 */
	#[HttpMethodRule([HttpMethod::METHOD_GET])]
	public function getAction(FormatPolicyRuleRepository $ruleRepository, FormatPolicyRuleService $ruleService, int $id): ?array
	{
		$rule = $ruleRepository->findById($id);
		if ($rule === null)
		{
			$this->addLocalizedError($ruleService, new Error('', FormatPolicyRuleService::ERROR_NOT_FOUND, ['ruleId' => $id]));

			return null;
		}

		return ['rule' => $this->buildRuleDtos($ruleService, [$rule])[0]];
	}

	/**
	 * Creates or changes a rule and answers with the rule as it was stored.
	 *
	 * @param FormatPolicyRuleService $ruleService
	 * @param RestrictionsAvailabilityProvider $availabilityProvider
	 * @param array $rule Draft of the rule, {@see FormatPolicyRuleService::save()}.
	 * @return array|null
	 */
	#[HttpMethodRule([HttpMethod::METHOD_POST])]
	public function saveAction(
		FormatPolicyRuleService $ruleService,
		RestrictionsAvailabilityProvider $availabilityProvider,
		array $rule = [],
	): ?array
	{
		if (!$this->checkPolicyIsApplied(RestrictionPolicy::Formats, $availabilityProvider))
		{
			return null;
		}

		$result = $ruleService->save($rule, (int)$this->getCurrentUser()?->getId());
		if (!$result->isSuccess())
		{
			foreach ($result->getErrors() as $error)
			{
				$this->addLocalizedError($ruleService, $error);
			}

			return null;
		}

		return ['rule' => $this->buildRuleDtos($ruleService, [$result->getData()['rule']])[0]];
	}

	/**
	 * Removes a rule for good.
	 *
	 * @param FormatPolicyRuleService $ruleService
	 * @param int $id Id of the rule.
	 * @return array|null
	 */
	#[HttpMethodRule([HttpMethod::METHOD_POST])]
	public function deleteAction(FormatPolicyRuleService $ruleService, int $id): ?array
	{
		$result = $ruleService->delete($id);
		if (!$result->isSuccess())
		{
			foreach ($result->getErrors() as $error)
			{
				$this->addLocalizedError($ruleService, $error);
			}

			return null;
		}

		return [];
	}

	/**
	 * Tells which formats an employee may not write and where the restriction comes from.
	 *
	 * The policy is worked out by a resolver of its own: the resolver of the hit keeps the answers it
	 * gave, and a rule saved earlier in the same request must be seen here.
	 *
	 * @param FormatPolicyRuleIndex $ruleIndex
	 * @param FormatPolicyStorage $policyStorage
	 * @param FormatPolicyRuleRepository $ruleRepository
	 * @param FormatPolicyRuleService $ruleService
	 * @param int $userId Employee the policy is asked about.
	 * @return array|null
	 */
	#[HttpMethodRule([HttpMethod::METHOD_GET])]
	public function getEffectivePolicyAction(
		FormatPolicyRuleIndex $ruleIndex,
		FormatPolicyStorage $policyStorage,
		FormatPolicyRuleRepository $ruleRepository,
		FormatPolicyRuleService $ruleService,
		int $userId,
	): ?array
	{
		if ($userId <= 0 || $ruleService->describeUser($userId) === null)
		{
			$this->addError(new Error(
				(string)Loc::getMessage('DISK_RESTRICTIONS_FORMAT_RULE_ERROR_USER_NOT_FOUND'),
				self::ERROR_USER_NOT_FOUND,
			));

			return null;
		}

		$policy = $this->createPolicyResolver($ruleIndex, $policyStorage, $ruleRepository)->resolve($userId);

		return $this->buildEffectivePolicyDto($ruleRepository, $ruleService, $policy);
	}

	protected function createPolicyResolver(
		FormatPolicyRuleIndex $ruleIndex,
		FormatPolicyStorage $policyStorage,
		FormatPolicyRuleRepository $ruleRepository,
	): EffectivePolicyResolver
	{
		return new EffectivePolicyResolver($ruleIndex, $policyStorage, $ruleRepository);
	}

	protected function buildEffectivePolicyDto(
		FormatPolicyRuleRepository $ruleRepository,
		FormatPolicyRuleService $ruleService,
		EffectivePolicy $policy,
	): array
	{
		$sources = [];
		if ($policy->usesGlobal)
		{
			$sources[] = ['kind' => 'global'];
		}

		$rules = $ruleRepository->findByIds($policy->ruleIds);
		$ruleDtos = $this->buildRuleDtos($ruleService, array_values($rules));
		foreach ($ruleDtos as $ruleDto)
		{
			$sources[] = [
				'kind' => 'rule',
				'ruleId' => $ruleDto['id'],
				'addressee' => $ruleDto['addressee'],
				'mode' => $ruleDto['mode'],
			];
		}

		return [
			'extensions' => array_map('strval', array_keys($policy->extensionMap)),
			'blockNoExtension' => $policy->blockNoExtension,
			'level' => $policy->level,
			'usesGlobal' => $policy->usesGlobal,
			'sources' => $sources,
		];
	}

	/**
	 * @param FormatPolicyRule[] $rules
	 * @return array[]
	 */
	protected function buildRuleDtos(FormatPolicyRuleService $ruleService, array $rules): array
	{
		$addressees = $ruleService->describeAddressees($rules);

		$dtos = [];
		foreach ($rules as $rule)
		{
			$addressee = $addressees[(int)$rule->getId()] ?? ['title' => '', 'avatar' => null, 'isAvailable' => false];

			$dtos[] = [
				'id' => (int)$rule->getId(),
				'addressee' => [
					'type' => $rule->getEntityType(),
					'id' => $rule->getEntityId(),
					'includeNested' => $rule->isIncludeNested(),
					'title' => $addressee['title'],
					'avatar' => $addressee['avatar'],
					'isAvailable' => $addressee['isAvailable'],
				],
				'mode' => $rule->getMode(),
				'extensions' => $rule->getExtensions(),
				'presets' => $rule->getPresets(),
				'blockNoExtension' => $rule->isBlockNoExtension(),
				'savedAt' => $rule->getSavedAt(),
			];
		}

		return $dtos;
	}

	protected function getAccessDeniedMessage(): string
	{
		return (string)Loc::getMessage('DISK_RESTRICTIONS_FORMAT_RULE_ERROR_ACCESS_DENIED');
	}

	protected function getRestrictionsUnavailableMessage(): string
	{
		return (string)Loc::getMessage('DISK_RESTRICTIONS_FORMAT_RULE_ERROR_UNAVAILABLE');
	}

	/**
	 * The service speaks its own technical language; the client gets the same code and custom data
	 * under a text it can show.
	 */
	protected function addLocalizedError(FormatPolicyRuleService $ruleService, Error $error): void
	{
		$this->addError(new Error(
			$this->getLocalizedErrorMessage($ruleService, $error),
			$error->getCode(),
			$error->getCustomData(),
		));
	}

	protected function getLocalizedErrorMessage(FormatPolicyRuleService $ruleService, Error $error): string
	{
		$customData = (array)$error->getCustomData();

		$message = match ($error->getCode())
		{
			FormatPolicyRuleService::ERROR_ALREADY_EXISTS => Loc::getMessage('DISK_RESTRICTIONS_FORMAT_RULE_ERROR_ALREADY_EXISTS'),
			FormatPolicyRuleService::ERROR_EMPTY_APPEND => Loc::getMessage('DISK_RESTRICTIONS_FORMAT_RULE_ERROR_EMPTY_APPEND'),
			FormatPolicyRuleService::ERROR_EMPTY_REPLACE_UNCONFIRMED
				=> Loc::getMessage('DISK_RESTRICTIONS_FORMAT_RULE_ERROR_EMPTY_REPLACE_UNCONFIRMED'),
			FormatPolicyRuleService::ERROR_LIMIT_EXCEEDED => Loc::getMessage('DISK_RESTRICTIONS_FORMAT_RULE_ERROR_LIMIT_EXCEEDED', [
				'#LIMIT#' => (int)($customData['limit'] ?? $ruleService->getRulesLimit()),
			]),
			FormatPolicyRuleService::ERROR_ADDRESSEE_INVALID => ($customData['reason'] ?? null) === 'immutable'
				? Loc::getMessage('DISK_RESTRICTIONS_FORMAT_RULE_ERROR_ADDRESSEE_IMMUTABLE')
				: Loc::getMessage('DISK_RESTRICTIONS_FORMAT_RULE_ERROR_ADDRESSEE_INVALID'),
			FormatPolicyRuleService::ERROR_NOT_FOUND => Loc::getMessage('DISK_RESTRICTIONS_FORMAT_RULE_ERROR_NOT_FOUND'),
			FormatPolicyRuleService::ERROR_MODE_INVALID => Loc::getMessage('DISK_RESTRICTIONS_FORMAT_RULE_ERROR_MODE_INVALID'),
			ExtensionListNormalizer::ERROR_INVALID_EXTENSIONS
				=> Loc::getMessage('DISK_RESTRICTIONS_FORMAT_RULE_ERROR_INVALID_EXTENSIONS'),
			ExtensionListNormalizer::ERROR_POLICY_TOO_LARGE => Loc::getMessage('DISK_RESTRICTIONS_FORMAT_RULE_ERROR_POLICY_TOO_LARGE', [
				'#LIMIT#' => ExtensionListNormalizer::MAX_COUNT,
			]),
			FormatPolicyRuleService::ERROR_INVALID_PRESETS => Loc::getMessage('DISK_RESTRICTIONS_FORMAT_RULE_ERROR_INVALID_PRESETS'),
			FormatPolicyRuleService::ERROR_BUSY => Loc::getMessage('DISK_RESTRICTIONS_FORMAT_RULE_ERROR_BUSY'),
			default => $error->getMessage(),
		};

		return (string)$message;
	}

	protected function isWholeNumber(mixed $value): bool
	{
		if (is_int($value))
		{
			return true;
		}

		if (is_float($value))
		{
			return $value === floor($value);
		}

		return is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1;
	}
}
