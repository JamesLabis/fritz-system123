<?php
/* Product visuals: uploaded photo if present, otherwise a detailed built-in illustration. */
function product_kind(?string $cat, ?string $name): string {
    $s = strtolower(($cat ?? '') . ' ' . ($name ?? ''));
    $map = ['cement'=>'cement','steel'=>'rebar','metal'=>'rebar','rebar'=>'rebar','paint'=>'paint','electric'=>'wire','wire'=>'wire','plumb'=>'pipe','pipe'=>'pipe','tool'=>'hammer','hammer'=>'hammer'];
    foreach ($map as $k => $v) if (str_contains($s, $k)) return $v;
    return 'box';
}
/** Resolve product photos stored by admins or supplied in img/product. */
function product_image_url(array $p, string $field = 'image'): ?string {
    $stored = trim((string)($p[$field] ?? ''));
    $root = dirname(__DIR__);
    $productDir = $root . '/img/product';

    // Database values may already contain a relative image path.
    if ($stored !== '') {
        $relative = ltrim(str_replace('\\', '/', $stored), '/');
        if (strpos($relative, 'img/product/') === 0) {
            $candidate = $root . '/' . $relative;
            if (is_file($candidate)) return BASE_URL . '/' . implode('/', array_map('rawurlencode', explode('/', $relative)));
        }
        $base = basename($relative);
        if (is_file($root . '/uploads/' . $base)) return BASE_URL . '/uploads/' . rawurlencode($base);
        if (is_file($productDir . '/' . $base)) return BASE_URL . '/img/product/' . rawurlencode($base);
    }

    // Built-in catalog image mapping for the six supplied BuildSmart product photos.
    // This fallback also works for existing databases whose image column is still NULL.
    $catalogImages = [
        'portland cement 40kg' => 'portland-cement-40kg.png',
        'deformed bar 10mm' => 'deformed-bar-10mm.jpg',
        'latex wall paint 4l' => 'latex-wall-paint-4l.jpg',
        'electrical wire 2.0mm' => 'electrical-wire-2-0mm.jpg',
        'pvc pipe 2 inch' => 'pvc-pipe-2-inch.jpg',
        'claw hammer' => 'claw-hammer.jpg',
    ];
    $normalizedProductName = strtolower(trim((string)($p['product_name'] ?? '')));
    if (isset($catalogImages[$normalizedProductName])) {
        $catalogFile = $catalogImages[$normalizedProductName];
        if (is_file($productDir . '/' . $catalogFile)) {
            return BASE_URL . '/img/product/' . rawurlencode($catalogFile);
        }
    }

    // Match common product-image filenames to the product name or product ID.
    if (is_dir($productDir)) {
        $extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $names = [];
        $productName = trim((string)($p['product_name'] ?? ''));
        if ($productName !== '') {
            $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $productName), '-'));
            $names[] = $slug;
            $names[] = str_replace('-', '_', $slug);
            $names[] = str_replace('-', '', $slug);
        }
        if (!empty($p['product_id'])) {
            $names[] = (string)$p['product_id'];
            $names[] = 'product-' . (int)$p['product_id'];
            $names[] = 'product_' . (int)$p['product_id'];
        }
        foreach ($names as $name) foreach ($extensions as $ext) {
            $candidate = $productDir . '/' . $name . '.' . $ext;
            if (is_file($candidate)) return BASE_URL . '/img/product/' . rawurlencode($name . '.' . $ext);
        }
        // Case-insensitive matching supports folders prepared on Windows.
        $files = @scandir($productDir) ?: [];
        $wanted = array_map('strtolower', $names);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || !in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $extensions, true)) continue;
            if (in_array(strtolower(pathinfo($file, PATHINFO_FILENAME)), $wanted, true))
                return BASE_URL . '/img/product/' . rawurlencode($file);
        }
    }
    return null;
}

