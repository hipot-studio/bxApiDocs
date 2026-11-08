<?php

namespace Bitrix\Sign\Operation\Placeholder;

use Bitrix\Main;
use Bitrix\Sign\Config;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Helper\Field\NameHelper;
use Bitrix\Sign\Item;
use Bitrix\Sign\Repository\BlockRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Document\Placeholder\PlaceholderCacheService;
use Bitrix\Sign\Service\Document\Placeholder\Strategy\EmployeeDynamicPlaceholderCollectorStrategy;
use Bitrix\Sign\Service\Document\Placeholder\Strategy\UserFieldsPlaceholderCollectorStrategy;
use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasContext;
use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasRoleResolver;
use Bitrix\Sign\Service\Placeholder\FieldAlias\FieldAliasService;
use Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy\HcmLinkFieldStrategy;
use Bitrix\Sign\Service\Placeholder\Restriction\BrokenAliasResolver;
use Bitrix\Sign\Service\Placeholder\Restriction\ParsedField;
use Bitrix\Sign\Service\Placeholder\Restriction\PlaceholderRestrictionService;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionPolicy;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionReason;
use Bitrix\Sign\Service\Placeholder\Restriction\ScenarioContext;
use Bitrix\Sign\Service\Placeholder\Restriction\ScenarioContextFactory;
use Bitrix\Sign\Service\Providers\MemberDynamicFieldInfoProvider;
use Bitrix\Sign\Service\Providers\ProfileProvider;
use Bitrix\Sign\Type\Field\FrontFieldCategory;
use Psr\Log\LoggerInterface;

/**
 * Groups the codes already inserted into the document body by the reason they are not supported
 * in its signing scenario.
 *
 * Read-only and informational: an unsupported code is reported, never removed, and the operation
 * stays successful even when the raw code list cannot be read.
 */
final class ValidateDocumentPlaceholders implements Contract\Operation
{
	/** @var array<string, list<string>> codes by RestrictionReason wire value */
	private array $unsupportedCodesByReason = [];

	/** @var array<string, string>|null raw field type by the code the placeholder name carries */
	private ?array $rawFieldTypes = null;

