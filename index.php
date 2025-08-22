<?php
// Playitas-style digital booking demo
// Single-file PHP 8.1+ + SQLite3
// Author: ChatGPT (GPT-5 Thinking)
// Timezone: Europe/Athens

// ----------------- CONFIG -----------------
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

date_default_timezone_set('Europe/Athens');

const DB_FILE = __DIR__ . '/data.sqlite';
const ADMIN_KEY = 'playitas'; // change me
const HOTEL_NAME = 'Apollo Playitas Rhodes (Demo)';

// Business rules
const DAILY_CAP_MORNING = 1;  // max confirmed classes per user per day before 13:00
const DAILY_CAP_AFTERNOON = 1; // max confirmed classes per user per day after 13:00
const MORNING_RELEASE_HOUR = 8; // 08:00 allocation window open
const AFTERNOON_RELEASE_HOUR = 13; // 13:00 allocation window open

// ------------------------------------------

session_start();

$db = new PDO('sqlite:' . DB_FILE);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

initialize($db);
seed_schedule($db);

$action = $_GET['action'] ?? '';

switch ($action) {
  case 'identify': handle_identify($db); break;
  case 'logout': handle_logout(); break;
  case 'request': require_post(); handle_request($db); break;
  case 'withdraw_request': require_post(); handle_withdraw_request($db); break;
  case 'cancel_booking': require_post(); handle_cancel_booking($db); break;
  case 'admin_add_class': require_admin(); require_post(); handle_admin_add_class($db); break;
  case 'admin_seed_today': require_admin(); handle_admin_seed_today($db); break;
  case 'run_allocation': require_admin(); handle_run_allocation($db); break;
  case 'mark_attended': require_admin(); require_post(); handle_mark_attended($db); break;
  default: render_home($db);
}

// ----------------- FUNCTIONS -----------------

function initialize(PDO $db): void {
  // Create schema if needed
  $db->exec('PRAGMA foreign_keys = ON');

  $db->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL,
      room TEXT NOT NULL,
      fingerprint TEXT NOT NULL UNIQUE,
      created_at TEXT NOT NULL
    );
  SQL);

  $db->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS classes (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      title TEXT NOT NULL,
      category TEXT NOT NULL,
      date TEXT NOT NULL,           -- YYYY-MM-DD
      start_time TEXT NOT NULL,     -- HH:MM
      end_time TEXT NOT NULL,       -- HH:MM
      capacity INTEGER NOT NULL
    );
  SQL);

  $db->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS requests (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      class_id INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
      created_at TEXT NOT NULL,
      UNIQUE(user_id, class_id)
    );
  SQL);

  $db->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS bookings (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      class_id INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
      status TEXT NOT NULL, -- CONFIRMED | WAITLIST | CANCELLED | ATTENDED | NO_SHOW
      created_at TEXT NOT NULL
    );
  SQL);
}

function seed_schedule(PDO $db): void {
  $exists = $db->query('SELECT COUNT(1) FROM classes')->fetchColumn();
  if ($exists) return;
  $schedule = [
    ['2025-08-18','07:30','08:30','RUN (ALL LEVELS)','Running',0],
    ['2025-08-18','09:00','10:00','LES MILLS BODYPUMP W/ JOHANNA','Fitness',12],
    ['2025-08-18','10:00','11:00','SPINNING','Cycling',12],
    ['2025-08-18','12:00','13:00','AQUA GYM','Aqua',0]
  ];
  $stmt = $db->prepare('INSERT INTO classes(title, category, date, start_time, end_time, capacity) VALUES(?,?,?,?,?,?)');
  foreach ($schedule as $s) {
    [$date,$start,$end,$title,$cat,$cap] = $s;
    $stmt->execute([$title,$cat,$date,$start,$end,$cap]);
  }
}

function require_post(): void {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
  }
}

function require_admin(): void {
  $key = $_GET['key'] ?? '';
  if ($key !== ADMIN_KEY) {
    http_response_code(403);
    exit('Forbidden (admin key)');
  }
}

