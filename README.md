# 📦 ZeroGit (The Naked VCS)

A single-file PHP cloud & Python CLI for solo developers. Zero dependencies. Zero Git.

ZeroGit is a brutally pragmatic Version Control System designed for developers who just want to push code to a cheap shared web-hosting space without dealing with SSH keys, Git hooks, merge conflicts, or complex CI/CD pipelines.

If you ever thought: "I just want to ZIP this folder and send it to my server to have a backup and a history", ZeroGit is exactly that—but automated, highly optimized, and wrapped in a beautiful Web UI.

# ✨ Features

Zero Dependencies: The CLI uses pure Python standard libraries (no pip install). The backend is a single PHP file using PDO MySQL.

Single-File Cloud: Drop zerogit.php on your server, and you instantly get a private GitHub-like web interface.

In-Memory Diffing: Run python zg.py diff to see what you changed locally before pushing, without extracting anything.

Web Editor: Edit your code directly in the browser via the built-in CodeMirror interface.

Collision Detection: If you edit code in the Web UI and try to push from your terminal later, ZeroGit blocks the push to prevent blind overwrites (unless you use --force).

Auto-Pruning: Keeps your server clean. Automatically deletes old ZIPs and keeps only the latest 50 snapshots per repository.

Public/Private Repos: Share your code with the world via read-only access and a "Download ZIP" button for guests.

# 🚀 60-Second Setup

1. The Server (Backend)

Create a blank MySQL database on your web host.

Edit the zerogit.php file and add your database credentials at the top:

$db_host = 'localhost';
$db_name = 'your_db_name';
$db_user = 'your_db_user';
$db_pass = 'your_db_pass';


Upload zerogit.php to your web server (e.g., https://yourdomain.com/zerogit.php).

Open the URL in your browser. The database tables will initialize automatically. Login with admin / admin.

Click Create Repository and note down the Repo ID.

2. The Local Machine (Client)

Drop the zg.py file into the root of your local project folder. Open your terminal and link it to your new cloud:

Initialize the connection
$ python zg.py init [https://yourdomain.com/zerogit.php](https://yourdomain.com/zerogit.php) admin admin <REPO_ID>

Save and push your first snapshot
$ python zg.py save "Initial Commit"


#🛠️ CLI Commands

python zg.py init <url> <u> <p> <id> - Links the current folder to your ZeroGit server.

python zg.py save "Message" - Zips your folder, pushes it, and registers a commit.

python zg.py save "Msg" --force - Force-pushes, ignoring server-side Web-UI edits.

python zg.py diff - Shows local changes compared to the latest server commit.

python zg.py history - Displays the timeline of your snapshots.

python zg.py undo <id> - Downloads snapshot <id>, cleans your local folder, and extracts it.

⚙️ Configuration & Ignore List

When you run init, ZeroGit creates a hidden .zg_config.json file in your directory.
You can edit this file to add folders or files that should never be uploaded (like node_modules, venv, or .env files).

{
    "url": "[https://yourdomain.com/zerogit.php](https://yourdomain.com/zerogit.php)",
    "ignore_list": [
        ".zg_config.json",
        "node_modules",
        "__pycache__",
        "vendor"
    ]
}


Note: ZeroGit uses Linux-native POSIX path evaluation. It will reliably ignore these folders regardless of your OS.

#🛡️ Architecture & Security

No ZIP Bombs: The temp-file generation runs locally on the HDD (preventing RAM/OOM crashes on large folders) and protects against recursive self-zipping.

CSRF Protection: All Web UI actions are secured via cryptographic session tokens.

Private Snapshots: The /snapshots directory is automatically protected via .htaccess against direct URL downloads.

ZeroGit - Built for Pragmatists.