function product_visual(array $p): string {
    $imageUrl = product_image_url($p);
    if ($imageUrl) {
        return '<img class="pimg" loading="lazy" src="' . e($imageUrl) . '" alt="' . e($p['image_alt'] ?? (($p['product_name'] ?? 'Product') . ', product photo')) . '">';
    }
    $name = $p['product_name'] ?? '';
    $hue = crc32($name) % 360;
    return product_art(product_kind($p['category_name'] ?? '', $name), $name, "hsl($hue,58%,46%)");
}
function product_art(string $kind, string $label = '', string $color = '#1fa3a3'): string {
    $L = e(mb_strimwidth($label, 0, 24, '…'));
    $svg = '<svg class="art" viewBox="0 0 400 300" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' . $L . '"><defs><radialGradient id="bg" cx=".5" cy=".4" r=".8"><stop offset="0" stop-color="#fff"/><stop offset="1" stop-color="#e4dfd3"/></radialGradient><pattern id="gr" width="20" height="20" patternUnits="userSpaceOnUse"><path d="M20 0H0V20" fill="none" stroke="#14181f" stroke-opacity=".06"/></pattern></defs><rect width="400" height="300" fill="url(#bg)"/><rect width="400" height="300" fill="url(#gr)"/><ellipse cx="200" cy="264" rx="125" ry="11" fill="#14181f" opacity=".18"/>';
    $F = 'font-family="Arial Black,Impact,sans-serif"';
    switch ($kind) {
    case 'cement':
        $body = '<defs><linearGradient id="cb" x1="0" x2="1"><stop offset="0" stop-color="#8f98a2"/><stop offset=".45" stop-color="#d3d8de"/><stop offset="1" stop-color="#848d97"/></linearGradient></defs>
<path d="M108 80Q103 165 110 247Q200 260 290 247Q297 165 292 80Z" fill="url(#cb)"/>
<rect x="107" y="122" width="186" height="88" fill="#ffc20e"/><rect x="107" y="122" width="186" height="6" fill="#14181f"/><rect x="107" y="204" width="186" height="6" fill="#14181f"/>
<path d="M108 80Q103 165 110 247L130 249Q124 165 128 84Z" fill="#000" opacity=".12"/><path d="M292 80Q297 165 290 247L272 249Q277 165 272 84Z" fill="#000" opacity=".1"/>
<path d="M150 98Q160 130 148 160M250 96Q242 140 254 170M140 216Q170 226 200 220" fill="none" stroke="#000" stroke-opacity=".12" stroke-width="2"/>
<path d="M108 80Q103 165 110 247Q200 260 290 247Q297 165 292 80Z" fill="none" stroke="#5d656e" stroke-width="2"/>
<path d="M106 54H294L292 84Q200 96 108 84Z" fill="#7a848e" stroke="#5d656e" stroke-width="2"/><path d="M112 68H288" stroke="#14181f" stroke-width="2" stroke-dasharray="6 4"/><path d="M112 78Q200 90 288 78" fill="none" stroke="#fff" stroke-opacity=".35"/>
<text x="200" y="150" text-anchor="middle" ' . $F . ' font-size="21" fill="#14181f">PORTLAND</text><text x="200" y="172" text-anchor="middle" ' . $F . ' font-size="21" fill="#14181f">CEMENT</text>
<text x="200" y="192" text-anchor="middle" font-family="Arial" font-weight="700" font-size="11" fill="#14181f">{L}</text>
<circle cx="146" cy="228" r="14" fill="#14181f"/><text x="146" y="231" text-anchor="middle" font-family="Arial" font-weight="700" font-size="8" fill="#ffc20e">TYPE I</text>
<path d="M206 226H276" stroke="#fff" stroke-width="16" stroke-dasharray="2 2 4 2 1 3 3 2 2 3" opacity=".9"/>';
        break;
    case 'rebar':
        $body = '<defs><linearGradient id="rb" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#eef1f4"/><stop offset=".35" stop-color="#8f99a4"/><stop offset="1" stop-color="#454d56"/></linearGradient><pattern id="rib" width="14" height="22" patternUnits="userSpaceOnUse" patternTransform="skewX(-25)"><rect width="3" height="22" fill="#2a3037" opacity=".6"/></pattern></defs><g transform="rotate(-14 200 150)">';
        for ($i = 0; $i < 4; $i++) { $y = 88 + $i * 30;
            $body .= '<rect x="30" y="'.$y.'" width="340" height="22" rx="11" fill="url(#rb)"/><rect x="30" y="'.$y.'" width="340" height="22" rx="11" fill="url(#rib)"/><path d="M44 '.($y+11).'H356" stroke="#2a3037" stroke-opacity=".5"/><path d="M44 '.($y+5).'H356" stroke="#fff" stroke-opacity=".45"/>'; }
        $body .= '<rect x="104" y="82" width="9" height="138" rx="3" fill="#2f353c"/><rect x="248" y="82" width="9" height="138" rx="3" fill="#2f353c"/><path d="M104 120h9M104 150h9M104 180h9M248 120h9M248 150h9M248 180h9" stroke="#8c95a0" stroke-width="2"/>
<path d="M300 126L296 150" stroke="#2f353c" stroke-width="2"/><rect x="278" y="146" width="70" height="38" rx="5" fill="#ffc20e" stroke="#14181f" stroke-width="2"/><circle cx="286" cy="154" r="2.5" fill="#14181f"/><text x="316" y="161" text-anchor="middle" font-family="Arial" font-weight="800" font-size="9" fill="#14181f">GRADE 40</text><text x="316" y="175" text-anchor="middle" font-family="Arial" font-weight="700" font-size="8" fill="#14181f">{L}</text></g>';
        break;
    case 'paint':
        $body = '<defs><linearGradient id="pb" x1="0" x2="1"><stop offset="0" stop-color="#aeb5bd"/><stop offset=".4" stop-color="#f1f3f5"/><stop offset="1" stop-color="#9aa2ab"/></linearGradient></defs>
<path d="M112 92Q200 6 288 92" fill="none" stroke="#4d555e" stroke-width="5"/><circle cx="112" cy="92" r="5" fill="#2a3037"/><circle cx="288" cy="92" r="5" fill="#2a3037"/>
<path d="M110 90L126 246Q200 262 274 246L290 90Z" fill="url(#pb)" stroke="#7b838c" stroke-width="2"/>
<path d="M114 130H286L278 206H122Z" fill="#fff"/><path d="M114 130H286L285 146H115Z" fill="{C}"/><path d="M122 206H278L277 214H123Z" fill="{C}"/>
<circle cx="200" cy="176" r="19" fill="{C}" stroke="#14181f" stroke-width="2"/><circle cx="193" cy="169" r="5" fill="#fff" opacity=".45"/>
<text x="200" y="124" text-anchor="middle" ' . $F . ' font-size="11" fill="#14181f" letter-spacing="2">PREMIUM LATEX</text>
<text x="200" y="204" text-anchor="middle" font-family="Arial" font-weight="800" font-size="10" fill="#14181f">{L}</text>
<path d="M112 112L289 112M120 232L280 232M122 240L278 240" stroke="#7b838c" stroke-opacity=".7"/>
<ellipse cx="200" cy="90" rx="90" ry="16" fill="#aab1b9" stroke="#6b737c" stroke-width="2"/><ellipse cx="200" cy="90" rx="78" ry="11" fill="{C}"/><ellipse cx="180" cy="88" rx="26" ry="3" fill="#fff" opacity=".4"/>
<path d="M126 92q-1 34 8 34t8-30M252 96q0 22 7 22t7-24" fill="{C}"/>';
        break;
    case 'wire':
        $body = '<circle cx="200" cy="150" r="106" fill="#d9a92b" stroke="#8a6410" stroke-width="3"/><circle cx="200" cy="150" r="98" fill="none" stroke="#b88a1c" stroke-width="2"/>';
        for ($r = 92, $k = 0; $r >= 36; $r -= 5, $k++) $body .= '<circle cx="200" cy="150" r="'.$r.'" fill="none" stroke="'.($k % 2 ? '#a62f23' : '#d44332').'" stroke-width="5"/>';
        $body .= '<circle cx="200" cy="150" r="34" fill="#8a6410"/><circle cx="200" cy="150" r="26" fill="#e2b53d"/><circle cx="200" cy="150" r="10" fill="#f4f0e6" stroke="#6b4c0c" stroke-width="2"/>
<path d="M120 88A100 100 0 0 1 200 52" stroke="#fff" stroke-opacity=".5" stroke-width="6" fill="none" stroke-linecap="round"/>
<path d="M286 134C334 116 376 152 352 196" fill="none" stroke="#c0392b" stroke-width="7" stroke-linecap="round"/><path d="M352 196l-7 14" stroke="#d9772b" stroke-width="4" stroke-linecap="round"/>
<g transform="rotate(-5 70 246)"><rect x="18" y="230" width="112" height="34" rx="4" fill="#fff" stroke="#14181f" stroke-width="2"/><rect x="18" y="230" width="112" height="8" fill="#ffc20e"/><text x="74" y="251" text-anchor="middle" font-family="Arial" font-weight="800" font-size="9" fill="#14181f">COPPER WIRE</text><text x="74" y="260" text-anchor="middle" font-family="Arial" font-size="7" fill="#14181f">{L}</text></g>';
        break;
    case 'pipe':
        $body = '<defs><linearGradient id="pv" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff"/><stop offset=".45" stop-color="#d3dae0"/><stop offset="1" stop-color="#97a1aa"/></linearGradient></defs>';
        for ($i = 0; $i < 3; $i++) { $y = 80 + $i * 58; $o = $i * 10;
            $body .= '<rect x="'.(36+$o).'" y="'.($y-5).'" width="54" height="56" rx="7" fill="url(#pv)" stroke="#8e979f" stroke-width="2"/><rect x="'.(90+$o).'" y="'.$y.'" width="232" height="46" fill="url(#pv)" stroke="#8e979f" stroke-width="2"/><rect x="'.(90+$o).'" y="'.($y+5).'" width="232" height="6" fill="#fff" opacity=".7"/><path d="M'.(98+$o).' '.($y-5).'v56" stroke="#8e979f" stroke-width="3"/><text x="'.(116+$o).'" y="'.($y+30).'" font-family="Arial" font-weight="800" font-size="10" fill="#1d4ed8" letter-spacing="1">PVC · 2 IN · PRESSURE PIPE · SDR 41</text><ellipse cx="'.(322+$o).'" cy="'.($y+23).'" rx="11" ry="23" fill="#eef1f3" stroke="#8e979f" stroke-width="2"/><ellipse cx="'.(322+$o).'" cy="'.($y+23).'" rx="7" ry="16" fill="#4b535b"/>'; }
        $body .= '<rect x="46" y="250" width="0" height="0"/><text x="200" y="283" text-anchor="middle" font-family="Arial" font-weight="700" font-size="11" fill="#14181f">{L}</text>';
        break;
    case 'hammer':
        $body = '<defs><linearGradient id="wd" x1="0" x2="1"><stop offset="0" stop-color="#b87a3f"/><stop offset=".5" stop-color="#e8b57a"/><stop offset="1" stop-color="#9a6030"/></linearGradient><linearGradient id="st" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f4f6f8"/><stop offset=".45" stop-color="#9ba5af"/><stop offset="1" stop-color="#4d555e"/></linearGradient></defs><g transform="rotate(35 210 160)">
<rect x="190" y="108" width="22" height="156" rx="6" fill="url(#wd)"/><path d="M196 120V190M203 114V190M208 124V190" stroke="#7a4a1e" stroke-opacity=".4"/>
<rect x="187" y="190" width="28" height="74" rx="9" fill="#14181f"/><rect x="187" y="190" width="28" height="6" rx="3" fill="#ffc20e"/><path d="M189 206H213M189 216H213M189 226H213M189 236H213M189 246H213" stroke="#3a424d" stroke-width="3"/><rect x="187" y="256" width="28" height="8" rx="4" fill="#ff7a00"/>
<rect x="184" y="92" width="34" height="22" rx="4" fill="url(#st)" stroke="#4a525b" stroke-width="2"/>
<rect x="108" y="60" width="34" height="42" rx="5" fill="url(#st)" stroke="#4a525b" stroke-width="2"/><rect x="112" y="66" width="26" height="30" rx="3" fill="none" stroke="#fff" stroke-opacity=".5"/>
<rect x="140" y="66" width="146" height="30" rx="5" fill="url(#st)" stroke="#4a525b" stroke-width="2"/><path d="M146 72H280" stroke="#fff" stroke-opacity=".7" stroke-width="3"/>
<path d="M284 66Q336 58 352 112Q326 90 292 96Z" fill="url(#st)" stroke="#4a525b" stroke-width="2"/><path d="M292 82Q322 78 338 98" stroke="#2f353c" stroke-width="3" fill="none"/></g>
<text x="200" y="285" text-anchor="middle" font-family="Arial" font-weight="700" font-size="11" fill="#14181f">{L}</text>';
        break;
    default:
        $body = '<rect x="96" y="96" width="208" height="150" rx="10" fill="#e9b949" stroke="#14181f" stroke-width="3"/><rect x="96" y="96" width="208" height="34" rx="10" fill="#ffc20e" stroke="#14181f" stroke-width="3"/><path d="M96 150H304M96 196H304" stroke="#14181f" stroke-opacity=".25" stroke-width="3"/><rect x="170" y="74" width="60" height="26" rx="13" fill="none" stroke="#14181f" stroke-width="5"/><rect x="176" y="138" width="48" height="22" rx="4" fill="#14181f"/><text x="200" y="278" text-anchor="middle" font-family="Arial" font-weight="700" font-size="11" fill="#14181f">{L}</text>';
    }
    return $svg . str_replace(['{L}', '{C}'], [$L, $color], $body) . '</svg>';
}
function pill_class(?string $s): string { return 's-' . strtolower(trim(preg_replace('/[^a-z]+/i', '-', (string)$s), '-')); }
function stock_badge(int $q): string {
    if ($q <= 0) return '<span class="badge out">Out of stock</span>';
    if ($q <= 10) return '<span class="badge low">' . icon('alert') . 'Low stock · ' . $q . ' left</span>';
    return '<span class="badge">' . $q . ' in stock</span>';
}

function empty_art(string $label='Nothing here yet'): string {
    $safe=e($label);
    return '<div class="empty-illustration"><svg viewBox="0 0 180 120" role="img" aria-label="'.$safe.'"><path d="M25 92h130" stroke="#c7c1b5" stroke-width="5" stroke-linecap="round"/><path d="M53 31h74l9 61H44z" fill="#f2d77b" stroke="#14181f" stroke-width="4"/><path d="M66 31q0-22 24-22t24 22" fill="none" stroke="#14181f" stroke-width="5"/><circle cx="75" cy="57" r="4" fill="#14181f"/><circle cx="105" cy="57" r="4" fill="#14181f"/><path d="M77 73q13 10 26 0" fill="none" stroke="#14181f" stroke-width="4" stroke-linecap="round"/><path d="M22 35l9-9m-9 0 9 9M146 19l8-8m-8 0 8 8" stroke="#e58b36" stroke-width="4" stroke-linecap="round"/></svg></div>';
}
