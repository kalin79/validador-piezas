<?php

declare(strict_types=1);

/**
 * Especificaciones de formato por canal.
 *
 * Fuente: guias publicas de dimensiones de Hootsuite y Buffer, agosto 2026.
 * Son un punto de partida verificable, no una verdad permanente: las
 * plataformas cambian estos valores y conviene revisarlos cada cierto tiempo.
 *
 * tolerance_ratio es la desviacion admitida en la relacion de aspecto. Un 2%
 * absorbe redondeos de exportacion sin dejar pasar una pieza recortada.
 */
return [

    'tolerance_ratio' => 0.02,

    'min_scale' => 0.5,   // por debajo de la mitad del ancho recomendado, se marca
    'max_scale' => 3.0,   // por encima del triple, es peso desperdiciado

    'presets' => [

        'instagram_post' => [
            'label' => 'Instagram feed cuadrado',
            'width' => 1080,
            'height' => 1080,
            'ratio' => 1 / 1,
            'ratio_label' => '1:1',
            'max_bytes' => 8 * 1024 * 1024,
        ],

        'instagram_post_vertical' => [
            'label' => 'Instagram feed vertical',
            'width' => 1080,
            'height' => 1350,
            'ratio' => 4 / 5,
            'ratio_label' => '4:5',
            'max_bytes' => 8 * 1024 * 1024,
        ],

        'instagram_story' => [
            'label' => 'Instagram historia',
            'width' => 1080,
            'height' => 1920,
            'ratio' => 9 / 16,
            'ratio_label' => '9:16',
            'max_bytes' => 8 * 1024 * 1024,
        ],

        'instagram_reel' => [
            'label' => 'Instagram reel (portada)',
            'width' => 1080,
            'height' => 1920,
            'ratio' => 9 / 16,
            'ratio_label' => '9:16',
            'max_bytes' => 8 * 1024 * 1024,
        ],

        'facebook_feed' => [
            'label' => 'Facebook feed',
            'width' => 1080,
            'height' => 1350,
            'ratio' => 4 / 5,
            'ratio_label' => '4:5',
            'max_bytes' => 8 * 1024 * 1024,
        ],

        'facebook_cover' => [
            'label' => 'Facebook portada',
            'width' => 851,
            'height' => 315,
            'ratio' => 851 / 315,
            'ratio_label' => '2.7:1',
            'max_bytes' => 100 * 1024,
        ],

        'facebook_story' => [
            'label' => 'Facebook historia',
            'width' => 1080,
            'height' => 1920,
            'ratio' => 9 / 16,
            'ratio_label' => '9:16',
            'max_bytes' => 8 * 1024 * 1024,
        ],

        'tiktok_video' => [
            'label' => 'TikTok (portada)',
            'width' => 1080,
            'height' => 1920,
            'ratio' => 9 / 16,
            'ratio_label' => '9:16',
            'max_bytes' => 8 * 1024 * 1024,
        ],

        'linkedin_post' => [
            'label' => 'LinkedIn publicacion',
            'width' => 1080,
            'height' => 1350,
            'ratio' => 4 / 5,
            'ratio_label' => '4:5',
            'max_bytes' => 8 * 1024 * 1024,
        ],

        'youtube_thumbnail' => [
            'label' => 'YouTube miniatura',
            'width' => 1280,
            'height' => 720,
            'ratio' => 16 / 9,
            'ratio_label' => '16:9',
            'max_bytes' => 2 * 1024 * 1024,
        ],

        'display_banner' => [
            'label' => 'Banner display',
            'width' => null,
            'height' => null,
            'ratio' => null,
            'ratio_label' => 'libre',
            'max_bytes' => 500 * 1024,
        ],

    ],
];
