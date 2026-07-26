#!/usr/bin/env python3
# Dateiname: zg.py
# Funktion: ZeroGit CLI Client. Zippt, synct, bereinigt lokal, bietet Diff-Ansicht.
# Maxime: E > H (Config-based Ignore, Collision-Detection mit --force, Auto-Migration)

import os
import sys
import json
import base64
import zipfile
import tempfile
import urllib.request
import io
import difflib
import fnmatch
import shutil
from urllib.error import URLError

CONFIG_FILE = ".zg_config.json"
_config_cache = None

def get_config():
    global _config_cache
    if _config_cache is None:
        if not os.path.exists(CONFIG_FILE):
            print("🚨 Fehler: ZeroGit ist hier nicht initialisiert.")
            print("Führe zuerst aus: python zg.py init <url> <user> <api_token> <repo_id>")
            sys.exit(1)
            
        with open(CONFIG_FILE, 'r') as f:
            _config_cache = json.load(f)
            
        # E > H Migration: Alte Configs automatisch mit neuen Feldern patchen
        needs_save = False
        if "ignore_list" not in _config_cache:
            _config_cache["ignore_list"] = [
                '.zg_config.json', '.git', 'node_modules', 'vendor', 
                '__pycache__', '.venv', 'env', '.DS_Store', 'zerogit.sqlite', 
                './backup', './factory', './bpe_init'
            ]
            needs_save = True
        if "last_commit" not in _config_cache:
            _config_cache["last_commit"] = 0
            needs_save = True
            
        if needs_save:
            save_config(_config_cache)
            
    return _config_cache

def save_config(config):
    global _config_cache
    with open(CONFIG_FILE, 'w') as f:
        json.dump(config, f, indent=4)
    _config_cache = config

def api_request(payload):
    config = get_config()
    payload['username'] = config['username']
    # E>H Fix: Sende das Token immer als 'api_token' (neu) UND als 'password' (alt). 
    # Damit ist der Client absolut kompatibel und der Auth-Fail ist Geschichte.
    payload['api_token'] = config.get('password', '') 
    payload['password'] = config.get('password', '') 
    payload['repo_id'] = config['repo_id']

    data = json.dumps(payload).encode('utf-8')
    
    try:
        req = urllib.request.Request(config['url'], data=data, headers={'Content-Type': 'application/json'})
        # Timeout (15s) gegen hängende Netzwerkverbindungen
        with urllib.request.urlopen(req, timeout=15) as response:
            res = json.loads(response.read().decode('utf-8'))
            if res.get('status') == 'error':
                print(f"❌ Server-Fehler: {res.get('message')}")
                sys.exit(1)
            return res
    except URLError as e:
        # Fallback/Workaround für hartnäckige DNS/WSL Probleme in Python
        if "Temporary failure in name resolution" in str(e.reason):
            print("⚠️ DNS-Auflösung in Python gescheitert. Versuche Fallback...")
            try:
                import socket
                from urllib.parse import urlparse
                parsed_url = urlparse(config['url'])
                ip = socket.gethostbyname(parsed_url.netloc)
                fallback_url = config['url'].replace(parsed_url.netloc, ip)
                req = urllib.request.Request(fallback_url, data=data, headers={
                    'Content-Type': 'application/json',
                    'Host': parsed_url.netloc # Host-Header ist zwingend nötig für Virtual Hosts wie bplaced
                })
                with urllib.request.urlopen(req, timeout=15) as response:
                    res = json.loads(response.read().decode('utf-8'))
                    if res.get('status') == 'error':
                        print(f"❌ Server-Fehler: {res.get('message')}")
                        sys.exit(1)
                    return res
            except Exception as fallback_e:
                 print(f"❌ Netzwerk-Fehler (Auch Fallback gescheitert): Konnte das Backend nicht erreichen ({e.reason})")
                 sys.exit(1)
        else:
            print(f"❌ Netzwerk-Fehler: Konnte das Backend nicht erreichen ({e.reason})")
            sys.exit(1)

def should_ignore(filepath):
    """ Dynamisch gesteuert durch .zg_config.json mit fnmatch (Globbing) Unterstützung """
    # Temporäre Systemdateien von zg.py immer ignorieren
    if "zg_temp_" in filepath or "zg_undo_" in filepath:
        return True
        
    config = get_config()
    ignore_list = config.get('ignore_list', [])
        
    # Pfad normalisieren für OS-übergreifende Konsistenz
    clean_path = os.path.normpath(filepath).replace('\\', '/')
    path_parts = clean_path.split('/')
    
    for ignored in ignore_list:
        clean_ignored = ignored.replace('./', '').strip('/')
        if not clean_ignored: continue
        
        # Standard: Exakter Ordner-Match (wie bisher)
        if clean_ignored in path_parts:
            return True
            
        # Neu: Glob pattern match (z.B. *.log, dist/*, src/**/*.js)
        if fnmatch.fnmatch(clean_path, clean_ignored) or \
           fnmatch.fnmatch(clean_path, f"*/{clean_ignored}") or \
           fnmatch.fnmatch(clean_path, f"*/{clean_ignored}/*"):
            return True
            
    return False