function be(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function fingerprint(string $name, string $room): string {
  $n = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
  $r = mb_strtolower(trim($room));
  return hash('sha256', $n . '|' . $r);
}

function me(PDO $db): ?array {
  if (!isset($_SESSION['fp'])) return null;
  $stmt = $db->prepare('SELECT * FROM users WHERE fingerprint = ?');
  $stmt->execute([$_SESSION['fp']]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);
  return $u ?: null;
}

function handle_identify(PDO $db): void {
  require_post();
  $name = trim($_POST['name'] ?? '');
  $room = trim($_POST['room'] ?? '');
  if ($name === '' || $room === '') {
    header('Location: ./?err=' . urlencode('Please enter name and room number.'));
    exit;
  }
  $fp = fingerprint($name, $room);
  $now = date('c');
  $stmt = $db->prepare('INSERT OR IGNORE INTO users(name, room, fingerprint, created_at) VALUES(?,?,?,?)');
  $stmt->execute([$name, $room, $fp, $now]);
  // keep latest name/room if changed
  $db->prepare('UPDATE users SET name = ?, room = ? WHERE fingerprint = ?')->execute([$name, $room, $fp]);

  $_SESSION['fp'] = $fp;
  header('Location: ./');
  exit;
}

function handle_logout(): void {
  session_destroy();
  header('Location: ./');
  exit;
}

function is_morning(array $class): bool {
  return strcmp($class['start_time'], '13:00') < 0; // start time < 13:00
}

function window_time(string $date, bool $morning): int {
  $hour = $morning ? MORNING_RELEASE_HOUR : AFTERNOON_RELEASE_HOUR;
  return strtotime($date . sprintf(' %02d:00:00', $hour));
}

function within_allocation_window(array $class, bool $force = false): bool {
  if ($force) return true;
  $ts = window_time($class['date'], is_morning($class));
  return time() >= $ts;
}

function handle_request(PDO $db): void {
  $u = me($db);
  if (!$u) { header('Location: ./?err=' . urlencode('Please identify yourself first.')); exit; }
  $class_id = (int)($_POST['class_id'] ?? 0);
  $stmt = $db->prepare('SELECT * FROM classes WHERE id = ?');
  $stmt->execute([$class_id]);
  $c = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$c) { header('Location: ./?err=' . urlencode('Class not found.')); exit; }

  // Already booked? prevent duplicate
  $exists = $db->prepare('SELECT 1 FROM bookings WHERE user_id = ? AND class_id = ? AND status IN ("CONFIRMED","WAITLIST")');
  $exists->execute([$u['id'], $class_id]);
  if ($exists->fetchColumn()) {
    header('Location: ./?err=' . urlencode('You are already in the queue/have a spot.'));
    exit;
  }

  // Create request
  $now = date('c');
  $db->prepare('INSERT OR IGNORE INTO requests(user_id, class_id, created_at) VALUES(?,?,?)')->execute([$u['id'], $class_id, $now]);
  // also create initial booking as WAITLIST (for transparency)
  $db->prepare('INSERT INTO bookings(user_id, class_id, status, created_at) VALUES(?,?,"WAITLIST",?)')->execute([$u['id'], $class_id, $now]);

  header('Location: ./?ok=' . urlencode('You joined the queue for "' . $c['title'] . '". Results will appear after allocation.'));
  exit;
}

function handle_withdraw_request(PDO $db): void {
  $u = me($db);
  if (!$u) { header('Location: ./?err=' . urlencode('Please identify yourself first.')); exit; }
  $class_id = (int)($_POST['class_id'] ?? 0);
  $db->prepare('DELETE FROM requests WHERE user_id = ? AND class_id = ?')->execute([$u['id'], $class_id]);
  // also remove WAITLIST bookings if any
  $db->prepare('DELETE FROM bookings WHERE user_id = ? AND class_id = ? AND status = "WAITLIST"')->execute([$u['id'], $class_id]);
  header('Location: ./?ok=' . urlencode('You have left the queue.'));
  exit;
}

function handle_cancel_booking(PDO $db): void {
  $u = me($db);
  if (!$u) { header('Location: ./?err=' . urlencode('Please identify yourself first.')); exit; }
  $class_id = (int)($_POST['class_id'] ?? 0);
  $db->prepare('UPDATE bookings SET status = "CANCELLED" WHERE user_id = ? AND class_id = ? AND status = "CONFIRMED"')->execute([$u['id'], $class_id]);
  header('Location: ./?ok=' . urlencode('Your spot has been canceled. Thanks for freeing it for someone else!'));
  exit;
}

