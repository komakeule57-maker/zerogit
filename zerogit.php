<?php
// Dateiname: zerogit.php
// Funktion: Vollständiges Backend für ZeroGit (MySQL).
// Features: Collision-Detection (Merge Konflikt Stop), Config-Ignore Ready, Web-Editor, Hierarchischer Dateibaum

session_start();

// CSRF-Token generieren (Sicherheit für Web-Aktionen)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helper: Dateigrößen lesbar machen
function formatBytes($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    elseif ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    else return round($bytes / 1048576, 1) . ' MB';
}

// Helper: Auto-Retention (Hält das Repo sauber auf max 50 Commits)
function prune_repo($db, $repo_id, $snap_dir) {
    $stmt = $db->prepare("SELECT id, hash FROM commits WHERE repo_id = ? ORDER BY id ASC");
    $stmt->execute([$repo_id]);
    $commits = $stmt->fetchAll();
    while (count($commits) > 50) {
        $oldest = array_shift($commits);
        @unlink($snap_dir . "/repo_{$repo_id}_{$oldest['hash']}.pack");
        $db->prepare("DELETE FROM commits WHERE id = ?")->execute([$oldest['id']]);
    }
}

// =========================================================================
// 1. DATENBANK & KONFIGURATION
// =========================================================================
$db_host = 'localhost';
$db_name = 'freya_zerogit';
$db_user = 'freya_absti';
$db_pass = 'eQ7XhkL8SefZNqkc';
$snap_dir = __DIR__ . '/snapshots';

if (!is_dir($snap_dir)) {
    @mkdir($snap_dir, 0777, true);
    @file_put_contents($snap_dir . '/.htaccess', "Order Deny,Allow\nDeny from all");
    @file_put_contents($snap_dir . '/index.php', "<?php http_response_code(403); die('Forbidden - ZeroGit Vault');");
}

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Auto-Init mit IF NOT EXISTS (Robust)
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL
        );
        CREATE TABLE IF NOT EXISTS repos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            is_public TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS commits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            repo_id INT NOT NULL,
            hash VARCHAR(50) NOT NULL,
            message TEXT NOT NULL,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");
    
    // Fallback Admin
    $stmt = $db->query("SELECT id FROM users LIMIT 1");
    if ($stmt->rowCount() === 0) {
        $stmt = $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        $stmt->execute(['admin', password_hash('admin', PASSWORD_DEFAULT)]);
    }

    // Auto-Patch für Public-Spalte
    try {
        $db->query("SELECT is_public FROM repos LIMIT 1");
    } catch (PDOException $e) {
        $db->exec("ALTER TABLE repos ADD COLUMN is_public TINYINT(1) DEFAULT 0");
    }

    // Auto-Patch für API Token (Erhöhte Sicherheit)
    try {
        $db->query("SELECT api_token FROM users LIMIT 1");
    } catch (PDOException $e) {
        $db->exec("ALTER TABLE users ADD COLUMN api_token VARCHAR(64) NULL");
        // Generiere sichere Tokens für existierende User
        $users = $db->query("SELECT id FROM users WHERE api_token IS NULL")->fetchAll();
        $st = $db->prepare("UPDATE users SET api_token = ? WHERE id = ?");
        foreach($users as $u) { $st->execute([bin2hex(random_bytes(32)), $u['id']]); }
    }

} catch (PDOException $e) {
    die("DB-Fehler: Bitte Zugangsdaten in der zerogit.php prüfen. Details: " . $e->getMessage());
}

