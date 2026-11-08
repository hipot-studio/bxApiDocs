<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template;

use Bitrix\Mail\Internal\Service\Signature\Template\Macro\CompanyMacroValueProvider;
use Bitrix\Mail\Internal\Service\Signature\Template\Macro\CompositeSignatureMacroValueProvider;
use Bitrix\Mail\Internal\Service\Signature\Template\Macro\CrmCompanyMacroDataSource;
use Bitrix\Mail\Internal\Service\Signature\Template\Macro\EmployeeMacroValueProvider;
use Bitrix\Mail\Internal\Service\Signature\Template\Macro\SignatureMacroProvider;
use Bitrix\Mail\Internal\Service\Signature\Template\Macro\SignatureMacroValueProvider;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Crm\Service\Container;

final class SignatureTemplateProcessorFactory
{
	public static function create(
		?SignatureMacroValueProvider $employeeValueProvider = null,
		?SignatureMacroValueProvider $companyValueProvider = null,
		?bool $macrosEnabled = null,
	): SignatureTemplateProcessor
	{
		$macrosEnabled ??= Feature::isSignatureMacrosAvailable();
		$providers = [new ImageSignatureTemplateProvider()];
		if (!$macrosEnabled)
		{
			return new SignatureTemplateProcessor(new SignatureTemplateProviderRegistry($providers));
		}

		$valueProvider = new CompositeSignatureMacroValueProvider([
			$employeeValueProvider ?? new EmployeeMacroValueProvider(),
			$companyValueProvider ?? new CompanyMacroValueProvider(
				new CrmCompanyMacroDataSource(
					static fn(int $userId, int $companyId): bool => Container::getInstance()
						->getUserPermissions($userId)
						->myCompany()
						->canReadBaseFields($companyId),
				),
			),
		]);

		$providers[] = new SignatureMacroProvider($valueProvider);

		return new SignatureTemplateProcessor(new SignatureTemplateProviderRegistry($providers));
	}
}
