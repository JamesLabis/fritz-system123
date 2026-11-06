<?php
/*
 * Delivery address helpers: cleaning, validation against the province / city / barangay lists,
 * saved addresses, delivery zones and the "Open in Maps" link.
 * Loaded from includes/functions.php. Uses UserFacingException and field_attrs() from there.
 */
const HOUSE_MAX = 30; const PROVINCE_MAX = 60; const LANDMARK_MAX = 200; const LABEL_MAX = 40;
const DEFAULT_PROVINCE = 'Cebu'; const MAX_SAVED_ADDRESSES = 10;

/** Plain one-line text: invalid UTF-8 scrubbed, control / zero-width characters and line breaks turned into spaces, runs of spaces collapsed. */
function clean_text($value): string {
    $v = is_scalar($value) ? (string)$value : '';
    if (!mb_check_encoding($v, 'UTF-8')) $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
    $v = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}\x{00A0}]+/u', ' ', $v) ?? '';
    $v = preg_replace('/\s+/u', ' ', $v) ?? '';
    return trim($v);
}
/** Removes a leading "Brgy", "Brgy.", "Bgy", "Brg" or "Barangay" (with or without the dot) so we always add our own "Brgy." */
function strip_brgy_prefix(string $barangay): string {
    return trim(preg_replace('/^(?:barangay|brgy|bgy|brg)\b\.?\s*/iu', '', $barangay) ?? $barangay);
}
/** One-line address stored on the order, e.g. "12 Mabini St, Brgy. Lahug, Cebu City, Cebu". Every part is cleaned again here. */
function compose_address(string $street, string $barangay, string $city, string $province = '', string $house = ''): string {
    $line = trim(clean_text($house) . ' ' . clean_text($street));
    $barangay = strip_brgy_prefix(clean_text($barangay)); $city = clean_text($city); $province = clean_text($province);
    return implode(', ', array_filter([$line, $barangay !== '' ? 'Brgy. ' . $barangay : '', $city, $province], fn($p) => $p !== ''));
}
/** Google Maps search link for an order / saved address row (uses the structured parts, or the old one-line address). */
function maps_url(array $r): string {
    $parts = array_filter([trim(($r['delivery_house_no'] ?? $r['house_no'] ?? '') . ' ' . ($r['delivery_street'] ?? $r['street'] ?? '')), ($b = $r['delivery_barangay'] ?? $r['barangay'] ?? '') !== '' ? 'Barangay ' . $b : '',
        $r['delivery_city'] ?? $r['city'] ?? '', $r['delivery_province'] ?? $r['province'] ?? ''], fn($p) => trim((string)$p) !== '');
    $q = $parts ? implode(', ', $parts) : (string)($r['delivery_address'] ?? '');
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q . ', Philippines');
}

/**
 * Cleans and checks everything the customer typed for a delivery: recipient, address and landmark.
 * Returns ['values'=>cleaned fields (city / barangay / province in the stored spelling), 'address'=>one-line text,
 *          'error'=>['field','message']|null, 'city_id'=>int|null, 'zone'=>['zone_id','zone_name','fee']|null].
 * A province that has a city list (Cebu) needs a city from that list, and a barangay from the list when the city has one.
 */
