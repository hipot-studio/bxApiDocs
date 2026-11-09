<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Argument;

final class BuilderArgumentContract
{
	private const METHODS = [
		'biconnector.builder.dashboard.list' => [
			'type' => 'object',
			'additionalProperties' => false,
			'required' => [],
			'x-field-id' => 'FIELD-001',
			'properties' => [
				'query' => [
					'type' => 'string',
					'default' => '',
					'x-field-id' => 'FIELD-002',
				],
				'offset' => [
					'type' => 'integer',
					'minimum' => 0,
					'default' => 0,
					'x-field-id' => 'FIELD-003',
				],
			],
		],
		'biconnector.builder.dashboard.get' => [
			'type' => 'object',
			'required' => [
				'dashboardId',
			],
			'additionalProperties' => false,
			'x-field-id' => 'FIELD-004',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'minimum' => 1,
					'x-field-id' => 'FIELD-005',
				],
				'appliedFilters' => [
					'type' => 'array',
					'default' => [],
					'items' => [
						'type' => 'object',
						'required' => [
							'name',
							'value',
						],
						'additionalProperties' => false,
						'x-field-id' => 'FIELD-007',
						'properties' => [
							'name' => [
								'type' => 'string',
								'x-field-id' => 'FIELD-008',
							],
							'value' => [
								'anyOf' => [
									[
										'type' => 'object',
										'additionalProperties' => [
											'$ref' => '#/$defs/JsonValue',
										],
									],
									[
										'type' => 'array',
										'items' => [
											'$ref' => '#/$defs/JsonValue',
										],
									],
								],
								'x-domain-rule' => 'period: existing date validation; other names: existing dashboard-filter validation',
								'x-field-id' => 'FIELD-009',
							],
						],
					],
					'x-field-id' => 'FIELD-006',
				],
				'urlParams' => [
					'type' => 'object',
					'additionalProperties' => [
						'$ref' => '#/$defs/JsonValue',
					],
					'default' => [],
					'x-field-id' => 'FIELD-010',
				],
			],
		],
		'biconnector.builder.dashboard.add' => [
			'type' => 'object',
			'required' => [
				'name',
			],
			'additionalProperties' => false,
			'x-field-id' => 'FIELD-011',
			'properties' => [
				'name' => [
					'type' => 'string',
					'minLength' => 1,
					'maxLength' => 128,
					'x-normalization' => 'trim; refuse blank; maxLength applies before trim',
					'x-field-id' => 'FIELD-012',
				],
			],
		],
		'biconnector.builder.dashboard.update' => [
			'type' => 'object',
			'required' => [
				'dashboardId',
				'name',
			],
			'additionalProperties' => false,
			'x-field-id' => 'FIELD-013',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'minimum' => 1,
					'x-field-id' => 'FIELD-014',
				],
				'name' => [
					'type' => 'string',
					'minLength' => 1,
					'maxLength' => 128,
					'x-normalization' => 'trim; refuse blank; maxLength applies before trim',
					'x-field-id' => 'FIELD-015',
				],
			],
		],
		'biconnector.builder.dashboard.content.set' => [
			'type' => 'object',
			'required' => [
				'dashboardId',
			],
			'additionalProperties' => false,
			'x-domain-rule' => 'at least one of charts/filters present; if filters is present, it must cont'
				. 'ain at least one time_range with nonblank defaultValue; charts-only require'
				. 's an existing time_range in the dashboard',
			'x-field-id' => 'FIELD-016',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'minimum' => 1,
					'x-field-id' => 'FIELD-017',
				],
				'charts' => [
					'type' => 'array',
					'maxItems' => 50,
					'items' => [
						'type' => 'object',
						'required' => [
							'chartId',
						],
						'additionalProperties' => false,
						'x-field-id' => 'FIELD-019',
						'properties' => [
							'chartId' => [
								'type' => 'integer',
								'minimum' => 1,
								'x-field-id' => 'FIELD-020',
							],
							'width' => [
								'type' => 'integer',
								'minimum' => 1,
								'maximum' => 12,
								'default' => 6,
								'x-field-id' => 'FIELD-021',
							],
							'height' => [
								'type' => 'integer',
								'minimum' => 10,
								'maximum' => 200,
								'default' => 50,
								'x-field-id' => 'FIELD-022',
							],
							'sliceName' => [
								'type' => 'string',
								'default' => '',
								'x-field-id' => 'FIELD-023',
							],
						],
					],
					'x-field-id' => 'FIELD-018',
				],
				'filters' => [
					'type' => 'array',
					'maxItems' => 20,
					'items' => [
						'type' => 'object',
						'required' => [
							'name',
							'filterType',
						],
						'additionalProperties' => false,
						'x-field-id' => 'FIELD-025',
						'properties' => [
							'name' => [
								'type' => 'string',
								'minLength' => 1,
								'x-field-id' => 'FIELD-026',
							],
							'filterType' => [
								'type' => 'string',
								'enum' => [
									'time_range',
									'value',
									'range',
								],
								'x-field-id' => 'FIELD-027',
							],
							'datasetId' => [
								'type' => 'integer',
								'minimum' => 1,
								'x-field-id' => 'FIELD-028',
							],
							'column' => [
								'type' => 'string',
								'x-domain-rule' => 'nonblank after trim for value/range; time_range does not use a target column',
								'x-field-id' => 'FIELD-029',
							],
							'chartIds' => [
								'type' => 'array',
								'x-default-rule' => 'all charts of the resulting layout when absent; [] means no charts',
								'items' => [
									'type' => 'integer',
									'minimum' => 1,
									'x-field-id' => 'FIELD-031',
								],
								'x-field-id' => 'FIELD-030',
							],
							'defaultValue' => [
								'type' => 'string',
								'x-domain-rule' => 'string optional per item; at least one time_range must have nonblank defaul'
									. 'tValue in supplied filters; ignored for value/range',
								'x-field-id' => 'FIELD-032',
							],
						],
					],
					'x-field-id' => 'FIELD-024',
				],
			],
		],
		'biconnector.builder.dashboard.delete' => [
			'type' => 'object',
			'required' => [
				'dashboardId',
			],
			'additionalProperties' => false,
			'x-field-id' => 'FIELD-033',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'minimum' => 1,
					'x-field-id' => 'FIELD-034',
				],
			],
		],
		'biconnector.builder.dashboard.copy' => [
			'type' => 'object',
			'required' => [
				'dashboardId',
			],
			'additionalProperties' => false,
			'x-field-id' => 'FIELD-035',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'minimum' => 1,
					'x-field-id' => 'FIELD-036',
				],
			],
		],
		'biconnector.builder.dashboard.filter.values' => [
			'type' => 'object',
			'required' => [
				'dashboardId',
				'filterColumn',
			],
			'additionalProperties' => false,
			'x-field-id' => 'FIELD-037',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'minimum' => 1,
					'x-field-id' => 'FIELD-038',
				],
				'filterColumn' => [
					'type' => 'string',
					'x-field-id' => 'FIELD-039',
				],
				'search' => [
					'type' => 'string',
					'maxLength' => 200,
					'default' => '',
					'x-field-id' => 'FIELD-040',
				],
				'appliedFilters' => [
					'type' => 'array',
					'default' => [],
					'items' => [
						'type' => 'object',
						'required' => [
							'name',
							'value',
						],
						'additionalProperties' => false,
						'x-field-id' => 'FIELD-042',
						'properties' => [
							'name' => [
								'type' => 'string',
								'x-field-id' => 'FIELD-043',
							],
							'value' => [
								'anyOf' => [
									[
										'type' => 'object',
										'additionalProperties' => [
											'$ref' => '#/$defs/JsonValue',
										],
									],
									[
										'type' => 'array',
										'items' => [
											'$ref' => '#/$defs/JsonValue',
										],
									],
								],
								'x-domain-rule' => 'period: existing date validation; other names: existing dashboard-filter validation',
								'x-field-id' => 'FIELD-044',
							],
						],
					],
					'x-field-id' => 'FIELD-041',
				],
				'urlParams' => [
					'type' => 'object',
					'additionalProperties' => [
						'$ref' => '#/$defs/JsonValue',
					],
					'default' => [],
					'x-field-id' => 'FIELD-045',
				],
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 20,
					'x-field-id' => 'FIELD-046',
				],
			],
		],
		'biconnector.builder.dashboard.parameters.search' => [
			'type' => 'object',
			'required' => [
				'dashboardId',
			],
			'additionalProperties' => false,
			'x-field-id' => 'FIELD-047',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'minimum' => 1,
					'x-field-id' => 'FIELD-048',
				],
				'search' => [
					'type' => 'string',
					'maxLength' => 200,
					'default' => '',
					'x-field-id' => 'FIELD-049',
				],
				'offset' => [
					'type' => 'integer',
					'default' => 0,
					'x-field-id' => 'FIELD-050',
				],
			],
		],
		'biconnector.builder.chart.list' => [
			'type' => 'object',
			'additionalProperties' => false,
			'required' => [],
			'x-field-id' => 'FIELD-051',
			'properties' => [
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 20,
					'x-field-id' => 'FIELD-052',
				],
				'offset' => [
					'type' => 'integer',
					'minimum' => 0,
					'default' => 0,
					'x-field-id' => 'FIELD-053',
				],
			],
		],
		'biconnector.builder.chart.get' => [
			'type' => 'object',
			'required' => [
				'chartId',
			],
			'additionalProperties' => false,
			'x-field-id' => 'FIELD-054',
			'properties' => [
				'chartId' => [
					'type' => 'integer',
					'minimum' => 1,
					'x-field-id' => 'FIELD-055',
				],
			],
		],
		'biconnector.builder.chart.add' => [
			'type' => 'object',
			'required' => [
				'name',
				'datasetId',
				'vizType',
				'temporalColumn',
			],
			'additionalProperties' => false,
			'x-domain-rule' => 'retain viz-dependent metrics/dimensions/xAxis requirements of ChartParamsBuilder',
			'x-field-id' => 'FIELD-056',
			'properties' => [
				'name' => [
					'type' => 'string',
					'minLength' => 1,
					'x-field-id' => 'FIELD-057',
				],
				'datasetId' => [
					'type' => 'integer',
					'minimum' => 1,
					'x-field-id' => 'FIELD-058',
				],
				'vizType' => [
					'type' => 'string',
					'enum' => [
						'bar',
						'line',
						'scatter',
						'pie',
						'table',
						'big_number',
					],
					'x-field-id' => 'FIELD-059',
				],
				'temporalColumn' => [
					'type' => 'string',
					'x-domain-rule' => 'nonblank after trim; actual date/datetime dataset column',
					'x-field-id' => 'FIELD-060',
				],
				'metrics' => [
					'type' => 'array',
					'default' => [],
					'items' => [
						'type' => 'object',
						'required' => [
							'column',
							'aggregate',
						],
						'additionalProperties' => false,
						'x-field-id' => 'FIELD-062',
						'properties' => [
							'column' => [
								'type' => 'string',
								'minLength' => 1,
								'x-field-id' => 'FIELD-063',
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
								'x-field-id' => 'FIELD-064',
							],
							'label' => [
								'type' => 'string',
								'default' => '',
								'x-field-id' => 'FIELD-065',
							],
						],
					],
					'x-field-id' => 'FIELD-061',
				],
				'dimensions' => [
					'type' => 'array',
					'default' => [],
					'items' => [
						'type' => 'string',
						'x-field-id' => 'FIELD-067',
					],
					'x-field-id' => 'FIELD-066',
				],
				'xAxis' => [
					'type' => 'string',
					'default' => '',
					'x-field-id' => 'FIELD-068',
				],
				'filters' => [
					'type' => 'array',
					'default' => [],
					'items' => [
						'type' => 'object',
						'required' => [
							'column',
						],
						'additionalProperties' => false,
						'x-field-id' => 'FIELD-070',
						'properties' => [
							'column' => [
								'type' => 'string',
								'minLength' => 1,
								'x-field-id' => 'FIELD-071',
							],
							'operator' => [
								'type' => 'string',
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
								'default' => '=',
								'x-field-id' => 'FIELD-072',
							],
							'value' => [
								'$ref' => '#/$defs/JsonValue',
								'default' => null,
								'x-domain-rule' => 'between: nonblank string or two-scalar nonblank list; otherwise comparator '
									. 'JSON passes unchanged',
								'x-field-id' => 'FIELD-073',
							],
						],
					],
					'x-field-id' => 'FIELD-069',
				],
				'description' => [
					'type' => 'string',
					'default' => '',
					'x-field-id' => 'FIELD-074',
				],
				'rowLimit' => [
					'type' => 'integer',
					'x-default-by-vizType' => [
						'pie' => 100,
						'table' => 1000,
						'big_number' => 10000,
						'bar' => 10000,
						'line' => 10000,
						'scatter' => 10000,
					],
					'x-normalization' => 'max(1,min(50000,value)); no input minimum/maximum; output range 1..50000',
					'x-field-id' => 'FIELD-075',
				],
			],
		],
		'biconnector.builder.chart.update' => [
			'type' => 'object',
			'required' => [
				'chartId',
			],
			'additionalProperties' => false,
			'x-domain-rule' => 'at least one of name/description present; empty description clears; name nonblank after trim',
			'x-field-id' => 'FIELD-076',
			'properties' => [
				'chartId' => [
					'type' => 'integer',
					'minimum' => 1,
					'x-field-id' => 'FIELD-077',
				],
				'name' => [
					'type' => 'string',
					'minLength' => 1,
					'x-field-id' => 'FIELD-078',
				],
				'description' => [
					'type' => 'string',
					'x-field-id' => 'FIELD-079',
				],
			],
		],
		'biconnector.builder.chart.config.set' => [
			'type' => 'object',
			'required' => [
				'chartId',
				'datasetId',
				'vizType',
				'temporalColumn',
			],
			'additionalProperties' => false,
			'x-domain-rule' => 'retain viz-dependent metrics/dimensions/xAxis requirements of ChartParamsBuilder',
			'x-field-id' => 'FIELD-080',
			'properties' => [
				'chartId' => [
					'type' => 'integer',
					'minimum' => 1,
					'x-field-id' => 'FIELD-081',
				],
				'datasetId' => [
					'type' => 'integer',
					'minimum' => 1,
					'x-field-id' => 'FIELD-082',
				],
				'vizType' => [
					'type' => 'string',
					'enum' => [
						'bar',
						'line',
						'scatter',
						'pie',
						'table',
						'big_number',
					],
					'x-field-id' => 'FIELD-083',
				],
				'temporalColumn' => [
					'type' => 'string',
					'x-domain-rule' => 'nonblank after trim; actual date/datetime dataset column',
					'x-field-id' => 'FIELD-084',
				],
				'metrics' => [
					'type' => 'array',
					'default' => [],
					'items' => [
						'type' => 'object',
						'required' => [
							'column',
							'aggregate',
						],
						'additionalProperties' => false,
						'x-field-id' => 'FIELD-086',
						'properties' => [
							'column' => [
								'type' => 'string',
								'minLength' => 1,
								'x-field-id' => 'FIELD-087',
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
								'x-field-id' => 'FIELD-088',
							],
							'label' => [
								'type' => 'string',
								'default' => '',
								'x-field-id' => 'FIELD-089',
							],
						],
					],
					'x-field-id' => 'FIELD-085',
				],
				'dimensions' => [
					'type' => 'array',
					'default' => [],
					'items' => [
						'type' => 'string',
						'x-field-id' => 'FIELD-091',
					],
					'x-field-id' => 'FIELD-090',
				],
				'xAxis' => [
					'type' => 'string',
					'default' => '',
					'x-field-id' => 'FIELD-092',
				],
				'filters' => [
					'type' => 'array',
					'default' => [],
					'items' => [
						'type' => 'object',
						'required' => [
							'column',
						],
						'additionalProperties' => false,
						'x-field-id' => 'FIELD-094',
						'properties' => [
							'column' => [
								'type' => 'string',
								'minLength' => 1,
								'x-field-id' => 'FIELD-095',
							],
							'operator' => [
								'type' => 'string',
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
								'default' => '=',
								'x-field-id' => 'FIELD-096',
							],
							'value' => [
								'$ref' => '#/$defs/JsonValue',
								'default' => null,
								'x-domain-rule' => 'between: nonblank string or two-scalar nonblank list; otherwise comparator '
									. 'JSON passes unchanged',
								'x-field-id' => 'FIELD-097',
							],
						],
					],
					'x-field-id' => 'FIELD-093',
				],
				'rowLimit' => [
					'type' => 'integer',
					'x-default-by-vizType' => [
						'pie' => 100,
						'table' => 1000,
						'big_number' => 10000,
						'bar' => 10000,
						'line' => 10000,
						'scatter' => 10000,
					],
					'x-normalization' => 'max(1,min(50000,value)); no input minimum/maximum; output range 1..50000',
					'x-field-id' => 'FIELD-098',
				],
			],
		],
		'biconnector.builder.chart.delete' => [
			'type' => 'object',
			'required' => [
				'chartId',
			],
			'additionalProperties' => false,
			'x-field-id' => 'FIELD-099',
			'properties' => [
				'chartId' => [
					'type' => 'integer',
					'minimum' => 1,
					'x-field-id' => 'FIELD-100',
				],
			],
		],
		'biconnector.builder.chart.data.get' => [
			'type' => 'object',
			'required' => [
				'dashboardId',
				'chartIds',
			],
			'additionalProperties' => false,
			'x-field-id' => 'FIELD-101',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'minimum' => 1,
					'x-field-id' => 'FIELD-102',
				],
				'chartIds' => [
					'type' => 'array',
					'minItems' => 1,
					'x-max-unique-items' => 5,
					'x-normalization' => 'deduplicate before cardinality check; no raw maxItems',
					'items' => [
						'type' => 'integer',
						'minimum' => 1,
						'x-field-id' => 'FIELD-104',
					],
					'x-field-id' => 'FIELD-103',
				],
				'appliedFilters' => [
					'type' => 'array',
					'default' => [],
					'items' => [
						'type' => 'object',
						'required' => [
							'name',
							'value',
						],
						'additionalProperties' => false,
						'x-field-id' => 'FIELD-106',
						'properties' => [
							'name' => [
								'type' => 'string',
								'x-field-id' => 'FIELD-107',
							],
							'value' => [
								'anyOf' => [
									[
										'type' => 'object',
										'additionalProperties' => [
											'$ref' => '#/$defs/JsonValue',
										],
									],
									[
										'type' => 'array',
										'items' => [
											'$ref' => '#/$defs/JsonValue',
										],
									],
								],
								'x-domain-rule' => 'period: existing date validation; other names: existing dashboard-filter validation',
								'x-field-id' => 'FIELD-108',
							],
						],
					],
					'x-field-id' => 'FIELD-105',
				],
				'urlParams' => [
					'type' => 'object',
					'additionalProperties' => [
						'$ref' => '#/$defs/JsonValue',
					],
					'default' => [],
					'x-field-id' => 'FIELD-109',
				],
				'rowLimit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 300,
					'default' => 20,
					'x-field-id' => 'FIELD-110',
				],
				'offset' => [
					'type' => 'integer',
					'minimum' => 0,
					'default' => 0,
					'x-field-id' => 'FIELD-111',
				],
				'sortBy' => [
					'type' => 'string',
					'default' => '',
					'maxLength' => 255,
					'x-field-id' => 'FIELD-112',
				],
				'sortOrder' => [
					'type' => 'string',
					'enum' => [
						'asc',
						'desc',
					],
					'default' => 'desc',
					'x-field-id' => 'FIELD-113',
				],
			],
		],
		'biconnector.builder.dataset.list' => [
			'type' => 'object',
			'additionalProperties' => false,
			'required' => [],
			'x-field-id' => 'FIELD-114',
			'properties' => [
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 20,
					'x-field-id' => 'FIELD-115',
				],
				'offset' => [
					'type' => 'integer',
					'minimum' => 0,
					'default' => 0,
					'x-field-id' => 'FIELD-116',
				],
			],
		],
		'biconnector.builder.dataset.get' => [
			'type' => 'object',
			'required' => [
				'datasetId',
			],
			'additionalProperties' => false,
			'x-field-id' => 'FIELD-117',
			'properties' => [
				'datasetId' => [
					'type' => 'integer',
					'minimum' => 1,
					'x-field-id' => 'FIELD-118',
				],
			],
		],
		'biconnector.builder.dataset.add' => [
			'type' => 'object',
			'required' => [
				'datasetName',
				'sql',
			],
			'additionalProperties' => false,
			'x-field-id' => 'FIELD-119',
			'properties' => [
				'datasetName' => [
					'type' => 'string',
					'minLength' => 1,
					'x-field-id' => 'FIELD-120',
				],
				'sql' => [
					'type' => 'string',
					'minLength' => 1,
					'x-field-id' => 'FIELD-121',
				],
			],
		],
		'biconnector.builder.dataset.addfromtable' => [
			'type' => 'object',
			'required' => [
				'datasetName',
				'sourceTable',
				'columns',
			],
			'additionalProperties' => false,
			'x-field-id' => 'FIELD-122',
			'properties' => [
				'datasetName' => [
					'type' => 'string',
					'minLength' => 1,
					'x-field-id' => 'FIELD-123',
				],
				'sourceTable' => [
					'type' => 'string',
					'minLength' => 1,
					'x-field-id' => 'FIELD-124',
				],
				'columns' => [
					'type' => 'array',
					'minItems' => 1,
					'x-normalization' => 'trim each string; drop blank strings; require at least one remaining column'
						. '; preserve order and duplicates',
					'items' => [
						'type' => 'string',
						'x-field-id' => 'FIELD-126',
					],
					'x-field-id' => 'FIELD-125',
				],
				'sourceSchema' => [
					'type' => 'string',
					'default' => '',
					'x-field-id' => 'FIELD-127',
				],
			],
		],
		'biconnector.builder.dataset.update' => [
			'type' => 'object',
			'required' => [
				'datasetId',
				'columns',
			],
			'additionalProperties' => false,
			'x-field-id' => 'FIELD-128',
			'properties' => [
				'datasetId' => [
					'type' => 'integer',
					'minimum' => 1,
					'x-field-id' => 'FIELD-129',
				],
				'columns' => [
					'type' => 'array',
					'minItems' => 1,
					'items' => [
						'type' => 'object',
						'required' => [
							'name',
							'type',
						],
						'additionalProperties' => false,
						'x-field-id' => 'FIELD-131',
						'properties' => [
							'name' => [
								'type' => 'string',
								'minLength' => 1,
								'x-normalization' => 'trim; refuse blank; no identifier regex',
								'x-field-id' => 'FIELD-132',
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
								'x-field-id' => 'FIELD-133',
							],
						],
					],
					'x-field-id' => 'FIELD-130',
				],
			],
		],
		'biconnector.builder.dataset.delete' => [
			'type' => 'object',
			'required' => [
				'datasetId',
			],
			'additionalProperties' => false,
			'x-field-id' => 'FIELD-134',
			'properties' => [
				'datasetId' => [
					'type' => 'integer',
					'minimum' => 1,
					'x-field-id' => 'FIELD-135',
				],
			],
		],
		'biconnector.builder.table.list' => [
			'type' => 'object',
			'additionalProperties' => false,
			'required' => [],
			'x-field-id' => 'FIELD-136',
			'properties' => [
				'schema' => [
					'type' => 'string',
					'default' => '',
					'x-field-id' => 'FIELD-137',
				],
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 20,
					'x-field-id' => 'FIELD-138',
				],
				'offset' => [
					'type' => 'integer',
					'minimum' => 0,
					'default' => 0,
					'x-field-id' => 'FIELD-139',
				],
			],
		],
		'biconnector.builder.table.schema.get' => [
			'type' => 'object',
			'required' => [
				'tableName',
			],
			'additionalProperties' => false,
			'x-field-id' => 'FIELD-140',
			'properties' => [
				'tableName' => [
					'type' => 'string',
					'x-field-id' => 'FIELD-141',
				],
				'schema' => [
					'type' => 'string',
					'default' => '',
					'x-field-id' => 'FIELD-142',
				],
			],
		],
	];

	private const TOOLS = [
		'list_dashboards' => 'biconnector.builder.dashboard.list',
		'get_dashboard_meta' => 'biconnector.builder.dashboard.get',
		'create_dashboard' => 'biconnector.builder.dashboard.add',
		'update_dashboard_metadata' => 'biconnector.builder.dashboard.update',
		'set_dashboard_content' => 'biconnector.builder.dashboard.content.set',
		'delete_dashboard' => 'biconnector.builder.dashboard.delete',
		'get_filter_values' => 'biconnector.builder.dashboard.filter.values',
		'search_dashboard_parameters' => 'biconnector.builder.dashboard.parameters.search',
		'list_charts' => 'biconnector.builder.chart.list',
		'get_chart' => 'biconnector.builder.chart.get',
		'create_chart' => 'biconnector.builder.chart.add',
		'update_chart_metadata' => 'biconnector.builder.chart.update',
		'replace_chart_config' => 'biconnector.builder.chart.config.set',
		'delete_chart' => 'biconnector.builder.chart.delete',
		'get_chart_data' => 'biconnector.builder.chart.data.get',
		'list_datasets' => 'biconnector.builder.dataset.list',
		'get_dataset' => 'biconnector.builder.dataset.get',
		'create_dataset_from_sql' => 'biconnector.builder.dataset.add',
		'create_virtual_dataset' => 'biconnector.builder.dataset.addfromtable',
		'update_dataset' => 'biconnector.builder.dataset.update',
		'delete_dataset' => 'biconnector.builder.dataset.delete',
		'list_bi_tables' => 'biconnector.builder.table.list',
		'get_bi_table_schema' => 'biconnector.builder.table.schema.get',
	];

	public static function forMethod(string $uri): array
	{
		return self::METHODS[$uri] ?? [];
	}

	public static function methodForTool(string $tool): ?string
	{
		return self::TOOLS[$tool] ?? null;
	}

	public static function schema(string $uri, string $channel, array $descriptions = [], string $path = ''): array
	{
		$schema = self::forMethod($uri);
		if ($schema === [] || !in_array($channel, ['rest', 'mcp'], true))
		{
			return [];
		}
		if ($channel === 'mcp')
		{
			if (!in_array($uri, self::TOOLS, true))
			{
				return [];
			}
			unset($schema['additionalProperties']);
			$schema['x-unknown-root-keys'] = 'discard-before-common-guard';
		}
		if ($path !== '')
		{
			foreach (explode('.', str_replace('[]', '.items', $path)) as $part)
			{
				$schema = $part === 'items' ? ($schema['items'] ?? []) : ($schema['properties'][$part] ?? []);
			}
		}
		$schema = self::describe($schema, $descriptions, $path);
		$schema['$defs']['JsonValue'] = [
			'anyOf' => [
				['type' => 'string'], ['type' => 'integer'], ['type' => 'number'],
				['type' => 'boolean'], ['type' => 'null'],
				['type' => 'array', 'items' => ['$ref' => '#/$defs/JsonValue']],
				['type' => 'object', 'additionalProperties' => ['$ref' => '#/$defs/JsonValue']],
			],
		];

		return $schema;
	}

	private static function describe(array $schema, array $descriptions, string $path): array
	{
		$description = $descriptions[$path] ?? $descriptions[$schema['x-field-id'] ?? ''] ?? '';
		foreach ($schema as $key => $value)
		{
			if (str_starts_with($key, 'x-') && $key !== 'x-field-id')
			{
				$description .= ($description === '' ? '' : ' ') . $key . ': '
					. (is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE));
			}
		}
		if ($description !== '')
		{
			$schema['description'] = $description;
		}
		if (($schema['type'] ?? '') === 'object' && array_key_exists('default', $schema) && $schema['default'] === [])
		{
			$schema['default'] = new \stdClass();
		}
		foreach ($schema['properties'] ?? [] as $key => $property)
		{
			$schema['properties'][$key] = self::describe($property, $descriptions, $path === '' ? $key : $path . '.' . $key);
		}
		if (isset($schema['items']))
		{
			$schema['items'] = self::describe($schema['items'], $descriptions, $path . '[]');
		}

		return $schema;
	}
}