def cmd_init(url, user, api_token, repo_id):
    if url.startswith("http://"):
        print("⚠️  WARNUNG: Du nutzt HTTP. Dein Token geht im Klartext übers Netz! Nutze wenn möglich HTTPS.")

    config = {
        "url": url if url.endswith('.php') else url.rstrip('/') + '/zerogit.php',
        "username": user,
        "password": api_token, # Key bleibt "password" in der config wegen Kompatibilität
        "repo_id": int(repo_id),
        "last_commit": 0,
        "ignore_list": [
            '.zg_config.json', '.git', 'node_modules', 'vendor', 
            '__pycache__', '.venv', 'env', '.DS_Store', 'zerogit.sqlite', 
            './backup', './factory', './bpe_init'
        ]
    }
    save_config(config)
    print("✅ ZeroGit erfolgreich initialisiert! (.zg_config.json erstellt)")
    print("Tipp: Deine Ignore-Liste liegt jetzt direkt editierbar in der .zg_config.json!")

def cmd_save(message, force=False):
    config = get_config()
    print(f"📦 Erstelle Snapshot {'(FORCE PUSH)' if force else ''}...")
    
    temp_zip = tempfile.NamedTemporaryFile(delete=False, prefix='zg_temp_', suffix='.zip', dir='.')
    temp_zip.close()

    file_count = 0
    with zipfile.ZipFile(temp_zip.name, 'w', zipfile.ZIP_DEFLATED) as zf:
        for root, dirs, files in os.walk('.'):
            # Ordner dynamisch filtern
            dirs[:] = [d for d in dirs if not should_ignore(os.path.join(root, d))]
            for file in files:
                file_path = os.path.join(root, file)
                if not should_ignore(file_path):
                    arcname = os.path.relpath(file_path, '.')
                    zf.write(file_path, arcname)
                    file_count += 1

    print(f"🚀 {file_count} Dateien komprimiert. Übertrage zum Server...")
    
    with open(temp_zip.name, "rb") as zf:
        b64_data = base64.b64encode(zf.read()).decode('utf-8')
    os.unlink(temp_zip.name)

    res = api_request({
        "action": "push",
        "message": message,
        "zip_data": b64_data,
        "base_commit": config.get("last_commit", 0),
        "force": force
    })

    new_commit_id = res.get('commit_id')
    print(f"✅ Snapshot gesichert! [ID: {new_commit_id}] - {message}")
    
    # Lokalen State updaten für zukünftige Kollisions-Erkennung
    config['last_commit'] = new_commit_id
    save_config(config)

def cmd_history():
    print("⏳ Lade Zeitstrahl...")
    res = api_request({"action": "history"})
    
    print("\n--- ZeroGit Lineare Historie ---")
    for commit in res.get('commits', []):
        print(f"[{commit['id']}] {commit['timestamp']} | {commit['message']}")
    print("--------------------------------\n")

def cmd_undo(commit_id):
    config = get_config()
    print(f"⏪ Lade Snapshot [{commit_id}] aus dem Backend herunter...")
    res = api_request({"action": "pull", "commit_id": commit_id})

    zip_data = base64.b64decode(res.get('zip_data'))
    
    temp_zip = tempfile.NamedTemporaryFile(delete=False, prefix='zg_temp_', suffix='.zip', dir='.')
    with open(temp_zip.name, 'wb') as f:
        f.write(zip_data)

    print("⚠️  WARNUNG: Dies überschreibt ALLE lokalen Änderungen und löscht ungetrackte Dateien!")
    confirm = input("Bist du sicher? (j/N): ")
    if confirm.lower() != 'j':
        os.unlink(temp_zip.name)
        print("Abgebrochen.")
        sys.exit(0)

    print("📦 Entpacke und verifiziere Snapshot...")
    # SICHERHEIT: Erst in ein Temp-Verzeichnis entpacken (Extract-then-Swap)
    extract_dir = tempfile.mkdtemp(prefix='zg_undo_', dir='.')
    
    try:
        with zipfile.ZipFile(temp_zip.name, 'r') as zf:
            for member in zf.namelist():
                # Path Traversal Prevention (ZipSlip) - Client-seitige Abwehr
                member_path = os.path.normpath(member)
                if member_path.startswith('/') or member_path.startswith('..') or os.path.isabs(member_path):
                    print(f"🚨 Sicherheitswarnung: Blockiere manipulierten Pfad: {member}")
                    continue
                zf.extract(member, extract_dir)
                
        print("🧹 Bereinige aktuelles Verzeichnis...")
        for root, dirs, files in os.walk('.'):
            # Temporären Entpackordner ignorieren
            dirs[:] = [d for d in dirs if not should_ignore(os.path.join(root, d))]
            for file in files:
                fp = os.path.join(root, file)
                if not should_ignore(fp) and temp_zip.name not in fp:
                    try: os.unlink(fp)
                    except: pass
                    
        print("🔄 Stelle Dateien wieder her...")
        for item in os.listdir(extract_dir):
            s = os.path.join(extract_dir, item)
            d = os.path.join('.', item)
            if os.path.isdir(s): 
                # dirs_exist_ok benötigt Python 3.8+
                shutil.copytree(s, d, dirs_exist_ok=True)
            else: 
                shutil.copy2(s, d)
            
    finally:
        # Aufräumen, egal ob Erfolg oder Fehler
        shutil.rmtree(extract_dir, ignore_errors=True)
        os.unlink(temp_zip.name)
        
    print(f"✅ Code erfolgreich auf Zustand [{commit_id}] synchronisiert!")
    
    # Status updaten, damit wir wieder ohne --force pushen können
    config['last_commit'] = int(commit_id)
    save_config(config)