// =========================================================================
// 1b. DOWNLOAD STREAM FÜR ZIPS (Projekt herunterladen)
// =========================================================================
if (isset($_GET['download']) && isset($_GET['repo'])) {
    $repo_id = (int)$_GET['repo'];
    $stmt = $db->prepare("SELECT name, is_public, user_id FROM repos WHERE id = ?");
    $stmt->execute([$repo_id]);
    $repo = $stmt->fetch();
    
    $allowed = false;
    if ($repo) {
        if ($repo['is_public'] == 1) $allowed = true;
        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] === $repo['user_id']) $allowed = true;
    }
    
    if ($allowed) {
        $stmt = $db->prepare("SELECT hash FROM commits WHERE repo_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$repo_id]);
        $c = $stmt->fetch();
        if ($c) {
            $file = $snap_dir . "/repo_{$repo_id}_{$c['hash']}.pack";
            if (file_exists($file)) {
                $clean_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $repo['name']);
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="'.$clean_name.'_latest.zip"');
                header('Content-Length: ' . filesize($file));
                readfile($file);
                exit;
            }
        }
    }
    die("Download nicht verfuegbar. Das Repo ist privat oder enthaelt noch keinen Code.");
}

// =========================================================================
// 2. JSON API FÜR DEN PYTHON CLIENT
// =========================================================================
$input_data = file_get_contents('php://input');
$json = json_decode($input_data, true);