function resolve_delivery_input(PDO $pdo, array $src): array {
    $v = [
        'recipient_name'  => clean_text($src['recipient_name'] ?? ''),
        'recipient_phone' => clean_text($src['recipient_phone'] ?? ''),
        'province'        => clean_text($src['province'] ?? ''),
        'city'            => clean_text(($src['city'] ?? '') !== '' ? $src['city'] : ($src['city_text'] ?? '')),
        'barangay'        => strip_brgy_prefix(clean_text(($src['barangay'] ?? '') !== '' ? $src['barangay'] : ($src['barangay_text'] ?? ''))),
        'house_no'        => clean_text($src['house_no'] ?? ''),
        'street'          => clean_text($src['street'] ?? ''),
        'landmark'        => clean_text($src['landmark'] ?? ''),
    ];
    $out = ['values' => $v, 'address' => '', 'error' => null, 'city_id' => null, 'zone' => null];
    $fail = function (string $field, string $message) use (&$out) { $out['error'] = ['field' => $field, 'message' => $message]; return $out; };

    if ($v['recipient_name'] === '') return $fail('recipient_name', 'Enter the name of the person who will receive the order.');
    if (mb_strlen($v['recipient_name']) > NAME_MAX) return $fail('recipient_name', 'Recipient name can be at most ' . NAME_MAX . ' characters.');
    if (!preg_match(PHONE_PATTERN, $v['recipient_phone'])) return $fail('recipient_phone', 'Enter a phone number for the recipient (7 to ' . PHONE_MAX . ' characters; numbers, spaces, + ( ) - only).');
    if ($v['province'] === '') return $fail('province', 'Select your province.');
    if (mb_strlen($v['province']) > PROVINCE_MAX) return $fail('province', 'Province can be at most ' . PROVINCE_MAX . ' characters.');
    $pm = null; foreach (all_provinces($pdo) as $pn) { if (strcasecmp($pn, $v['province']) === 0) { $pm = $pn; break; } }
    if ($pm === null) return $fail('province', 'Pick your province from the list.');
    $v['province'] = $pm;
    if ($v['city'] === '') return $fail('city', 'Select your city or municipality.');
    if (mb_strlen($v['city']) > CITY_MAX) return $fail('city', 'City / municipality can be at most ' . CITY_MAX . ' characters.');
    if ($v['barangay'] === '') return $fail('barangay', 'Select your barangay.');
    if (mb_strlen($v['barangay']) > BARANGAY_MAX) return $fail('barangay', 'Barangay can be at most ' . BARANGAY_MAX . ' characters.');
    if ($v['house_no'] === '' && $v['street'] === '') return $fail('house_no', 'Enter your house or unit number, or your street.');
    if (mb_strlen($v['house_no']) > HOUSE_MAX) return $fail('house_no', 'House / unit number can be at most ' . HOUSE_MAX . ' characters.');
    if (mb_strlen($v['street']) > STREET_MAX) return $fail('street', 'Street can be at most ' . STREET_MAX . ' characters.');
    if (mb_strlen($v['landmark']) > LANDMARK_MAX) return $fail('landmark', 'Landmark / delivery notes can be at most ' . LANDMARK_MAX . ' characters.');

    // Lists (the collation is accent- and case-insensitive, so "santo nino" finds "Santo Niño").
    $s = $pdo->prepare('SELECT province FROM cities WHERE province=? LIMIT 1');
    $s->execute([$v['province']]);
    $knownProvince = $s->fetchColumn();
    if ($knownProvince !== false) {
        $v['province'] = (string)$knownProvince;
        $s = $pdo->prepare('SELECT city_id,city,zone_id FROM cities WHERE province=? AND city IN (?,?) ORDER BY (city=?) DESC LIMIT 1');
        $s->execute([$v['province'], $v['city'], $v['city'] . ' City', $v['city']]);
        $city = $s->fetch();
        if (!$city) return $fail('city', 'We could not find "' . $v['city'] . '" in ' . $v['province'] . '. Pick your city or municipality from the list.');
        $v['city'] = (string)$city['city']; $out['city_id'] = (int)$city['city_id'];
        $s = $pdo->prepare('SELECT barangay FROM barangays WHERE city_id=? AND barangay=? LIMIT 1');
        $s->execute([$out['city_id'], $v['barangay']]);
        $b = $s->fetchColumn();
        if ($b !== false) {
            $v['barangay'] = (string)$b;
        } else {
            $n = $pdo->prepare('SELECT COUNT(*) FROM barangays WHERE city_id=?');
            $n->execute([$out['city_id']]);
            if ((int)$n->fetchColumn() > 0) return $fail('barangay', 'Pick your barangay in ' . $v['city'] . ' from the list. If it is missing, please contact the store so we can add it.');
        }
        if ($city['zone_id']) {
            $z = $pdo->prepare('SELECT zone_id,zone_name,fee FROM delivery_zones WHERE zone_id=? AND is_active=1');
            $z->execute([(int)$city['zone_id']]);
            $out['zone'] = $z->fetch() ?: null;
        }
    }
    $out['values'] = $v;
    $out['address'] = compose_address($v['street'], $v['barangay'], $v['city'], $v['province'], $v['house_no']);
    return $out;
}