function handle_admin_add_class(PDO $db): void {
  $title = trim($_POST['title'] ?? '');
  $category = trim($_POST['category'] ?? '');
  $date = trim($_POST['date'] ?? '');
  $start = trim($_POST['start_time'] ?? '');
  $end = trim($_POST['end_time'] ?? '');
  $cap = (int)($_POST['capacity'] ?? 0);
  if (!$title || !$category || !$date || !$start || !$end || $cap <= 0) {
    header('Location: ./?admin=1&key=' . urlencode($_GET['key']) . '&err=' . urlencode('Please fill in all fields.'));
    exit;
  }
  $db->prepare('INSERT INTO classes(title, category, date, start_time, end_time, capacity) VALUES(?,?,?,?,?,?)')
    ->execute([$title, $category, $date, $start, $end, $cap]);
  header('Location: ./?admin=1&key=' . urlencode($_GET['key']) . '&ok=' . urlencode('Class added.'));
  exit;
}

function handle_admin_seed_today(PDO $db): void {
  $today = date('Y-m-d');
  $seed = [
    ['Morning Padel', 'Padel', '08:00', '09:00', 12],
    ['Beach Run', 'Running', '08:30', '09:15', 30],
    ['Spinning', 'Cycling', '10:00', '10:45', 20],
    ['Core Workout', 'Fitness', '11:30', '12:15', 25],
    ['Afternoon Padel', 'Padel', '15:00', '16:00', 12],
    ['Yoga Sunset', 'Yoga', '18:00', '19:00', 28],
  ];
  foreach ($seed as $s) {
    [$title, $cat, $st, $et, $cap] = $s;
    $db->prepare('INSERT INTO classes(title, category, date, start_time, end_time, capacity) VALUES(?,?,?,?,?,?)')
      ->execute([$title, $cat, $today, $st, $et, $cap]);
  }
  header('Location: ./?admin=1&key=' . urlencode($_GET['key']) . '&ok=' . urlencode('Sample classes added for today.'));
  exit;
}

function handle_run_allocation(PDO $db): void {
  $date = $_GET['date'] ?? date('Y-m-d');
  $force = isset($_GET['force']) && $_GET['force'] === '1';

  // Fetch classes for date
  $stmt = $db->prepare('SELECT * FROM classes WHERE date = ? ORDER BY start_time');
  $stmt->execute([$date]);
  $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $allocated = 0; $skipped = 0; $errors = [];
  foreach ($classes as $c) {
    if (!within_allocation_window($c, $force)) { $skipped++; continue; }
    try {
      allocate_for_class($db, (int)$c['id']);
      $allocated++;
    } catch (Throwable $e) {
      $errors[] = 'Could not allocate class #' . $c['id'] . ' (' . $c['title'] . '): ' . $e->getMessage();
    }
  }
  $msg = "Allocation done. Allocated {$allocated} classes, skipped {$skipped}.";
  if ($errors) $msg .= "\n" . implode("\n", $errors);

  header('Location: ./?admin=1&key=' . urlencode($_GET['key']) . '&ok=' . urlencode($msg));
  exit;
}

function week_bounds(string $date): array {
  // Week Monday..Sunday for given date
  $ts = strtotime($date);
  $dow = (int)date('N', $ts); // 1..7
  $monday = strtotime('-' . ($dow - 1) . ' days', $ts);
  $sunday = strtotime('+' . (7 - $dow) . ' days', $ts);
  return [date('Y-m-d', $monday), date('Y-m-d', $sunday)];
}

function count_attended_in_week(PDO $db, int $user_id, string $start_date, string $end_date, ?string $category = null): int {
  $sql = 'SELECT COUNT(1) FROM bookings b JOIN classes c ON c.id = b.class_id
          WHERE b.user_id = ? AND b.status IN ("CONFIRMED","ATTENDED")
            AND c.date BETWEEN ? AND ?';
  $params = [$user_id, $start_date, $end_date];
  if ($category !== null) {
    $sql .= ' AND c.category = ?';
    $params[] = $category;
  }
  $stmt = $db->prepare($sql);
  $stmt->execute($params);
  return (int)$stmt->fetchColumn();
}