if ($json && isset($json['action'])) {
    header('Content-Type: application/json');
    
    // Auth erfolgt jetzt über das API Token, nicht mehr über das Klartext-Passwort
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND api_token = ?");
    $stmt->execute([$json['username'] ?? '', $json['api_token'] ?? '']);
    $api_user = $stmt->fetch();

    if (!$api_user) {
        usleep(random_int(400000, 800000)); // E>H Anti-Brute-Force
        echo json_encode(['status' => 'error', 'message' => 'Ungültiger Benutzer oder falsches API Token.']);
        exit;
    }

    $repo_id = (int)($json['repo_id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM repos WHERE id = ? AND user_id = ?");
    $stmt->execute([$repo_id, $api_user['id']]);
    if (!$stmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Repository existiert nicht oder gehört dir nicht.']);
        exit;
    }

    if ($json['action'] === 'push') {
        $msg = $json['message'] ?? 'Auto-Snapshot';
        $b64_zip = $json['zip_data'];
        
        // Transaktion starten, um Race Conditions bei gleichzeitigen Pushes zu verhindern
        $db->beginTransaction();
        
        // --- KOLLISIONS ERKENNUNG (Merge Conflict Logic) ---
        $client_base = isset($json['base_commit']) ? (int)$json['base_commit'] : 0;
        $force = isset($json['force']) ? (bool)$json['force'] : false;
        
        $stmt = $db->prepare("SELECT id FROM commits WHERE repo_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$repo_id]);
        $latest = $stmt->fetch();
        $latest_id = $latest ? (int)$latest['id'] : 0;
        
        if (!$force && $latest_id > 0 && $client_base !== $latest_id) {
            $db->rollBack();
            echo json_encode([
                'status' => 'error', 
                'message' => "KOLLISION: Der Server ist auf Commit [$latest_id], dein lokaler Stand auf [$client_base].\nJemand (oder du per Web-Editor) hat neueren Code gepusht.\n-> Nutze 'python zg.py pull $latest_id' zum synchronisieren.\n-> Oder hänge '--force' an deinen save-Befehl an, um den Server rigoros zu überschreiben."
            ]);
            exit;
        }
        // ---------------------------------------------------

        $zip_bin = base64_decode($b64_zip);
        $hash = substr(hash('sha256', $zip_bin . time()), 0, 12);
        // Wir speichern als .pack, damit Nginx/Apache die Datei bei fehlender .htaccess nicht direkt ausliefern
        $file_path = $snap_dir . "/repo_{$repo_id}_{$hash}.pack";
        
        file_put_contents($file_path, $zip_bin);

        $stmt = $db->prepare("INSERT INTO commits (repo_id, hash, message) VALUES (?, ?, ?)");
        $stmt->execute([$repo_id, $hash, $msg]);
        
        $new_commit_id = $db->lastInsertId(); // FIX: ID zwingend vor dem commit() sichern!
        
        prune_repo($db, $repo_id, $snap_dir);
        
        $db->commit();
        echo json_encode(['status' => 'success', 'commit_id' => $new_commit_id]);
        exit;
    }
    
    if ($json['action'] === 'history') {
        $stmt = $db->prepare("SELECT id, message, timestamp FROM commits WHERE repo_id = ? ORDER BY id DESC LIMIT 50");
        $stmt->execute([$repo_id]);
        echo json_encode(['status' => 'success', 'commits' => $stmt->fetchAll()]);
        exit;
    }

    if ($json['action'] === 'pull') {
        $commit_id = (int)$json['commit_id'];
        $stmt = $db->prepare("SELECT hash FROM commits WHERE id = ? AND repo_id = ?");
        $stmt->execute([$commit_id, $repo_id]);
        $commit = $stmt->fetch();
        
        if ($commit && file_exists($snap_dir . "/repo_{$repo_id}_{$commit['hash']}.pack")) {
            $b64_data = base64_encode(file_get_contents($snap_dir . "/repo_{$repo_id}_{$commit['hash']}.pack"));
            echo json_encode(['status' => 'success', 'zip_data' => $b64_data]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Snapshot nicht gefunden.']);
        }
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Unbekannte Action.']);
    exit;
}

// =========================================================================
// 3. AUTHENTIFIZIERUNG & WEB-ROUTING (Browser)
// =========================================================================
$error = '';
$success = '';
$is_logged_in = isset($_SESSION['user_id']);

// Auto-Heilung: Repariert alte Sessions, bei denen das API-Token noch fehlt
if ($is_logged_in && empty($_SESSION['api_token'])) {
    $stmt = $db->prepare("SELECT api_token FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $_SESSION['api_token'] = $stmt->fetchColumn();
}

if (isset($_GET['logout'])) { session_destroy(); header("Location: ?"); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // CSRF Check für alle POST-Aktionen
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Sicherheitsfehler: Ungültiges CSRF-Token (Lade die Seite neu).";
    } else {
        if ($_POST['action'] === 'login') {
            $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$_POST['username']]);
            $user = $stmt->fetch();
            if ($user && password_verify($_POST['password'], $user['password'])) {
                $_SESSION['user_id'] = $user['id']; 
                $_SESSION['username'] = $user['username'];
                $_SESSION['api_token'] = $user['api_token']; // Session merkt sich das Token
                header("Location: ?"); exit;
            } else { 
                usleep(random_int(400000, 800000)); // E>H Anti-Brute-Force
                $error = "Falscher Benutzername oder Passwort."; 
            }
        }
        
        if ($_POST['action'] === 'register') {
            $username = trim($_POST['username']); $password = $_POST['password'];
            if (!empty($username) && !empty($password)) {
                $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetch()) { $error = "Benutzername vergeben."; } 
                else {
                    $token = bin2hex(random_bytes(32));
                    $stmt = $db->prepare("INSERT INTO users (username, password, api_token) VALUES (?, ?, ?)");
                    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $token]);
                    $success = "Registrierung erfolgreich!";
                }
            }
        }

        if ($is_logged_in) {
            if ($_POST['action'] === 'create_repo') {
                $repo_name = trim($_POST['repo_name']);
                if (!empty($repo_name)) {
                    $stmt = $db->prepare("INSERT INTO repos (user_id, name, description, is_public) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$_SESSION['user_id'], $repo_name, trim($_POST['repo_desc']), isset($_POST['is_public']) ? 1 : 0]);
                    $success = "Repo erstellt. ID: " . $db->lastInsertId();
                }
            }

            if ($_POST['action'] === 'toggle_public') {
                $stmt = $db->prepare("UPDATE repos SET is_public = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([(int)$_POST['new_status'], (int)$_POST['repo_id'], $_SESSION['user_id']]);
                header("Location: ?repo=" . (int)$_POST['repo_id']); exit;
            }

            if ($_POST['action'] === 'web_commit') {
                $repo_id = (int)$_POST['repo_id'];
                
                // CRITICAL FIX: Zip Slip / Path Traversal verhindern (Korrigierte Version)
                // Wir lehnen jegliche Navigationsversuche (..) und absolute Pfade sofort ab
                $filepath = $_POST['filepath'];
                $path_parts = explode('/', $filepath);
                $is_valid_path = true;
                foreach ($path_parts as $part) {
                    if ($part === '..' || $part === '.') {
                        $error = "Sicherheitsfehler: Ungültiger Dateipfad (Directory Traversal erkannt).";
                        $is_valid_path = false;
                        break;
                    }
                }
                
                if ($is_valid_path) {
                    // Führende Slashes entfernen und Mehrfach-Slashes zu einem zusammenfassen
                    $filepath = ltrim(preg_replace('/\/+/', '/', $filepath), '/');
                    
                    $stmt = $db->prepare("SELECT hash FROM commits WHERE repo_id = ? AND repo_id IN (SELECT id FROM repos WHERE user_id = ?) ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$repo_id, $_SESSION['user_id']]);
                    $latest = $stmt->fetch();
                    
                    if ($latest && file_exists($snap_dir . "/repo_{$repo_id}_{$latest['hash']}.pack")) {
                        $old_zip = $snap_dir . "/repo_{$repo_id}_{$latest['hash']}.pack";
                        $temp_zip = sys_get_temp_dir() . '/zg_temp_' . uniqid() . '.zip';
                        copy($old_zip, $temp_zip);
                        
                        $zip = new ZipArchive;
                        if ($zip->open($temp_zip) === TRUE) {
                            $zip->addFromString($filepath, $_POST['content']);
                            $zip->close();
                            
                            $zip_bin = file_get_contents($temp_zip);
                            unlink($temp_zip);
                            
                            $hash = substr(hash('sha256', $zip_bin . time()), 0, 12);
                            file_put_contents($snap_dir . "/repo_{$repo_id}_{$hash}.pack", $zip_bin);
                            
                            $msg = !empty($_POST['commit_msg']) ? trim($_POST['commit_msg']) : "Web Edit: " . basename($filepath);
                            $stmt = $db->prepare("INSERT INTO commits (repo_id, hash, message) VALUES (?, ?, ?)");
                            $stmt->execute([$repo_id, $hash, $msg]);
                            
                            // FIX: Auch nach dem Web-Commit das Pruning auslösen!
                            prune_repo($db, $repo_id, $snap_dir);
                            
                            $success = "🚀 Web-Commit erfolgreich!";
                        }
                    } else { $error = "Fehler oder keine Berechtigung."; }
                }
            }
        }
    }
}

$active_repo_id = isset($_GET['repo']) ? (int)$_GET['repo'] : null;
$active_repo = null;
$is_owner = false;

if ($active_repo_id) {
    $stmt = $db->prepare("SELECT repos.*, users.username AS owner_name FROM repos JOIN users ON repos.user_id = users.id WHERE repos.id = ?");
    $stmt->execute([$active_repo_id]);
    $active_repo = $stmt->fetch();

    if ($active_repo) {
        $is_owner = ($is_logged_in && $active_repo['user_id'] === $_SESSION['user_id']);
        if (!$is_owner && $active_repo['is_public'] == 0) { $error = "Privates Repo."; $active_repo = null; }
    }
}

// =========================================================================
// 4. FRONTEND (Tailwind)
// =========================================================================
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZeroGit</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/theme/darcula.min.css">
    <style>
        body { background-color: #0d1117; color: #c9d1d9; font-family: sans-serif; }
        .zg-panel { background-color: #161b22; border: 1px solid #30363d; border-radius: 6px; }
        .zg-input { background-color: #0d1117; border: 1px solid #30363d; color: #c9d1d9; border-radius: 6px; padding: 0.5rem; width: 100%; outline: none; }
        .zg-input:focus { border-color: #58a6ff; }
        .zg-btn { background-color: #238636; color: #ffffff; border: 1px solid rgba(240,246,252,0.1); border-radius: 6px; padding: 0.5rem 1rem; cursor: pointer; }
        .CodeMirror { height: 60vh !important; border-radius: 6px; border: 1px solid #30363d; }
    </style>
</head>
<body class="antialiased min-h-screen flex flex-col">

    <header class="border-b border-[#30363d] bg-[#161b22] py-4 px-6 flex justify-between items-center">
        <a href="?" class="text-xl font-bold text-white tracking-tight">ZeroGit</a>
        <?php if ($is_logged_in): ?>
            <div class="text-sm flex items-center gap-3">
                <span>Angemeldet als <strong><?= htmlspecialchars($_SESSION['username']) ?></strong></span>
                
                <div class="flex items-center bg-[#0d1117] border border-[#30363d] rounded overflow-hidden" title="Dein API-Token für das Terminal">
                    <span class="px-2 text-xs text-gray-500">🔑</span>
                    <input type="text" id="api_token_field" value="<?= $_SESSION['api_token'] ?>" class="bg-transparent text-xs text-gray-400 w-24 outline-none cursor-text py-1" readonly onclick="this.select();">
                    <button type="button" onclick="document.getElementById('api_token_field').select(); document.execCommand('copy'); const t = this.innerHTML; this.innerHTML = '✅'; setTimeout(() => this.innerHTML = t, 1500);" class="bg-[#21262d] hover:bg-[#30363d] text-gray-300 px-2 py-1 text-xs transition-colors border-l border-[#30363d] cursor-pointer">📋 cp</button>
                </div>
                
                <a href="?logout=1" class="text-gray-400 hover:text-white transition-colors border-l border-[#30363d] pl-3">Abmelden</a>
            </div>
        <?php else: ?>
            <a href="?" class="text-[#58a6ff] text-sm">Login</a>
        <?php endif; ?>
    </header>

    <main class="flex-grow flex items-start justify-center p-6">
        <div class="w-full max-w-6xl">
            <?php if ($success): ?><div class="bg-green-900 text-green-400 px-4 py-3 rounded mb-4 text-sm"><?= $success ?></div><?php endif; ?>
            <?php if ($error): ?><div class="bg-red-900 text-red-200 px-4 py-3 rounded mb-4 text-sm"><?= $error ?></div><?php endif; ?>

            <?php if ($active_repo): 
                $stmt = $db->prepare("SELECT * FROM commits WHERE repo_id = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$active_repo_id]);
                $latest_commit = $stmt->fetch();
            ?>
                <div class="zg-panel p-6 mb-6 flex justify-between items-start">
                    <div>
                        <h2 class="text-2xl font-bold text-white"><?= htmlspecialchars($active_repo['name']) ?> <span class="text-xs text-gray-500 font-normal">von <?= htmlspecialchars($active_repo['owner_name']) ?></span></h2>
                        <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars($active_repo['description'] ?? '') ?></p>
                        
                        <div class="mt-4 flex gap-3">
                            <a href="?download=1&repo=<?= $active_repo_id ?>" class="text-xs px-2 py-1 rounded border border-blue-500/50 text-blue-400 hover:bg-blue-900/30 transition">⬇️ Projekt als ZIP laden</a>
                            <?php if ($is_owner): ?>
                                <form method="POST" action="?repo=<?= $active_repo_id ?>" class="inline-block">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="action" value="toggle_public"><input type="hidden" name="repo_id" value="<?= $active_repo_id ?>"><input type="hidden" name="new_status" value="<?= $active_repo['is_public'] ? 0 : 1 ?>">
                                    <button type="submit" class="text-xs px-2 py-1 rounded border <?= $active_repo['is_public'] ? 'border-green-500/50 text-green-400' : 'border-gray-600 text-gray-400' ?>">
                                        <?= $active_repo['is_public'] ? '🌍 Öffentlich' : '🔒 Privat' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (isset($_GET['edit']) && $latest_commit): 
                    $edit_file = $_GET['edit'];
                    $zip = new ZipArchive; $file_content = '';
                    if ($zip->open($snap_dir . "/repo_{$active_repo_id}_{$latest_commit['hash']}.pack") === TRUE) {
                        $file_content = $zip->getFromName($edit_file) ?: "Fehler beim Lesen."; $zip->close();
                    }
                    
                    // CodeMirror: Dynamische Spracherkennung für korrektes Highlighting
                    $ext = strtolower(pathinfo($edit_file, PATHINFO_EXTENSION));
                    $cm_mode = 'javascript';
                    if ($ext === 'php') $cm_mode = 'application/x-httpd-php';
                    if ($ext === 'css') $cm_mode = 'css';
                    if ($ext === 'html') $cm_mode = 'htmlmixed';
                ?>
                    <div class="zg-panel overflow-hidden">
                        <div class="bg-[#161b22] px-4 py-3 border-b border-[#30363d] flex justify-between">
                            <span class="text-sm text-gray-300">✏️ <?= htmlspecialchars($edit_file) ?></span>
                            <a href="?repo=<?= $active_repo_id ?>" class="text-sm text-blue-400">Zurück</a>
                        </div>
                        <form method="POST" action="?repo=<?= $active_repo_id ?>" class="p-4">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="web_commit"><input type="hidden" name="repo_id" value="<?= $active_repo_id ?>"><input type="hidden" name="filepath" value="<?= htmlspecialchars($edit_file) ?>">
                            <textarea id="code_editor" name="content"><?= htmlspecialchars($file_content) ?></textarea>
                            <div class="mt-4 flex gap-4">
                                <?php if ($is_owner): ?>
                                    <input type="text" name="commit_msg" class="zg-input flex-grow" placeholder="Commit-Nachricht">
                                    <button type="submit" class="zg-btn">Speichern (Web-Commit)</button>
                                <?php else: ?>
                                    <span class="text-sm text-gray-500">Read-Only Modus (Gast)</span>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.js"></script>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/javascript/javascript.min.js"></script>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/xml/xml.min.js"></script>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/htmlmixed/htmlmixed.min.js"></script>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/css/css.min.js"></script>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/clike/clike.min.js"></script>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/php/php.min.js"></script>
                    <script>var editor = CodeMirror.fromTextArea(document.getElementById("code_editor"), { lineNumbers: true, theme: "darcula", mode: "<?= $cm_mode ?>", readOnly: <?= $is_owner ? 'false' : 'true' ?> });</script>

                <?php else: ?>
                    <div class="zg-panel overflow-hidden mb-6">
                        <div class="bg-[#161b22] px-4 py-3 border-b border-[#30363d] flex justify-between items-center">
                            <h3 class="font-semibold text-white">Dateibaum</h3>
                            <span class="text-xs text-gray-500 font-mono">Commit: <?= substr($latest_commit['hash'], 0, 8) ?? 'none' ?></span>
                        </div>
                        <?php if ($latest_commit): ?>
                            <ul class="flex flex-col">
                                <?php 
                                $zip = new ZipArchive;
                                if ($zip->open($snap_dir . "/repo_{$active_repo_id}_{$latest_commit['hash']}.pack") === TRUE) {
                                    $files = []; 
                                    for ($i = 0; $i < $zip->numFiles; $i++) {
                                        $stat = $zip->statIndex($i);
                                        // Wir filtern explizite Ordner-Einträge (enden mit /) heraus, 
                                        // da wir die Ordnerstruktur dynamisch aus den Dateipfaden extrahieren.
                                        if (substr($stat['name'], -1) === '/') continue; 
                                        $files[] = ['name' => $stat['name'], 'size' => $stat['size']];
                                    }
                                    
                                    // Alphabetische Sortierung zwingt Dateien automatisch in korrekte Ordnergruppen
                                    usort($files, function($a, $b) { return strcmp($a['name'], $b['name']); });
                                    
                                    $current_path = [];
                                    foreach ($files as $f) {
                                        $parts = explode('/', $f['name']);
                                        $filename = array_pop($parts); // Nur der Dateiname (z.b. style.css)
                                        $dir_path = $parts;            // Die Ordner-Struktur davor (z.b. ['public', 'css'])
                                        $size_str = formatBytes($f['size']);
                                        
                                        // 1. Finde den Index, an dem sich der aktuelle Pfad vom vorherigen unterscheidet
                                        $diff_index = 0;
                                        while (isset($current_path[$diff_index]) && isset($dir_path[$diff_index]) && $current_path[$diff_index] === $dir_path[$diff_index]) {
                                            $diff_index++;
                                        }
                                        
                                        // 2. Gebe alle NEUEN Ordner-Hierarchien aus, in die wir gerade abtauchen
                                        for ($i = $diff_index; $i < count($dir_path); $i++) {
                                            $pad = $i * 1.5 + 1; // Einrückung pro Ebene
                                            echo '<li class="bg-[#1c2128]/60 border-y border-[#30363d]/40 px-4 py-1.5 text-sm font-semibold text-gray-400 flex items-center gap-2" style="padding-left: '.$pad.'rem;">';
                                            echo '📁 <span>' . htmlspecialchars($dir_path[$i]) . '</span>';
                                            echo '</li>';
                                        }
                                        
                                        $current_path = $dir_path;
                                        $depth = count($dir_path);
                                        $file_pad = $depth * 1.5 + 1.5; // Datei noch etwas weiter einrücken als den Ordner
                                        
                                        // 3. Datei ausgeben
                                        echo '<li class="hover:bg-[#21262d] flex justify-between items-center px-4 py-2 text-sm border-b border-[#30363d]/20 last:border-0 transition-colors group">';
                                        echo '<div class="text-gray-300 flex items-center gap-2" style="padding-left: '.$file_pad.'rem;">📄 <span class="group-hover:text-white transition-colors">'.htmlspecialchars($filename).'</span></div>';
                                        echo '<div class="flex items-center gap-4"><span class="text-xs text-gray-500 font-mono">'.$size_str.'</span><a href="?repo='.$active_repo_id.'&edit='.urlencode($f['name']).'" class="text-blue-400 hover:text-blue-300 bg-blue-900/10 hover:bg-blue-900/30 px-2 py-1 rounded transition-colors text-xs">Editor</a></div>';
                                        echo '</li>';
                                    } 
                                    $zip->close();
                                }
                                ?>
                            </ul>
                        <?php else: ?>
                            <div class="p-6 text-center">
                                <p class="text-gray-400 text-sm mb-4">Dieses Repository ist noch leer.</p>
                                <?php if ($is_owner): 
                                    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                                    $auto_url = $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
                                ?>
                                    <pre class="bg-black p-4 rounded text-sm overflow-x-auto text-left inline-block">
<code class="block mb-2 text-gray-500"><span class="select-none">$ </span><span class="text-green-400">python zg.py init <?= $auto_url ?> <?= htmlspecialchars($_SESSION['username']) ?> &lt;DEIN_API_TOKEN&gt; <?= $active_repo_id ?></span></code>
<code class="block text-gray-500"><span class="select-none">$ </span><span class="text-green-400">python zg.py save "Initial Commit"</span></code>
                                    </pre>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php elseif ($is_logged_in): ?>
                <div class="flex gap-6">
                    <div class="w-1/3 space-y-6">
                        <div class="zg-panel p-4">
                            <h3 class="text-sm font-semibold text-gray-400 mb-4">Meine Repositories</h3>
                            <?php 
                            // Raw-SQL bereinigt für konsistente PDO Prepared Statements
                            $stmt = $db->prepare("SELECT * FROM repos WHERE user_id = ? ORDER BY id DESC");
                            $stmt->execute([$_SESSION['user_id']]);
                            foreach ($stmt->fetchAll() as $r): ?>
                                <a href="?repo=<?= $r['id'] ?>" class="block p-2 hover:bg-[#21262d] rounded"><div class="text-blue-400 font-semibold"><?= htmlspecialchars($r['name']) ?></div></a>
                            <?php endforeach; ?>
                        </div>
                        <div class="zg-panel p-4">
                            <h3 class="text-sm font-semibold text-gray-400 mb-4">🌍 Explore</h3>
                            <?php 
                            // Raw-SQL bereinigt für konsistente PDO Prepared Statements
                            $stmt = $db->prepare("SELECT r.*, u.username FROM repos r JOIN users u ON r.user_id=u.id WHERE is_public=1 AND user_id!= ? LIMIT 10");
                            $stmt->execute([$_SESSION['user_id']]);
                            foreach ($stmt->fetchAll() as $r): ?>
                                <a href="?repo=<?= $r['id'] ?>" class="block p-2 hover:bg-[#21262d] rounded"><div class="text-gray-300 font-semibold"><?= htmlspecialchars($r['name']) ?> <span class="text-xs text-gray-500">(<?= htmlspecialchars($r['username']) ?>)</span></div></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="w-2/3 zg-panel p-6">
                        <h2 class="text-xl text-white mb-4">Neues Repository</h2>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="create_repo">
                            <input type="text" name="repo_name" class="zg-input mb-4" required placeholder="Name">
                            <input type="text" name="repo_desc" class="zg-input mb-4" placeholder="Beschreibung">
                            <label class="flex items-center gap-2 mb-6 text-sm text-gray-300"><input type="checkbox" name="is_public"> Öffentlich sichtbar</label>
                            <button type="submit" class="zg-btn">Erstellen</button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="flex gap-8 items-start justify-center mt-10">
                    <!-- Login Panel -->
                    <div class="zg-panel p-8 w-full max-w-md">
                        <h2 class="text-xl text-white mb-6">Login / Registrieren</h2>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="<?= isset($_GET['view']) ? 'register' : 'login' ?>">
                            <input type="text" name="username" class="zg-input mb-4" required placeholder="Username">
                            <input type="password" name="password" class="zg-input mb-6" required placeholder="Passwort">
                            <button type="submit" class="zg-btn w-full"><?= isset($_GET['view']) ? 'Account erstellen' : 'Einloggen' ?></button>
                        </form>
                        <div class="mt-4 text-center text-sm"><a href="?<?= isset($_GET['view']) ? '' : 'view=register' ?>" class="text-blue-400">Modus wechseln</a></div>
                    </div>

                    <!-- Explore-Ansicht für Gäste -->
                    <div class="w-full max-w-sm">
                        <div class="zg-panel p-6">
                            <h2 class="text-xl font-semibold text-white mb-4 flex items-center gap-2">
                                🌍 Explore
                            </h2>
                            <p class="text-sm text-gray-400 mb-6">Öffentliche Repositories auf dieser Instanz.</p>
                            
                            <div class="space-y-3">
                                <?php
                                $stmt = $db->query("SELECT repos.*, users.username FROM repos JOIN users ON repos.user_id = users.id WHERE is_public = 1 ORDER BY created_at DESC LIMIT 15");
                                $pub_repos = $stmt->fetchAll();

                                if (count($pub_repos) > 0):
                                    foreach ($pub_repos as $repo):
                                ?>
                                    <a href="?repo=<?= $repo['id'] ?>" class="block p-4 border border-[#30363d] rounded transition hover:border-[#58a6ff] hover:bg-[#21262d]">
                                        <div class="font-semibold text-blue-400"><?= htmlspecialchars($repo['name']) ?></div>
                                        <div class="text-xs text-gray-500 mt-1">von <?= htmlspecialchars($repo['username']) ?></div>
                                    </a>
                                <?php 
                                    endforeach;
                                else:
                                    echo '<div class="text-sm text-gray-500 italic">Noch keine öffentlichen Repos.</div>';
                                endif; 
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body></html>
