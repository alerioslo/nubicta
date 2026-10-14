<?php

return [
    'endpoints' => [
        'habilitacion' => 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc',
        'produccion' => 'https://vpfe.dian.gov.co/WcfDianCustomerServices.svc',
    ],

    'soap_action_base' => 'http://wcf.dian.colombia/IWcfDianCustomerServices/',

    'signature_policy_url' => 'https://facturaelectronica.dian.gov.co/politicadefirma/v2/politicadefirmav2.pdf',

    // Documentos que exige cada set de pruebas (ajustar al set asignado en el portal DIAN).
    'test_sets' => [
        'fe' => ['FV' => 30, 'NC' => 10, 'ND' => 10],
        'nomina' => ['NE' => 8, 'NA' => 2],
        'ds' => ['DS' => 10, 'NA' => 2],
    ],

    // Rangos que devuelve la consulta simulada cuando no hay resolución cargada.
    'simulated_ranges' => [
        ['type' => 'FV', 'resolution' => '18760000001', 'resolution_date' => '2026-01-01', 'prefix' => 'SETP', 'from' => 990000000, 'to' => 995000000, 'valid_from' => '2026-01-01', 'valid_to' => '2030-12-31'],
    ],
];