/** Every selectable province: the full Philippine list from places_seed.php plus any province already in the cities table, A-Z. */
function all_provinces(PDO $pdo): array {
    static $list = null;
    if ($list !== null) return $list;
    $seed = require __DIR__ . '/../config/places_seed.php';
    $list = array_values(array_unique(array_merge($seed['provinces'] ?? [], array_keys(place_data($pdo)))));
    sort($list, SORT_NATURAL | SORT_FLAG_CASE);
    return $list;
}
/** Province -> city -> {barangays, zone fee} for the pickers. Fee is null where the standard delivery fee applies. */
function place_data(PDO $pdo): array {
    static $data = null;
    if ($data !== null) return $data;
    $data = [];
    $rows = $pdo->query('SELECT c.city_id,c.province,c.city,z.fee FROM cities c LEFT JOIN delivery_zones z ON z.zone_id=c.zone_id AND z.is_active=1 ORDER BY c.province,c.city')->fetchAll();
    $byId = [];
    foreach ($rows as $r) { $data[$r['province']][$r['city']] = ['b' => [], 'f' => $r['fee'] === null ? null : (float)$r['fee']]; $byId[(int)$r['city_id']] = [$r['province'], $r['city']]; }
    foreach ($pdo->query('SELECT city_id,barangay FROM barangays ORDER BY barangay')->fetchAll() as $b) {
        if (isset($byId[(int)$b['city_id']])) { [$p, $c] = $byId[(int)$b['city_id']]; $data[$p][$c]['b'][] = $b['barangay']; }
    }
    return $data;
}

/**
 * Recipient, address and landmark inputs. $v = current values, $idp = id prefix.
 * Province, city and barangay are dropdowns that fill each other (see place_picker_script()).
 * A city with no barangay list shows a text box for the barangay instead.
 */
