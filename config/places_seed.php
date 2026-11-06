<?php
/*
 * Starter data for the province / city / barangay lists. Imported once by migrate_core.php (core_v4)
 * when the `cities` table is empty. After that, edit the lists in Admin > Delivery zones.
 *
 * IMPORTANT: the Cebu City barangay list below was typed from general knowledge, not copied from the PSA
 * master list. Check it against the official list before launch and add anything missing in
 * Admin > Delivery zones. Other cities start with no barangay list, so customers type those freely
 * until you add them.
 */
return [
    'province' => 'Cebu',
    // Every province (82) plus Metro Manila. All of them show in the Province dropdown; only provinces that have
    // towns in the `cities` table get a Town / Barangay dropdown, the rest use required text boxes until you add the lists.
    'provinces' => [
        'Abra', 'Agusan del Norte', 'Agusan del Sur', 'Aklan', 'Albay', 'Antique',
        'Apayao', 'Aurora', 'Basilan', 'Bataan', 'Batanes', 'Batangas',
        'Benguet', 'Biliran', 'Bohol', 'Bukidnon', 'Bulacan', 'Cagayan',
        'Camarines Norte', 'Camarines Sur', 'Camiguin', 'Capiz', 'Catanduanes', 'Cavite',
        'Cebu', 'Cotabato', 'Davao de Oro', 'Davao del Norte', 'Davao del Sur', 'Davao Occidental',
        'Davao Oriental', 'Dinagat Islands', 'Eastern Samar', 'Guimaras', 'Ifugao', 'Ilocos Norte',
        'Ilocos Sur', 'Iloilo', 'Isabela', 'Kalinga', 'La Union', 'Laguna',
        'Lanao del Norte', 'Lanao del Sur', 'Leyte', 'Maguindanao del Norte', 'Maguindanao del Sur', 'Marinduque',
        'Masbate', 'Metro Manila', 'Misamis Occidental', 'Misamis Oriental', 'Mountain Province', 'Negros Occidental',
        'Negros Oriental', 'Northern Samar', 'Nueva Ecija', 'Nueva Vizcaya', 'Occidental Mindoro', 'Oriental Mindoro',
        'Palawan', 'Pampanga', 'Pangasinan', 'Quezon', 'Quirino', 'Rizal',
        'Romblon', 'Samar', 'Sarangani', 'Siquijor', 'Sorsogon', 'South Cotabato',
        'Southern Leyte', 'Sultan Kudarat', 'Sulu', 'Surigao del Norte', 'Surigao del Sur', 'Tarlac',
        'Tawi-Tawi', 'Zambales', 'Zamboanga del Norte', 'Zamboanga del Sur', 'Zamboanga Sibugay',
    ],
    // 9 cities + 44 municipalities of Cebu province
    'cities' => [
        'Bogo City', 'Carcar City', 'Cebu City', 'Danao City', 'Lapu-Lapu City', 'Mandaue City', 'Naga City', 'Talisay City', 'Toledo City',
        'Alcantara', 'Alcoy', 'Alegria', 'Aloguinsan', 'Argao', 'Asturias', 'Badian', 'Balamban', 'Bantayan', 'Barili', 'Boljoon', 'Borbon',
        'Carmen', 'Catmon', 'Compostela', 'Consolacion', 'Cordova', 'Daanbantayan', 'Dalaguete', 'Dumanjug', 'Ginatilan', 'Liloan',
        'Madridejos', 'Malabuyoc', 'Medellin', 'Minglanilla', 'Moalboal', 'Oslob', 'Pilar', 'Pinamungajan', 'Poro', 'Ronda', 'Samboan',
        'San Fernando', 'San Francisco', 'San Remigio', 'Santa Fe', 'Santander', 'Sibonga', 'Sogod', 'Tabogon', 'Tabuelan', 'Tuburan', 'Tudela',
    ],
    'barangays' => [
        'Cebu City' => [
            'Adlaon', 'Agsungot', 'Apas', 'Babag', 'Bacayan', 'Banilad', 'Basak Pardo', 'Basak San Nicolas', 'Binaliw', 'Bonbon', 'Budla-an',
            'Buhisan', 'Bulacao', 'Buot', 'Busay', 'Calamba', 'Cambinocot', 'Capitol Site', 'Carreta', 'Cogon Pardo', 'Cogon Ramos', 'Day-as',
            'Duljo Fatima', 'Ermita', 'Guadalupe', 'Guba', 'Hippodromo', 'Inayawan', 'Kalubihan', 'Kalunasan', 'Kamagayan', 'Kamputhaw',
            'Kasambagan', 'Kinasang-an', 'Labangon', 'Lahug', 'Lorega San Miguel', 'Lusaran', 'Luz', 'Mabini', 'Mabolo', 'Malubog', 'Mambaling',
            'Pahina Central', 'Pahina San Nicolas', 'Pamutan', 'Pardo', 'Pari-an', 'Paril', 'Pasil', 'Pit-os', 'Poblacion Pardo', 'Pulangbato',
            'Pung-ol Sibugay', 'Punta Princesa', 'Quiot Pardo', 'Sambag 1', 'Sambag 2', 'San Antonio', 'San Jose', 'San Nicolas Proper',
            'San Roque', 'Santa Cruz', 'Santo Niño', 'Sapangdaku', 'Sawang Calero', 'Sinsin', 'Sirao', 'Suba', 'Sudlon 1', 'Sudlon 2',
            'T. Padilla', 'Tabunan', 'Tagba-o', 'Talamban', 'Taptap', 'Tejero', 'Tinago', 'Tisa', 'To-ong', 'Zapatera',
        ],
    ],
    // Cities that start in the first delivery zone. Everything else falls back to the standard delivery fee.
    'metro_zone' => ['Cebu City', 'Mandaue City', 'Lapu-Lapu City', 'Talisay City', 'Minglanilla', 'Consolacion', 'Cordova', 'Liloan', 'Compostela'],
];
