<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Service\PublicBooking;

use Bitrix\Calendar\Internal\Service\PublicBooking\Source\SourceContextResolver;
use Bitrix\Calendar\Internal\Service\PublicBooking\Source\SourceDescriptor;
use Bitrix\Calendar\UserField\ResourceBooking;

/**
 * Orchestrates secure form-data retrieval for public booking forms.
 *
 * The user/resource set passed into the domain call is always sourced from the
 * server-side TrustedBookingConfig, never narrowed by client-supplied input.
 * The domain answer goes out through ResponseProjection, so the anonymous
 * visitor receives only the whitelisted subset of it.
 */
class PublicBookingFormDataService
{
	public function __construct(
		private readonly SourceContextResolver $resolver,
	)
	{
	}

	public function getFormData(SourceDescriptor $descriptor, array $window): array
	{
		$config = $this->resolver->resolve($descriptor);

		if ($config === null)
		{
			return [];
		}

		$data = [];
		if ($config->getUserIds())
		{
			$data['users'] = ['value' => $config->getUserIds()];
		}
		if ($config->getResourceIds())
		{
			$data['resources'] = ['value' => $config->getResourceIds()];
		}

		$result = $this->callFillFormData($data, [
			'fieldName' => $config->getEntityFieldName(),
			'from' => ($window['from'] ?? null) ?: null,
			'to' => ($window['to'] ?? null) ?: null,
		]);

		return (new ResponseProjection())->project($result, $config->getResourceIds());
	}

	protected function callFillFormData(array $data, array $params): array
	{
		return ResourceBooking::getFillFormData($data, $params);
	}

}
