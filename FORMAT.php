<?php
// ==========================================
// 1. CONFIGURATION & DATA FETCHING
// ==========================================

// Manual Holiday List (Format: YYYY-MM-DD)
$holidays = [
    '2026-01-30', // Specific holiday adjustment
    '2026-01-26', 
    '2026-03-25', 
    '2026-04-14', 
    '2026-08-15', 
    '2026-10-02', 
    '2026-12-25'
];

function get_live_price($symbol) {
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/" . $symbol . "?interval=1d&range=1d";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $result = curl_exec($ch);
    if (curl_errno($ch)) return 0;
    curl_close($ch);
    $data = json_decode($result, true);
    return $data['chart']['result'][0]['meta']['regularMarketPrice'] ?? 0;
}

$spot_prices = [
    'N' => get_live_price('^NSEI'),
    'B' => get_live_price('^NSEBANK'),
    'S' => get_live_price('^BSESN')
];

// ==========================================
// 2. SMART EXPIRY LOGIC (Holiday Aware)
// ==========================================

function check_holiday_adjustment($date_obj, $holidays) {
    while (in_array($date_obj->format('Y-m-d'), $holidays)) {
        $date_obj->modify('-1 day');
    }
    return $date_obj;
}

function get_expiry_details($trade_date_str, $index_code, $holidays) {
    try {
        $trade_date = new DateTime($trade_date_str);
        $year = $trade_date->format('Y');
        $month = $trade_date->format('m');
        
        if ($index_code == 'B') { // BANK NIFTY (Monthly)
            $last_day = new DateTime("last tuesday of $year-$month");
            if ($trade_date > $last_day) {
                $trade_date->modify('first day of next month');
                $next_month = $trade_date->format('F Y');
                $expiry_date = new DateTime("last tuesday of $next_month");
            } else {
                $expiry_date = $last_day;
            }
        } 
        elseif ($index_code == 'N') { // NIFTY (Tue)
            $target_day = 2; 
            $current_day = (int)$trade_date->format('N');
            if ($current_day == $target_day) { $days_to_add = 0; }
            elseif ($current_day < $target_day) { $days_to_add = $target_day - $current_day; }
            else { $days_to_add = 7 - ($current_day - $target_day); }
            $expiry_date = clone $trade_date;
            $expiry_date->modify("+$days_to_add days");
        }
        elseif ($index_code == 'S') { // SENSEX (Fri)
            $target_day = 5; 
            $current_day = (int)$trade_date->format('N');
            if ($current_day == $target_day) { $days_to_add = 0; }
            elseif ($current_day < $target_day) { $days_to_add = $target_day - $current_day; }
            else { $days_to_add = 7 - ($current_day - $target_day); }
            $expiry_date = clone $trade_date;
            $expiry_date->modify("+$days_to_add days");
        } 
        else {
            return ["ymd" => "000000", "display" => "N/A"];
        }

        $expiry_date = check_holiday_adjustment($expiry_date, $holidays);

        return [
            "ymd" => $expiry_date->format('ymd'),
            "display" => $expiry_date->format('d M')
        ];

    } catch (Exception $e) {
        return ["ymd" => "000000", "display" => "ERR"];
    }
}

$today_str = date('Y-m-d');
$next_exp_n = get_expiry_details($today_str, 'N', $holidays)['display'];
$next_exp_b = get_expiry_details($today_str, 'B', $holidays)['display'];
$next_exp_s = get_expiry_details($today_str, 'S', $holidays)['display'];

// ==========================================
// 3. MAIN PROCESSOR
// ==========================================

function calculate_full_strike($spot_price, $suffix) {
    $suffix_len = strlen($suffix);
    $divisor = pow(10, $suffix_len);
    $prefix_guess = floor($spot_price / $divisor);
    $candidates = [
        ($prefix_guess - 1) * $divisor + $suffix,
        ($prefix_guess) * $divisor + $suffix,
        ($prefix_guess + 1) * $divisor + $suffix
    ];
    $closest = $candidates[0];
    foreach ($candidates as $c) {
        if (abs($spot_price - $c) < abs($spot_price - $closest)) {
            $closest = $c;
        }
    }
    return $closest;
}

$trades = [];

