<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Documentation\Builder;

use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentContract;

final class BuilderContractSchemas
{
	public static function forMethod(string $uri): array
	{
		$path = '#/paths/~1' . $uri . '/post';

		$operation = match ($uri) {
			'biconnector.builder.dashboard.list' => self::dashboardList($path),
			'biconnector.builder.dashboard.get' => self::dashboardGet($path),
			'biconnector.builder.dashboard.add' => self::dashboardAdd($path),
			'biconnector.builder.dashboard.update' => self::dashboardUpdate($path),
			'biconnector.builder.dashboard.content.set' => self::dashboardContentSet($path),
			'biconnector.builder.dashboard.delete' => self::dashboardDelete($path),
			'biconnector.builder.dashboard.copy' => self::dashboardCopy($path),
			'biconnector.builder.dashboard.filter.values' => self::dashboardFilterValues($path),
			'biconnector.builder.dashboard.parameters.search' => self::dashboardParametersSearch($path),
			'biconnector.builder.chart.list' => self::chartList($path),
			'biconnector.builder.chart.get' => self::chartGet($path),
			'biconnector.builder.chart.add' => self::chartAdd($path),
			'biconnector.builder.chart.update' => self::chartUpdate($path),
			'biconnector.builder.chart.config.set' => self::chartConfigSet($path),
			'biconnector.builder.chart.delete' => self::chartDelete($path),
			'biconnector.builder.chart.data.get' => self::chartDataGet($path),
			'biconnector.builder.dataset.list' => self::datasetList($path),
			'biconnector.builder.dataset.get' => self::datasetGet($path),
			'biconnector.builder.dataset.add' => self::datasetAdd($path),
			'biconnector.builder.dataset.addfromtable' => self::datasetAddFromTable($path),
			'biconnector.builder.dataset.update' => self::datasetUpdate($path),
			'biconnector.builder.dataset.delete' => self::datasetDelete($path),
			'biconnector.builder.table.list' => self::tableList($path),
			'biconnector.builder.table.schema.get' => self::tableSchemaGet($path),
			default => throw new \InvalidArgumentException('Unknown Builder method: ' . $uri),
		};
		$schema = BuilderArgumentContract::schema($uri, 'rest', [
			'FIELD-018' => 'Omit to preserve the layout; an empty list removes all charts.',
			'FIELD-024' => 'Omit to preserve filters; supplied filters must include a time range.',
			'FIELD-078' => 'Optional new name; trim and refuse blank when supplied.',
			'FIELD-079' => 'Optional description; an empty string clears it.',
			'FIELD-141' => 'Table name; trim then refuse blank; no identifier pattern.',
		], '');
		$operation['requestBody']['content']['application/json']['schema'] = self::requestSchema(
			$schema,
			$path . '/requestBody/content/application~1json/schema',
		);

		return $operation;
	}

	private static function requestSchema(array $schema, string $path): array
	{
		unset($schema['$defs']);
		if (($schema['$ref'] ?? null) === '#/$defs/JsonValue')
		{
			unset($schema['$ref']);

			return array_merge(self::jsonValue($path), $schema);
		}
		if (isset($schema['type']))
		{
			$schema['nullable'] = false;
		}
		foreach ($schema['properties'] ?? [] as $name => $property)
		{
			$segment = str_replace(['~', '/'], ['~0', '~1'], $name);
			$schema['properties'][$name] = self::requestSchema($property, $path . '/properties/' . $segment);
		}
		foreach (['items', 'additionalProperties'] as $key)
		{
			if (is_array($schema[$key] ?? null))
			{
				$schema[$key] = self::requestSchema($schema[$key], $path . '/' . $key);
			}
		}
		foreach ($schema['anyOf'] ?? [] as $index => $branch)
		{
			$schema['anyOf'][$index] = self::requestSchema($branch, $path . '/anyOf/' . $index);
		}

		return $schema;
	}