def cmd_diff():
    print("🔍 Analysiere Änderungen zum Server...")
    
    hist = api_request({"action": "history"})
    commits = hist.get('commits', [])
    if not commits:
        print("Keine Commits auf dem Server gefunden.")
        return
        
    latest_id = commits[0]['id']
    res = api_request({"action": "pull", "commit_id": latest_id})
    zip_bin = base64.b64decode(res.get('zip_data'))
    
    with zipfile.ZipFile(io.BytesIO(zip_bin), 'r') as zf:
        server_files = set(zf.namelist())
        local_files = set()
        
        for root, dirs, files in os.walk('.'):
            dirs[:] = [d for d in dirs if not should_ignore(os.path.join(root, d))]
            for file in files:
                fp = os.path.join(root, file)
                if not should_ignore(fp):
                    local_files.add(os.path.relpath(fp, '.').replace('\\', '/'))
                    
        added = local_files - server_files
        deleted = server_files - local_files
        common = local_files.intersection(server_files)
        
        modified = []
        for f in common:
            try:
                server_content = zf.read(f)
                with open(f, 'rb') as lf:
                    local_content = lf.read()
                if server_content != local_content:
                    modified.append(f)
            except Exception: pass
                
        if not added and not deleted and not modified:
            print(f"✅ Keine lokalen Änderungen. Alles synchron mit Server-Commit [{latest_id}].")
            return
            
        if added:
            print("\n[NEU]")
            for f in added: print(f"  \033[92m+ {f}\033[0m")
        if deleted:
            print("\n[GELÖSCHT]")
            for f in deleted: print(f"  \033[91m- {f}\033[0m")
            
        if modified:
            print("\n[MODIFIZIERT]")
            for f in modified: 
                print(f"  \033[93m~ {f}\033[0m")
                try:
                    sc_bytes = zf.read(f)
                    lc_bytes = open(f, 'rb').read()
                    
                    # Heuristik: Ist es eine Binärdatei? (Enthält Null-Bytes)
                    if b'\0' in sc_bytes or b'\0' in lc_bytes:
                        print("    \033[90m(Binärdatei geändert - Kein Text-Diff möglich)\033[0m")
                        continue
                        
                    sc_str = sc_bytes.decode('utf-8').splitlines()
                    lc_str = lc_bytes.decode('utf-8').splitlines()
                    diff = list(difflib.unified_diff(sc_str, lc_str, n=0, lineterm=''))
                    for line in diff[2:10]: 
                        if line.startswith('+'): print(f"    \033[92m{line}\033[0m")
                        elif line.startswith('-'): print(f"    \033[91m{line}\033[0m")
                    if len(diff) > 10: print("    \033[90m... weitere Änderungen\033[0m")
                except UnicodeDecodeError:
                    print("    \033[90m(Konnte Text-Diff nicht interpretieren)\033[0m")
                except Exception: 
                    pass

# --- ROUTER ---
if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("ZeroGit (Naked VCS) - Befehle:")
        print("  init <url> <user> <api_token> <repo>  - Verbindet diesen Ordner mit der Cloud")
        print("  save [message] [--force]              - Erstellt ein Backup & pusht es")
        print("  diff                                  - Vergleicht lokal vs. letzter Server-Commit")
        print("  history                               - Zeigt alle Snapshots")
        print("  pull <id> / undo <id>                 - Lädt Zustand <id> vom Server herunter")
        sys.exit(1)

    cmd = sys.argv[1].lower()

    if cmd == "init" and len(sys.argv) == 6: 
        cmd_init(sys.argv[2], sys.argv[3], sys.argv[4], sys.argv[5])
    elif cmd == "save": 
        force_push = "--force" in sys.argv
        args = [a for a in sys.argv[2:] if a != "--force"]
        msg = args[0] if args else "Auto-Snapshot"
        cmd_save(msg, force=force_push)
    elif cmd == "diff": 
        cmd_diff()
    elif cmd == "history": 
        cmd_history()
    elif cmd in ["undo", "pull"] and len(sys.argv) == 3: 
        cmd_undo(sys.argv[2])
    else: 
        print("❌ Unbekannter Befehl oder falsche Parameter.")