function has_time_conflict(PDO $db, int $user_id, array $class): bool {
  $sql = 'SELECT COUNT(1) FROM bookings b JOIN classes c ON c.id = b.class_id
          WHERE b.user_id = ? AND b.status = "CONFIRMED" AND c.date = ?
            AND NOT( c.end_time <= ? OR c.start_time >= ? )';
  $stmt = $db->prepare($sql);
  $stmt->execute([$user_id, $class['date'], $class['start_time'], $class['end_time']]);
  return (int)$stmt->fetchColumn() > 0;
}

function daily_cap_reached(PDO $db, int $user_id, array $class): bool {
  $isMorning = is_morning($class);
  $cap = $isMorning ? DAILY_CAP_MORNING : DAILY_CAP_AFTERNOON;
  $sql = 'SELECT COUNT(1) FROM bookings b JOIN classes c ON c.id = b.class_id
          WHERE b.user_id = ? AND b.status = "CONFIRMED" AND c.date = ?';
  if ($isMorning) {
    $sql .= ' AND c.start_time < "13:00"';
  } else {
    $sql .= ' AND c.start_time >= "13:00"';
  }
  $stmt = $db->prepare($sql);
  $stmt->execute([$user_id, $class['date']]);
  $cnt = (int)$stmt->fetchColumn();
  return $cnt >= $cap;
}

function allocate_for_class(PDO $db, int $class_id): void {
  // Load class
  $stmt = $db->prepare('SELECT * FROM classes WHERE id = ?');
  $stmt->execute([$class_id]);
  $class = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$class) throw new RuntimeException('Unknown class.');

  // Fetch requesters
  $q = $db->prepare('SELECT r.*, u.id AS uid, u.name, u.room FROM requests r JOIN users u ON u.id = r.user_id WHERE r.class_id = ? ORDER BY r.created_at');
  $q->execute([$class_id]);
  $requests = $q->fetchAll(PDO::FETCH_ASSOC);

  // Clear previous WAITLIST/CONFIRMED to re-run fairly (keep CANCELLED/ATTENDED/NO_SHOW)
  $db->prepare('DELETE FROM bookings WHERE class_id = ? AND status IN ("WAITLIST","CONFIRMED")')->execute([$class_id]);

  $capacity = (int)$class['capacity'];
  [$wStart, $wEnd] = week_bounds($class['date']);

  // Score each user for fairness
  $scored = [];
  foreach ($requests as $r) {
    $uid = (int)$r['uid'];
    $sameCat = count_attended_in_week($db, $uid, $wStart, $wEnd, $class['category']);
    $total = count_attended_in_week($db, $uid, $wStart, $wEnd, null);
    $created = strtotime($r['created_at']);
    // Sort key: fewer same category, then fewer total, then earlier request
    $scored[] = [
      'uid' => $uid,
      'name' => $r['name'],
      'room' => $r['room'],
      'sameCat' => $sameCat,
      'total' => $total,
      'created' => $created,
    ];
  }

  usort($scored, function($a, $b) {
    return [$a['sameCat'], $a['total'], $a['created']] <=> [$b['sameCat'], $b['total'], $b['created']];
  });

  $now = date('c');
  $confirmed = 0;
  foreach ($scored as $s) {
    $uid = $s['uid'];

    // Skip if time conflict or daily cap already met
    if (has_time_conflict($db, $uid, $class)) {
      // keep them on waitlist to show they tried
      $db->prepare('INSERT INTO bookings(user_id, class_id, status, created_at) VALUES(?,?,"WAITLIST",?)')
         ->execute([$uid, $class_id, $now]);
      continue;
    }
    if (daily_cap_reached($db, $uid, $class)) {
      $db->prepare('INSERT INTO bookings(user_id, class_id, status, created_at) VALUES(?,?,"WAITLIST",?)')
         ->execute([$uid, $class_id, $now]);
      continue;
    }

    if ($confirmed < $capacity) {
      $db->prepare('INSERT INTO bookings(user_id, class_id, status, created_at) VALUES(?,?,"CONFIRMED",?)')
        ->execute([$uid, $class_id, $now]);
      $confirmed++;
    } else {
      $db->prepare('INSERT INTO bookings(user_id, class_id, status, created_at) VALUES(?,?,"WAITLIST",?)')
        ->execute([$uid, $class_id, $now]);
    }
  }

  // Clear processed requests (optional): keep history minimal
  $db->prepare('DELETE FROM requests WHERE class_id = ?')->execute([$class_id]);
}

