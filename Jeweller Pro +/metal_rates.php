<?php
// MAA GOURI-jewellers/metal_rates.php
// Live Indian Metal Rates - Multi-source with fast cURL & robust backup APIs

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Cache file (stores last successful fetch for 30 min)
$cache_file = sys_get_temp_dir() . '/radhe_shyam_metal_rates.json';
$cache_ttl  = 1800; // 30 minutes

// Return cached if fresh
if(file_exists($cache_file)) {
    $cached = json_decode(file_get_contents($cache_file), true);
    if($cached && isset($cached['ts']) && (time() - $cached['ts']) < $cache_ttl) {
        $cached['cached'] = true;
        $cached['updated'] = date('d M Y, h:i A', $cached['ts']);
        echo json_encode($cached);
        exit();
    }
}

// Fast cURL helper with SSL bypass for Windows local environments
function curl_get_url($url, $timeout = 2) {
    if(!function_exists('curl_init')) {
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
        return @file_get_contents($url, false, $ctx);
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

// USD/INR rate fetch
function getUsdInr() {
    $res = curl_get_url('https://api.exchangerate-api.com/v4/latest/USD', 1.5);
    if($res) {
        $d = json_decode($res, true);
        if(isset($d['rates']['INR']) && $d['rates']['INR'] > 70) {
            return (float)$d['rates']['INR'];
        }
    }
    return 96.26;
}

// Source 1: FawazAhmed Currency API (Free, CDN, extremely fast, no key)
function fetchFromFawazAhmed() {
    $resXau = curl_get_url('https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/xau.json', 2);
    $resXag = curl_get_url('https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/xag.json', 2);
    if(!$resXau) return null;
    
    $dXau = json_decode($resXau, true);
    $dXag = json_decode($resXag, true);
    
    if(!isset($dXau['xau']['inr'])) return null;
    
    $inr_per_oz_gold = (float)$dXau['xau']['inr'];
    $inr_per_oz_silver = isset($dXag['xag']['inr']) ? (float)$dXag['xag']['inr'] : ($inr_per_oz_gold / 80);
    
    $gold24_10g = round(($inr_per_oz_gold / 3.11035) * 1.155);
    $gold22_10g = round($gold24_10g * 0.9167);
    $gold18_10g = round($gold24_10g * 0.7500);
    $gold12_10g = round($gold24_10g * 0.5000);
    $gold9_10g  = round($gold24_10g * 0.3750);
    $silver_10g = round(($inr_per_oz_silver / 3.11035) * 1.27);
    $plat_10g   = round($gold24_10g * 0.367);
    
    if($gold24_10g < 50000 || $gold24_10g > 350000) return null;
    
    return [
        'success'  => true,
        'source'   => 'FawazAhmed Open API',
        'fallback' => false,
        'gold24'   => $gold24_10g,
        'gold22'   => $gold22_10g,
        'gold18'   => $gold18_10g,
        'gold12'   => $gold12_10g,
        'gold9'    => $gold9_10g,
        'silver'   => $silver_10g,
        'platinum' => $plat_10g,
    ];
}

// Source 2: api.gold-api.com
function fetchViaForexAndSpot($usdInr) {
    $g = curl_get_url('https://api.gold-api.com/price/XAU', 2);
    $s = curl_get_url('https://api.gold-api.com/price/XAG', 2);
    $p = curl_get_url('https://api.gold-api.com/price/XPT', 2);
    
    if(!$g || !$s) return null;
    
    $gd = json_decode($g, true);
    $sd = json_decode($s, true);
    $pd = json_decode($p, true);
    
    if(!isset($gd['price']) || !isset($sd['price'])) return null;
    
    $prices = [
        'gold' => (float)$gd['price'],
        'silver' => (float)$sd['price'],
        'platinum' => isset($pd['price']) ? (float)$pd['price'] : null
    ];
    
    $gold24_10g = round((($prices['gold']   * $usdInr) / 3.11035) * 1.155);
    $silver_10g = round((($prices['silver'] * $usdInr) / 3.11035) * 1.27);
    $plat_10g   = isset($prices['platinum']) ? round((($prices['platinum'] * $usdInr) / 3.11035) * 1.05) : round($gold24_10g * 0.367);
    $gold22_10g = round($gold24_10g * 0.9167);
    $gold18_10g = round($gold24_10g * 0.7500);
    $gold12_10g = round($gold24_10g * 0.5000);
    $gold9_10g  = round($gold24_10g * 0.3750);
    
    if($gold24_10g < 50000 || $gold24_10g > 350000) return null;
    
    return [
        'success'  => true,
        'source'   => 'api.gold-api.com',
        'fallback' => false,
        'gold24'   => $gold24_10g,
        'gold22'   => $gold22_10g,
        'gold18'   => $gold18_10g,
        'gold12'   => $gold12_10g,
        'gold9'    => $gold9_10g,
        'silver'   => $silver_10g,
        'platinum' => $plat_10g,
    ];
}

// Source 3: CoinGecko PaxGold Physical Gold API
function fetchFromCoinGecko() {
    $res = curl_get_url('https://api.coingecko.com/api/v3/simple/price?ids=pax-gold&vs_currencies=inr', 2);
    if(!$res) return null;
    $d = json_decode($res, true);
    if(!isset($d['pax-gold']['inr'])) return null;
    
    $inr_per_oz_gold = (float)$d['pax-gold']['inr'];
    $gold24_10g = round(($inr_per_oz_gold / 3.11035) * 1.155);
    $gold22_10g = round($gold24_10g * 0.9167);
    $gold18_10g = round($gold24_10g * 0.7500);
    $gold12_10g = round($gold24_10g * 0.5000);
    $gold9_10g  = round($gold24_10g * 0.3750);
    $silver_10g = round($gold24_10g * 0.016);
    $plat_10g   = round($gold24_10g * 0.367);
    
    if($gold24_10g < 50000 || $gold24_10g > 350000) return null;
    
    return [
        'success'  => true,
        'source'   => 'CoinGecko Physical Gold API',
        'fallback' => false,
        'gold24'   => $gold24_10g,
        'gold22'   => $gold22_10g,
        'gold18'   => $gold18_10g,
        'gold12'   => $gold12_10g,
        'gold9'    => $gold9_10g,
        'silver'   => $silver_10g,
        'platinum' => $plat_10g,
    ];
}

// Accurate Fallback
function getAccurateFallback() {
    return [
        'success'  => true,
        'source'   => 'Live Indian Market (Fallback)',
        'fallback' => true,
        'gold24'   => 159605,   // ₹1,59,605 per 10g
        'gold22'   => 146310,   // ₹1,46,310 per 10g
        'gold18'   => 119704,   // ₹1,19,704 per 10g
        'gold12'   => 79803,    // ₹79,803 per 10g
        'gold9'    => 59852,    // ₹59,852 per 10g
        'silver'   => 2573,     // ₹2,573 per 10g
        'platinum' => 58550,    // ₹58,550 per 10g
    ];
}

// Try sources in order
$usdInr = getUsdInr();
$result = fetchFromFawazAhmed();

if(!$result) {
    $result = fetchViaForexAndSpot($usdInr);
}
if(!$result) {
    $result = fetchFromCoinGecko();
}
if(!$result) {
    $result = getAccurateFallback();
}

$result['ts']      = time();
$result['updated'] = date('d M Y, h:i A');
$result['cached']  = false;
$result['usd_inr'] = $usdInr;

if(!$result['fallback']) {
    @file_put_contents($cache_file, json_encode($result));
}

echo json_encode($result);
?>


