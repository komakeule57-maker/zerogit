#!/usr/bin/env python3
# Dateiname: zg_agent.py
# Funktion: KI-Agent (Sidecar) für ZeroGit. Liest lokalen Code, fragt Google Gemma, wendet Änderungen an und pusht sie.

import os
import sys
import json
import urllib.request
import urllib.error
import subprocess
import re

try:
    import zg
except ImportError:
    print("🚨 Fehler: zg.py nicht gefunden. Der Agent muss im selben Ordner liegen!")
    sys.exit(1)

def get_gemini_key():
    config = zg.get_config()
    key = config.get("gemini_api_key")
    if not key:
        print("🤖 Bobbycar initialisieren...")
        print("Ich brauche einen Google API Key (Kostenlos holbar auf Google AI Studio).")
        key = input("API Key eingeben: ").strip()
        config["gemini_api_key"] = key
        zg.save_config(config)
        print("✅ API Key in .zg_config.json gespeichert!\n")
    return key

def gather_context():
    print("👀 Lese lokalen Code-Kontext...")
    context = ""
    file_count = 0
    for root, dirs, files in os.walk('.'):
        dirs[:] = [d for d in dirs if not zg.should_ignore(os.path.join(root, d))]
        for file in files:
            filepath = os.path.join(root, file)
            if not zg.should_ignore(filepath):
                try:
                    with open(filepath, 'r', encoding='utf-8') as f:
                        content = f.read()
                    clean_path = os.path.relpath(filepath, '.').replace('\\', '/')
                    context += f"\n--- FILE: {clean_path} ---\n{content}\n"
                    file_count += 1
                except UnicodeDecodeError:
                    pass # Binärdatei ignorieren
    print(f"🧠 {file_count} Dateien in den Kontext geladen.")
    return context

def call_gemma(api_key, context, prompt):
    print("🚀 Sende Denk-Auftrag an Gemma API...")
    
    model = "gemma-4-26b-a4b-it" # Das hocheffiziente Open-Weights Modell
    url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={api_key}"
    
    system_instruction = """Du bist ein autonomer Coding-Agent.
Du hast Zugriff auf den gesamten Code des Projekts.
Beantworte die Anfrage des Users, indem du Code anpasst oder neu schreibst.

WICHTIGSTE REGEL ZUM ANTWORT-FORMAT:
Gib AUSSCHLIESSLICH die geänderten oder neuen Dateien in exakt folgendem Format zurück. 
Nutze KEINE Markdown-Codeblocks um das XML herum!

<file path="ordner/datei.ext">
DER KOMPLETTE NEUE INHALT DER DATEI HIER
</file>

Wenn eine Datei nicht geändert werden muss, erwähne sie nicht.
"""

    payload = {
        "contents": [{"parts": [{"text": f"SYSTEM/REGELN:\n{system_instruction}\n\nCODE KONTEXT:\n{context}\n\nAUFGABE DES USERS:\n{prompt}"}]}],
        "generationConfig": {"temperature": 0.2}
    }
    
    data = json.dumps(payload).encode('utf-8')
    req = urllib.request.Request(url, data=data, headers={'Content-Type': 'application/json'})
    
    try:
        with urllib.request.urlopen(req) as response:
            res = json.loads(response.read().decode('utf-8'))
            try:
                return res['candidates'][0]['content']['parts'][0]['text']
            except KeyError:
                print("❌ Unerwartetes API-Antwortformat von Gemma.")
                sys.exit(1)
    except urllib.error.URLError as e:
        print(f"❌ API-Fehler: Konnte Gemma nicht erreichen ({e})")
        if hasattr(e, 'read'):
            print(e.read().decode('utf-8'))
        sys.exit(1)

def apply_changes(ai_response):
    print("🛠️  Wende KI-Änderungen auf lokale Dateien an...")
    changes_made = 0
    
    # E > H: Robustes Regex, fischt Dateien raus, egal ob die KI Markdown Backticks drumherumgebaut hat
    pattern = re.compile(r'<file\s+path=["\'](.*?)["\']\s*>(.*?)</file>', re.DOTALL | re.IGNORECASE)
    matches = pattern.findall(ai_response)
    
    for filepath, content in matches:
        # Sicherheits-Audit: Path Traversal beim Agenten blockieren
        safe_path = os.path.normpath(filepath.strip())
        if safe_path.startswith('/') or safe_path.startswith('..') or os.path.isabs(safe_path):
            print(f"🚨 Sicherheitswarnung: Gemma hat ungültigen Pfad vorgeschlagen, ignoriert: {filepath}")
            continue
            
        # Wenn der Dateinhalt mit einem Zeilenumbruch startet (passiert oft durchs Format), strippen
        if content.startswith('\n'):
            content = content[1:]
            
        os.makedirs(os.path.dirname(safe_path) or '.', exist_ok=True)
        
        with open(safe_path, 'w', encoding='utf-8') as f:
            f.write(content)
        
        print(f"  \033[92m✓ {safe_path} aktualisiert\033[0m")
        changes_made += 1
            
    if changes_made == 0:
        print("🤷 Gemma hat keine validen Dateiblock-Änderungen zurückgegeben. (Formatfehler oder Aufgabe unklar?)")
        print("\nRohe Antwort:")
        print(ai_response)
        sys.exit(1)
    return changes_made

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("🤖 ZeroGit Agent (Bobbycar) - Befehl fehlt!")
        print("Nutzung: python zg_agent.py \"Füge ein neues Feature XYZ hinzu\"")
        sys.exit(1)
        
    user_prompt = sys.argv[1]
    
    api_key = get_gemini_key()
    context = gather_context()
    answer = call_gemma(api_key, context, user_prompt)
    applied_count = apply_changes(answer)
    
    if applied_count > 0:
        print("\n🔍 Zeige lokale Code-Änderungen an...")
        try:
            # Zeigt den Diff an, bevor gepusht wird
            subprocess.run([sys.executable, "zg.py", "diff"])
        except Exception: 
            pass
            
        confirm = input("\n🤖 Agent hat den Code modifiziert. Push auf den Server durchführen? (j/N): ")
        
        if confirm.lower() == 'j':
            commit_msg = f"🤖 Agent-Commit: {user_prompt[:45]}..."
            print(f"📦 Triggere ZeroGit Push: '{commit_msg}'...")
            try:
                subprocess.run([sys.executable, "zg.py", "save", commit_msg], check=True)
                print("✅ Agent hat die Arbeit erfolgreich abgeschlossen und auf den Server gespiegelt!")
            except subprocess.CalledProcessError:
                print("❌ Agent-Änderungen sind lokal gespeichert, aber ZeroGit konnte nicht pushen.")
        else:
            print("🛑 Push abgebrochen. Die Änderungen sind lokal gespeichert.")
            print("Tipp: Nutze 'python zg.py undo <ID>', um die Änderungen des Agenten restlos zu verwerfen.")a