function handle_mark_attended(PDO $db): void {
  $booking_id = (int)($_POST['booking_id'] ?? 0);
  $status = $_POST['status'] ?? 'ATTENDED';
  if (!in_array($status, ['ATTENDED','NO_SHOW','CONFIRMED','CANCELLED','WAITLIST'], true)) $status = 'ATTENDED';
  $db->prepare('UPDATE bookings SET status = ? WHERE id = ?')->execute([$status, $booking_id]);
  header('Location: ./?admin=1&key=' . urlencode($_GET['key']) . '&ok=' . urlencode('Updated.'));
  exit;
}

function list_classes_for_date(PDO $db, string $date): array {
  $stmt = $db->prepare('SELECT * FROM classes WHERE date = ? ORDER BY start_time');
  $stmt->execute([$date]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function user_bookings_map(PDO $db, int $user_id, string $date): array {
  $stmt = $db->prepare('SELECT b.*, c.start_time, c.end_time, c.title FROM bookings b JOIN classes c ON c.id=b.class_id WHERE b.user_id = ? AND c.date = ?');
  $stmt->execute([$user_id, $date]);
  $map = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $map[(int)$row['class_id']] = $row;
  }
  return $map;
}

function class_counts(PDO $db, int $class_id): array {
  $stmt = $db->prepare('SELECT status, COUNT(1) as cnt FROM bookings WHERE class_id = ? GROUP BY status');
  $stmt->execute([$class_id]);
  $res = ['CONFIRMED'=>0,'WAITLIST'=>0,'CANCELLED'=>0,'ATTENDED'=>0,'NO_SHOW'=>0];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $res[$row['status']] = (int)$row['cnt']; }
  return $res;
}