	private readonly BlockRepository $blockRepository;
	private readonly PlaceholderCacheService $placeholderCacheService;
	private readonly FieldAliasService $fieldAliasService;
	private readonly AliasRoleResolver $aliasRoleResolver;
	private readonly PlaceholderRestrictionService $restrictionService;
	private readonly ScenarioContextFactory $scenarioContextFactory;
	private readonly BrokenAliasResolver $brokenAliasResolver;
	private readonly ProfileProvider $profileProvider;
	private readonly MemberDynamicFieldInfoProvider $memberDynamicFieldProvider;
	private readonly Config\Storage $config;
	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly Item\Document $document,
		?BlockRepository $blockRepository = null,
		?PlaceholderCacheService $placeholderCacheService = null,
		?FieldAliasService $fieldAliasService = null,
		?AliasRoleResolver $aliasRoleResolver = null,
		?PlaceholderRestrictionService $restrictionService = null,
		?ScenarioContextFactory $scenarioContextFactory = null,
		?BrokenAliasResolver $brokenAliasResolver = null,
		?ProfileProvider $profileProvider = null,
		?MemberDynamicFieldInfoProvider $memberDynamicFieldProvider = null,
		?Config\Storage $config = null,
		?LoggerInterface $logger = null,
	)
	{
		$container = Container::instance();
		$this->blockRepository = $blockRepository ?? $container->getBlockRepository();
		$this->placeholderCacheService = $placeholderCacheService ?? $container->getDocumentPlaceholderCacheService();
		$this->fieldAliasService = $fieldAliasService ?? $container->getFieldAliasService();
		$this->aliasRoleResolver = $aliasRoleResolver ?? $container->getAliasRoleResolver();
		$this->restrictionService = $restrictionService ?? $container->getPlaceholderRestrictionService();
		$this->scenarioContextFactory = $scenarioContextFactory ?? $container->getScenarioContextFactory();
		$this->brokenAliasResolver = $brokenAliasResolver ?? $container->getBrokenAliasResolver();
		$this->profileProvider = $profileProvider ?? $container->getServiceProfileProvider();
		$this->memberDynamicFieldProvider = $memberDynamicFieldProvider
			?? $container->getMemberDynamicFieldProvider()
		;
		$this->config = $config ?? Config\Storage::instance();
		$this->logger = $logger ?? $container->getLogger('Operation');
	}

	public function launch(): Main\Result
	{
		$this->unsupportedCodesByReason = [];

		if (!$this->config->isPlaceholderRestrictionEnabled())
		{
			return new Main\Result();
		}

		if ($this->document->id === null || $this->document->blankId === null)
		{
			return new Main\Result();
		}

		$insertedCodes = $this->collectInsertedCodes();
		if ($insertedCodes === [])
		{
			return new Main\Result();
		}

		$this->classify($insertedCodes);

		return new Main\Result();
	}

	/**
	 * @return array<string, list<string>> codes by RestrictionReason wire value, violations only
	 */
	public function getUnsupportedCodesByReason(): array
	{
		return $this->unsupportedCodesByReason;
	}

	/**
	 * @return list<string>
	 */
	private function collectInsertedCodes(): array
	{
		$codes = array_merge($this->collectPersistedBlockAliases(), $this->collectRawPlaceholders());

		return array_values(array_unique($codes));
	}

	/**
	 * @return list<string>
	 */
	private function collectPersistedBlockAliases(): array
	{
		return $this->blockRepository->getPlaceholderAliasesByBlankId($this->document->blankId);
	}

	/**
	 * Codes of the document body itself, precomputed after upload. Validation stays fail-open on a miss:
	 * an external safe-service call must not extend the synchronous send path.
	 *
	 * @return list<string>
	 */
	private function collectRawPlaceholders(): array
	{
		$cached = $this->placeholderCacheService->getRawPlaceholders($this->document->id, $this->document->blankId);
		if ($cached !== null)
		{
			return $this->normalizeCodes($cached);
		}

		$this->logger->debug('Raw placeholder list of the document was not precomputed', [
			'documentId' => $this->document->id,
			'documentUid' => $this->document->uid,
			'blankId' => $this->document->blankId,
		]);

		return [];
	}

	/**
	 * @param array<mixed> $codes
	 * @return list<string>
	 */
	private function normalizeCodes(array $codes): array
	{
		$normalized = [];

		foreach ($codes as $code)
		{
			if (is_string($code) && $code !== '')
			{
				$normalized[] = $code;
			}
		}

		return $normalized;
	}

	/**
	 * @param list<string> $insertedCodes
	 */
	private function classify(array $insertedCodes): void
	{
		$scenario = $this->scenarioContextFactory->fromDocument($this->document);
		$contextByCode = $this->resolveContextsByCode($insertedCodes);
		$fieldNameByCode = $this->resolveFieldNames($contextByCode);

		foreach ($contextByCode as $code => $context)
		{
			if ($this->isHcmLinkAliasForbiddenWithoutCompany($code, $context, $scenario))
			{
				$this->addUnsupported(RestrictionReason::SCENARIO_FORBIDDEN, $code);

				continue;
			}

			$fieldName = $fieldNameByCode[$code] ?? null;
			if ($fieldName === null || $this->brokenAliasResolver->isBroken($fieldName, $context))
			{
				$this->addUnsupported(RestrictionReason::BROKEN, $code);

				continue;
			}

			$field = ParsedField::fromName($fieldName);
			$reason = $this->restrictionService->getRestrictionReason(
				$field->withRawFieldType($this->getRawFieldTypes()[$field->fieldCode] ?? ''),
				$scenario,
				RestrictionPolicy::STRICT,
			);

			if ($reason !== null)
			{
				$this->addUnsupported($reason, $code);
			}
		}
	}

	private function isHcmLinkAliasForbiddenWithoutCompany(
		string $code,
		AliasContext $aliasContext,
		ScenarioContext $scenario,
	): bool
	{
		if ($scenario->hcmLinkCompanyId !== null || $aliasContext->role === null)
		{
			return false;
		}

		$parts = explode('.', $code);

		return count($parts) === 3
			&& $parts[0] === HcmLinkFieldStrategy::ALIAS_PREFIX
			&& $parts[2] !== ''
		;
	}

	/**
	 * An alias carries the member role, and resolving it without that role in the context always
	 * fails, so every code gets the context of its own role.
	 *
	 * @param list<string> $codes
	 * @return array<string, AliasContext>
	 */
	private function resolveContextsByCode(array $codes): array
	{
		$documentContext = AliasContext::fromDocument($this->document);
		$contexts = [];

		foreach ($codes as $code)
		{
			$contexts[$code] = $this->isRawFieldName($code)
				? $documentContext->withParty(NameHelper::parse($code)['party'])
				: $this->aliasRoleResolver->resolveContextByAlias($documentContext, $code)
			;
		}

		return $contexts;
	}

	/**
	 * Resolves codes in batches, one per role context, so that the alias strategies preload their
	 * field data once per batch instead of once per code.
	 *
	 * @param array<string, AliasContext> $contextByCode
	 * @return array<string, string> field name by code
	 */
	private function resolveFieldNames(array $contextByCode): array
	{
		$aliasesByRole = [];
		$fieldNames = [];

		foreach ($contextByCode as $code => $context)
		{
			if ($this->isRawFieldName($code))
			{
				$fieldNames[$code] = $code;
				continue;
			}

			$aliasesByRole[$context->role ?? ''][] = $code;
		}

		foreach ($aliasesByRole as $codes)
		{
			$fieldNames += $this->fieldAliasService->toFieldNames($codes, $contextByCode[$codes[0]]);
		}

		return $fieldNames;
	}

	private function isRawFieldName(string $code): bool
	{
		return $this->config->isPlaceholderVerifiedAliasFilterDisabled()
			&& NameHelper::isValidFieldName($code)
			&& $this->fieldAliasService->supportsFieldName($code)
		;
	}

	/**
	 * The types the sources of the fields declare, before FieldService converted them into the ones the
	 * placeholder names carry: the conversion turns a url into a plain string, and a rule that judges a
	 * url would never see it in the name. Every source names its codes in its own namespace, so the map
	 * of each strategy that owns one merges into a single map; a field absent from it keeps the type of
	 * its name.
	 *
	 * @return array<string, string> raw field type by the code the placeholder name carries
	 */
	private function getRawFieldTypes(): array
	{
		if ($this->rawFieldTypes !== null)
		{
			return $this->rawFieldTypes;
		}

		$profileFields = $this->profileProvider->getFieldsForSelector();
		$dynamicFields = $this->memberDynamicFieldProvider->getFieldsForSelector();

		return $this->rawFieldTypes = UserFieldsPlaceholderCollectorStrategy::collectRawFieldTypes(
			$profileFields[FrontFieldCategory::PROFILE->value]['FIELDS'] ?? [],
		) + EmployeeDynamicPlaceholderCollectorStrategy::collectRawFieldTypes(
			$dynamicFields[FrontFieldCategory::DYNAMIC_MEMBER->value]['FIELDS'] ?? [],
		);
	}

	private function addUnsupported(RestrictionReason $reason, string $code): void
	{
		$this->unsupportedCodesByReason[$reason->value][] = $code;
	}
}
