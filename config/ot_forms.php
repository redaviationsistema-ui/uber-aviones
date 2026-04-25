<?php

return [
    'default' => [
        'version' => 1,
        'tabs' => [
            [
                'key' => 'partes',
                'label' => 'Partes',
                'collection' => 'refacciones',
                'empty_state' => 'Sin partes configuradas para esta area.',
                'fields' => [
                    ['key' => 'item', 'label' => 'Item', 'type' => 'text'],
                    ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
                    ['key' => 'descripcion', 'label' => 'Descripcion', 'type' => 'textarea'],
                    ['key' => 'cantidad', 'label' => 'Cantidad', 'type' => 'number'],
                    ['key' => 'numero_parte', 'label' => 'Numero de parte', 'type' => 'text'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
                    ['key' => 'area_procedencia', 'label' => 'Area procedencia', 'type' => 'text'],
                ],
                'presets' => [],
            ],
            [
                'key' => 'materiales',
                'label' => 'Materiales',
                'collection' => 'consumibles',
                'empty_state' => 'Sin materiales configurados para esta area.',
                'fields' => [
                    ['key' => 'item', 'label' => 'Item', 'type' => 'text'],
                    ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
                    ['key' => 'descripcion', 'label' => 'Descripcion', 'type' => 'textarea'],
                    ['key' => 'cantidad', 'label' => 'Cantidad', 'type' => 'number'],
                    ['key' => 'numero_parte', 'label' => 'Numero de parte', 'type' => 'text'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
                    ['key' => 'area_procedencia', 'label' => 'Area procedencia', 'type' => 'text'],
                ],
                'presets' => [],
            ],
        ],
    ],
    'areas' => [
        'AVCS' => [
            'tabs' => [
                [
                    'key' => 'partes',
                    'presets' => [
                        ['nombre' => 'Arnes electrico', 'descripcion' => 'Cableado o arnes de reemplazo'],
                        ['nombre' => 'Conector', 'descripcion' => 'Conector o terminal avionica'],
                        ['nombre' => 'Breaker', 'descripcion' => 'Proteccion electrica'],
                    ],
                ],
                [
                    'key' => 'materiales',
                    'presets' => [
                        ['nombre' => 'Termofit', 'descripcion' => 'Aislamiento para arnes'],
                        ['nombre' => 'Cinta electrica', 'descripcion' => 'Proteccion y sujecion'],
                        ['nombre' => 'Lacing cord', 'descripcion' => 'Amarre de arnes'],
                    ],
                ],
            ],
        ],
        'HANG' => [
            'tabs' => [
                [
                    'key' => 'partes',
                    'presets' => [
                        ['nombre' => 'Panel', 'descripcion' => 'Parte de fuselaje o acceso'],
                        ['nombre' => 'Bisagra', 'descripcion' => 'Herraje o punto de union'],
                        ['nombre' => 'Remache', 'descripcion' => 'Fijacion estructural'],
                    ],
                ],
                [
                    'key' => 'materiales',
                    'presets' => [
                        ['nombre' => 'Sellador', 'descripcion' => 'Aplicacion en juntas o paneles'],
                        ['nombre' => 'Primer', 'descripcion' => 'Preparacion de superficie'],
                        ['nombre' => 'Solvente', 'descripcion' => 'Limpieza de superficie'],
                    ],
                ],
            ],
        ],
        'BATT' => [
            'tabs' => [
                [
                    'key' => 'partes',
                    'presets' => [
                        ['nombre' => 'Celda', 'descripcion' => 'Elemento de bateria'],
                        ['nombre' => 'Conector de bateria', 'descripcion' => 'Terminal o borne'],
                        ['nombre' => 'Caja de bateria', 'descripcion' => 'Contenedor o soporte'],
                    ],
                ],
                [
                    'key' => 'materiales',
                    'presets' => [
                        ['nombre' => 'Grasa dielectrica', 'descripcion' => 'Proteccion de terminales'],
                        ['nombre' => 'Solucion limpiadora', 'descripcion' => 'Limpieza de residuos'],
                        ['nombre' => 'Material absorbente', 'descripcion' => 'Control de derrames'],
                    ],
                ],
            ],
        ],
        'FREN' => [
            'tabs' => [
                [
                    'key' => 'partes',
                    'presets' => [
                        ['nombre' => 'Disco de freno', 'descripcion' => 'Elemento de frenado'],
                        ['nombre' => 'Sello', 'descripcion' => 'Sello o empaque'],
                        ['nombre' => 'Piston', 'descripcion' => 'Componente de actuacion'],
                    ],
                ],
                [
                    'key' => 'materiales',
                    'presets' => [
                        ['nombre' => 'Fluido hidraulico', 'descripcion' => 'Servicio de sistema'],
                        ['nombre' => 'Limpiador', 'descripcion' => 'Limpieza de componentes'],
                        ['nombre' => 'Grasa', 'descripcion' => 'Lubricacion controlada'],
                    ],
                ],
            ],
        ],
        'TREN' => [
            'tabs' => [
                [
                    'key' => 'partes',
                    'presets' => [
                        ['nombre' => 'Buje', 'descripcion' => 'Elemento de soporte'],
                        ['nombre' => 'Actuador', 'descripcion' => 'Componente de extension o retraccion'],
                        ['nombre' => 'Lock', 'descripcion' => 'Mecanismo de bloqueo'],
                    ],
                ],
                [
                    'key' => 'materiales',
                    'presets' => [
                        ['nombre' => 'Skydrol', 'descripcion' => 'Fluido hidraulico'],
                        ['nombre' => 'Antisize', 'descripcion' => 'Compuesto antiagarrotante'],
                        ['nombre' => 'Lubricante', 'descripcion' => 'Servicio general'],
                    ],
                ],
            ],
        ],
        'HELI' => [
            'tabs' => [
                [
                    'key' => 'partes',
                    'presets' => [
                        ['nombre' => 'Panel de acceso', 'descripcion' => 'Parte de helicoptero'],
                        ['nombre' => 'Soporte', 'descripcion' => 'Soporte o fijacion'],
                        ['nombre' => 'Herraje', 'descripcion' => 'Elemento estructural'],
                    ],
                ],
                [
                    'key' => 'materiales',
                    'presets' => [
                        ['nombre' => 'Sellador', 'descripcion' => 'Proteccion de juntas'],
                        ['nombre' => 'Primer', 'descripcion' => 'Preparacion de superficie'],
                        ['nombre' => 'Solvente', 'descripcion' => 'Limpieza tecnica'],
                    ],
                ],
            ],
        ],
        'PROP' => [
            'tabs' => [
                [
                    'key' => 'partes',
                    'presets' => [
                        ['nombre' => 'Blade clamp', 'descripcion' => 'Elemento de ensamble'],
                        ['nombre' => 'Spinner', 'descripcion' => 'Componente de helice'],
                        ['nombre' => 'Reten', 'descripcion' => 'Sello de sistema'],
                    ],
                ],
                [
                    'key' => 'materiales',
                    'presets' => [
                        ['nombre' => 'Grasa', 'descripcion' => 'Lubricacion de helice'],
                        ['nombre' => 'Solvente', 'descripcion' => 'Limpieza previa'],
                        ['nombre' => 'Pintura', 'descripcion' => 'Acabado superficial'],
                    ],
                ],
            ],
        ],
        'PIST' => [
            'tabs' => [
                [
                    'key' => 'partes',
                    'presets' => [
                        ['nombre' => 'Piston', 'descripcion' => 'Parte de motor reciproco'],
                        ['nombre' => 'Anillo', 'descripcion' => 'Anillo de piston'],
                        ['nombre' => 'Valvula', 'descripcion' => 'Valvula de admision o escape'],
                    ],
                ],
                [
                    'key' => 'materiales',
                    'presets' => [
                        ['nombre' => 'Aceite', 'descripcion' => 'Servicio de motor'],
                        ['nombre' => 'Pasta de ensamble', 'descripcion' => 'Armado controlado'],
                        ['nombre' => 'Solvente', 'descripcion' => 'Limpieza tecnica'],
                    ],
                ],
            ],
        ],
        'VEST' => [
            'tabs' => [
                [
                    'key' => 'partes',
                    'presets' => [
                        ['nombre' => 'Panel interior', 'descripcion' => 'Acabado de cabina'],
                        ['nombre' => 'Cojin', 'descripcion' => 'Elemento de asiento'],
                        ['nombre' => 'Cubierta', 'descripcion' => 'Recubrimiento interior'],
                    ],
                ],
                [
                    'key' => 'materiales',
                    'presets' => [
                        ['nombre' => 'Tela', 'descripcion' => 'Tapiceria'],
                        ['nombre' => 'Adhesivo', 'descripcion' => 'Pegado de interiores'],
                        ['nombre' => 'Espuma', 'descripcion' => 'Relleno o soporte'],
                    ],
                ],
            ],
        ],
        'ESTR' => [
            'tabs' => [
                [
                    'key' => 'partes',
                    'presets' => [
                        ['nombre' => 'Larguero', 'descripcion' => 'Elemento estructural'],
                        ['nombre' => 'Costilla', 'descripcion' => 'Refuerzo estructural'],
                        ['nombre' => 'Placa', 'descripcion' => 'Parche o refuerzo'],
                    ],
                ],
                [
                    'key' => 'materiales',
                    'presets' => [
                        ['nombre' => 'Remaches', 'descripcion' => 'Fijacion estructural'],
                        ['nombre' => 'Sellador', 'descripcion' => 'Proteccion y sellado'],
                        ['nombre' => 'Primer', 'descripcion' => 'Proteccion anticorrosiva'],
                    ],
                ],
            ],
        ],
        'TORN' => [
            'tabs' => [
                [
                    'key' => 'partes',
                    'presets' => [
                        ['nombre' => 'Buje maquinado', 'descripcion' => 'Pieza fabricada'],
                        ['nombre' => 'Eje', 'descripcion' => 'Componente torneado'],
                        ['nombre' => 'Separador', 'descripcion' => 'Pieza de ajuste'],
                    ],
                ],
                [
                    'key' => 'materiales',
                    'presets' => [
                        ['nombre' => 'Barra de aluminio', 'descripcion' => 'Materia prima'],
                        ['nombre' => 'Barra de acero', 'descripcion' => 'Materia prima'],
                        ['nombre' => 'Refrigerante', 'descripcion' => 'Proceso de maquinado'],
                    ],
                ],
            ],
        ],
        'SALV' => [
            'tabs' => [
                [
                    'key' => 'partes',
                    'presets' => [
                        ['nombre' => 'Cartucho', 'descripcion' => 'Componente de sistema'],
                        ['nombre' => 'Arnes', 'descripcion' => 'Elemento de sujecion'],
                        ['nombre' => 'Valvula', 'descripcion' => 'Componente de activacion'],
                    ],
                ],
                [
                    'key' => 'materiales',
                    'presets' => [
                        ['nombre' => 'Sellador', 'descripcion' => 'Proteccion de ensamble'],
                        ['nombre' => 'Lubricante', 'descripcion' => 'Mantenimiento preventivo'],
                        ['nombre' => 'Limpiador', 'descripcion' => 'Servicio y limpieza'],
                    ],
                ],
            ],
        ],
        'SOLD' => [
            'tabs' => [
                [
                    'key' => 'partes',
                    'presets' => [
                        ['nombre' => 'Bracket', 'descripcion' => 'Pieza a reparar o fabricar'],
                        ['nombre' => 'Soporte', 'descripcion' => 'Elemento estructural'],
                        ['nombre' => 'Tubo', 'descripcion' => 'Componente a unir'],
                    ],
                ],
                [
                    'key' => 'materiales',
                    'presets' => [
                        ['nombre' => 'Varilla de aporte', 'descripcion' => 'Material de soldadura'],
                        ['nombre' => 'Gas inerte', 'descripcion' => 'Proteccion de proceso'],
                        ['nombre' => 'Desengrasante', 'descripcion' => 'Preparacion de superficie'],
                    ],
                ],
            ],
        ],
    ],
];