	private static function dashboardList(string $path): array
	{
		return self::operation(
			self::dashboardListRequest($path . '/requestBody/content/application~1json/schema'),
			self::dashboardListResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function dashboardListRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'query' => [
					'type' => 'string',
					'nullable' => false,
					'default' => '',
				],
				'offset' => [
					'type' => 'integer',
					'nullable' => false,
					'default' => 0,
					'minimum' => 0,
				],
			],
			'required' => [],
			'additionalProperties' => false,
		];
	}

	private static function dashboardListResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dashboards' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'id' => [
								'type' => 'integer',
							],
							'title' => [
								'type' => 'string',
							],
							'type' => [
								'type' => 'string',
								'nullable' => true,
								'enum' => [
									'SYSTEM',
									'CUSTOM',
									'MARKET',
									null,
								],
							],
							'description' => [
								'type' => 'string',
								'nullable' => true,
							],
							'requiresScope' => [
								'type' => 'boolean',
							],
						],
						'required' => [
							'id',
							'title',
							'type',
							'description',
							'requiresScope',
						],
						'additionalProperties' => false,
					],
				],
				'has_more' => [
					'type' => 'boolean',
				],
				'total' => [
					'type' => 'integer',
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'dashboards',
				'has_more',
				'total',
			],
			'additionalProperties' => false,
		];
	}

	private static function dashboardGet(string $path): array
	{
		return self::operation(
			self::dashboardGetRequest($path . '/requestBody/content/application~1json/schema'),
			self::dashboardGetResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function dashboardGetRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'nullable' => false,
					'minimum' => 1,
				],
				'appliedFilters' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'name' => [
								'type' => 'string',
							],
							'value' => [
								'anyOf' => [
									[
										'type' => 'array',
										'items' => self::jsonValue($path . '/properties/appliedFilters/items/properties/value/anyOf/0/items'),
									],
									[
										'type' => 'object',
										'additionalProperties' => self::jsonValue($path . '/properties/appliedFilters/items/properties/value/anyOf/1/'
											. 'additionalProperties'),
									],
								],
							],
						],
						'required' => [
							'name',
							'value',
						],
						'additionalProperties' => false,
					],
					'nullable' => false,
					'default' => [],
				],
				'urlParams' => [
					'type' => 'object',
					'additionalProperties' => [
						'anyOf' => [
							[
								'type' => 'string',
								'nullable' => true,
							],
							[
								'type' => 'number',
							],
							[
								'type' => 'boolean',
							],
							[
								'type' => 'array',
								'items' => [
									'$ref' => $path . '/properties/urlParams/additionalProperties',
								],
							],
							[
								'type' => 'object',
								'additionalProperties' => [
									'$ref' => $path . '/properties/urlParams/additionalProperties',
								],
							],
						],
					],
					'nullable' => false,
					'default' => new \stdClass(),
				],
			],
			'required' => [
				'dashboardId',
			],
			'additionalProperties' => false,
		];
	}

	private static function dashboardGetResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dashboard' => [
					'type' => 'object',
					'properties' => [
						'id' => [
							'type' => 'integer',
						],
						'title' => [
							'type' => 'string',
						],
					],
					'required' => [
						'id',
						'title',
					],
					'additionalProperties' => false,
				],
				'caller_filters' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'name' => [
								'type' => 'string',
							],
							'value' => [
								'anyOf' => [
									[
										'type' => 'array',
										'items' => self::jsonValue($path . '/properties/caller_filters/items/properties/value/anyOf/0/items'),
									],
									[
										'type' => 'object',
										'additionalProperties' => self::jsonValue($path . '/properties/caller_filters/items/properties/value/anyOf/1/'
											. 'additionalProperties'),
									],
								],
							],
						],
						'required' => [
							'name',
							'value',
						],
						'additionalProperties' => false,
					],
				],
				'charts' => self::dashboardCharts($path . '/properties/charts'),
				'available_filters' => self::dashboardAvailableFilters($path . '/properties/available_filters'),
				'meta' => [
					'type' => 'object',
					'properties' => [
						'period' => [
							'type' => 'object',
							'properties' => [
								'from' => [
									'type' => 'string',
								],
								'to' => [
									'type' => 'string',
								],
							],
							'required' => [
								'from',
								'to',
							],
							'additionalProperties' => false,
						],
						'charts_with_stats' => [
							'type' => 'integer',
						],
						'charts_total' => [
							'type' => 'integer',
						],
					],
					'required' => [],
					'additionalProperties' => false,
					'minProperties' => 1,
				],
				'filter_coverage' => [
					'anyOf' => [
						[
							'type' => 'object',
							'additionalProperties' => [
								'type' => 'object',
								'required' => [
									'applied_to',
									'skipped',
								],
								'properties' => [
									'applied_to' => [
										'type' => 'array',
										'items' => [
											'type' => 'integer',
										],
									],
									'skipped' => [
										'type' => 'array',
										'items' => [
											'type' => 'integer',
										],
									],
								],
								'additionalProperties' => false,
							],
							'minProperties' => 1,
						],
						[
							'type' => 'array',
							'items' => [
								'type' => 'object',
								'required' => [
									'applied_to',
									'skipped',
								],
								'properties' => [
									'applied_to' => [
										'type' => 'array',
										'items' => [
											'type' => 'integer',
										],
									],
									'skipped' => [
										'type' => 'array',
										'items' => [
											'type' => 'integer',
										],
									],
								],
								'additionalProperties' => false,
							],
							'minItems' => 1,
						],
					],
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'dashboard',
				'caller_filters',
				'charts',
				'available_filters',
			],
			'additionalProperties' => false,
		];
	}

	private static function dashboardAdd(string $path): array
	{
		return self::operation(
			self::dashboardAddRequest($path . '/requestBody/content/application~1json/schema'),
			self::dashboardAddResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function dashboardAddRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'name' => [
					'type' => 'string',
					'nullable' => false,
					'minLength' => 1,
					'maxLength' => 128,
				],
			],
			'required' => [
				'name',
			],
			'additionalProperties' => false,
		];
	}

	private static function dashboardAddResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => [
					'type' => 'integer',
				],
				'name' => [
					'type' => 'string',
				],
				'published' => [
					'type' => 'boolean',
				],
				'reused' => [
					'type' => 'boolean',
					'enum' => [
						true,
					],
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'id',
				'name',
				'published',
			],
			'additionalProperties' => false,
		];
	}

	private static function dashboardUpdate(string $path): array
	{
		return self::operation(
			self::dashboardUpdateRequest($path . '/requestBody/content/application~1json/schema'),
			self::dashboardUpdateResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function dashboardUpdateRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'nullable' => false,
					'minimum' => 1,
				],
				'name' => [
					'type' => 'string',
					'nullable' => false,
					'minLength' => 1,
					'maxLength' => 128,
				],
			],
			'required' => [
				'dashboardId',
				'name',
			],
			'additionalProperties' => false,
		];
	}

	private static function dashboardUpdateResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => [
					'type' => 'integer',
				],
				'name' => [
					'type' => 'string',
				],
				'published' => [
					'type' => 'boolean',
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'id',
				'name',
				'published',
			],
			'additionalProperties' => false,
		];
	}

	private static function dashboardContentSet(string $path): array
	{
		return self::operation(
			self::dashboardContentSetRequest($path . '/requestBody/content/application~1json/schema'),
			self::dashboardContentSetResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function dashboardContentSetRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'nullable' => false,
					'minimum' => 1,
				],
				'charts' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'chartId' => [
								'type' => 'integer',
								'minimum' => 1,
							],
							'width' => [
								'type' => 'integer',
								'minimum' => 1,
								'maximum' => 12,
								'default' => 6,
							],
							'height' => [
								'type' => 'integer',
								'minimum' => 10,
								'maximum' => 200,
								'default' => 50,
							],
							'sliceName' => [
								'type' => 'string',
								'default' => '',
							],
						],
						'required' => [
							'chartId',
						],
						'additionalProperties' => self::jsonValue($path . '/properties/charts/items/additionalProperties'),
					],
					'maxItems' => 50,
					'nullable' => true,
					'default' => null,
				],
				'filters' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'name' => [
								'type' => 'string',
								'minLength' => 1,
							],
							'filterType' => [
								'type' => 'string',
								'enum' => [
									'time_range',
									'value',
									'range',
								],
							],
							'datasetId' => [
								'type' => 'integer',
								'minimum' => 1,
							],
							'column' => [
								'type' => 'string',
								'minLength' => 1,
							],
							'chartIds' => [
								'type' => 'array',
								'items' => [
									'type' => 'integer',
									'minimum' => 1,
								],
								'nullable' => true,
							],
							'defaultValue' => [
								'type' => 'string',
							],
						],
						'required' => [
							'name',
							'filterType',
						],
						'additionalProperties' => self::jsonValue($path . '/properties/filters/items/additionalProperties'),
					],
					'maxItems' => 20,
					'nullable' => true,
					'default' => null,
				],
			],
			'required' => [
				'dashboardId',
			],
			'additionalProperties' => false,
		];
	}

	private static function dashboardContentSetResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => [
					'type' => 'integer',
				],
				'name' => [
					'type' => 'string',
				],
				'published' => [
					'type' => 'boolean',
				],
				'charts_count' => [
					'type' => 'integer',
					'nullable' => true,
				],
				'filters_count' => [
					'type' => 'integer',
					'nullable' => true,
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'id',
				'name',
				'published',
				'charts_count',
				'filters_count',
			],
			'additionalProperties' => false,
		];
	}

	private static function dashboardDelete(string $path): array
	{
		return self::operation(
			self::dashboardDeleteRequest($path . '/requestBody/content/application~1json/schema'),
			self::dashboardDeleteResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function dashboardDeleteRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'nullable' => false,
					'minimum' => 1,
				],
			],
			'required' => [
				'dashboardId',
			],
			'additionalProperties' => false,
		];
	}

	private static function dashboardDeleteResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'deleted' => [
					'type' => 'boolean',
					'enum' => [
						true,
					],
				],
				'id' => [
					'type' => 'integer',
				],
				'name' => [
					'type' => 'string',
				],
				'already_deleted' => [
					'type' => 'boolean',
					'enum' => [
						true,
					],
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'deleted',
				'id',
				'name',
			],
			'additionalProperties' => false,
		];
	}

	private static function dashboardCopy(string $path): array
	{
		return self::operation(
			self::dashboardCopyRequest($path . '/requestBody/content/application~1json/schema'),
			self::dashboardCopyResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function dashboardCopyRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'nullable' => false,
					'minimum' => 1,
				],
			],
			'required' => [
				'dashboardId',
			],
			'additionalProperties' => false,
		];
	}

	private static function dashboardCopyResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => [
					'type' => 'integer',
				],
				'name' => [
					'type' => 'string',
				],
				'published' => [
					'type' => 'boolean',
					'enum' => [
						false,
					],
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'id',
				'name',
				'published',
			],
			'additionalProperties' => false,
		];
	}

	private static function dashboardFilterValues(string $path): array
	{
		return self::operation(
			self::dashboardFilterValuesRequest($path . '/requestBody/content/application~1json/schema'),
			self::dashboardFilterValuesResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function dashboardFilterValuesRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'nullable' => false,
					'minimum' => 1,
				],
				'filterColumn' => [
					'type' => 'string',
					'nullable' => false,
				],
				'search' => [
					'type' => 'string',
					'nullable' => false,
					'default' => '',
					'maxLength' => 200,
				],
				'limit' => [
					'type' => 'integer',
					'nullable' => false,
					'default' => 20,
					'minimum' => 1,
					'maximum' => 50,
				],
				'appliedFilters' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'name' => [
								'type' => 'string',
							],
							'value' => [
								'anyOf' => [
									[
										'type' => 'array',
										'items' => self::jsonValue($path . '/properties/appliedFilters/items/properties/value/anyOf/0/items'),
									],
									[
										'type' => 'object',
										'additionalProperties' => self::jsonValue($path . '/properties/appliedFilters/items/properties/value/anyOf/1/'
											. 'additionalProperties'),
									],
								],
							],
						],
						'required' => [
							'name',
							'value',
						],
						'additionalProperties' => false,
					],
					'nullable' => false,
					'default' => [],
				],
				'urlParams' => [
					'type' => 'object',
					'additionalProperties' => [
						'anyOf' => [
							[
								'type' => 'string',
								'nullable' => true,
							],
							[
								'type' => 'number',
							],
							[
								'type' => 'boolean',
							],
							[
								'type' => 'array',
								'items' => [
									'$ref' => $path . '/properties/urlParams/additionalProperties',
								],
							],
							[
								'type' => 'object',
								'additionalProperties' => [
									'$ref' => $path . '/properties/urlParams/additionalProperties',
								],
							],
						],
					],
					'nullable' => false,
					'default' => new \stdClass(),
				],
			],
			'required' => [
				'dashboardId',
				'filterColumn',
			],
			'additionalProperties' => false,
		];
	}

	private static function dashboardFilterValuesResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'column' => [
					'type' => 'string',
					'minLength' => 1,
					'pattern' => '^[^\\u0009-\\u000D\\u001C-\\u0020\\u0085\\u00A0\\u'
						. '1680\\u2000-\\u200A\\u2028\\u2029\\u202F\\u205F\\u'
						. '3000](?:[\\s\\S]*[^\\u0009-\\u000D\\u001C-\\u0020'
						. '\\u0085\\u00A0\\u1680\\u2000-\\u200A\\u2028\\u2029'
						. '\\u202F\\u205F\\u3000])?(?![\\s\\S])',
				],
				'values_for_display' => [
					'type' => 'array',
					'items' => self::jsonValue($path . '/properties/values_for_display/items'),
				],
				'values_for_filter' => [
					'type' => 'array',
					'items' => self::jsonValue($path . '/properties/values_for_filter/items'),
				],
				'has_more' => [
					'type' => 'boolean',
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'column',
				'values_for_display',
				'values_for_filter',
				'has_more',
			],
			'additionalProperties' => false,
		];
	}

	private static function dashboardParametersSearch(string $path): array
	{
		return self::operation(
			self::dashboardParametersSearchRequest($path . '/requestBody/content/application~1json/schema'),
			self::dashboardParametersSearchResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function dashboardParametersSearchRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'nullable' => false,
					'minimum' => 1,
				],
				'search' => [
					'type' => 'string',
					'nullable' => false,
					'default' => '',
					'maxLength' => 200,
				],
				'offset' => [
					'type' => 'integer',
					'nullable' => false,
					'default' => 0,
				],
			],
			'required' => [
				'dashboardId',
			],
			'additionalProperties' => false,
		];
	}

	private static function dashboardParametersSearchResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
				],
				'required' => [
					'type' => 'boolean',
				],
				'parameters' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'paramCode' => [
								'type' => 'string',
							],
							'paramTitle' => [
								'type' => 'string',
							],
							'availableValues' => [
								'type' => 'array',
								'items' => [
									'type' => 'object',
									'properties' => [
										'id' => [
											'type' => 'integer',
										],
										'name' => [
											'type' => 'string',
										],
									],
									'required' => [
										'id',
										'name',
									],
									'additionalProperties' => false,
								],
							],
							'offset' => [
								'type' => 'integer',
							],
							'hasMore' => [
								'type' => 'boolean',
							],
						],
						'required' => [
							'paramCode',
							'paramTitle',
							'availableValues',
							'offset',
							'hasMore',
						],
						'additionalProperties' => false,
					],
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'dashboardId',
				'required',
				'parameters',
			],
			'additionalProperties' => false,
		];
	}

	private static function chartList(string $path): array
	{
		return self::operation(
			self::chartListRequest($path . '/requestBody/content/application~1json/schema'),
			self::chartListResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function chartListRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'limit' => [
					'type' => 'integer',
					'nullable' => false,
					'default' => 20,
					'minimum' => 1,
					'maximum' => 50,
				],
				'offset' => [
					'type' => 'integer',
					'nullable' => false,
					'default' => 0,
					'minimum' => 0,
				],
			],
			'required' => [],
			'additionalProperties' => false,
		];
	}

	private static function chartListResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'charts' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'id' => [
								'type' => 'integer',
							],
							'chart_name' => [
								'type' => 'string',
							],
							'viz_type' => [
								'type' => 'string',
							],
							'dataset_id' => [
								'type' => 'integer',
							],
							'description' => [
								'type' => 'string',
							],
						],
						'required' => [
							'id',
							'chart_name',
							'viz_type',
							'dataset_id',
							'description',
						],
						'additionalProperties' => false,
					],
				],
				'total' => [
					'type' => 'integer',
				],
				'limit' => [
					'type' => 'integer',
				],
				'offset' => [
					'type' => 'integer',
				],
				'has_more' => [
					'type' => 'boolean',
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'charts',
				'total',
				'limit',
				'offset',
				'has_more',
			],
			'additionalProperties' => false,
		];
	}

	private static function chartGet(string $path): array
	{
		return self::operation(
			self::chartGetRequest($path . '/requestBody/content/application~1json/schema'),
			self::chartGetResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function chartGetRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'chartId' => [
					'type' => 'integer',
					'nullable' => false,
					'minimum' => 1,
				],
			],
			'required' => [
				'chartId',
			],
			'additionalProperties' => false,
		];
	}

	private static function chartGetResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => [
					'type' => 'integer',
				],
				'name' => [
					'type' => 'string',
				],
				'description' => [
					'type' => 'string',
				],
				'viz_type' => [
					'type' => 'string',
				],
				'dataset_id' => [
					'type' => 'integer',
				],
				'datasource_type' => [
					'type' => 'string',
				],
				'params' => [
					'anyOf' => [
						[
							'type' => 'object',
							'properties' => [
								'viz_type' => self::jsonValue($path . '/properties/params/anyOf/0/properties/viz_type'),
								'datasource' => self::jsonValue($path . '/properties/params/anyOf/0/properties/datasource'),
								'metrics' => self::jsonValue($path . '/properties/params/anyOf/0/properties/metrics'),
								'metric' => self::jsonValue($path . '/properties/params/anyOf/0/properties/metric'),
								'groupby' => self::jsonValue($path . '/properties/params/anyOf/0/properties/groupby'),
								'columns' => self::jsonValue($path . '/properties/params/anyOf/0/properties/columns'),
								'all_columns' => self::jsonValue($path . '/properties/params/anyOf/0/properties/all_columns'),
								'query_mode' => self::jsonValue($path . '/properties/params/anyOf/0/properties/query_mode'),
								'x_axis' => self::jsonValue($path . '/properties/params/anyOf/0/properties/x_axis'),
								'granularity_sqla' => self::jsonValue($path . '/properties/params/anyOf/0/properties/granularity_sqla'),
								'temporal_columns_lookup' => self::jsonValue(
									$path . '/properties/params/anyOf/0/properties/temporal_columns_lookup',
								),
								'adhoc_filters' => self::jsonValue($path . '/properties/params/anyOf/0/properties/adhoc_filters'),
								'time_range' => self::jsonValue($path . '/properties/params/anyOf/0/properties/time_range'),
								'row_limit' => self::jsonValue($path . '/properties/params/anyOf/0/properties/row_limit'),
								'order_desc' => self::jsonValue($path . '/properties/params/anyOf/0/properties/order_desc'),
								'orderby' => self::jsonValue($path . '/properties/params/anyOf/0/properties/orderby'),
							],
							'required' => [],
							'additionalProperties' => false,
							'minProperties' => 1,
						],
						[
							'type' => 'array',
							'items' => self::jsonValue($path . '/properties/params/anyOf/1/items'),
							'maxItems' => 0,
						],
						[
							'type' => 'string',
							'nullable' => true,
						],
						[
							'type' => 'number',
						],
						[
							'type' => 'boolean',
						],
					],
				],
				'owners' => [
					'type' => 'array',
					'items' => [
						'anyOf' => [
							[
								'type' => 'string',
								'nullable' => true,
							],
							[
								'type' => 'object',
								'properties' => [
									'id' => [
										'type' => 'integer',
										'nullable' => true,
									],
									'first_name' => [
										'type' => 'string',
										'nullable' => true,
									],
									'last_name' => [
										'type' => 'string',
										'nullable' => true,
									],
								],
								'additionalProperties' => false,
							],
						],
					],
				],
				'dashboards' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'id' => [
								'type' => 'integer',
							],
							'title' => [
								'type' => 'string',
							],
						],
						'required' => [
							'id',
							'title',
						],
						'additionalProperties' => false,
					],
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'id',
				'name',
				'description',
				'viz_type',
				'dataset_id',
				'datasource_type',
				'params',
				'owners',
				'dashboards',
			],
			'additionalProperties' => false,
		];
	}

	private static function chartAdd(string $path): array
	{
		return self::operation(
			self::chartAddRequest($path . '/requestBody/content/application~1json/schema'),
			self::chartAddResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function chartAddRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'name' => [
					'type' => 'string',
					'nullable' => false,
					'minimum' => 1,
					'minLength' => 1,
				],
				'datasetId' => [
					'type' => 'integer',
					'nullable' => false,
					'minimum' => 1,
				],
				'vizType' => [
					'type' => 'string',
					'nullable' => false,
					'enum' => [
						'bar',
						'line',
						'scatter',
						'pie',
						'table',
						'big_number',
					],
				],
				'temporalColumn' => [
					'type' => 'string',
					'nullable' => false,
				],
				'metrics' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'column' => [
								'type' => 'string',
								'minLength' => 1,
							],
							'aggregate' => [
								'type' => 'string',
								'enum' => [
									'SUM',
									'AVG',
									'COUNT',
									'COUNT_DISTINCT',
									'MIN',
									'MAX',
								],
							],
							'label' => [
								'type' => 'string',
							],
						],
						'required' => [
							'column',
							'aggregate',
						],
						'additionalProperties' => self::jsonValue($path . '/properties/metrics/items/additionalProperties'),
					],
					'nullable' => false,
					'default' => [],
				],
				'dimensions' => [
					'type' => 'array',
					'items' => [
						'type' => 'string',
					],
					'nullable' => false,
					'default' => [],
				],
				'xAxis' => [
					'type' => 'string',
					'nullable' => false,
					'default' => '',
				],
				'filters' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'column' => [
								'type' => 'string',
								'minLength' => 1,
							],
							'operator' => [
								'type' => 'string',
								'default' => '=',
								'enum' => [
									'=',
									'==',
									'!=',
									'<>',
									'<',
									'>',
									'<=',
									'>=',
									'in',
									'not_in',
									'like',
									'is_null',
									'is_not_null',
									'between',
								],
							],
							'value' => self::jsonValue($path . '/properties/filters/items/properties/value'),
						],
						'required' => [
							'column',
						],
						'additionalProperties' => self::jsonValue($path . '/properties/filters/items/additionalProperties'),
					],
					'nullable' => false,
					'default' => [],
				],
				'description' => [
					'type' => 'string',
					'nullable' => false,
					'default' => '',
				],
				'rowLimit' => [
					'type' => 'integer',
					'nullable' => true,
					'default' => null,
				],
			],
			'required' => [
				'name',
				'datasetId',
				'vizType',
				'temporalColumn',
			],
			'additionalProperties' => false,
		];
	}

	private static function chartAddResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => [
					'type' => 'integer',
				],
				'name' => [
					'type' => 'string',
				],
				'viz_type' => [
					'type' => 'string',
				],
				'dataset_id' => [
					'type' => 'integer',
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'id',
				'name',
				'viz_type',
				'dataset_id',
			],
			'additionalProperties' => false,
		];
	}

	private static function chartUpdate(string $path): array
	{
		return self::operation(
			self::chartUpdateRequest($path . '/requestBody/content/application~1json/schema'),
			self::chartUpdateResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function chartUpdateRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'chartId' => [
					'type' => 'integer',
					'nullable' => false,
					'minimum' => 1,
				],
				'name' => [
					'type' => 'string',
					'nullable' => true,
					'default' => null,
				],
				'description' => [
					'type' => 'string',
					'nullable' => true,
					'default' => null,
				],
			],
			'required' => [
				'chartId',
			],
			'additionalProperties' => false,
		];
	}

	private static function chartUpdateResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => [
					'type' => 'integer',
				],
				'name' => [
					'type' => 'string',
				],
				'description' => [
					'type' => 'string',
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'id',
				'name',
			],
			'additionalProperties' => false,
		];
	}

	private static function chartConfigSet(string $path): array
	{
		return self::operation(
			self::chartConfigSetRequest($path . '/requestBody/content/application~1json/schema'),
			self::chartConfigSetResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function chartConfigSetRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'chartId' => [
					'type' => 'integer',
					'nullable' => false,
					'minimum' => 1,
				],
				'datasetId' => [
					'type' => 'integer',
					'nullable' => false,
					'minimum' => 1,
				],
				'vizType' => [
					'type' => 'string',
					'nullable' => false,
					'enum' => [
						'bar',
						'line',
						'scatter',
						'pie',
						'table',
						'big_number',
					],
				],
				'temporalColumn' => [
					'type' => 'string',
					'nullable' => false,
				],
				'metrics' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'column' => [
								'type' => 'string',
								'minLength' => 1,
							],
							'aggregate' => [
								'type' => 'string',
								'enum' => [
									'SUM',
									'AVG',
									'COUNT',
									'COUNT_DISTINCT',
									'MIN',
									'MAX',
								],
							],
							'label' => [
								'type' => 'string',
							],
						],
						'required' => [
							'column',
							'aggregate',
						],
						'additionalProperties' => self::jsonValue($path . '/properties/metrics/items/additionalProperties'),
					],
					'nullable' => false,
					'default' => [],
				],
				'dimensions' => [
					'type' => 'array',
					'items' => [
						'type' => 'string',
					],
					'nullable' => false,
					'default' => [],
				],
				'xAxis' => [
					'type' => 'string',
					'nullable' => false,
					'default' => '',
				],
				'filters' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'column' => [
								'type' => 'string',
								'minLength' => 1,
							],
							'operator' => [
								'type' => 'string',
								'default' => '=',
								'enum' => [
									'=',
									'==',
									'!=',
									'<>',
									'<',
									'>',
									'<=',
									'>=',
									'in',
									'not_in',
									'like',
									'is_null',
									'is_not_null',
									'between',
								],
							],
							'value' => self::jsonValue($path . '/properties/filters/items/properties/value'),
						],
						'required' => [
							'column',
						],
						'additionalProperties' => self::jsonValue($path . '/properties/filters/items/additionalProperties'),
					],
					'nullable' => false,
					'default' => [],
				],
				'rowLimit' => [
					'type' => 'integer',
					'nullable' => true,
					'default' => null,
				],
			],
			'required' => [
				'chartId',
				'datasetId',
				'vizType',
				'temporalColumn',
			],
			'additionalProperties' => false,
		];
	}

	private static function chartConfigSetResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => [
					'type' => 'integer',
				],
				'name' => [
					'type' => 'string',
				],
				'viz_type' => [
					'type' => 'string',
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'id',
				'name',
				'viz_type',
			],
			'additionalProperties' => false,
		];
	}

	private static function chartDelete(string $path): array
	{
		return self::operation(
			self::chartDeleteRequest($path . '/requestBody/content/application~1json/schema'),
			self::chartDeleteResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function chartDeleteRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'chartId' => [
					'type' => 'integer',
					'nullable' => false,
					'minimum' => 1,
				],
			],
			'required' => [
				'chartId',
			],
			'additionalProperties' => false,
		];
	}

	private static function chartDeleteResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'deleted' => [
					'type' => 'boolean',
					'enum' => [
						true,
					],
				],
				'id' => [
					'type' => 'integer',
				],
				'name' => [
					'type' => 'string',
				],
				'already_deleted' => [
					'type' => 'boolean',
					'enum' => [
						true,
					],
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'deleted',
				'id',
				'name',
			],
			'additionalProperties' => false,
		];
	}

	private static function chartDataGet(string $path): array
	{
		return self::operation(
			self::chartDataGetRequest($path . '/requestBody/content/application~1json/schema'),
			self::chartDataGetResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function chartDataGetRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'nullable' => false,
					'minimum' => 1,
				],
				'chartIds' => [
					'type' => 'array',
					'items' => [
						'type' => 'integer',
						'minimum' => 1,
					],
					'minItems' => 1,
					'nullable' => false,
				],
				'appliedFilters' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'name' => [
								'type' => 'string',
							],
							'value' => [
								'anyOf' => [
									[
										'type' => 'array',
										'items' => self::jsonValue($path . '/properties/appliedFilters/items/properties/value/anyOf/0/items'),
									],
									[
										'type' => 'object',
										'additionalProperties' => self::jsonValue($path . '/properties/appliedFilters/items/properties/value/anyOf/1/'
											. 'additionalProperties'),
									],
								],
							],
						],
						'required' => [
							'name',
							'value',
						],
						'additionalProperties' => false,
					],
					'nullable' => false,
					'default' => [],
				],
				'urlParams' => [
					'type' => 'object',
					'additionalProperties' => [
						'anyOf' => [
							[
								'type' => 'string',
								'nullable' => true,
							],
							[
								'type' => 'number',
							],
							[
								'type' => 'boolean',
							],
							[
								'type' => 'array',
								'items' => [
									'$ref' => $path . '/properties/urlParams/additionalProperties',
								],
							],
							[
								'type' => 'object',
								'additionalProperties' => [
									'$ref' => $path . '/properties/urlParams/additionalProperties',
								],
							],
						],
					],
					'nullable' => false,
					'default' => new \stdClass(),
				],
				'rowLimit' => [
					'type' => 'integer',
					'nullable' => false,
					'default' => 20,
					'minimum' => 1,
					'maximum' => 300,
				],
				'offset' => [
					'type' => 'integer',
					'nullable' => false,
					'default' => 0,
					'minimum' => 0,
				],
				'sortBy' => [
					'type' => 'string',
					'nullable' => false,
					'default' => '',
					'maxLength' => 255,
				],
				'sortOrder' => [
					'type' => 'string',
					'nullable' => false,
					'default' => 'desc',
					'enum' => [
						'asc',
						'desc',
					],
				],
			],
			'required' => [
				'dashboardId',
				'chartIds',
			],
			'additionalProperties' => false,
		];
	}

	private static function chartDataGetResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dashboard' => [
					'type' => 'object',
					'properties' => [
						'title' => [
							'type' => 'string',
						],
					],
					'required' => [
						'title',
					],
					'additionalProperties' => false,
				],
				'charts' => self::chartDataCharts($path . '/properties/charts'),
				'filter_coverage' => [
					'anyOf' => [
						[
							'type' => 'object',
							'additionalProperties' => [
								'type' => 'object',
								'required' => [
									'applied_to',
									'skipped',
								],
								'properties' => [
									'applied_to' => [
										'type' => 'array',
										'items' => [
											'type' => 'integer',
										],
									],
									'skipped' => [
										'type' => 'array',
										'items' => [
											'type' => 'integer',
										],
									],
								],
								'additionalProperties' => false,
							],
							'minProperties' => 1,
						],
						[
							'type' => 'array',
							'items' => [
								'type' => 'object',
								'required' => [
									'applied_to',
									'skipped',
								],
								'properties' => [
									'applied_to' => [
										'type' => 'array',
										'items' => [
											'type' => 'integer',
										],
									],
									'skipped' => [
										'type' => 'array',
										'items' => [
											'type' => 'integer',
										],
									],
								],
								'additionalProperties' => false,
							],
						],
					],
				],
				'meta' => [
					'type' => 'object',
					'properties' => [
						'dashboard_title' => [
							'type' => 'string',
						],
						'total_charts' => [
							'type' => 'integer',
						],
						'charts_has_more' => [
							'type' => 'boolean',
							'enum' => [
								false,
							],
						],
						'period' => [
							'type' => 'object',
							'properties' => [
								'from' => [
									'type' => 'string',
								],
								'to' => [
									'type' => 'string',
								],
							],
							'required' => [
								'from',
								'to',
							],
							'additionalProperties' => false,
						],
					],
					'required' => [
						'dashboard_title',
						'total_charts',
						'charts_has_more',
					],
					'additionalProperties' => false,
				],
				'applied_filters' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'name' => [
								'type' => 'string',
							],
							'value' => [
								'anyOf' => [
									[
										'type' => 'array',
										'items' => self::jsonValue($path . '/properties/applied_filters/items/properties/value/anyOf/0/items'),
									],
									[
										'type' => 'object',
										'additionalProperties' => self::jsonValue($path . '/properties/applied_filters/items/properties/value'
											. '/anyOf/1/'
											. 'additionalProperties'),
									],
								],
							],
						],
						'required' => [
							'name',
							'value',
						],
						'additionalProperties' => false,
					],
				],
				'missing_chart_ids' => [
					'type' => 'array',
					'items' => [
						'type' => 'integer',
					],
					'minItems' => 1,
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'dashboard',
				'charts',
				'filter_coverage',
			],
			'additionalProperties' => false,
		];
	}

	private static function datasetList(string $path): array
	{
		return self::operation(
			self::datasetListRequest($path . '/requestBody/content/application~1json/schema'),
			self::datasetListResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function datasetListRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'limit' => [
					'type' => 'integer',
					'nullable' => false,
					'default' => 20,
					'minimum' => 1,
					'maximum' => 50,
				],
				'offset' => [
					'type' => 'integer',
					'nullable' => false,
					'default' => 0,
					'minimum' => 0,
				],
			],
			'required' => [],
			'additionalProperties' => false,
		];
	}

	private static function datasetListResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'datasets' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'id' => [
								'type' => 'integer',
							],
							'table_name' => [
								'type' => 'string',
							],
							'is_virtual' => [
								'type' => 'boolean',
							],
							'description' => [
								'type' => 'string',
							],
							'schema' => [
								'type' => 'string',
							],
							'sql_preview' => [
								'type' => 'string',
							],
						],
						'required' => [
							'id',
							'table_name',
							'is_virtual',
							'description',
						],
						'additionalProperties' => false,
					],
				],
				'total' => [
					'type' => 'integer',
				],
				'limit' => [
					'type' => 'integer',
				],
				'offset' => [
					'type' => 'integer',
				],
				'has_more' => [
					'type' => 'boolean',
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'datasets',
				'total',
				'limit',
				'offset',
				'has_more',
			],
			'additionalProperties' => false,
		];
	}

	private static function datasetGet(string $path): array
	{
		return self::operation(
			self::datasetGetRequest($path . '/requestBody/content/application~1json/schema'),
			self::datasetGetResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function datasetGetRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'datasetId' => [
					'type' => 'integer',
					'nullable' => false,
					'minimum' => 1,
				],
			],
			'required' => [
				'datasetId',
			],
			'additionalProperties' => false,
		];
	}

	private static function datasetGetResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => [
					'type' => 'integer',
				],
				'table_name' => [
					'type' => 'string',
				],
				'description' => [
					'type' => 'string',
				],
				'is_virtual' => [
					'type' => 'boolean',
				],
				'sql' => [
					'type' => 'string',
				],
				'columns' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'name' => [
								'type' => 'string',
							],
							'type' => [
								'type' => 'string',
							],
							'is_dttm' => [
								'type' => 'boolean',
							],
							'verbose_name' => [
								'type' => 'string',
							],
							'description' => [
								'type' => 'string',
							],
						],
						'required' => [
							'name',
							'type',
							'is_dttm',
						],
						'additionalProperties' => false,
					],
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'id',
				'table_name',
				'description',
				'is_virtual',
				'sql',
				'columns',
			],
			'additionalProperties' => false,
		];
	}

	private static function datasetAdd(string $path): array
	{
		return self::operation(
			self::datasetAddRequest($path . '/requestBody/content/application~1json/schema'),
			self::datasetAddResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function datasetAddRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'datasetName' => [
					'type' => 'string',
					'nullable' => false,
				],
				'sql' => [
					'type' => 'string',
					'nullable' => false,
				],
			],
			'required' => [
				'datasetName',
				'sql',
			],
			'additionalProperties' => false,
		];
	}

	private static function datasetAddResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => [
					'type' => 'integer',
				],
				'name' => [
					'type' => 'string',
				],
				'sql' => [
					'type' => 'string',
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'id',
				'name',
				'sql',
			],
			'additionalProperties' => false,
		];
	}

	private static function datasetAddFromTable(string $path): array
	{
		return self::operation(
			self::datasetAddFromTableRequest($path . '/requestBody/content/application~1json/schema'),
			self::datasetAddFromTableResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function datasetAddFromTableRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'datasetName' => [
					'type' => 'string',
					'nullable' => false,
				],
				'sourceTable' => [
					'type' => 'string',
					'nullable' => false,
				],
				'columns' => [
					'type' => 'array',
					'items' => [
						'type' => 'string',
					],
					'minItems' => 1,
					'nullable' => false,
				],
				'sourceSchema' => [
					'type' => 'string',
					'nullable' => false,
					'default' => '',
				],
			],
			'required' => [
				'datasetName',
				'sourceTable',
				'columns',
			],
			'additionalProperties' => false,
		];
	}

	private static function datasetAddFromTableResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => [
					'type' => 'integer',
				],
				'name' => [
					'type' => 'string',
				],
				'sql' => [
					'type' => 'string',
				],
				'source_table' => [
					'type' => 'string',
				],
				'source_schema' => [
					'type' => 'string',
				],
				'columns' => [
					'type' => 'array',
					'minItems' => 1,
					'uniqueItems' => true,
					'items' => [
						'type' => 'string',
						'pattern' => '^[A-Za-z_][A-Za-z0-9_]*(?![\\s\\S])',
					],
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'id',
				'name',
				'sql',
				'source_table',
				'source_schema',
				'columns',
			],
			'additionalProperties' => false,
		];
	}

	private static function datasetUpdate(string $path): array
	{
		return self::operation(
			self::datasetUpdateRequest($path . '/requestBody/content/application~1json/schema'),
			self::datasetUpdateResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function datasetUpdateRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'datasetId' => [
					'type' => 'integer',
					'nullable' => false,
					'minimum' => 1,
				],
				'columns' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'name' => [
								'type' => 'string',
								'minLength' => 1,
							],
							'type' => [
								'type' => 'string',
								'enum' => [
									'INT',
									'STRING',
									'DOUBLE',
									'DATE',
									'DATETIME',
								],
							],
						],
						'required' => [
							'name',
							'type',
						],
						'additionalProperties' => self::jsonValue($path . '/properties/columns/items/additionalProperties'),
					],
					'minItems' => 1,
					'nullable' => false,
				],
			],
			'required' => [
				'datasetId',
				'columns',
			],
			'additionalProperties' => false,
		];
	}

	private static function datasetUpdateResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'updated' => [
					'type' => 'boolean',
					'enum' => [
						true,
					],
				],
				'id' => [
					'type' => 'integer',
				],
				'name' => [
					'type' => 'string',
				],
				'columns' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'name' => [
								'type' => 'string',
							],
							'type' => [
								'type' => 'string',
								'enum' => [
									'INT',
									'STRING',
									'DOUBLE',
									'DATE',
									'DATETIME',
								],
							],
						],
						'required' => [
							'name',
							'type',
						],
						'additionalProperties' => false,
					],
					'minItems' => 1,
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'updated',
				'id',
				'name',
				'columns',
			],
			'additionalProperties' => false,
		];
	}

	private static function datasetDelete(string $path): array
	{
		return self::operation(
			self::datasetDeleteRequest($path . '/requestBody/content/application~1json/schema'),
			self::datasetDeleteResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function datasetDeleteRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'datasetId' => [
					'type' => 'integer',
					'nullable' => false,
					'minimum' => 1,
				],
			],
			'required' => [
				'datasetId',
			],
			'additionalProperties' => false,
		];
	}

	private static function datasetDeleteResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'deleted' => [
					'type' => 'boolean',
					'enum' => [
						true,
					],
				],
				'id' => [
					'type' => 'integer',
				],
				'name' => [
					'type' => 'string',
				],
				'already_deleted' => [
					'type' => 'boolean',
					'enum' => [
						true,
					],
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'deleted',
				'id',
				'name',
			],
			'additionalProperties' => false,
		];
	}

	private static function tableList(string $path): array
	{
		return self::operation(
			self::tableListRequest($path . '/requestBody/content/application~1json/schema'),
			self::tableListResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function tableListRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'schema' => [
					'type' => 'string',
					'nullable' => false,
					'default' => '',
				],
				'limit' => [
					'type' => 'integer',
					'nullable' => false,
					'default' => 20,
					'minimum' => 1,
					'maximum' => 50,
				],
				'offset' => [
					'type' => 'integer',
					'nullable' => false,
					'default' => 0,
					'minimum' => 0,
				],
			],
			'required' => [],
			'additionalProperties' => false,
		];
	}

	private static function tableListResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'tables' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'name' => [
								'type' => 'string',
							],
							'type' => [
								'type' => 'string',
							],
							'schema' => [
								'type' => 'string',
							],
						],
						'required' => [
							'name',
						],
						'additionalProperties' => false,
					],
				],
				'database_id' => [
					'type' => 'integer',
				],
				'total' => [
					'type' => 'integer',
				],
				'limit' => [
					'type' => 'integer',
				],
				'offset' => [
					'type' => 'integer',
				],
				'has_more' => [
					'type' => 'boolean',
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'tables',
				'database_id',
				'total',
				'limit',
				'offset',
				'has_more',
			],
			'additionalProperties' => false,
		];
	}

	private static function tableSchemaGet(string $path): array
	{
		return self::operation(
			self::tableSchemaGetRequest($path . '/requestBody/content/application~1json/schema'),
			self::tableSchemaGetResult($path . '/responses/200/content/application~1json/schema/properties/result'),
		);
	}

	private static function tableSchemaGetRequest(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'tableName' => [
					'type' => 'string',
					'nullable' => false,
				],
				'schema' => [
					'type' => 'string',
					'nullable' => false,
					'default' => '',
				],
			],
			'required' => [
				'tableName',
			],
			'additionalProperties' => false,
		];
	}

	private static function tableSchemaGetResult(string $path): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'tableName' => [
					'type' => 'string',
				],
				'schema' => [
					'type' => 'string',
				],
				'columns' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'name' => [
								'type' => 'string',
							],
							'type' => [
								'type' => 'string',
							],
							'nullable' => [
								'type' => 'boolean',
							],
						],
						'required' => [
							'name',
							'type',
							'nullable',
						],
						'additionalProperties' => false,
					],
				],
				'signature' => [
					'type' => 'string',
				],
			],
			'required' => [
				'tableName',
				'schema',
				'columns',
			],
			'additionalProperties' => false,
		];
	}

	private static function operation(array $request, array $result): array
	{
		return [
			'tags' => ['biconnector'],
			'requestBody' => [
				'content' => [
					'application/json' => ['schema' => $request],
				],
			],
			'responses' => [
				200 => [
					'description' => 'Successful response',
					'content' => [
						'application/json' => [
							'schema' => [
								'type' => 'object',
								'properties' => [
									'result' => $result,
									'time' => self::responseTime(),
								],
								'required' => ['result'],
								'additionalProperties' => false,
							],
						],
					],
				],
			],
		];
	}

	private static function responseTime(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'start' => [
					'type' => 'number',
				],
				'finish' => [
					'type' => 'number',
				],
				'duration' => [
					'type' => 'number',
				],
				'processing' => [
					'type' => 'number',
				],
				'date_start' => [
					'type' => 'string',
				],
				'date_finish' => [
					'type' => 'string',
				],
				'operating_reset_at' => [
					'type' => 'integer',
				],
				'operating' => [
					'type' => 'number',
				],
			],
			'required' => [
				'start',
				'finish',
				'duration',
				'processing',
				'date_start',
				'date_finish',
			],
			'additionalProperties' => false,
		];
	}

	private static function jsonValue(string $path): array
	{
		return [
			'anyOf' => [
				[
					'type' => 'string',
					'nullable' => true,
				],
				[
					'type' => 'number',
				],
				[
					'type' => 'boolean',
				],
				[
					'type' => 'array',
					'items' => [
						'$ref' => $path,
					],
				],
				[
					'type' => 'object',
					'additionalProperties' => [
						'$ref' => $path,
					],
				],
			],
		];
	}

	private static function nonNullJsonValue(string $path): array
	{
		return [
			'anyOf' => [
				[
					'type' => 'string',
					'minLength' => 1,
				],
				[
					'type' => 'number',
					'not' => [
						'enum' => [
							0,
						],
					],
				],
				[
					'type' => 'boolean',
					'enum' => [
						true,
					],
				],
				[
					'type' => 'array',
					'items' => self::jsonValue($path . '/anyOf/3/items'),
					'minItems' => 1,
				],
				[
					'type' => 'object',
					'additionalProperties' => self::jsonValue($path . '/anyOf/4/additionalProperties'),
					'minProperties' => 1,
				],
			],
		];
	}

	private static function numericStats(string $path): array
	{
		return [
			'type' => 'object',
			'required' => [
				'min',
				'max',
				'avg',
				'sum',
				'count',
				'stddev',
			],
			'properties' => [
				'min' => [
					'type' => 'number',
				],
				'max' => [
					'type' => 'number',
				],
				'avg' => [
					'type' => 'number',
				],
				'sum' => [
					'type' => 'number',
				],
				'stddev' => [
					'type' => 'number',
				],
				'count' => [
					'type' => 'integer',
				],
				'n_distinct' => [
					'type' => 'integer',
				],
				'peak_dates' => [
					'type' => 'array',
					'items' => self::dashboardExtreme($path . '/properties/peak_dates/items'),
				],
				'trough_dates' => [
					'type' => 'array',
					'items' => self::dashboardExtreme($path . '/properties/trough_dates/items'),
				],
			],
			'additionalProperties' => false,
		];
	}

	private static function drillNumericStats(string $path): array
	{
		return [
			'type' => 'object',
			'required' => [
				'full',
			],
			'properties' => [
				'full' => self::numericStats($path . '/properties/full'),
				'slice' => self::numericStats($path . '/properties/slice'),
			],
			'additionalProperties' => false,
		];
	}

	private static function flatStats(string $path): array
	{
		return [
			'type' => 'object',
			'required' => [
				'row_count',
			],
			'properties' => [
				'row_count' => [
					'anyOf' => [
						[
							'type' => 'integer',
						],
						self::numericStats($path . '/properties/row_count/anyOf/1'),
					],
				],
				'stats_truncated_at' => [
					'anyOf' => [
						[
							'type' => 'integer',
						],
						self::numericStats($path . '/properties/stats_truncated_at/anyOf/1'),
					],
				],
			],
			'additionalProperties' => self::numericStats($path . '/additionalProperties'),
		];
	}

	private static function drillStats(string $path): array
	{
		return [
			'type' => 'object',
			'required' => [
				'row_count',
			],
			'properties' => [
				'row_count' => [
					'anyOf' => [
						[
							'type' => 'integer',
						],
						self::drillNumericStats($path . '/properties/row_count/anyOf/1'),
					],
				],
				'stats_truncated_at' => [
					'anyOf' => [
						[
							'type' => 'integer',
						],
						self::drillNumericStats($path . '/properties/stats_truncated_at/anyOf/1'),
					],
				],
			],
			'additionalProperties' => self::drillNumericStats($path . '/additionalProperties'),
		];
	}

	private static function successfulStats(string $path): array
	{
		return [
			'anyOf' => [
				self::flatStats($path . '/anyOf/0'),
				self::drillStats($path . '/anyOf/1'),
			],
		];
	}

	private static function dashboardExtreme(string $path): array
	{
		return [
			'type' => 'object',
			'required' => [
				'value',
				'count',
				'dates',
			],
			'properties' => [
				'value' => [
					'type' => 'number',
				],
				'count' => [
					'type' => 'integer',
				],
				'dates' => [
					'type' => 'array',
					'items' => self::jsonValue($path . '/properties/dates/items'),
				],
			],
			'additionalProperties' => false,
		];
	}

	private static function dataExtreme(string $path): array
	{
		return [
			'type' => 'object',
			'required' => [
				'value',
				'count',
			],
			'properties' => [
				'value' => [
					'type' => 'number',
				],
				'count' => [
					'type' => 'integer',
				],
				'dates' => [
					'type' => 'array',
					'items' => self::jsonValue($path . '/properties/dates/items'),
				],
			],
			'additionalProperties' => false,
		];
	}

	private static function dataNumericStats(string $path): array
	{
		return [
			'type' => 'object',
			'required' => [
				'min',
				'max',
				'avg',
				'sum',
				'count',
				'stddev',
			],
			'properties' => [
				'min' => [
					'type' => 'number',
				],
				'max' => [
					'type' => 'number',
				],
				'avg' => [
					'type' => 'number',
				],
				'sum' => [
					'type' => 'number',
				],
				'stddev' => [
					'type' => 'number',
				],
				'count' => [
					'type' => 'integer',
				],
				'n_distinct' => [
					'type' => 'integer',
				],
				'peak_dates' => [
					'type' => 'array',
					'items' => self::dataExtreme($path . '/properties/peak_dates/items'),
				],
				'trough_dates' => [
					'type' => 'array',
					'items' => self::dataExtreme($path . '/properties/trough_dates/items'),
				],
			],
			'additionalProperties' => false,
		];
	}

	private static function dataDrillNumericStats(string $path): array
	{
		return [
			'type' => 'object',
			'required' => [
				'full',
			],
			'properties' => [
				'full' => self::dataNumericStats($path . '/properties/full'),
				'slice' => self::dataNumericStats($path . '/properties/slice'),
			],
			'additionalProperties' => false,
		];
	}

	private static function dataFlatStats(string $path): array
	{
		return [
			'type' => 'object',
			'required' => [
				'row_count',
			],
			'properties' => [
				'row_count' => [
					'anyOf' => [
						[
							'type' => 'integer',
						],
						self::dataNumericStats($path . '/properties/row_count/anyOf/1'),
					],
				],
				'stats_truncated_at' => [
					'anyOf' => [
						[
							'type' => 'integer',
						],
						self::dataNumericStats($path . '/properties/stats_truncated_at/anyOf/1'),
					],
				],
			],
			'additionalProperties' => self::dataNumericStats($path . '/additionalProperties'),
		];
	}

	private static function dataDrillStats(string $path): array
	{
		return [
			'type' => 'object',
			'required' => [
				'row_count',
			],
			'properties' => [
				'row_count' => [
					'anyOf' => [
						[
							'type' => 'integer',
						],
						self::dataDrillNumericStats($path . '/properties/row_count/anyOf/1'),
					],
				],
				'stats_truncated_at' => [
					'anyOf' => [
						[
							'type' => 'integer',
						],
						self::dataDrillNumericStats($path . '/properties/stats_truncated_at/anyOf/1'),
					],
				],
			],
			'additionalProperties' => self::dataDrillNumericStats($path . '/additionalProperties'),
		];
	}

	private static function dataSuccessfulStats(string $path): array
	{
		return [
			'anyOf' => [
				self::dataFlatStats($path . '/anyOf/0'),
				self::dataDrillStats($path . '/anyOf/1'),
			],
		];
	}

	private static function dashboardCharts(string $path): array
	{
		return [
			'type' => 'array',
			'items' => [
				'type' => 'object',
				'properties' => [
					'id' => [
						'type' => 'integer',
					],
					'name' => [
						'type' => 'string',
						'nullable' => true,
					],
					'kind' => [
						'type' => 'string',
						'enum' => [
							'scalar',
							'gauge',
							'timeseries',
							'categorical',
							'ranked_list',
							'matrix',
							'geo',
							'other',
						],
					],
					'viz_label' => [
						'type' => 'string',
						'nullable' => true,
					],
					'tab_path' => [
						'type' => 'string',
						'nullable' => true,
					],
					'description' => [
						'type' => 'string',
						'minLength' => 1,
					],
					'stats' => [
						'anyOf' => [
							[
								'type' => 'string',
								'nullable' => true,
								'enum' => [
									null,
								],
							],
							self::successfulStats($path . '/items/properties/stats/anyOf/1'),
						],
					],
					'stats_omitted' => [
						'type' => 'boolean',
						'enum' => [
							true,
						],
					],
					'column_descriptions' => [
						'anyOf' => [
							[
								'type' => 'object',
								'additionalProperties' => [
									'type' => 'object',
									'required' => [
										'type',
									],
									'properties' => [
										'type' => [
											'type' => 'string',
											'enum' => [
												'number',
												'date',
												'bool',
												'string',
												'unknown',
												'metric',
											],
										],
										'verbose_name' => [
											'type' => 'string',
										],
										'description' => [
											'type' => 'string',
										],
										'is_percent' => [
											'type' => 'boolean',
											'enum' => [
												true,
											],
										],
									],
									'additionalProperties' => false,
								],
								'minProperties' => 1,
							],
							[
								'type' => 'array',
								'items' => [
									'type' => 'object',
									'required' => [
										'type',
									],
									'properties' => [
										'type' => [
											'type' => 'string',
											'enum' => [
												'number',
												'date',
												'bool',
												'string',
												'unknown',
												'metric',
											],
										],
										'verbose_name' => [
											'type' => 'string',
										],
										'description' => [
											'type' => 'string',
										],
										'is_percent' => [
											'type' => 'boolean',
											'enum' => [
												true,
											],
										],
									],
									'additionalProperties' => false,
								],
								'minItems' => 1,
							],
						],
					],
				],
				'required' => [
					'id',
					'name',
					'kind',
					'viz_label',
					'tab_path',
				],
				'additionalProperties' => false,
			],
		];
	}

	private static function dashboardAvailableFilters(string $path): array
	{
		return [
			'type' => 'array',
			'items' => [
				'type' => 'object',
				'properties' => [
					'name' => self::jsonValue($path . '/items/properties/name'),
					'kind' => [
						'type' => 'string',
						'enum' => [
							'date_range',
							'values_list',
							'grain',
							'other',
						],
					],
					'column' => self::nonNullJsonValue($path . '/items/properties/column'),
					'default' => [
						'anyOf' => [
							[
								'type' => 'string',
							],
							[
								'type' => 'array',
								'items' => self::jsonValue($path . '/items/properties/default/anyOf/1/items'),
							],
						],
					],
					'passthrough' => [
						'type' => 'string',
						'enum' => [
							'time_grain_sqla',
							'time_range',
						],
					],
					'allowed_values' => [
						'type' => 'array',
						'minItems' => 14,
						'maxItems' => 14,
						'enum' => [
							[
								[
									'value' => 'PT1S',
									'label' => 'Second',
								],
								[
									'value' => 'PT1M',
									'label' => 'Minute',
								],
								[
									'value' => 'PT5M',
									'label' => '5 minutes',
								],
								[
									'value' => 'PT10M',
									'label' => '10 minutes',
								],
								[
									'value' => 'PT15M',
									'label' => '15 minutes',
								],
								[
									'value' => 'PT30M',
									'label' => '30 minutes',
								],
								[
									'value' => 'PT1H',
									'label' => 'Hour',
								],
								[
									'value' => 'PT6H',
									'label' => '6 hours',
								],
								[
									'value' => 'P1D',
									'label' => 'Day',
								],
								[
									'value' => 'P1W',
									'label' => 'Week',
								],
								[
									'value' => 'P1M',
									'label' => 'Month',
								],
								[
									'value' => 'P3M',
									'label' => 'Quarter',
								],
								[
									'value' => 'P6M',
									'label' => 'Half year',
								],
								[
									'value' => 'P1Y',
									'label' => 'Year',
								],
							],
						],
						'items' => [
							'type' => 'object',
							'properties' => [
								'value' => [
									'type' => 'string',
								],
								'label' => [
									'type' => 'string',
								],
							],
							'required' => [
								'value',
								'label',
							],
							'additionalProperties' => false,
						],
					],
				],
				'required' => [
					'name',
					'kind',
				],
				'additionalProperties' => false,
			],
		];
	}

	private static function chartDataCharts(string $path): array
	{
		return [
			'type' => 'array',
			'items' => [
				'type' => 'object',
				'properties' => [
					'id' => [
						'type' => 'integer',
					],
					'name' => [
						'type' => 'string',
						'nullable' => true,
					],
					'kind' => [
						'type' => 'string',
						'enum' => [
							'scalar',
							'gauge',
							'timeseries',
							'categorical',
							'ranked_list',
							'matrix',
							'geo',
							'other',
						],
					],
					'viz_label' => [
						'type' => 'string',
						'nullable' => true,
					],
					'tab_path' => [
						'type' => 'string',
						'nullable' => true,
					],
					'description' => [
						'type' => 'string',
						'minLength' => 1,
					],
					'column_descriptions' => [
						'anyOf' => [
							[
								'type' => 'object',
								'additionalProperties' => [
									'anyOf' => [
										[
											'type' => 'object',
											'required' => [
												'type',
												'description',
											],
											'properties' => [
												'type' => [
													'type' => 'string',
													'enum' => [
														'date',
														'integer',
														'float',
														'string',
														'boolean',
													],
												],
												'description' => [
													'type' => 'string',
												],
												'is_percent' => [
													'type' => 'boolean',
													'enum' => [
														true,
													],
												],
											],
											'additionalProperties' => false,
										],
										[
											'type' => 'object',
											'required' => [
												'type',
											],
											'properties' => [
												'type' => [
													'type' => 'string',
													'enum' => [
														'number',
														'date',
														'bool',
														'string',
														'unknown',
														'metric',
													],
												],
												'verbose_name' => [
													'type' => 'string',
												],
												'description' => [
													'type' => 'string',
												],
												'is_percent' => [
													'type' => 'boolean',
													'enum' => [
														true,
													],
												],
											],
											'additionalProperties' => false,
										],
									],
								],
								'minProperties' => 1,
							],
							[
								'type' => 'array',
								'items' => [
									'anyOf' => [
										[
											'type' => 'object',
											'required' => [
												'type',
												'description',
											],
											'properties' => [
												'type' => [
													'type' => 'string',
													'enum' => [
														'date',
														'integer',
														'float',
														'string',
														'boolean',
													],
												],
												'description' => [
													'type' => 'string',
												],
												'is_percent' => [
													'type' => 'boolean',
													'enum' => [
														true,
													],
												],
											],
											'additionalProperties' => false,
										],
										[
											'type' => 'object',
											'required' => [
												'type',
											],
											'properties' => [
												'type' => [
													'type' => 'string',
													'enum' => [
														'number',
														'date',
														'bool',
														'string',
														'unknown',
														'metric',
													],
												],
												'verbose_name' => [
													'type' => 'string',
												],
												'description' => [
													'type' => 'string',
												],
												'is_percent' => [
													'type' => 'boolean',
													'enum' => [
														true,
													],
												],
											],
											'additionalProperties' => false,
										],
									],
								],
								'minItems' => 1,
							],
						],
					],
					'stats' => self::dataSuccessfulStats($path . '/items/properties/stats'),
					'slice_stable' => [
						'type' => 'boolean',
					],
					'columns' => [
						'type' => 'array',
						'uniqueItems' => true,
						'items' => [
							'anyOf' => [
								[
									'type' => 'string',
								],
								[
									'type' => 'integer',
								],
							],
						],
					],
					'data' => [
						'anyOf' => [
							[
								'type' => 'string',
								'minLength' => 1,
							],
							[
								'type' => 'array',
								'items' => self::jsonValue($path . '/items/properties/data/anyOf/1/items'),
								'minItems' => 1,
							],
						],
					],
					'offset' => [
						'type' => 'integer',
						'minimum' => 1,
					],
					'row_limit' => [
						'type' => 'integer',
						'minimum' => 1,
						'maximum' => 300,
					],
					'total_rows' => [
						'type' => 'integer',
						'minimum' => 0,
					],
					'has_more' => [
						'type' => 'boolean',
					],
				],
				'required' => [
					'id',
					'kind',
					'stats',
				],
				'additionalProperties' => false,
			],
			'minItems' => 1,
		];
	}
}