if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($_POST['raw_data'])) {
    $raw_data = trim($_POST['raw_data']);
    $lines = explode("\n", $raw_data);
    $lines = array_map('trim', $lines);
    $lines = array_filter($lines);
    $lines = array_values($lines);

    for ($i = 0; $i < count($lines); $i += 4) {
        if (!isset($lines[$i+3])) break;

        $trade = [];

        // LINE 1: Date & Entry
        $parts = preg_split('/\s+/', $lines[$i]);
        $trade['date'] = $parts[0]; 
        $raw_price_str = $parts[1];

        if (preg_match('/([cpCP])(\d+)|(\d+)([cpCP])/', $raw_price_str, $matches)) {
            $type_char = !empty($matches[1]) ? $matches[1] : $matches[4];
            $trade['entry'] = !empty($matches[2]) ? $matches[2] : $matches[3];
            $trade['type_short'] = strtoupper($type_char); 
            $trade['type_display'] = $trade['type_short'] . "E"; 
        }

        // LINE 2 & 3: SL & Target
        $trade['sl_points'] = (int) filter_var($lines[$i+1], FILTER_SANITIZE_NUMBER_INT);
        $trade['sl_price'] = $trade['entry'] - $trade['sl_points'];
        $trade['target_price'] = (int) filter_var($lines[$i+2], FILTER_SANITIZE_NUMBER_INT);
        $trade['target_points'] = $trade['target_price'] - $trade['entry'];

        // LINE 4: Strike & Index
        $parts_l4 = preg_split('/\s+/', $lines[$i+3]);
        $strike_suffix = $parts_l4[0];
        $index_code = strtoupper($parts_l4[1]);
        
        // Maps
        $index_display_map = ['N' => 'NIFTY', 'B' => 'BANKNIFTY', 'S' => 'SENSEX'];
        $trade['index_name'] = $index_display_map[$index_code] ?? 'UNKNOWN';
        $trade['raw_code'] = $index_code; // Store raw code for CSS coloring
        
        $index_symbol_map = ['N' => 'NIFTY', 'B' => 'BANKNIFTY', 'S' => 'BSX'];
        $symbol_prefix = $index_symbol_map[$index_code] ?? 'UNKNOWN';

        // --- CALCULATE ---
        $current_spot = $spot_prices[$index_code] ?? 0;
        
        if ($current_spot > 0) {
            $full_strike = calculate_full_strike($current_spot, $strike_suffix);
            $trade['strike_full'] = $full_strike;
            $expiry_data = get_expiry_details($trade['date'], $index_code, $holidays);
            $expiry_ymd = $expiry_data['ymd'];
            $trade['script_name'] = $symbol_prefix . $expiry_ymd . $trade['type_short'] . $full_strike;
        } else {
            $trade['strike_full'] = "Error";
            $trade['script_name'] = "Error";
        }

        $trades[] = $trade;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trade Decoder Ultimate</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; padding: 20px; background: #f4f7f6; }
        .container { max-width: 1200px; margin: 0 auto; }
        .status-bar { background: #fff; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); text-align: center; }
        .status-box { background: #f8f9fa; padding: 10px; border-radius: 6px; border: 1px solid #e9ecef; }
        .status-box h4 { margin: 0 0 5px 0; font-size: 0.9rem; color: #6c757d; }
        .price { font-size: 1.2rem; font-weight: bold; color: #2d3436; }
        .expiry { font-size: 0.8rem; color: #007bff; margin-top: 4px; font-weight: 500; }
        
        textarea { width: 100%; height: 120px; padding: 12px; border: 1px solid #ddd; border-radius: 8px; font-family: monospace; }
        button { background-color: #00b894; color: white; padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; margin-top: 10px; width: 100%; }
        button:hover { background-color: #00a884; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 25px; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        th, td { padding: 12px 8px; text-align: center; border-bottom: 1px solid #eee; font-size: 0.95rem; }
        th { background-color: #2d3436; color: white; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 0.5px; }
        
        /* --- COLOR CODED ROWS --- */
        .row-nifty { background-color: #FF75C8; } /* Light Green/Teal for Nifty */
        .row-bank { background-color: #78E9FA; }  /* Light Blue for Bank Nifty */
        .row-sensex { background-color: #DEF514; } /* Light Orange/Yellow for Sensex */
        .row-unknown { background-color: #FC8130; }

        .symbol-col { font-weight: bold; color: #c0392b; font-family: monospace; background-color: rgba(255, 255, 255, 0.6); border: 1px solid rgba(0,0,0,0.05); user-select: all; }
        .pts-col { color: #636e72; font-style: italic; }
        
        /* Hover Effect to darken the row slightly */
        tr:hover td { filter: brightness(95%); cursor: default; }
    </style>
</head>
<body>

<div class="container">
    <div class="status-bar">
        <div class="status-box">
            <h4>NIFTY</h4>
            <div class="price"><?php echo number_format($spot_prices['N'], 2); ?></div>
            <div class="expiry">Exp: <?php echo $next_exp_n; ?></div>
        </div>
        <div class="status-box">
            <h4>BANK NIFTY</h4>
            <div class="price"><?php echo number_format($spot_prices['B'], 2); ?></div>
            <div class="expiry">Exp: <?php echo $next_exp_b; ?></div>
        </div>
        <div class="status-box">
            <h4>SENSEX</h4>
            <div class="price"><?php echo number_format($spot_prices['S'], 2); ?></div>
            <div class="expiry">Exp: <?php echo $next_exp_s; ?></div>
        </div>
    </div>

    <form method="POST">
        <textarea name="raw_data" placeholder="Paste data here..."><?php echo isset($_POST['raw_data']) ? htmlspecialchars($_POST['raw_data']) : ''; ?></textarea>
        <button type="submit">Decode Data</button>
    </form>

    <?php if (!empty($trades)): ?>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Index Name</th>
                <th>Strike</th>
                <th>Type</th>
                <th>Script Name</th>
                <th>Entry</th>
                <th>SL Pts</th>
                <th>Stop Loss</th>
                <th>Tgt Pts</th>
                <th>Target</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($trades as $t): 
                // Determine Row Class based on Index Code
                $row_class = 'row-unknown';
                if ($t['raw_code'] == 'N') $row_class = 'row-nifty';
                if ($t['raw_code'] == 'B') $row_class = 'row-bank';
                if ($t['raw_code'] == 'S') $row_class = 'row-sensex';
            ?>
            <tr class="<?php echo $row_class; ?>">
                <td><?php echo $t['date']; ?></td>
                <td><?php echo $t['index_name']; ?></td>
                <td><?php echo $t['strike_full']; ?></td>
                <td><?php echo $t['type_display']; ?></td>
                <td class="symbol-col"><?php echo $t['script_name']; ?></td>
                <td><?php echo $t['entry']; ?></td>
                <td class="pts-col"><?php echo $t['sl_points']; ?></td>
                <td style="color:red; font-weight:500;"><?php echo $t['sl_price']; ?></td>
                <td class="pts-col"><?php echo $t['target_points']; ?></td>
                <td style="color:green; font-weight:bold;"><?php echo $t['target_price']; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

</body>
</html>