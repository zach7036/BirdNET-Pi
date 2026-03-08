<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'scripts/common.php';

$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

// 1. Lifetime Species
$lifetime_species = $db->querySingle('SELECT COUNT(DISTINCT(Sci_Name)) FROM detections') ?: 0;

// 2. Best Day Count
$best_day_res = $db->query('SELECT Date, COUNT(*) as cnt FROM detections GROUP BY Date ORDER BY cnt DESC LIMIT 1');
$best_day_row = $best_day_res ? $best_day_res->fetchArray(SQLITE3_ASSOC) : false;
$best_day_count = $best_day_row ? $best_day_row['cnt'] : 0;
$best_day_date = $best_day_row ? date('M j, Y', strtotime($best_day_row['Date'])) : 'N/A';

// 3. Longest Streak (Consecutive Days with any detection)
$streak_res = $db->query('SELECT Date FROM detections GROUP BY Date ORDER BY Date ASC');
$dates = [];
if ($streak_res) {
    while($row = $streak_res->fetchArray(SQLITE3_ASSOC)) {
        $dates[] = $row['Date'];
    }
}

$max_streak = 0;
$current_streak = 0;
$prev_date = null;

foreach ($dates as $date_str) {
    if ($prev_date === null) {
        $current_streak = 1;
    } else {
        $diff = (strtotime($date_str) - strtotime($prev_date)) / 86400;
        if ($diff == 1) {
            $current_streak++;
        } else {
            $max_streak = max($max_streak, $current_streak);
            $current_streak = 1;
        }
    }
    $prev_date = $date_str;
}
$max_streak = max($max_streak, $current_streak);

// 4. Rare Species (Detected < 5 times ever)
$rarest = [];
$rare_res = $db->query('SELECT Com_Name, Sci_Name, COUNT(*) as cnt, MIN(Date) as first_seen, MAX(Date) as last_seen FROM detections GROUP BY Sci_Name HAVING cnt < 5 ORDER BY cnt ASC, last_seen DESC LIMIT 10');
if ($rare_res) {
    while($row = $rare_res->fetchArray(SQLITE3_ASSOC)) {
        $rarest[] = $row;
    }
}
$rare_total = $db->querySingle('SELECT COUNT(*) FROM (SELECT Sci_Name FROM detections GROUP BY Sci_Name HAVING COUNT(*) < 5)') ?: 0;

// 5. Personal Milestones
$milestones = [];
$total_detections = $db->querySingle('SELECT COUNT(*) FROM detections') ?: 0;
$first_det = $db->querySingle('SELECT MIN(Date) FROM detections');
$milestones[] = ["title" => "First Detection", "val" => $first_det ?: 'N/A'];
$milestones[] = ["title" => "Lifetime Detections", "val" => number_format($total_detections)];

// Top Daily Record for a Single Species
$top_spec_res = $db->query('SELECT Com_Name, Date, COUNT(*) as cnt FROM detections GROUP BY Sci_Name, Date ORDER BY cnt DESC LIMIT 1');
$top_spec_day = $top_spec_res ? $top_spec_res->fetchArray(SQLITE3_ASSOC) : false;
if ($top_spec_day) {
    $milestones[] = ["title" => "Single Day Record", "val" => $top_spec_day['cnt'] . " " . $top_spec_day['Com_Name'] . " on " . date('M j, Y', strtotime($top_spec_day['Date']))];
}

$db->close();
?>

<style>
    .insights-container {
        padding: 20px;
        max-width: 1200px;
        margin: 0 auto;
        color: var(--text-primary);
    }
    .insights-header {
        text-align: center;
        margin-bottom: 30px;
        background: var(--bg-card);
        padding: 30px;
        border-radius: 20px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow-md);
    }
    .insights-header h1 {
        margin: 0;
        font-size: 2.2em;
        background: linear-gradient(135deg, var(--accent) 0%, #6366f1 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    .insights-subtitle {
        color: var(--text-secondary);
        font-size: 1.1em;
        margin-top: 10px;
    }
    .insights-kpi-cards {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        margin-bottom: 40px;
        width: 100%;
    }
    .insights-kpi-card {
        background: var(--bg-card);
        padding: 24px 15px;
        border-radius: 16px;
        border: 1px solid var(--border);
        text-align: center;
        box-shadow: var(--shadow-sm);
        transition: transform 0.2s;
        flex: 1 1 180px;
        min-width: 180px;
    }
    .insights-kpi-card:hover { transform: translateY(-5px); }
    .insights-kpi-val { font-size: 2em; font-weight: 800; display: block; margin-bottom: 4px; white-space: nowrap; }
    .insights-kpi-label { font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); }

    .insights-sections-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
        gap: 30px;
    }
    .insights-section {
        background: var(--bg-card);
        border-radius: 20px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }
    .insights-section-title {
        background: var(--bg-primary);
        padding: 15px 20px;
        font-weight: bold;
        border-bottom: 1px solid var(--border);
        font-size: 1.1em;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .insights-stats-list { display: flex; flex-direction: column; gap: 8px; padding: 15px; }
    .insights-stats-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 15px;
        background: var(--bg-primary);
        border-radius: 12px;
        border: 1px solid var(--border-light);
    }
    .insights-stats-name { font-weight: 600; color: var(--text-heading); }
    .insights-stats-count { font-weight: 800; color: var(--accent); }
</style>

<div class="insights-container">
    <header class="insights-header">
        <h1>BirdNET Insights</h1>
        <div class="insights-subtitle">Deep behavioral analysis and seasonal trends for your station.</div>
    </header>

    <div class="insights-kpi-cards">
        <div class="insights-kpi-card">
            <span class="insights-kpi-val"><?php echo number_format($lifetime_species); ?></span>
            <span class="insights-kpi-label">Lifetime Species</span>
        </div>
        <div class="insights-kpi-card">
            <span class="insights-kpi-val"><?php echo number_format($best_day_count); ?></span>
            <span class="insights-kpi-label">Best Day (<?php echo $best_day_date; ?>)</span>
        </div>
        <div class="insights-kpi-card">
            <span class="insights-kpi-val"><?php echo $max_streak; ?> Days</span>
            <span class="insights-kpi-label">Longest Streak</span>
        </div>
        <div class="insights-kpi-card">
            <span class="insights-kpi-val"><?php echo number_format($rare_total); ?></span>
            <span class="insights-kpi-label">Rare Species</span>
        </div>
    </div>

    <div class="insights-sections-grid">
        <section class="insights-section">
            <div class="insights-section-title">🏆 Personal Records & Milestones</div>
            <div class="insights-stats-list">
                <?php foreach($milestones as $m): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name"><?php echo $m['title']; ?></span>
                    <span class="insights-stats-count"><?php echo $m['val']; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="insights-section">
            <div class="insights-section-title">💎 Rarest Detections (&lt; 5 ever)</div>
            <div class="insights-stats-list">
                <?php if(empty($rarest)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">No rare species detected yet</span>
                    <span class="insights-stats-count">—</span>
                </div>
                <?php else: ?>
                <?php foreach($rarest as $r): ?>
                <div class="insights-stats-item">
                    <div>
                        <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo $r['Com_Name']; ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted);">Last seen: <?php echo date('M j, Y', strtotime($r['last_seen'])); ?></div>
                    </div>
                    <span class="insights-stats-count"><?php echo $r['cnt']; ?>x</span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