function render_home(PDO $db): void {
  $today = $_GET['date'] ?? date('Y-m-d');
  $u = me($db);
  $user_map = $u ? user_bookings_map($db, (int)$u['id'], $today) : [];
  $classes = list_classes_for_date($db, $today);
  $err = $_GET['err'] ?? null; $ok = $_GET['ok'] ?? null;
  $admin = (isset($_GET['admin']) && $_GET['admin'] == '1' && ($_GET['key'] ?? '') === ADMIN_KEY);

  echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<title>' . be(HOTEL_NAME) . ' — Digital booking (demo)</title>';
  echo '<style>
    :root{--orange:#ff7900;--dark:#000;--light:#f5f5f5;--dark-gray:#333;}
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:var(--light);color:var(--dark-gray);}
    header{background:url("gradient.png") center/cover no-repeat var(--dark);padding:12px;}
    .logo{height:40px}
    .wrap{max-width:1000px;margin:0 auto;padding:20px}
    .card{background:white;border-radius:12px;padding:16px;margin-bottom:12px;box-shadow:0 2px 4px rgba(0,0,0,.1)}
    .row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
    input,select,button{padding:10px 12px;border-radius:10px;border:1px solid var(--dark-gray);background:white;color:var(--dark-gray)}
    button.primary{background:var(--orange);color:white;border:none}
    button.warn{background:#dc2626;color:white;border:none}
    .k{font-size:12px;opacity:.8}
    .ok{background:#d1fae5;border:1px solid #10b981;color:#065f46;padding:12px;border-radius:10px;margin:10px 0}
    .err{background:#fecaca;border:1px solid #b91c1c;color:#7f1d1d;padding:12px;border-radius:10px;margin:10px 0}
    .muted{opacity:.8}
    .grid{display:grid;grid-template-columns:1fr;gap:12px}
    @media(min-width:700px){.grid{grid-template-columns:1fr 1fr}}
    .badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;margin-left:6px;background:var(--dark-gray);color:white}
    .tag{padding:2px 8px;border-radius:999px;background:var(--orange);color:white;font-size:12px;margin-left:6px}
    a{color:var(--orange)}
  </style>';
  echo '</head><body>';
  echo '<header><div class="wrap"><div class="row" style="justify-content:space-between;align-items:center"><img src="logo.png" alt="Playitas logo" class="logo"><form method="get" class="row" style="margin-left:auto"><input type="date" name="date" value="' . be($today) . '" onchange="this.form.submit()"></form></div></div></header>';

  echo '<div class="wrap">';
  if ($ok) echo '<div class="ok">' . be($ok) . '</div>';
  if ($err) echo '<div class="err">' . be($err) . '</div>';

  echo '<div class="card">';
  if ($u) {
    echo '<div class="row"><div>Logged in as <strong>' . be($u['name']) . '</strong> (Room ' . be($u['room']) . ')</div>';
    echo '<form method="get" action="."><input type="hidden" name="action" value="logout"><button class="warn">Switch guest</button></form></div>';
  } else {
    echo '<form method="post" action="?action=identify" class="row"><strong>Sign in</strong>
          <input name="name" placeholder="Name" required>
          <input name="room" placeholder="Room number" required>
          <button class="primary">Continue</button>
        </form>';
    echo '<p class="k muted">No user account required. The system only uses name and room to create a temporary profile.</p>';
  }
  echo '</div>';

  // Admin panel
  if ($admin) {
    echo '<div class="card">';
    echo '<h3 style="margin-top:0">Admin</h3>';
    echo '<form method="post" action="?action=admin_add_class&key=' . be($_GET['key']) . '" class="row">
            <input name="title" placeholder="Title" required>
            <input name="category" placeholder="Category" required>
            <input type="date" name="date" value="' . be($today) . '" required>
            <input type="time" name="start_time" value="09:00" required>
            <input type="time" name="end_time" value="10:00" required>
            <input type="number" name="capacity" placeholder="Capacity" min="1" style="width:120px" required>
            <button class="primary">Add class</button>
          </form>';
    echo '<div class="row" style="margin-top:8px">
            <a class="badge" href="?action=run_allocation&key=' . be($_GET['key']) . '&date=' . be($today) . '">Run allocation (respect window)</a>
            <a class="badge" href="?action=run_allocation&force=1&key=' . be($_GET['key']) . '&date=' . be($today) . '">Run allocation (force)</a>
            <a class="badge" href="?action=admin_seed_today&key=' . be($_GET['key']) . '">Seed sample day</a>
          </div>';
    echo '<p class="k muted">Allocation windows: morning ' . MORNING_RELEASE_HOUR . ':00, afternoon ' . AFTERNOON_RELEASE_HOUR . ':00.</p>';
    echo '</div>';
  }

  // List classes
  echo '<div class="grid">';
  if (!$classes) {
    echo '<div class="card">No classes for selected date.</div>';
  }
  foreach ($classes as $c) {
    $counts = class_counts($db, (int)$c['id']);
    $free = max(0, (int)$c['capacity'] - $counts['CONFIRMED']);
    $isMorning = is_morning($c);
    $release = window_time($c['date'], $isMorning);
    echo '<div class="card">';
    echo '<div class="row"><h3 style="margin:0">' . be($c['title']) . '</h3>';
    echo '<span class="tag">' . be($c['category']) . '</span>';
    echo '<span class="badge">' . be($c['date']) . ' ' . be($c['start_time']) . '–' . be($c['end_time']) . '</span>';
    if ((int)$c['capacity'] > 0) {
      echo '<span class="badge">Capacity: ' . (int)$c['capacity'] . '</span>';
      echo '<span class="badge">Spots left: ' . $free . '</span>';
      echo '<span class="badge">Queue: ' . $counts['WAITLIST'] . '</span>';
      echo '<span class="badge">Window: ' . ($isMorning ? '08:00' : '13:00') . '</span>';
    } else {
      echo '<span class="badge">No booking required</span>';
    }
    echo '</div>';

    // Show my status if logged in
    if ($u) {
      $my = $user_map[(int)$c['id']] ?? null;
      if ($my) {
        $status = $my['status'];
        $note = '';
        if ($status === 'CONFIRMED') $note = 'You have a confirmed spot.';
        elseif ($status === 'WAITLIST') $note = 'You are in the queue. Allocation runs after the window.';
        elseif ($status === 'CANCELLED') $note = 'You have canceled this class.';
        echo '<p class="muted">Status: <strong>' . be($status) . '</strong>. ' . be($note) . '</p>';
      }
    }

    echo '<div class="row">';
    if ($u) {
      $my = $user_map[(int)$c['id']] ?? null;
      if ((int)$c['capacity'] === 0) {
        echo '<span class="muted">No booking needed.</span>';
      } elseif (!$my) {
        echo '<form method="post" action="?action=request" class="row">
                <input type="hidden" name="class_id" value="' . (int)$c['id'] . '">
                <button class="primary">Join queue</button>
              </form>';
      } elseif ($my['status'] === 'WAITLIST') {
        echo '<form method="post" action="?action=withdraw_request" class="row">
                <input type="hidden" name="class_id" value="' . (int)$c['id'] . '">
                <button>Withdraw / leave queue</button>
              </form>';
      } elseif ($my['status'] === 'CONFIRMED') {
        echo '<form method="post" action="?action=cancel_booking" class="row">
                <input type="hidden" name="class_id" value="' . (int)$c['id'] . '">
                <button class="warn">Cancel spot</button>
              </form>';
      }
    } else {
      echo '<span class="muted">Identify yourself to queue / book.</span>';
    }
    echo '</div>';

    if ($admin) {
      // Admin view small roster
      $stmt = $db->prepare('SELECT b.id AS bid, b.status, u.name, u.room FROM bookings b JOIN users u ON u.id=b.user_id WHERE b.class_id = ? ORDER BY b.status DESC, b.created_at');
      $stmt->execute([(int)$c['id']]);
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
      echo '<hr><div class="k">Admin: roster</div>';
      if ($rows) {
        echo '<table style="width:100%;border-collapse:collapse">';
        echo '<tr><th style="text-align:left">Guest</th><th>Room</th><th>Status</th><th>Change</th></tr>';
        foreach ($rows as $r) {
          echo '<tr style="border-top:1px solid #334155"><td>' . be($r['name']) . '</td><td style="text-align:center">' . be($r['room']) . '</td><td style="text-align:center">' . be($r['status']) . '</td><td style="text-align:center">';
          echo '<form method="post" action="?action=mark_attended&key=' . be($_GET['key']) . '" class="row" style="justify-content:center">';
          echo '<input type="hidden" name="booking_id" value="' . (int)$r['bid'] . '">';
          echo '<select name="status"><option>CONFIRMED</option><option>WAITLIST</option><option>ATTENDED</option><option>NO_SHOW</option><option>CANCELLED</option></select>';
          echo '<button>Update</button></form>';
          echo '</td></tr>';
        }
        echo '</table>';
      } else {
        echo '<p class="muted">No bookings yet.</p>';
      }
    }

    echo '</div>'; // card
  }
  echo '</div>'; // grid

  echo '<div class="card">';
  echo '<h3 style="margin-top:0">How allocation works (fairness)</h3>';
  echo '<ul class="k"><li>At allocation (08:00 for morning, 13:00 for afternoon) the queue is sorted by: 1) fewer participations in the same category this week, 2) fewer total participations this week, 3) earliest queue time.</li>
        <li>You can have at most ' . DAILY_CAP_MORNING . ' morning classes and ' . DAILY_CAP_AFTERNOON . ' afternoon classes per day.</li>
        <li>Time conflicts are blocked automatically.</li>
        <li>After allocation you will see if you got a <strong>CONFIRMED</strong> spot or remain on the <strong>WAITLIST</strong>.</li></ul>';
  echo '</div>';

  echo '<footer class="wrap" style="opacity:.6;padding-bottom:40px">Demo app. For production: add email/SMS notifications, stronger identification (e.g. one-time code at check-in), cleanup/archiving and logs.</footer>';
  echo '</div></body></html>';
}

?>