function address_fields(array $v, string $errField, string $idp, PDO $pdo): string {
    $v += ['recipient_name' => '', 'recipient_phone' => '', 'province' => DEFAULT_PROVINCE, 'city' => '', 'barangay' => '', 'house_no' => '', 'street' => '', 'landmark' => ''];
    if ($v['province'] === '') $v['province'] = DEFAULT_PROVINCE;
    $in = function (string $k, string $label, int $max, string $ac, string $ph, string $extra = '', string $type = 'text', bool $req = true) use ($v, $errField, $idp): string {
        return '<label for="' . $idp . '-' . $k . '">' . e($label) . ($req ? ' <span class="req" aria-hidden="true">*</span>' : ' <span class="muted small">(optional)</span>') . '</label>'
            . '<input id="' . $idp . '-' . $k . '" type="' . $type . '"' . field_attrs($k, $errField) . ' name="' . $k . '" value="' . e((string)$v[$k]) . '"' . ($req ? ' required' : '')
            . ' maxlength="' . $max . '" autocomplete="' . $ac . '" placeholder="' . e($ph) . '"' . $extra . '>';
    };
    $sel = function (string $k, string $label, string $current, string $inner) use ($errField, $idp): string {
        return '<label for="' . $idp . '-' . $k . '">' . e($label) . ' <span class="req" aria-hidden="true">*</span></label>'
            . '<select id="' . $idp . '-' . $k . '"' . field_attrs($k, $errField) . ' name="' . $k . '" required data-value="' . e($current) . '">' . $inner . '</select>';
    };
    $provinces = all_provinces($pdo);
    $provOpts = '<option value="">Select province</option>';
    foreach ($provinces as $p) $provOpts .= '<option value="' . e($p) . '"' . (strcasecmp($p, $v['province']) === 0 ? ' selected' : '') . '>' . e($p) . '</option>';
    $o = '<div data-addr-group>';
    $o .= '<div class="form-row"><div>' . $in('recipient_name', 'Recipient name', NAME_MAX, 'off', 'Who will receive the order') . '</div>'
        . '<div>' . $in('recipient_phone', 'Recipient phone', PHONE_MAX, 'off', 'Phone the driver can call', ' pattern="[0-9+\(\)\-\s]{7,20}" title="7 to 20 characters: numbers, spaces, + ( ) -"', 'tel') . '</div></div>';
    $o .= $sel('province', 'Province', $v['province'], $provOpts);
    $o .= $sel('city', 'Town / city', $v['city'], '<option value="">Select province first</option>');
    $o .= '<div data-city-text hidden><label for="' . $idp . '-city_text">Town / city <span class="req" aria-hidden="true">*</span></label>'
        . '<input id="' . $idp . '-city_text"' . field_attrs('city', $errField) . ' name="city_text" value="' . e($v['city']) . '" maxlength="' . CITY_MAX . '" autocomplete="address-level2" placeholder="Type your town / city" disabled>'
        . '<p class="small muted">We do not have a town list for this province yet, so please type it.</p></div>';
    $o .= $sel('barangay', 'Barangay', $v['barangay'], '<option value="">Select town / city first</option>');
    $o .= '<div data-brgy-text hidden><label for="' . $idp . '-barangay_text">Barangay <span class="req" aria-hidden="true">*</span></label>'
        . '<input id="' . $idp . '-barangay_text"' . field_attrs('barangay', $errField) . ' name="barangay_text" value="' . e($v['barangay']) . '" maxlength="' . BARANGAY_MAX . '" autocomplete="address-level3" placeholder="Type your barangay" disabled>'
        . '<p class="small muted">We do not have a barangay list for this town yet, so please type it.</p></div>';
    $o .= '<div class="form-row"><div>' . $in('house_no', 'House / unit no.', HOUSE_MAX, 'address-line2', 'e.g. 12, Unit 3B', '', 'text', false) . '</div>'
        . '<div>' . $in('street', 'Street', STREET_MAX, 'address-line1', 'Street, purok or subdivision', '', 'text', false) . '</div></div>';
    $o .= '<p class="small muted">Give a house or unit number, a street, or both.</p>';
    $o .= $in('landmark', 'Landmark / delivery notes', LANDMARK_MAX, 'off', 'e.g. blue gate beside the sari-sari store; ask for the foreman', '', 'text', false);
    return $o . '</div>';
}
/** Print once per page, after the address fields: the list data plus the script that makes province, city and barangay dropdowns fill each other. */
function place_picker_script(PDO $pdo): string {
    $pd = place_data($pdo); foreach (all_provinces($pdo) as $pn) { if (!isset($pd[$pn])) $pd[$pn] = (object)[]; }
    $json = json_encode($pd, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<script type="application/json" id="place-data">' . $json . '</script><script>(function(){'
      . 'var D=JSON.parse(document.getElementById("place-data").textContent);'
      . 'function key(obj,val){val=(val||"").trim().toLowerCase();if(!val)return null;for(var k in obj){if(k.toLowerCase()===val)return k;}for(var k2 in obj){if(k2.toLowerCase()===val+" city")return k2;}return null;}'
      . 'function find(arr,val){val=(val||"").trim().toLowerCase();for(var i=0;i<arr.length;i++){if(arr[i].toLowerCase()===val||arr[i].toLowerCase()===val+" city")return arr[i];}return "";}'
      . 'function opts(sel,items,ph,val){sel.innerHTML="";var o=document.createElement("option");o.value="";o.textContent=ph;sel.appendChild(o);items.forEach(function(t){o=document.createElement("option");o.value=t;o.textContent=t;sel.appendChild(o);});sel.value=find(items,val);}'
      . 'document.querySelectorAll("[data-addr-group]").forEach(function(g){'
      . 'var p=g.querySelector("[name=province]"),c=g.querySelector("[name=city]"),b=g.querySelector("[name=barangay]"),bt=g.querySelector("[name=barangay_text]"),bw=g.querySelector("[data-brgy-text]"),ct=g.querySelector("[name=city_text]"),cw=g.querySelector("[data-city-text]"),cl=g.querySelector("label[for="+c.id+"]");'
      . 'function cities(cv){var pk=key(D,p.value),cs=pk?D[pk]:null,has=!!cs&&Object.keys(cs).length>0;'
      . 'if(pk&&!has){c.hidden=true;c.disabled=true;c.required=false;cl.hidden=true;cw.hidden=false;ct.disabled=false;ct.required=true;ct.value=cv||"";opts(c,[],"","");}'
      . 'else{c.hidden=false;c.disabled=false;c.required=true;cl.hidden=false;cw.hidden=true;ct.disabled=true;ct.required=false;ct.value="";opts(c,has?Object.keys(cs):[],has?"Select town / city":"Select province first",cv);}}'
      . 'function brgys(bv){var pk=key(D,p.value),cs=pk?D[pk]:{},ck=key(cs,c.value),list=ck?cs[ck].b:[];'
      . 'if((ck&&!list.length)||!ct.disabled){b.hidden=true;b.disabled=true;b.required=false;g.querySelector("label[for="+b.id+"]").hidden=true;bw.hidden=false;bt.disabled=false;bt.required=true;if(bv!==undefined&&bv!==null)bt.value=bv;}'
      . 'else{b.hidden=false;b.disabled=false;b.required=true;g.querySelector("label[for="+b.id+"]").hidden=false;bw.hidden=true;bt.disabled=true;bt.required=false;opts(b,list,ck?"Select barangay":"Select town / city first",bv);}'
      . 'g.dispatchEvent(new CustomEvent("placechange",{bubbles:true,detail:{city:ck,fee:ck?cs[ck].f:null}}));}'
      . 'p.addEventListener("change",function(){cities("");brgys("");});c.addEventListener("change",function(){brgys("");});'
      . 'g.setPlace=function(d){p.value=find(Object.keys(D),d.province)||"";cities(d.city);brgys(d.barangay);};'
      . 'cities(c.dataset.value);brgys(b.dataset.value);});})();</script>';
}

/** Recipient, address, landmark and "Open in Maps" for an order row ($r needs the orders columns; $accName / $accPhone are the fallback for old orders). */
function delivery_block_html(array $r, string $accName = '', string $accPhone = '', bool $maps = true): string {
    $name = trim((string)($r['recipient_name'] ?? '')) !== '' ? $r['recipient_name'] : $accName;
    $phone = trim((string)($r['recipient_phone'] ?? '')) !== '' ? $r['recipient_phone'] : $accPhone;
    $h = '';
    if ($name !== '' || $phone !== '') $h .= '<div><b>' . e($name) . '</b>' . ($phone !== '' ? ' · <a href="tel:' . e(preg_replace('/[^0-9+]/', '', $phone)) . '">' . e($phone) . '</a>' : '') . '</div>';
    $h .= '<div>' . e((string)($r['delivery_address'] ?? '')) . '</div>';
    if (trim((string)($r['delivery_landmark'] ?? '')) !== '') $h .= '<div class="muted"><b>Landmark / notes:</b> ' . e($r['delivery_landmark']) . '</div>';
    if ($maps) $h .= '<div><a href="' . e(maps_url($r)) . '" target="_blank" rel="noopener noreferrer">Open in Maps ↗</a></div>';
    return $h;
}

/* ---------- Saved addresses ---------- */
function user_addresses(PDO $pdo, int $userId): array {
    $s = $pdo->prepare('SELECT * FROM user_addresses WHERE user_id=? ORDER BY is_default DESC, address_id DESC');
    $s->execute([$userId]);
    return $s->fetchAll();
}
/** Adds a saved address (or updates $addressId). The same address saved twice is stored once. Returns the address id. */
function save_user_address(PDO $pdo, int $userId, array $v, string $label, bool $makeDefault = false, int $addressId = 0): int {
    $label = mb_substr(clean_text($label), 0, LABEL_MAX);
    return run_in_transaction($pdo, function () use ($pdo, $userId, $v, $label, $makeDefault, $addressId) {
        $cols = [$v['recipient_name'], $v['recipient_phone'], $v['province'], $v['city'], $v['barangay'], $v['house_no'] !== '' ? $v['house_no'] : null, $v['street'], $v['landmark'] !== '' ? $v['landmark'] : null];
        $have = $pdo->prepare('SELECT COUNT(*) FROM user_addresses WHERE user_id=?'); $have->execute([$userId]); $count = (int)$have->fetchColumn();
        if ($addressId) {
            $own = $pdo->prepare('SELECT address_id FROM user_addresses WHERE address_id=? AND user_id=? FOR UPDATE'); $own->execute([$addressId, $userId]);
            if (!$own->fetchColumn()) throw new UserFacingException('That saved address was not found.');
            $pdo->prepare('UPDATE user_addresses SET label=?,recipient_name=?,recipient_phone=?,province=?,city=?,barangay=?,house_no=?,street=?,landmark=? WHERE address_id=?')->execute(array_merge([$label !== '' ? $label : null], $cols, [$addressId]));
            $id = $addressId;
        } else {
            $dupe = $pdo->prepare('SELECT address_id FROM user_addresses WHERE user_id=? AND province=? AND city=? AND barangay=? AND COALESCE(house_no,\'\')=? AND street=? AND recipient_name=? AND recipient_phone=? AND COALESCE(landmark,\'\')=? LIMIT 1');
            $dupe->execute([$userId, $v['province'], $v['city'], $v['barangay'], $v['house_no'], $v['street'], $v['recipient_name'], $v['recipient_phone'], $v['landmark']]);
            $existing = $dupe->fetchColumn();
            if ($existing) return (int)$existing;
            if ($count >= MAX_SAVED_ADDRESSES) throw new UserFacingException('You can save up to ' . MAX_SAVED_ADDRESSES . ' addresses. Delete one first.');
            $pdo->prepare('INSERT INTO user_addresses(user_id,label,recipient_name,recipient_phone,province,city,barangay,house_no,street,landmark) VALUES(?,?,?,?,?,?,?,?,?,?)')
                ->execute(array_merge([$userId, $label !== '' ? $label : null], $cols));
            $id = (int)$pdo->lastInsertId();
        }
        if ($makeDefault || $count === 0) set_default_address($pdo, $userId, $id);
        return $id;
    });
}
function set_default_address(PDO $pdo, int $userId, int $addressId): void {
    $pdo->prepare('UPDATE user_addresses SET is_default=(address_id=?) WHERE user_id=?')->execute([$addressId, $userId]);
}
function delete_user_address(PDO $pdo, int $userId, int $addressId): void {
    run_in_transaction($pdo, function () use ($pdo, $userId, $addressId) {
        $pdo->prepare('DELETE FROM user_addresses WHERE address_id=? AND user_id=?')->execute([$addressId, $userId]);
        $left = $pdo->prepare('SELECT address_id FROM user_addresses WHERE user_id=? ORDER BY is_default DESC, address_id DESC LIMIT 1');
        $left->execute([$userId]);
        $next = $left->fetchColumn();
        if ($next) $pdo->prepare('UPDATE user_addresses SET is_default=(address_id=?) WHERE user_id=?')->execute([(int)$next, $userId]);
    });
}

/* ---------- Admin: fix the address on an order ---------- */
/**
 * Correct the delivery address of an order that is not finished (for example after a failed delivery because of a wrong address).
 * The delivery fee and order total are NOT changed; if the new city is in another zone the result says so, so staff can decide.
 * Returns ['zone_changed'=>bool,'old_zone'=>?string,'new_zone'=>?string].
 */
function update_order_address(PDO $pdo, int $orderId, array $place, string $reason): array {
    $reason = clean_text($reason);
    if ($reason === '') throw new UserFacingException('Enter why the address is being changed (it is saved in the order history).');
    if (mb_strlen($reason) > 150) throw new UserFacingException('The reason can be at most 150 characters.');
    if ($place['error'] !== null) throw new UserFacingException($place['error']['message']);
    $v = $place['values'];
    return run_in_transaction($pdo, function () use ($pdo, $orderId, $place, $v, $reason) {
        $s = $pdo->prepare('SELECT order_status,user_id,delivery_address,delivery_zone FROM orders WHERE order_id=? FOR UPDATE');
        $s->execute([$orderId]);
        $o = $s->fetch();
        if (!$o) throw new UserFacingException('Order not found.');
        if (in_array($o['order_status'], ['Completed', 'Cancelled'], true)) throw new UserFacingException('The address of a ' . $o['order_status'] . ' order cannot be changed.');
        $open = open_delivery($pdo, $orderId);
        if ($open && $open['status'] === 'Out for Delivery') throw new UserFacingException('The driver is already on the way. Mark the delivery as failed first (or tell the driver), then correct the address.');
        $newZone = $place['zone']['zone_name'] ?? null;
        $pdo->prepare('UPDATE orders SET delivery_address=?,delivery_province=?,delivery_city=?,delivery_barangay=?,delivery_house_no=?,delivery_street=?,delivery_landmark=?,recipient_name=?,recipient_phone=?,delivery_zone=? WHERE order_id=?')
            ->execute([$place['address'], $v['province'], $v['city'], $v['barangay'], $v['house_no'] !== '' ? $v['house_no'] : null, $v['street'], $v['landmark'] !== '' ? $v['landmark'] : null, $v['recipient_name'], $v['recipient_phone'], $newZone, $orderId]);
        $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
        $note = 'Delivery address corrected (' . $reason . '). Was: ' . $o['delivery_address'] . ' | Now: ' . $place['address'];
        $pdo->prepare('INSERT INTO order_status_history(order_id,admin_id,status,remarks) VALUES(?,?,?,?)')->execute([$orderId, $adminId, $o['order_status'], $note]);
        notify_customer($pdo, (int)$o['user_id'], $orderId, 'Delivery address updated', 'The delivery address for order #' . $orderId . ' is now: ' . $place['address'] . '.');
        log_activity($pdo, 'order_address', 'order', $orderId, $reason . ' → ' . $place['address']);
        return ['zone_changed' => ($o['delivery_zone'] ?? null) !== $newZone, 'old_zone' => $o['delivery_zone'], 'new_zone' => $newZone];
    });
}
