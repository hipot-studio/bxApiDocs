<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Controller\Builder;

use Bitrix\BIConnector\Infrastructure\Rest\Attribute\DescribesApi;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\FailureCodes;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\Quota;
use Bitrix\BIConnector\Infrastructure\Rest\Controller\AbstractBuilderController;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Operation\Builder\CatalogOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

/**
 * Catalog of the builder family: what the V3 documentation does not tell, the refusal codes of every method.
 */
final class Catalog extends AbstractBuilderController
{
	/**
	 * biconnector.builder.catalog.get: published methods of the family, each with the codes its
	 * #[FailureCodes] declares. Metadata only, so neither BI Builder nor a right of the caller is required.
	 * A controller declaring FEATURE_FLAG is listed only while that flag is on for the caller.
	 */
	#[Quota(BuilderQuotaClass::Read)]
	#[DescribesApi]
	#[FailureCodes(FailureCode::RateLimit)]
	public function getAction(): ArrayResponse
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		$flagGate = $this->createFlagGate();

		$result = ServiceLocator::getInstance()->get(CatalogOperation::class)->execute(
			static fn(string $controllerFqcn, string $method): array => self::declaredCodes($controllerFqcn, $method),
			static fn(string $controllerFqcn): bool => self::isControllerOpen($flagGate, $controllerFqcn, $userId),
		);

		return new ArrayResponse($this->requireResultData($result));
	}

	private static function isControllerOpen(FlagGate $flagGate, string $controllerFqcn, int $userId): bool
	{
		$flagConstant = $controllerFqcn . '::FEATURE_FLAG';
		if (!defined($flagConstant))
		{
			return true;
		}

		return $flagGate->isEnabled((string)constant($flagConstant), $userId);
	}

	/**
	 * @return FailureCode[]
	 */
	private static function declaredCodes(string $controllerFqcn, string $method): array
	{
		$actionName = $method . 'Action';
		if (!method_exists($controllerFqcn, $actionName))
		{
			return [];
		}

		$attributes = (new \ReflectionMethod($controllerFqcn, $actionName))->getAttributes(FailureCodes::class);

		return $attributes === [] ? [] : $attributes[0]->newInstance()->codes;
	}
}
